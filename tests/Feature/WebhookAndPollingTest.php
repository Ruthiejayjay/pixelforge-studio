<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\LedgerEntryType;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedgerService;
use App\Services\Generations\GenerationStateService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookAndPollingTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test_signing_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
            'services.pixelforge.api_key' => 'pf_test_key',
            'services.pixelforge.webhook_secret' => $this->secret,
            'services.pixelforge.markup_percent' => 25,
        ]);
    }

    private function postSignedWebhook(array $event, ?int $timestamp = null, ?string $secretOverride = null): TestResponse
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $ts = $timestamp ?? time();
        $sig = hash_hmac('sha256', "{$ts}.{$raw}", $secretOverride ?? $this->secret);

        return $this->call(
            method: 'POST',
            uri: '/webhooks/pixelforge',
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$ts},v1={$sig}",
                'HTTP_PIXELFORGE_EVENT_ID' => (string) ($event['id'] ?? ''),
                'HTTP_PIXELFORGE_EVENT_TYPE' => (string) ($event['type'] ?? ''),
                'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
            ],
            content: $raw,
        );
    }

    private function createHeldGeneration(User $user, GenerationStatus $status = GenerationStatus::Queued, ?string $providerId = 'gen_01ABC'): Generation
    {
        $uuid = (string) Str::uuid();
        $reserveNanos = 200_000_000; // $0.20 reserve

        $generation = Generation::create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'idempotency_key' => "genreq_{$uuid}",
            'provider_generation_id' => $providerId,
            'status' => $status,
            'prompt' => 'A lighthouse at dawn',
            'size' => ImageSize::Square1024,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'estimated_min_user_cost_nanos' => 50_000_000,
            'estimated_expected_user_cost_nanos' => 100_000_000,
            'estimated_max_user_cost_nanos' => $reserveNanos,
            'reserved_user_cost_nanos' => $reserveNanos,
        ]);

        app(CreditLedgerService::class)->placeHold($user, $generation, $reserveNanos);

        return $generation;
    }

    public function test_rejects_invalid_signature_and_accepts_ping(): void
    {
        $ping = ['id' => 'evt_ping_1', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-21T10:00:00Z'];

        $this->postSignedWebhook($ping, secretOverride: 'whsec_wrong')->assertStatus(400);
        $this->assertDatabaseCount('pixelforge_webhook_events', 0);

        $this->postSignedWebhook($ping)->assertNoContent();
        $this->assertDatabaseHas('pixelforge_webhook_events', ['event_id' => 'evt_ping_1', 'event_type' => 'ping']);
    }

    public function test_succeeded_webhook_bills_exact_amount_and_handles_duplicates_and_out_of_order_events(): void
    {
        $startingNanos = Money::fromDecimalString('1.00')->nanos;
        $user = User::factory()->create(['balance_nanos' => $startingNanos]);
        $generation = $this->createHeldGeneration($user);

        // Provider cost: 10*5000 + 0 + 1100*40000 + 800*12500 = 50,000 + 44,000,000 + 10,000,000 = 54,050,000 ($0.054050000)
        // User cost (+25%): 54,050,000 * 1.25 = 67,562,500 ($0.067562500)
        $succeededEvent = [
            'id' => 'evt_succ_001',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'created_at' => '2026-11-21T10:01:00Z',
            'data' => [
                'object' => [
                    'id' => 'gen_01ABC',
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'metadata' => ['local_generation_id' => $generation->uuid],
                    'image_url' => 'https://pixelforge.promptedit.dev/images/gen_01ABC.png?sig=1',
                    'image_url_expires_at' => '2026-11-22T10:01:00Z',
                    'usage' => [
                        'input_text_tokens' => 10,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 800,
                        'total_tokens' => 1910,
                    ],
                    'rates' => [
                        'input_text_tokens' => '5.00',
                        'input_image_tokens' => '10.00',
                        'output_tokens' => '40.00',
                        'reasoning_tokens' => '12.50',
                    ],
                    'completed_at' => '2026-11-21T10:01:00Z',
                ],
            ],
        ];

        // 1. First delivery succeeds & settles
        $this->postSignedWebhook($succeededEvent)->assertNoContent();

        // 2. Duplicate delivery (same evt_id) is ignored
        $this->postSignedWebhook($succeededEvent)->assertNoContent();

        // 3. Out-of-order delayed processing event with a new evt_id never regresses terminal status
        $this->postSignedWebhook([
            'id' => 'evt_proc_late',
            'object' => 'event',
            'type' => 'generation.processing',
            'created_at' => '2026-11-21T10:00:15Z',
            'data' => [
                'object' => [
                    'id' => 'gen_01ABC',
                    'object' => 'generation',
                    'status' => 'processing',
                    'metadata' => ['local_generation_id' => $generation->uuid],
                ],
            ],
        ])->assertNoContent();

        $generation->refresh();
        $user->refresh();

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(54_050_000, $generation->actual_provider_cost_nanos);
        $this->assertSame(67_562_500, $generation->actual_user_cost_nanos);
        $this->assertSame('2026-11-21', $generation->completed_utc_date->toDateString());
        $this->assertSame($startingNanos - 67_562_500, $user->balance_nanos);

        // Exactly 2 ledger entries for this generation: hold (-200M) + settlement refund (+132.4375M)
        $this->assertSame(2, CreditLedgerEntry::where('generation_id', $generation->id)->count());
        $this->assertSame(-67_562_500, (int) CreditLedgerEntry::where('generation_id', $generation->id)->sum('amount_nanos'));
    }

    public function test_early_webhook_arriving_before_post_response_matches_by_metadata_and_does_not_regress(): void
    {
        $startingNanos = Money::fromDecimalString('1.00')->nanos;
        $user = User::factory()->create(['balance_nanos' => $startingNanos]);
        $generation = $this->createHeldGeneration($user, GenerationStatus::Submitting, providerId: null);

        // Webhook arrives BEFORE POST /v1/generations returns (provider_generation_id is still null locally)
        $this->postSignedWebhook([
            'id' => 'evt_early_succ',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'created_at' => '2026-11-21T10:01:00Z',
            'data' => [
                'object' => [
                    'id' => 'gen_01EARLY',
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'metadata' => ['local_generation_id' => $generation->uuid],
                    'usage' => [
                        'input_text_tokens' => 4,
                        'input_image_tokens' => 0,
                        'output_tokens' => 280,
                        'reasoning_tokens' => 0,
                        'total_tokens' => 284,
                    ],
                    'rates' => [
                        'input_text_tokens' => '5.00',
                        'input_image_tokens' => '10.00',
                        'output_tokens' => '40.00',
                        'reasoning_tokens' => '12.50',
                    ],
                    'completed_at' => '2026-11-21T10:01:00Z',
                ],
            ],
        ])->assertNoContent();

        // Delayed 202 Accepted response arrives afterwards with status = queued
        app(GenerationStateService::class)->applyProviderPayload([
            'id' => 'gen_01EARLY',
            'status' => 'queued',
            'created_at' => '2026-11-21T10:00:50Z',
        ], $generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('gen_01EARLY', $generation->provider_generation_id);
        $this->assertSame(11_220_000, $generation->actual_provider_cost_nanos);
        $this->assertSame(14_025_000, $generation->actual_user_cost_nanos);
    }

    public function test_failed_webhook_charges_zero_and_refunds_full_reserve(): void
    {
        $startingNanos = Money::fromDecimalString('1.00')->nanos;
        $user = User::factory()->create(['balance_nanos' => $startingNanos]);
        $generation = $this->createHeldGeneration($user);

        $this->postSignedWebhook([
            'id' => 'evt_fail_001',
            'object' => 'event',
            'type' => 'generation.failed',
            'created_at' => '2026-11-21T10:02:00Z',
            'data' => [
                'object' => [
                    'id' => 'gen_01ABC',
                    'object' => 'generation',
                    'status' => 'failed',
                    'error' => ['code' => 'internal_error', 'message' => 'Something went wrong. You were not charged.'],
                    'completed_at' => '2026-11-21T10:02:00Z',
                ],
            ],
        ])->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->actual_provider_cost_nanos);
        $this->assertSame(0, $generation->actual_user_cost_nanos);
        $this->assertSame($startingNanos, $user->fresh()->balance_nanos);
        $this->assertDatabaseHas('credit_ledger_entries', [
            'generation_id' => $generation->id,
            'type' => LedgerEntryType::GenerationFailureRefund->value,
        ]);
    }

    public function test_polling_reconciliation_command_settles_stuck_generations_idempotently(): void
    {
        $startingNanos = Money::fromDecimalString('1.00')->nanos;
        $user = User::factory()->create(['balance_nanos' => $startingNanos]);
        $generation = $this->createHeldGeneration($user, GenerationStatus::Processing, 'gen_01STUCK');

        // Backdate updated_at so reconciliation picks it up
        Generation::whereKey($generation->id)->update(['updated_at' => now()->subMinutes(2)]);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations/gen_01STUCK' => Http::response([
                'id' => 'gen_01STUCK',
                'object' => 'generation',
                'status' => 'succeeded',
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_01STUCK.png',
                'usage' => [
                    'input_text_tokens' => 4,
                    'input_image_tokens' => 0,
                    'output_tokens' => 280,
                    'reasoning_tokens' => 0,
                    'total_tokens' => 284,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'completed_at' => '2026-11-21T10:05:00Z',
            ], 200, ['X-RateLimit-Remaining' => '55']),
        ]);

        $this->artisan('pixelforge:reconcile-pending')->assertSuccessful();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame($startingNanos - 14_025_000, $user->fresh()->balance_nanos);
    }
}