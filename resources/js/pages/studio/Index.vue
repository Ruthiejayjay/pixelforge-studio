<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { estimateGenerationCost, formatNanosDisplay, formatNanosExact } from '@/lib/pricing';
import type { BreadcrumbItem, GenerationItem, ImageQuality, ImageSize, Paginated, PricingConfig, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import {
    AlertCircle,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Clock,
    ExternalLink,
    ImageOff,
    Loader2,
    Plus,
    RefreshCw,
    Sparkles,
    Trash2,
    Wallet,
} from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

interface Props {
    pricing: PricingConfig;
    markupPercent: number;
    generations: Paginated<GenerationItem>;
    hasActiveGenerations: boolean;
}

const props = defineProps<Props>();
const page = usePage<SharedData>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Studio', href: '/dashboard' }];

const user = computed(() => page.props.auth.user);
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

const form = useForm({
    prompt: '',
    size: '1024x1024' as ImageSize,
    quality: 'medium' as ImageQuality,
    reference_images: [] as string[],
});

const formErrors = computed(() => form.errors as Record<string, string>);
const balanceError = computed(() => formErrors.value.balance);

const sizes: { value: ImageSize; label: string; hint: string }[] = [
    { value: '1024x1024', label: '1024 × 1024', hint: 'Square' },
    { value: '1536x1024', label: '1536 × 1024', hint: 'Landscape' },
    { value: '1024x1536', label: '1024 × 1536', hint: 'Portrait' },
];

const qualities: { value: ImageQuality; label: string; desc: string }[] = [
    { value: 'low', label: 'Low', desc: 'Fastest & lowest cost' },
    { value: 'medium', label: 'Medium', desc: 'Balanced detail' },
    { value: 'high', label: 'High', desc: 'Maximum detail' },
];

const activeRefCount = computed(() => form.reference_images.filter((url) => url.trim().length > 0).length);

const estimate = computed(() =>
    estimateGenerationCost(form.prompt, form.size, form.quality, activeRefCount.value, props.pricing, props.markupPercent),
);

const userBalanceNanos = computed(() => BigInt(user.value?.balance_nanos ?? 0));
const canAfford = computed(() => userBalanceNanos.value >= estimate.value.userCostNanos.max);
const promptOverLimit = computed(() => estimate.value.tokens.promptCharacters > 4000);
const showEstimateBreakdown = ref(false);

const addReferenceImage = () => {
    if (form.reference_images.length < 4) {
        form.reference_images.push('');
    }
};

const removeReferenceImage = (index: number) => {
    form.reference_images.splice(index, 1);
};

const submit = () => {
    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt', 'reference_images');
        },
    });
};

// Automatically poll Inertia every 3 seconds ONLY while at least one generation is active.
const { start, stop } = usePoll(3000, { only: ['generations', 'hasActiveGenerations', 'auth'] }, { autoStart: false });

watch(
    () => props.hasActiveGenerations,
    (active) => {
        if (active) {
            start();
        } else {
            stop();
        }
    },
    { immediate: true },
);

const refreshingMap = reactive<Record<string, boolean>>({});
const brokenImages = reactive<Record<string, boolean>>({});
const expandedDetails = reactive<Record<string, boolean>>({});
const previewGeneration = ref<GenerationItem | null>(null);

const refreshGeneration = (uuid: string) => {
    refreshingMap[uuid] = true;
    router.post(
        route('generations.refresh', { generation: uuid }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                refreshingMap[uuid] = false;
            },
        },
    );
};

const statusBadge = (status: GenerationItem['status']) => {
    switch (status) {
        case 'submitting':
        case 'queued':
            return {
                label: 'Queued',
                classes: 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20',
            };
        case 'processing':
            return {
                label: 'Processing',
                classes: 'bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/20',
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                classes: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20',
            };
        case 'failed':
            return {
                label: 'Failed',
                classes: 'bg-red-500/10 text-red-700 dark:text-red-400 border-red-500/20',
            };
    }
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
    <Head title="Studio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Flash Alerts -->
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300"
            >
                <CheckCircle2 class="size-4 shrink-0" />
                <span>{{ flashSuccess }}</span>
            </div>

            <div
                v-if="flashError"
                class="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-800 dark:text-red-300"
            >
                <AlertCircle class="size-4 shrink-0" />
                <span>{{ flashError }}</span>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
                <!-- Left Column: Composer + Live Cost Estimator -->
                <div class="lg:col-span-5">
                    <Card class="sticky top-6">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Sparkles class="size-5 text-primary" />
                                Generate Image
                            </CardTitle>
                            <CardDescription>
                                Powered by Pixelforge. Costs reflect live provider rates plus a {{ markupPercent }}% platform fee.
                            </CardDescription>
                        </CardHeader>

                        <CardContent>
                            <form class="space-y-5" @submit.prevent="submit">
                                <!-- Prompt -->
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="prompt">Prompt</Label>
                                        <span
                                            class="font-mono text-xs"
                                            :class="promptOverLimit ? 'font-semibold text-destructive' : 'text-muted-foreground'"
                                        >
                                            {{ estimate.tokens.promptCharacters.toLocaleString() }} / 4,000 chars
                                        </span>
                                    </div>
                                    <textarea
                                        id="prompt"
                                        v-model="form.prompt"
                                        rows="4"
                                        placeholder="A lighthouse on a cliff at dawn, oil painting..."
                                        class="flex min-h-[104px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Tip: Test Pixelforge error handling with keywords like
                                        <code class="rounded bg-muted px-1">[succeed]</code>, <code class="rounded bg-muted px-1">[fail]</code>,
                                        <code class="rounded bg-muted px-1">[blocked]</code>, <code class="rounded bg-muted px-1">[slow]</code>, or
                                        <code class="rounded bg-muted px-1">[duplicate]</code>.
                                    </p>
                                    <InputError :message="form.errors.prompt" />
                                </div>

                                <!-- Size -->
                                <div class="space-y-2">
                                    <Label>Size</Label>
                                    <div class="grid grid-cols-3 gap-2">
                                        <button
                                            v-for="option in sizes"
                                            :key="option.value"
                                            type="button"
                                            class="flex flex-col items-center rounded-lg border px-2 py-2.5 text-center transition-colors"
                                            :class="
                                                form.size === option.value
                                                    ? 'border-primary bg-primary/5 text-primary ring-1 ring-primary'
                                                    : 'border-input hover:bg-accent'
                                            "
                                            @click="form.size = option.value"
                                        >
                                            <span class="font-mono text-xs font-semibold">{{ option.label }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ option.hint }}</span>
                                        </button>
                                    </div>
                                    <InputError :message="form.errors.size" />
                                </div>

                                <!-- Quality -->
                                <div class="space-y-2">
                                    <Label>Quality</Label>
                                    <div class="grid grid-cols-3 gap-2">
                                        <button
                                            v-for="option in qualities"
                                            :key="option.value"
                                            type="button"
                                            class="flex flex-col items-center rounded-lg border px-2 py-2.5 text-center transition-colors"
                                            :class="
                                                form.quality === option.value
                                                    ? 'border-primary bg-primary/5 text-primary ring-1 ring-primary'
                                                    : 'border-input hover:bg-accent'
                                            "
                                            @click="form.quality = option.value"
                                        >
                                            <span class="text-xs font-semibold">{{ option.label }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ option.desc }}</span>
                                        </button>
                                    </div>
                                    <InputError :message="form.errors.quality" />
                                </div>

                                <!-- Reference Image URLs -->
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label>Reference Image URLs <span class="font-normal text-muted-foreground">(optional, max 4)</span></Label>
                                        <Button
                                            v-if="form.reference_images.length < 4"
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="addReferenceImage"
                                        >
                                            <Plus class="size-3.5" />
                                            Add URL
                                        </Button>
                                    </div>

                                    <div v-if="form.reference_images.length > 0" class="space-y-2">
                                        <div v-for="(_, index) in form.reference_images" :key="index" class="space-y-1">
                                            <div class="flex items-center gap-2">
                                                <Input
                                                    v-model="form.reference_images[index]"
                                                    type="url"
                                                    placeholder="https://example.com/reference.png"
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Remove reference image"
                                                    @click="removeReferenceImage(index)"
                                                >
                                                    <Trash2 class="size-4 text-muted-foreground" />
                                                </Button>
                                            </div>
                                            <InputError :message="formErrors[`reference_images.${index}`]" />
                                        </div>
                                        <p class="text-xs text-muted-foreground">
                                            Each reference image adds {{ pricing.tokens.per_reference_image.toLocaleString() }} input image tokens.
                                        </p>
                                    </div>
                                    <InputError :message="form.errors.reference_images" />
                                </div>

                                <!-- Live Upfront Cost Estimate Box -->
                                <div class="rounded-lg border bg-muted/40 p-4">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground"> Estimated Cost </span>
                                        <span
                                            class="font-mono text-base font-semibold"
                                            :title="`Exact range: $${formatNanosExact(estimate.userCostNanos.min)} – $${formatNanosExact(estimate.userCostNanos.max)}`"
                                        >
                                            {{ formatNanosDisplay(estimate.userCostNanos.min) }} –
                                            {{ formatNanosDisplay(estimate.userCostNanos.max) }}
                                        </span>
                                    </div>

                                    <div class="mt-1.5 flex items-center justify-between text-xs text-muted-foreground">
                                        <span>Typical: ~{{ formatNanosDisplay(estimate.userCostNanos.expected) }}</span>
                                        <span>Max reserve: {{ formatNanosDisplay(estimate.userCostNanos.max) }}</span>
                                    </div>

                                    <p class="mt-2 text-xs text-muted-foreground">
                                        We temporarily reserve the maximum possible cost when you submit and immediately refund the unused difference
                                        once complete. Failed generations cost <strong>$0.00</strong>.
                                    </p>

                                    <button
                                        type="button"
                                        class="mt-2.5 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                        @click="showEstimateBreakdown = !showEstimateBreakdown"
                                    >
                                        <span>{{ showEstimateBreakdown ? 'Hide' : 'Show' }} token & rate breakdown</span>
                                        <ChevronUp v-if="showEstimateBreakdown" class="size-3.5" />
                                        <ChevronDown v-else class="size-3.5" />
                                    </button>

                                    <div
                                        v-if="showEstimateBreakdown"
                                        class="mt-3 space-y-1.5 border-t pt-2.5 font-mono text-xs text-muted-foreground"
                                    >
                                        <div class="flex justify-between">
                                            <span>Input text (±15%):</span>
                                            <span
                                                >{{ estimate.tokens.inputTextMin }}–{{ estimate.tokens.inputTextMax }} tok @ ${{
                                                    pricing.rates.input_text_tokens
                                                }}/1M</span
                                            >
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Input images ({{ activeRefCount }}):</span>
                                            <span
                                                >{{ estimate.tokens.inputImage.toLocaleString() }} tok @ ${{
                                                    pricing.rates.input_image_tokens
                                                }}/1M</span
                                            >
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Output ({{ form.quality }}, {{ form.size }}):</span>
                                            <span>{{ estimate.tokens.output.toLocaleString() }} tok @ ${{ pricing.rates.output_tokens }}/1M</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Reasoning ({{ form.quality }}):</span>
                                            <span
                                                >{{ estimate.tokens.reasoningMin.toLocaleString() }}–{{
                                                    estimate.tokens.reasoningMax.toLocaleString()
                                                }}
                                                tok @ ${{ pricing.rates.reasoning_tokens }}/1M</span
                                            >
                                        </div>
                                        <div class="flex justify-between border-t pt-1.5 font-semibold text-foreground">
                                            <span>Includes {{ markupPercent }}% markup:</span>
                                            <span
                                                >${{ formatNanosExact(estimate.userCostNanos.min) }}–${{
                                                    formatNanosExact(estimate.userCostNanos.max)
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                </div>

                                <!-- Insufficient Balance Banner -->
                                <div
                                    v-if="!canAfford || balanceError"
                                    class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
                                >
                                    <div class="flex items-start gap-2">
                                        <AlertCircle class="mt-0.5 size-4 shrink-0" />
                                        <div class="flex-1 space-y-2">
                                            <p>
                                                {{
                                                    balanceError ||
                                                    `You need at least ${formatNanosDisplay(estimate.userCostNanos.max)} available to cover the maximum cost (current balance: ${user.balance_display}).`
                                                }}
                                            </p>
                                            <Button as-child size="sm" variant="destructive">
                                                <Link :href="route('credits.index')">
                                                    <Wallet class="size-3.5" />
                                                    Buy Credits
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                </div>

                                <Button
                                    type="submit"
                                    class="w-full"
                                    size="lg"
                                    :disabled="form.processing || !canAfford || promptOverLimit || form.prompt.trim().length === 0"
                                >
                                    <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                                    <Sparkles v-else class="size-4" />
                                    <span>{{ form.processing ? 'Submitting...' : 'Generate Image' }}</span>
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                <!-- Right Column: Live Generation History -->
                <div class="space-y-4 lg:col-span-7">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight">Generation History</h2>
                            <p class="text-sm text-muted-foreground">
                                {{ generations.total }} total {{ generations.total === 1 ? 'generation' : 'generations' }}
                            </p>
                        </div>

                        <div
                            v-if="hasActiveGenerations"
                            class="inline-flex items-center gap-2 rounded-full border border-blue-500/25 bg-blue-500/10 px-3 py-1 text-xs font-medium text-blue-700 dark:text-blue-300"
                        >
                            <span class="size-2 animate-pulse rounded-full bg-blue-500" />
                            Live updates active
                        </div>
                    </div>

                    <Card v-if="generations.data.length === 0">
                        <CardContent class="flex flex-col items-center justify-center py-16 text-center">
                            <Sparkles class="mb-3 size-10 text-muted-foreground/50" />
                            <h3 class="font-medium">No generations yet</h3>
                            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                                Write a prompt on the left to create your first Pixelforge image and watch live status and exact billing updates here.
                            </p>
                        </CardContent>
                    </Card>

                    <div v-else class="space-y-4">
                        <Card v-for="gen in generations.data" :key="gen.uuid" class="overflow-hidden">
                            <CardContent class="p-4">
                                <div class="flex flex-col gap-4 sm:flex-row">
                                    <!-- Image Thumbnail / Status Placeholder -->
                                    <div class="relative shrink-0 overflow-hidden rounded-lg border bg-muted sm:h-36 sm:w-36">
                                        <button
                                            v-if="gen.status === 'succeeded' && gen.image_url && !gen.image_url_expired && !brokenImages[gen.uuid]"
                                            type="button"
                                            class="group relative block h-full w-full"
                                            @click="previewGeneration = gen"
                                        >
                                            <img
                                                :src="gen.image_url"
                                                :alt="gen.prompt"
                                                class="h-48 w-full object-cover transition-transform group-hover:scale-105 sm:h-36 sm:w-36"
                                                @error="brokenImages[gen.uuid] = true"
                                            />
                                        </button>

                                        <div
                                            v-else-if="gen.status === 'succeeded'"
                                            class="flex h-36 w-full flex-col items-center justify-center gap-1 p-3 text-center text-xs text-muted-foreground"
                                        >
                                            <ImageOff class="size-6 opacity-60" />
                                            <span>Image URL expired</span>
                                        </div>

                                        <div
                                            v-else-if="gen.status === 'failed'"
                                            class="flex h-36 w-full flex-col items-center justify-center gap-1 bg-red-500/5 p-3 text-center text-xs text-red-600 dark:text-red-400"
                                        >
                                            <AlertCircle class="size-6 opacity-80" />
                                            <span>Not generated</span>
                                        </div>

                                        <div
                                            v-else
                                            class="flex h-36 w-full flex-col items-center justify-center gap-2 p-3 text-center text-xs text-muted-foreground"
                                        >
                                            <Loader2 class="size-6 animate-spin text-primary" />
                                            <span>{{ gen.status === 'processing' ? 'Drawing...' : 'In queue...' }}</span>
                                        </div>
                                    </div>

                                    <!-- Details -->
                                    <div class="flex min-w-0 flex-1 flex-col justify-between gap-3">
                                        <div class="space-y-2">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span
                                                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                                        :class="statusBadge(gen.status).classes"
                                                    >
                                                        <Loader2 v-if="gen.is_active" class="size-3 animate-spin" />
                                                        <CheckCircle2 v-else-if="gen.status === 'succeeded'" class="size-3" />
                                                        <AlertCircle v-else class="size-3" />
                                                        {{ statusBadge(gen.status).label }}
                                                    </span>

                                                    <span class="rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground">
                                                        {{ gen.size }}
                                                    </span>
                                                    <span class="rounded bg-muted px-2 py-0.5 text-xs capitalize text-muted-foreground">
                                                        {{ gen.quality }}
                                                    </span>
                                                    <span
                                                        v-if="gen.reference_images.length > 0"
                                                        class="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                                                    >
                                                        {{ gen.reference_images.length }} ref {{ gen.reference_images.length === 1 ? 'img' : 'imgs' }}
                                                    </span>
                                                </div>

                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                        <Clock class="size-3" />
                                                        {{ formatDateTime(gen.created_at) }}
                                                    </span>
                                                    <Button
                                                        v-if="gen.is_active && gen.provider_generation_id"
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        class="h-7 px-2 text-xs"
                                                        :disabled="refreshingMap[gen.uuid]"
                                                        @click="refreshGeneration(gen.uuid)"
                                                    >
                                                        <RefreshCw class="size-3" :class="{ 'animate-spin': refreshingMap[gen.uuid] }" />
                                                        Poll
                                                    </Button>
                                                </div>
                                            </div>

                                            <p class="line-clamp-2 text-sm leading-relaxed">{{ gen.prompt }}</p>

                                            <!-- Error Message -->
                                            <div
                                                v-if="gen.status === 'failed'"
                                                class="rounded-md border border-red-500/25 bg-red-500/10 px-3 py-2 text-xs text-red-700 dark:text-red-300"
                                            >
                                                <span v-if="gen.error_code" class="mr-1.5 font-mono font-semibold">[{{ gen.error_code }}]</span>
                                                <span>{{ gen.error_message || 'Generation failed. You were not charged.' }}</span>
                                            </div>
                                        </div>

                                        <!-- Pricing Footer -->
                                        <div class="flex flex-wrap items-center justify-between gap-2 border-t pt-2.5 text-xs">
                                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                                <span class="text-muted-foreground">
                                                    Estimated:
                                                    <span class="font-mono text-foreground">
                                                        {{ gen.estimated_min_display }} – {{ gen.estimated_max_display }}
                                                    </span>
                                                </span>

                                                <span v-if="gen.status === 'succeeded'" class="text-muted-foreground">
                                                    Actual:
                                                    <span
                                                        class="font-mono font-semibold text-emerald-700 dark:text-emerald-400"
                                                        :title="`Exact billed: $${gen.actual_user_cost_exact}`"
                                                    >
                                                        {{ gen.actual_user_cost_display }}
                                                    </span>
                                                </span>

                                                <span v-else-if="gen.status === 'failed'" class="font-medium text-muted-foreground">
                                                    Actual: <span class="font-mono font-semibold text-foreground">$0.00</span> (refunded)
                                                </span>

                                                <span v-else class="text-muted-foreground">
                                                    Reserved: <span class="font-mono text-foreground">{{ gen.reserved_display }}</span>
                                                </span>
                                            </div>

                                            <button
                                                v-if="gen.provider_usage"
                                                type="button"
                                                class="font-medium text-primary hover:underline"
                                                @click="expandedDetails[gen.uuid] = !expandedDetails[gen.uuid]"
                                            >
                                                {{ expandedDetails[gen.uuid] ? 'Hide receipt' : 'View receipt' }}
                                            </button>
                                        </div>

                                        <!-- Exact Token & Cost Receipt -->
                                        <div
                                            v-if="expandedDetails[gen.uuid] && gen.provider_usage"
                                            class="rounded-md bg-muted/50 p-3 font-mono text-[11px] text-muted-foreground"
                                        >
                                            <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                                                <div>Text: {{ gen.provider_usage.input_text_tokens.toLocaleString() }}</div>
                                                <div>Images: {{ gen.provider_usage.input_image_tokens.toLocaleString() }}</div>
                                                <div>Output: {{ gen.provider_usage.output_tokens.toLocaleString() }}</div>
                                                <div>Reasoning: {{ gen.provider_usage.reasoning_tokens.toLocaleString() }}</div>
                                            </div>
                                            <div class="mt-2 flex flex-wrap justify-between gap-2 border-t pt-2 text-foreground">
                                                <span>Total tokens: {{ gen.provider_usage.total_tokens.toLocaleString() }}</span>
                                                <span>Exact user charge: ${{ gen.actual_user_cost_exact }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Pagination -->
                        <div v-if="generations.last_page > 1" class="flex flex-wrap items-center justify-center gap-1 pt-2">
                            <template v-for="(link, idx) in generations.links" :key="idx">
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
                    </div>
                </div>
            </div>
        </div>

        <!-- Full Image Preview Modal -->
        <Dialog
            :open="previewGeneration !== null"
            @update:open="
                (open) => {
                    if (!open) previewGeneration = null;
                }
            "
        >
            <DialogContent class="max-w-3xl">
                <DialogHeader>
                    <DialogTitle class="pr-6 text-base font-medium leading-snug">
                        {{ previewGeneration?.prompt }}
                    </DialogTitle>
                </DialogHeader>
                <div v-if="previewGeneration?.image_url" class="space-y-3">
                    <img
                        :src="previewGeneration.image_url"
                        :alt="previewGeneration.prompt"
                        class="max-h-[70vh] w-full rounded-lg border object-contain"
                    />
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span>
                            {{ previewGeneration.size }} · {{ previewGeneration.quality }} · Billed
                            <strong class="font-mono text-foreground">{{ previewGeneration.actual_user_cost_display }}</strong>
                        </span>
                        <a
                            :href="previewGeneration.image_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                        >
                            Open PNG
                            <ExternalLink class="size-3.5" />
                        </a>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
