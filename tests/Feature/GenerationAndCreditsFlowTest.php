<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\SubmitGenerationToPixelforgeJob;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgePricingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerationAndCreditsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/v1/pricing' => Http::response(PixelforgePricingService::defaultPricing(), 200),
        ]);
    }

    public function test_new_user_registration_grants_welcome_credits_in_ledger(): void
    {
        $response = $this->post('/register', [
            'name' => 'New Creator',
            'email' => 'creator@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard'));

        $user = User::where('email', 'creator@example.com')->firstOrFail();
        $expectedNanos = (int) config('services.pixelforge.welcome_credits_nanos');

        $this->assertSame($expectedNanos, $user->balance_nanos);
        $this->assertDatabaseHas('credit_ledger_entries', [
            'user_id' => $user->id,
            'type' => LedgerEntryType::WelcomeBonus->value,
            'amount_nanos' => $expectedNanos,
            'balance_after_nanos' => $expectedNanos,
        ]);
    }

    public function test_submitting_generation_places_exact_max_reserve_and_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create(['balance_nanos' => Money::fromDecimalString('5.00')->nanos]);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [
                'https://example.com/ref-1.png',
                'https://example.com/ref-2.png',
            ],
        ]);

        $response->assertRedirect(route('dashboard'));

        $generation = Generation::firstOrFail();
        $this->assertSame(GenerationStatus::Submitting, $generation->status);
        $this->assertSame(467_343_750, $generation->estimated_max_user_cost_nanos);
        $this->assertSame(467_343_750, $generation->reserved_user_cost_nanos);
        $this->assertStringStartsWith('genreq_', $generation->idempotency_key);

        $user->refresh();
        $this->assertSame(5_000_000_000 - 467_343_750, $user->balance_nanos);

        $this->assertDatabaseHas('credit_ledger_entries', [
            'user_id' => $user->id,
            'generation_id' => $generation->id,
            'type' => LedgerEntryType::GenerationHold->value,
            'amount_nanos' => -467_343_750,
        ]);

        Queue::assertPushed(SubmitGenerationToPixelforgeJob::class, fn($job) => $job->generationId === $generation->id);
    }

    public function test_generation_is_blocked_when_user_cannot_afford_max_estimate(): void
    {
        Queue::fake();

        $user = User::factory()->create(['balance_nanos' => Money::fromDecimalString('0.10')->nanos]);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A dramatic mountain sunrise',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [],
        ]);

        $response->assertSessionHasErrors('balance');
        $this->assertDatabaseCount('generations', 0);
        $this->assertSame(100_000_000, $user->fresh()->balance_nanos);
        Queue::assertNothingPushed();
    }

    public function test_generation_request_validates_prompt_length_and_reference_urls(): void
    {
        $user = User::factory()->create(['balance_nanos' => Money::fromDecimalString('10.00')->nanos]);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => str_repeat('x', 4001),
            'size' => 'invalid',
            'quality' => 'ultra',
            'reference_images' => [
                'ftp://not-allowed.example/img.png',
                'https://example.com/2.png',
                'https://example.com/3.png',
                'https://example.com/4.png',
                'https://example.com/5.png',
            ],
        ]);

        $response->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images', 'reference_images.0']);
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_user_can_purchase_credits_and_ledger_sum_matches_balance(): void
    {
        $user = User::factory()->create(['balance_nanos' => 0]);

        $this->actingAs($user)
            ->post(route('credits.purchase'), ['amount_usd' => '12.50'])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('credits.purchase'), ['amount_usd' => '5.00'])
            ->assertRedirect();

        $user->refresh();
        $ledgerSum = (int) CreditLedgerEntry::where('user_id', $user->id)->sum('amount_nanos');

        $this->assertSame(17_500_000_000, $user->balance_nanos);
        $this->assertSame($user->balance_nanos, $ledgerSum);
    }
}
