<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ReportController extends Controller
{
    public function index(Request $request, PixelforgeClient $client): Response
    {
        $todayUtc = Carbon::now('UTC')->startOfDay();
        $defaultStart = $todayUtc->copy()->subDays(29);

        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $start = ! empty($validated['start_date'])
            ? Carbon::createFromFormat('Y-m-d', $validated['start_date'], 'UTC')->startOfDay()
            : $defaultStart;

        $end = ! empty($validated['end_date'])
            ? Carbon::createFromFormat('Y-m-d', $validated['end_date'], 'UTC')->startOfDay()
            : $todayUtc;

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        // Enforce Pixelforge GET /v1/usage maximum 92-day span (inclusive)
        if ($start->diffInDays($end) >= 92) {
            $start = $end->copy()->subDays(91);
        }

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        // Pull all succeeded & failed generations billed/completed in this UTC date range.
        // Note: Pixelforge GET /v1/usage attributes a generation to the UTC day it COMPLETED.
        $generations = Generation::query()
            ->with('user:id,name,email,balance_nanos')
            ->whereIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->whereBetween('completed_utc_date', [$startDate, $endDate])
            ->get();

        // Initialize every UTC day in range with zeros so rows align 1:1 with GET /v1/usage.
        $dailyMap = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $key = $day->toDateString();
            $dailyMap[$key] = [
                'date' => $key,
                'succeeded_count' => 0,
                'failed_count' => 0,
                'input_text_tokens' => 0,
                'input_image_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'credit_spend_nanos' => 0,
                'provider_cost_nanos' => 0,
                'profit_nanos' => 0,
            ];
        }

        $userMap = [];
        $totalSucceeded = 0;
        $totalFailed = 0;
        $totalCreditSpendNanos = 0;
        $totalProviderCostNanos = 0;

        foreach ($generations as $gen) {
            $dayKey = $gen->completed_utc_date?->toDateString();
            $userId = $gen->user_id;

            if (! isset($userMap[$userId])) {
                $userMap[$userId] = [
                    'user_id' => $userId,
                    'name' => $gen->user?->name ?? 'Unknown',
                    'email' => $gen->user?->email ?? '',
                    'balance_nanos' => $gen->user?->balance_nanos ?? 0,
                    'succeeded_count' => 0,
                    'failed_count' => 0,
                    'credit_spend_nanos' => 0,
                    'provider_cost_nanos' => 0,
                    'profit_nanos' => 0,
                ];
            }

            if ($gen->status === GenerationStatus::Failed) {
                $totalFailed++;
                $userMap[$userId]['failed_count']++;
                if ($dayKey && isset($dailyMap[$dayKey])) {
                    $dailyMap[$dayKey]['failed_count']++;
                }

                continue;
            }

            $providerNanos = (int) ($gen->actual_provider_cost_nanos ?? 0);
            $userNanos = (int) ($gen->actual_user_cost_nanos ?? 0);
            $profitNanos = $userNanos - $providerNanos;
            $usage = $gen->provider_usage ?? [];

            $totalSucceeded++;
            $totalCreditSpendNanos += $userNanos;
            $totalProviderCostNanos += $providerNanos;

            $userMap[$userId]['succeeded_count']++;
            $userMap[$userId]['credit_spend_nanos'] += $userNanos;
            $userMap[$userId]['provider_cost_nanos'] += $providerNanos;
            $userMap[$userId]['profit_nanos'] += $profitNanos;

            if ($dayKey && isset($dailyMap[$dayKey])) {
                $dailyMap[$dayKey]['succeeded_count']++;
                $dailyMap[$dayKey]['input_text_tokens'] += (int) ($usage['input_text_tokens'] ?? 0);
                $dailyMap[$dayKey]['input_image_tokens'] += (int) ($usage['input_image_tokens'] ?? 0);
                $dailyMap[$dayKey]['output_tokens'] += (int) ($usage['output_tokens'] ?? 0);
                $dailyMap[$dayKey]['reasoning_tokens'] += (int) ($usage['reasoning_tokens'] ?? 0);
                $dailyMap[$dayKey]['credit_spend_nanos'] += $userNanos;
                $dailyMap[$dayKey]['provider_cost_nanos'] += $providerNanos;
                $dailyMap[$dayKey]['profit_nanos'] += $profitNanos;
            }
        }

        // Also include users with 0 generations in this window so operators see all accounts.
        $allUsers = User::query()->select(['id', 'name', 'email', 'balance_nanos'])->orderBy('id')->get();
        foreach ($allUsers as $u) {
            if (! isset($userMap[$u->id])) {
                $userMap[$u->id] = [
                    'user_id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'balance_nanos' => $u->balance_nanos,
                    'succeeded_count' => 0,
                    'failed_count' => 0,
                    'credit_spend_nanos' => 0,
                    'provider_cost_nanos' => 0,
                    'profit_nanos' => 0,
                ];
            }
        }

        // Fetch live GET /v1/usage reconciliation from Pixelforge
        $pixelforgeByDay = [];
        $pixelforgeTotalNanos = null;
        $pixelforgeTotalGenerations = null;
        $reconciliationError = null;

        try {
            $usageReport = $client->getUsage($startDate, $endDate);

            foreach ($usageReport['data'] ?? [] as $row) {
                $date = (string) ($row['date'] ?? '');
                if ($date !== '') {
                    $pixelforgeByDay[$date] = [
                        'generations' => (int) ($row['generations'] ?? 0),
                        'cost_usd' => (string) ($row['cost_usd'] ?? '0.000000000'),
                        'cost_nanos' => Money::fromDecimalString((string) ($row['cost_usd'] ?? '0.000000000'))->nanos,
                    ];
                }
            }

            if (isset($usageReport['total']['cost_usd'])) {
                $pixelforgeTotalNanos = Money::fromDecimalString((string) $usageReport['total']['cost_usd'])->nanos;
                $pixelforgeTotalGenerations = (int) ($usageReport['total']['generations'] ?? 0);
            }
        } catch (Throwable $e) {
            $reconciliationError = $e->getMessage();
        }

        $allDaysMatch = $reconciliationError === null && $pixelforgeTotalNanos !== null;

        $dailyRows = [];
        foreach (array_reverse(array_values($dailyMap)) as $row) {
            $pf = $pixelforgeByDay[$row['date']] ?? null;
            $deltaNanos = $pf !== null ? ($row['provider_cost_nanos'] - $pf['cost_nanos']) : null;
            $matches = $pf !== null && $deltaNanos === 0 && $row['succeeded_count'] === $pf['generations'];

            if ($pf !== null && ! $matches) {
                $allDaysMatch = false;
            }

            $dailyRows[] = [
                ...$row,
                'credit_spend_usd' => Money::fromNanodollars($row['credit_spend_nanos'])->toExactUsdString(),
                'provider_cost_usd' => Money::fromNanodollars($row['provider_cost_nanos'])->toExactUsdString(),
                'profit_usd' => Money::fromNanodollars($row['profit_nanos'])->toExactUsdString(),
                'pixelforge_generations' => $pf['generations'] ?? null,
                'pixelforge_cost_usd' => $pf['cost_usd'] ?? null,
                'delta_nanos' => $deltaNanos,
                'delta_usd' => $deltaNanos !== null ? Money::fromNanodollars($deltaNanos)->toExactUsdString() : null,
                'reconciled' => $matches,
            ];
        }

        usort($userMap, static fn(array $a, array $b): int => $b['credit_spend_nanos'] <=> $a['credit_spend_nanos']);

        $userRows = array_map(static fn(array $row): array => [
            ...$row,
            'balance_display' => Money::fromNanodollars($row['balance_nanos'])->toDisplayUsd(2, 6),
            'credit_spend_usd' => Money::fromNanodollars($row['credit_spend_nanos'])->toExactUsdString(),
            'provider_cost_usd' => Money::fromNanodollars($row['provider_cost_nanos'])->toExactUsdString(),
            'profit_usd' => Money::fromNanodollars($row['profit_nanos'])->toExactUsdString(),
        ], $userMap);

        $totalProfitNanos = $totalCreditSpendNanos - $totalProviderCostNanos;
        $totalDeltaNanos = $pixelforgeTotalNanos !== null ? ($totalProviderCostNanos - $pixelforgeTotalNanos) : null;

        return Inertia::render('admin/Report', [
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'totals' => [
                'succeeded_count' => $totalSucceeded,
                'failed_count' => $totalFailed,
                'credit_spend_usd' => Money::fromNanodollars($totalCreditSpendNanos)->toExactUsdString(),
                'credit_spend_display' => Money::fromNanodollars($totalCreditSpendNanos)->toDisplayUsd(2, 6),
                'provider_cost_usd' => Money::fromNanodollars($totalProviderCostNanos)->toExactUsdString(),
                'provider_cost_display' => Money::fromNanodollars($totalProviderCostNanos)->toDisplayUsd(2, 6),
                'profit_usd' => Money::fromNanodollars($totalProfitNanos)->toExactUsdString(),
                'profit_display' => Money::fromNanodollars($totalProfitNanos)->toDisplayUsd(2, 6),
            ],
            'reconciliation' => [
                'available' => $reconciliationError === null && $pixelforgeTotalNanos !== null,
                'error' => $reconciliationError,
                'is_exact_match' => $allDaysMatch && $totalDeltaNanos === 0 && $pixelforgeTotalGenerations === $totalSucceeded,
                'pixelforge_generations' => $pixelforgeTotalGenerations,
                'pixelforge_total_usd' => $pixelforgeTotalNanos !== null
                    ? Money::fromNanodollars($pixelforgeTotalNanos)->toExactUsdString()
                    : null,
                'delta_usd' => $totalDeltaNanos !== null
                    ? Money::fromNanodollars($totalDeltaNanos)->toExactUsdString()
                    : null,
            ],
            'daily' => $dailyRows,
            'users' => $userRows,
        ]);
    }
}
