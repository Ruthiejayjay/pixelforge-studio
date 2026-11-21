<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportTest extends TestCase
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

    public function test_non_admin_users_receive_403_on_admin_report(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.report'))
            ->assertForbidden();
    }

    public function test_admin_report_groups_by_utc_completion_date_and_reconciles_against_usage_api(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);

        // Created late on Nov 1 UTC, completed early on Nov 2 UTC -> MUST be attributed to 2026-11-02!
        Generation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'idempotency_key' => 'genreq_1',
            'provider_generation_id' => 'gen_1',
            'status' => GenerationStatus::Succeeded,
            'prompt' => 'Cross-midnight generation',
            'size' => ImageSize::Square1024,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'provider_usage' => ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'total_tokens' => 6291],
            'estimated_min_user_cost_nanos' => 100_000_000,
            'estimated_expected_user_cost_nanos' => 200_000_000,
            'estimated_max_user_cost_nanos' => 300_000_000,
            'reserved_user_cost_nanos' => 300_000_000,
            'actual_provider_cost_nanos' => 169_080_000, // $0.169080000
            'actual_user_cost_nanos' => 211_350_000,     // $0.211350000 (+25%)
            'provider_created_at' => '2026-11-01 23:58:00',
            'provider_completed_at' => '2026-11-02 00:01:00',
            'completed_utc_date' => '2026-11-02',
        ]);

        // Failed generation on Nov 2 -> must contribute $0.000000000
        Generation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'idempotency_key' => 'genreq_2',
            'provider_generation_id' => 'gen_2',
            'status' => GenerationStatus::Failed,
            'prompt' => 'Failed generation',
            'size' => ImageSize::Square1024,
            'quality' => ImageQuality::Low,
            'reference_images' => [],
            'estimated_min_user_cost_nanos' => 10_000_000,
            'estimated_expected_user_cost_nanos' => 20_000_000,
            'estimated_max_user_cost_nanos' => 30_000_000,
            'reserved_user_cost_nanos' => 30_000_000,
            'actual_provider_cost_nanos' => 0,
            'actual_user_cost_nanos' => 0,
            'provider_completed_at' => '2026-11-02 01:00:00',
            'completed_utc_date' => '2026-11-02',
        ]);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-02',
                'data' => [
                    ['date' => '2026-11-01', 'generations' => 0, 'cost_usd' => '0.000000000'],
                    ['date' => '2026-11-02', 'generations' => 1, 'cost_usd' => '0.169080000'],
                ],
                'total' => ['generations' => 1, 'cost_usd' => '0.169080000'],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.report', ['start_date' => '2026-11-01', 'end_date' => '2026-11-02']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Report')
                ->where('totals.succeeded_count', 1)
                ->where('totals.failed_count', 1)
                ->where('totals.provider_cost_usd', '0.169080000')
                ->where('totals.credit_spend_usd', '0.211350000')
                ->where('totals.profit_usd', '0.042270000')
                ->where('reconciliation.is_exact_match', true)
                ->where('reconciliation.delta_usd', '0.000000000')
                ->where('daily.0.date', '2026-11-02')
                ->where('daily.0.succeeded_count', 1)
                ->where('daily.0.reconciled', true)
                ->where('daily.1.date', '2026-11-01')
                ->where('daily.1.succeeded_count', 0)
            );
    }
}