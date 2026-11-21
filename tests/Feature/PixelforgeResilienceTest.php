<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\LedgerEntryType;
use App\Jobs\SubmitGenerationToPixelforgeJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedgerService;
use App\Services\Generations\GenerationStateService;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PixelforgeResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
            'services.pixelforge.api_key' => 'pf_test_key',
        ]);
    }

    private function createHeldGeneration(User $user, int $reserveNanos = 100_000_000, array $referenceImages = []): Generation
    {
        $uuid = (string) Str::uuid();
        $generation = Generation::create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'idempotency_key' => "genreq_{$uuid}",
            'status' => GenerationStatus::Submitting,
            'prompt' => 'A lighthouse at dawn',
            'size' => ImageSize::Square1024,
            'quality' => ImageQuality::Medium,
            'reference_images' => $referenceImages,
            'estimated_min_user_cost_nanos' => 50_000_000,
            'estimated_expected_user_cost_nanos' => 75_000_000,
            'estimated_max_user_cost_nanos' => $reserveNanos,
            'reserved_user_cost_nanos' => $reserveNanos,
        ]);

        app(CreditLedgerService::class)->placeHold($user, $generation, $reserveNanos);

        return $generation;
    }

    public function test_submission_sends_idempotency_key_and_metadata_and_transitions_to_queued(): void
    {
        $user = User::factory()->create(['balance_nanos' => Money::fromDecimalString('5.00')->nanos]);
        $generation = $this->createHeldGeneration($user);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_01TEST0001',
                'object' => 'generation',
                'status' => 'queued',
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'created_at' => '2026-11-21T10:00:00Z',
            ], 202),
        ]);

        $job = (new SubmitGenerationToPixelforgeJob($generation->id))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationStateService::class));

        Http::assertSent(function (HttpClientRequest $request) use ($generation): bool {
            return $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
                && $request['metadata']['local_generation_id'] === $generation->uuid
                && ! array_key_exists('reference_images', $request->data());
        });

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_01TEST0001', $generation->provider_generation_id);
        $this->assertSame('40.00', $generation->provider_rates['output_tokens']);
    }

    public function test_overloaded_503_and_concurrency_429_release_job_with_retry_after(): void
    {
        $user = User::factory()->create(['balance_nanos' => Money::fromDecimalString('5.00')->nanos]);
        $gen503 = $this->createHeldGeneration($user);
        $gen429 = $this->createHeldGeneration($user);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::sequence()
                ->push(['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Temporarily overloaded']], 503, ['Retry-After' => '3'])
                ->push(['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many concurrent']], 429, ['Retry-After' => '5']),
        ]);

        $job1 = (new SubmitGenerationToPixelforgeJob($gen503->id))->withFakeQueueInteractions();
        $job1->handle(app(PixelforgeClient::class), app(GenerationStateService::class));
        $job1->assertReleased(3);
        $this->assertSame(GenerationStatus::Submitting, $gen503->fresh()->status);

        $job2 = (new SubmitGenerationToPixelforgeJob($gen429->id))->withFakeQueueInteractions();
        $job2->handle(app(PixelforgeClient::class), app(GenerationStateService::class));
        $job2->assertReleased(5);
        $this->assertSame(GenerationStatus::Submitting, $gen429->fresh()->status);
    }

    public function test_content_policy_violation_400_fails_immediately_and_refunds_full_hold(): void
    {
        $startingNanos = Money::fromDecimalString('5.00')->nanos;
        $user = User::factory()->create(['balance_nanos' => $startingNanos]);
        $generation = $this->createHeldGeneration($user);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'The prompt was rejected. Nothing was created or billed.',
                ],
            ], 400),
        ]);

        $job = (new SubmitGenerationToPixelforgeJob($generation->id))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationStateService::class));
        $job->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(0, $generation->actual_provider_cost_nanos);
        $this->assertSame(0, $generation->actual_user_cost_nanos);
        $this->assertSame($startingNanos, $user->fresh()->balance_nanos);
        $this->assertDatabaseHas('credit_ledger_entries', [
            'generation_id' => $generation->id,
            'type' => LedgerEntryType::GenerationFailureRefund->value,
            'amount_nanos' => $generation->reserved_user_cost_nanos,
        ]);
    }

    public function test_exhausted_retries_mark_generation_failed_and_refund_full_hold(): void
    {
        $startingNanos = Money::fromDecimalString('5.00')->nanos;
        $user = User::factory()->create(['balance_nanos' => $startingNanos]);
        $generation = $this->createHeldGeneration($user);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Still overloaded'],
            ], 503, ['Retry-After' => '2']),
        ]);

        $job = (new SubmitGenerationToPixelforgeJob($generation->id))->withFakeQueueInteractions();
        $job->tries = 1; // Simulate final attempt exhausted
        $job->handle(app(PixelforgeClient::class), app(GenerationStateService::class));

        $job->assertNotReleased();
        $this->assertSame(GenerationStatus::Failed, $generation->fresh()->status);
        $this->assertSame(0, $generation->fresh()->actual_user_cost_nanos);
        $this->assertSame($startingNanos, $user->fresh()->balance_nanos);
    }
}