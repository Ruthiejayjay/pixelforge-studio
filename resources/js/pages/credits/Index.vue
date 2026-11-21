<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, CreditLedgerEntryItem, Paginated, SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Coins, Loader2, Wallet } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    summary: {
        balance_nanos: number;
        balance_display: string;
        balance_exact: string;
        total_spent_display: string;
        active_hold_display: string;
    };
    packs: string[];
    entries: Paginated<CreditLedgerEntryItem>;
}

defineProps<Props>();
const page = usePage<SharedData>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/credits' }];
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({
    amount_usd: '5.00',
});

const buyPack = (amount: string) => {
    form.amount_usd = amount;
    submitPurchase();
};

const submitPurchase = () => {
    form.post(route('credits.purchase'), {
        preserveScroll: true,
    });
};

const formatDateTime = (iso: string | null) => {
    if (!iso) return '';
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(new Date(iso));
};
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300"
            >
                <CheckCircle2 class="size-4 shrink-0" />
                <span>{{ flashSuccess }}</span>
            </div>

            <!-- Summary KPIs -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Available Balance</CardDescription>
                        <CardTitle class="font-mono text-2xl">{{ summary.balance_display }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="font-mono text-xs text-muted-foreground">Exact: ${{ summary.balance_exact }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>In-Flight Reserves</CardDescription>
                        <CardTitle class="font-mono text-2xl">{{ summary.active_hold_display }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-xs text-muted-foreground">Held for active generations; unused portion refunded automatically.</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Total Spent</CardDescription>
                        <CardTitle class="font-mono text-2xl">{{ summary.total_spent_display }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-xs text-muted-foreground">Succeeded generations only. Failed generations are free.</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Buy Credits Card -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Wallet class="size-5 text-primary" />
                        Buy Credits
                    </CardTitle>
                    <CardDescription> Simulated top-up for testing—no real payments are processed. Credits equal US dollars. </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-wrap gap-2">
                        <Button v-for="pack in packs" :key="pack" type="button" variant="outline" :disabled="form.processing" @click="buyPack(pack)">
                            <Coins class="size-4" />
                            +${{ pack }}
                        </Button>
                    </div>

                    <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="submitPurchase">
                        <div class="w-full max-w-xs space-y-1.5">
                            <Label for="amount_usd">Custom Amount (USD)</Label>
                            <Input id="amount_usd" v-model="form.amount_usd" type="text" inputmode="decimal" placeholder="10.00" />
                            <InputError :message="form.errors.amount_usd" />
                        </div>

                        <Button type="submit" :disabled="form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                            <span>Add Credits</span>
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <!-- Ledger Table -->
            <Card>
                <CardHeader>
                    <CardTitle>Credit Ledger</CardTitle>
                    <CardDescription>Immutable audit log of every credit top-up, reserve, settlement refund, and failure refund.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th class="py-2.5 pr-4">Time</th>
                                    <th class="py-2.5 pr-4">Type</th>
                                    <th class="py-2.5 pr-4">Description</th>
                                    <th class="py-2.5 pr-4 text-right">Amount</th>
                                    <th class="py-2.5 text-right">Balance After</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-if="entries.data.length === 0">
                                    <td colspan="5" class="py-8 text-center text-muted-foreground">No ledger entries yet.</td>
                                </tr>
                                <tr v-for="entry in entries.data" :key="entry.id">
                                    <td class="whitespace-nowrap py-3 pr-4 text-xs text-muted-foreground">
                                        {{ formatDateTime(entry.created_at) }}
                                    </td>
                                    <td class="whitespace-nowrap py-3 pr-4">
                                        <span class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium">
                                            {{ entry.type_label }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 text-xs text-muted-foreground">
                                        {{ entry.description }}
                                    </td>
                                    <td
                                        class="whitespace-nowrap py-3 pr-4 text-right font-mono text-xs font-semibold"
                                        :class="entry.amount_nanos >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'"
                                        :title="`Exact: $${entry.amount_exact}`"
                                    >
                                        {{ entry.amount_nanos >= 0 ? '+' : '' }}{{ entry.amount_display }}
                                    </td>
                                    <td class="whitespace-nowrap py-3 text-right font-mono text-xs">
                                        {{ entry.balance_after_display }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="entries.last_page > 1" class="mt-4 flex flex-wrap items-center justify-center gap-1">
                        <template v-for="(link, idx) in entries.links" :key="idx">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                class="rounded-md border px-3 py-1.5 text-xs transition-colors"
                                :class="link.active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent'"
                            >
                                {{ link.label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»') }}
                            </Link>
                            <span v-else class="rounded-md border border-transparent px-3 py-1.5 text-xs text-muted-foreground opacity-50">
                                {{ link.label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»') }}
                            </span>
                        </template>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
