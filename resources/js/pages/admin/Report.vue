<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AdminDailyRow, AdminReconciliation, AdminReportTotals, AdminUserRow, BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, RefreshCw, ShieldCheck, TrendingUp } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    filters: {
        start_date: string;
        end_date: string;
    };
    totals: AdminReportTotals;
    reconciliation: AdminReconciliation;
    daily: AdminDailyRow[];
    users: AdminUserRow[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin Report', href: '/admin/report' }];

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const showZeroDays = ref(false);
const loading = ref(false);

const visibleDailyRows = computed(() => {
    if (showZeroDays.value) {
        return props.daily;
    }

    return props.daily.filter(
        (row) =>
            row.succeeded_count > 0 ||
            row.failed_count > 0 ||
            (row.pixelforge_generations !== null && row.pixelforge_generations > 0) ||
            (row.delta_nanos !== null && row.delta_nanos !== 0),
    );
});

const applyFilter = () => {
    loading.value = true;
    router.get(
        route('admin.report'),
        { start_date: startDate.value, end_date: endDate.value },
        {
            preserveState: true,
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

const applyPresetDays = (days: number) => {
    const end = new Date();
    const start = new Date(Date.UTC(end.getUTCFullYear(), end.getUTCMonth(), end.getUTCDate() - (days - 1)));
    startDate.value = start.toISOString().slice(0, 10);
    endDate.value = end.toISOString().slice(0, 10);
    applyFilter();
};
</script>

<template>
    <Head title="Admin Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header + UTC Date Filters -->
            <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Operator Billing & Usage Report</h1>
                    <p class="text-sm text-muted-foreground">
                        All daily figures are grouped by <strong>UTC completion date</strong> to match Pixelforge's <code>GET /v1/usage</code> source
                        of truth.
                    </p>
                </div>

                <form class="flex flex-wrap items-end gap-2" @submit.prevent="applyFilter">
                    <div class="space-y-1">
                        <Label for="start_date" class="text-xs">Start (UTC)</Label>
                        <Input id="start_date" v-model="startDate" type="date" class="h-9 w-40" />
                    </div>
                    <div class="space-y-1">
                        <Label for="end_date" class="text-xs">End (UTC)</Label>
                        <Input id="end_date" v-model="endDate" type="date" class="h-9 w-40" />
                    </div>
                    <Button type="submit" size="sm" class="h-9" :disabled="loading">
                        <RefreshCw class="size-3.5" :class="{ 'animate-spin': loading }" />
                        Apply & Reconcile
                    </Button>
                </form>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" @click="applyPresetDays(1)">Today (UTC)</Button>
                <Button type="button" variant="outline" size="sm" @click="applyPresetDays(7)">Last 7 days</Button>
                <Button type="button" variant="outline" size="sm" @click="applyPresetDays(30)">Last 30 days</Button>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Credit Spend (User Charged)</CardDescription>
                        <CardTitle class="font-mono text-2xl">{{ totals.credit_spend_display }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="font-mono text-xs text-muted-foreground">Exact: ${{ totals.credit_spend_usd }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Provider Cost (Local Records)</CardDescription>
                        <CardTitle class="font-mono text-2xl">{{ totals.provider_cost_display }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="font-mono text-xs text-muted-foreground">Exact: ${{ totals.provider_cost_usd }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription class="flex items-center gap-1">
                            <TrendingUp class="size-3.5 text-emerald-600" />
                            Gross Profit (25% Markup)
                        </CardDescription>
                        <CardTitle class="font-mono text-2xl text-emerald-700 dark:text-emerald-400">
                            {{ totals.profit_display }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="font-mono text-xs text-muted-foreground">
                            {{ totals.succeeded_count }} succeeded · {{ totals.failed_count }} failed ($0)
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription class="flex items-center gap-1">
                            <ShieldCheck class="size-3.5" />
                            Pixelforge /v1/usage Check
                        </CardDescription>
                        <CardTitle class="text-lg">
                            <span
                                v-if="reconciliation.available && reconciliation.is_exact_match"
                                class="inline-flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400"
                            >
                                <CheckCircle2 class="size-5" />
                                Exact Match
                            </span>
                            <span v-else-if="reconciliation.available" class="inline-flex items-center gap-1.5 text-amber-700 dark:text-amber-400">
                                <AlertCircle class="size-5" />
                                Delta: ${{ reconciliation.delta_usd }}
                            </span>
                            <span v-else class="inline-flex items-center gap-1.5 text-muted-foreground">
                                <AlertCircle class="size-5" />
                                Unavailable
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="reconciliation.available" class="font-mono text-xs text-muted-foreground">
                            API Total: ${{ reconciliation.pixelforge_total_usd }} ({{ reconciliation.pixelforge_generations }} gens)
                        </p>
                        <p v-else class="text-xs text-destructive">{{ reconciliation.error }}</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Daily (UTC) Breakdown & Reconciliation Table -->
            <Card>
                <CardHeader class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                    <div>
                        <CardTitle>Daily Breakdown (UTC)</CardTitle>
                        <CardDescription>
                            Side-by-side comparison of local provider costs against Pixelforge <code>GET /v1/usage</code> down to 9 decimal places.
                        </CardDescription>
                    </div>
                    <Button type="button" variant="outline" size="sm" @click="showZeroDays = !showZeroDays">
                        {{ showZeroDays ? 'Hide zero-activity days' : `Show all ${daily.length} days` }}
                    </Button>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th class="py-2.5 pr-4">Date (UTC)</th>
                                    <th class="py-2.5 pr-4 text-right">Succeeded / Failed</th>
                                    <th class="py-2.5 pr-4 text-right">Credit Spend</th>
                                    <th class="py-2.5 pr-4 text-right">Provider Cost (Local)</th>
                                    <th class="py-2.5 pr-4 text-right">Pixelforge /v1/usage</th>
                                    <th class="py-2.5 pr-4 text-right">Profit</th>
                                    <th class="py-2.5 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y font-mono text-xs">
                                <tr v-if="visibleDailyRows.length === 0">
                                    <td colspan="7" class="py-8 text-center font-sans text-sm text-muted-foreground">
                                        No activity in this date range. Click "Show all days" to view zero-usage rows.
                                    </td>
                                </tr>
                                <tr v-for="row in visibleDailyRows" :key="row.date">
                                    <td class="whitespace-nowrap py-3 pr-4 font-medium">{{ row.date }}</td>
                                    <td class="whitespace-nowrap py-3 pr-4 text-right">
                                        {{ row.succeeded_count }} / <span class="text-muted-foreground">{{ row.failed_count }}</span>
                                    </td>
                                    <td class="whitespace-nowrap py-3 pr-4 text-right">${{ row.credit_spend_usd }}</td>
                                    <td class="whitespace-nowrap py-3 pr-4 text-right">${{ row.provider_cost_usd }}</td>
                                    <td class="whitespace-nowrap py-3 pr-4 text-right">
                                        {{ row.pixelforge_cost_usd !== null ? `$${row.pixelforge_cost_usd}` : '—' }}
                                    </td>
                                    <td class="whitespace-nowrap py-3 pr-4 text-right text-emerald-700 dark:text-emerald-400">
                                        ${{ row.profit_usd }}
                                    </td>
                                    <td class="whitespace-nowrap py-3 text-right font-sans">
                                        <span
                                            v-if="row.reconciled"
                                            class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400"
                                        >
                                            <CheckCircle2 class="size-3" />
                                            Match
                                        </span>
                                        <span
                                            v-else-if="row.delta_usd !== null"
                                            class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 font-mono text-xs text-amber-700 dark:text-amber-400"
                                        >
                                            Δ ${{ row.delta_usd }}
                                        </span>
                                        <span v-else class="text-xs text-muted-foreground">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Per-User Breakdown Table -->
            <Card>
                <CardHeader>
                    <CardTitle>Per-User Breakdown</CardTitle>
                    <CardDescription
                        >Spend, provider cost, and gross profit by user for {{ filters.start_date }} to {{ filters.end_date }} (UTC).</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th class="py-2.5 pr-4">User</th>
                                    <th class="py-2.5 pr-4 text-right">Succeeded / Failed</th>
                                    <th class="py-2.5 pr-4 text-right">Credit Spend</th>
                                    <th class="py-2.5 pr-4 text-right">Provider Cost</th>
                                    <th class="py-2.5 pr-4 text-right">Profit</th>
                                    <th class="py-2.5 text-right">Current Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="u in users" :key="u.user_id">
                                    <td class="py-3 pr-4">
                                        <div class="font-medium">{{ u.name }}</div>
                                        <div class="text-xs text-muted-foreground">{{ u.email }}</div>
                                    </td>
                                    <td class="whitespace-nowrap py-3 pr-4 text-right font-mono text-xs">
                                        {{ u.succeeded_count }} / <span class="text-muted-foreground">{{ u.failed_count }}</span>
                                    </td>
                                    <td class="whitespace-nowrap py-3 pr-4 text-right font-mono text-xs">${{ u.credit_spend_usd }}</td>
                                    <td class="whitespace-nowrap py-3 pr-4 text-right font-mono text-xs">${{ u.provider_cost_usd }}</td>
                                    <td class="whitespace-nowrap py-3 pr-4 text-right font-mono text-xs text-emerald-700 dark:text-emerald-400">
                                        ${{ u.profit_usd }}
                                    </td>
                                    <td class="whitespace-nowrap py-3 text-right font-mono text-xs">{{ u.balance_display }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
