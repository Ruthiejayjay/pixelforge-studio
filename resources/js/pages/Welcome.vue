<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, ShieldCheck, Sparkles, Wallet, Zap } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
</script>

<template>
    <Head title="Pixelforge Studio — AI Image Generation" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <!-- Top Navigation -->
        <header class="border-b bg-background/80 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
                <Link :href="route('home')" class="flex items-center gap-2.5">
                    <div class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                        <Sparkles class="size-4" />
                    </div>
                    <span class="text-sm font-semibold tracking-tight">Pixelforge Studio</span>
                </Link>

                <nav class="flex items-center gap-2">
                    <template v-if="isAuthenticated">
                        <Button as-child size="sm">
                            <Link :href="route('dashboard')">
                                Open Studio
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                    </template>
                    <template v-else>
                        <Button as-child variant="ghost" size="sm">
                            <Link :href="route('login')">Log in</Link>
                        </Button>
                        <Button as-child size="sm">
                            <Link :href="route('register')">Get Started</Link>
                        </Button>
                    </template>
                </nav>
            </div>
        </header>

        <!-- Hero -->
        <main class="flex-1">
            <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
                <div class="mx-auto max-w-3xl text-center">
                    <div
                        class="mb-6 inline-flex items-center gap-2 rounded-full border bg-muted/50 px-3.5 py-1 text-xs font-medium text-muted-foreground"
                    >
                        <Sparkles class="size-3.5 text-primary" />
                        <span>Powered by Pixelforge API · Exact nanodollar billing</span>
                    </div>

                    <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">AI image generation with transparent, exact token pricing.</h1>

                    <p class="mt-5 text-base leading-relaxed text-muted-foreground sm:text-lg">
                        Write a prompt, preview your exact cost range before submitting, and watch generations complete live. Unused credit reserves
                        are refunded automatically, and failed generations always cost <strong class="text-foreground">$0.00</strong>.
                    </p>

                    <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                        <Button v-if="isAuthenticated" as-child size="lg">
                            <Link :href="route('dashboard')">
                                Go to Studio
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <template v-else>
                            <Button as-child size="lg">
                                <Link :href="route('register')">
                                    Create Account ($2.00 Free Credits)
                                    <ArrowRight class="size-4" />
                                </Link>
                            </Button>
                            <Button as-child variant="outline" size="lg">
                                <Link :href="route('login')">Sign in</Link>
                            </Button>
                        </template>
                    </div>
                </div>

                <!-- Feature Highlights -->
                <div class="mt-16 grid grid-cols-1 gap-6 md:grid-cols-3">
                    <div class="rounded-xl border bg-card p-6 text-card-foreground shadow-sm">
                        <div class="mb-4 flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Wallet class="size-5" />
                        </div>
                        <h2 class="font-semibold">Upfront Cost Estimates</h2>
                        <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                            See the exact minimum, typical, and maximum price before clicking generate—including reference images and reasoning token
                            ranges.
                        </p>
                    </div>

                    <div class="rounded-xl border bg-card p-6 text-card-foreground shadow-sm">
                        <div class="mb-4 flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Zap class="size-5" />
                        </div>
                        <h2 class="font-semibold">Resilient Live Pipeline</h2>
                        <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                            Queued retries with <code class="rounded bg-muted px-1 text-xs">Idempotency-Key</code>, HMAC-verified webhooks, and
                            automatic polling keep every status accurate.
                        </p>
                    </div>

                    <div class="rounded-xl border bg-card p-6 text-card-foreground shadow-sm">
                        <div class="mb-4 flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <ShieldCheck class="size-5" />
                        </div>
                        <h2 class="font-semibold">Auditable Ledger & Reports</h2>
                        <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                            Every hold, settlement refund, and top-up is tracked to <code class="rounded bg-muted px-1 text-xs">$0.000000001</code>
                            and reconciled daily against Pixelforge usage records.
                        </p>
                    </div>
                </div>

                <!-- Transparent Billing Summary Bar -->
                <div class="mt-12 rounded-xl border bg-muted/30 p-6">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                        <div>
                            <h3 class="text-sm font-semibold">How billing works</h3>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Provider token cost + 25% markup · Credits equal US dollars · Simulated top-ups, no real charges.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-x-5 gap-y-2 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1.5">
                                <CheckCircle2 class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                $2.00 welcome credits
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <CheckCircle2 class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                $0 on failed generations
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <CheckCircle2 class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                Automatic reserve refunds
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="border-t py-6">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-4 text-xs text-muted-foreground sm:flex-row sm:px-6">
                <span>Pixelforge Studio · Built with Laravel, Vue 3, and Inertia</span>
                <a
                    href="https://pixelforge.promptedit.dev/docs"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="hover:text-foreground hover:underline"
                >
                    Pixelforge API Reference
                </a>
            </div>
        </footer>
    </div>
</template>
