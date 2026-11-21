import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface FlashMessages {
    success: string | null;
    error: string | null;
}

export interface SharedData {
    [key: string]: unknown;
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash?: FlashMessages;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    is_admin: boolean;
    balance_nanos: number;
    balance_display: string;
    balance_exact: string;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type ImageSize = '1024x1024' | '1536x1024' | '1024x1536';
export type ImageQuality = 'low' | 'medium' | 'high';
export type GenerationStatus = 'submitting' | 'queued' | 'processing' | 'succeeded' | 'failed';
export interface PricingRates {
    input_text_tokens: string;
    input_image_tokens: string;
    output_tokens: string;
    reasoning_tokens: string;
}
export interface PricingConfig {
    object: string;
    currency: string;
    unit: string;
    rates: PricingRates;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: number;
        per_reference_image: number;
        output: Record<ImageQuality, Record<ImageSize, number>>;
        reasoning: Record<ImageQuality, { min: number; max: number }>;
    };
}

export interface TokenUsage {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens: number;
}

export interface GenerationItem {
    uuid: string;
    provider_generation_id: string | null;
    status: GenerationStatus;
    is_active: boolean;
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_images: string[];
    provider_rates: PricingRates | null;
    provider_usage: TokenUsage | null;
    estimated_min_user_cost_nanos: number;
    estimated_expected_user_cost_nanos: number;
    estimated_max_user_cost_nanos: number;
    reserved_user_cost_nanos: number;
    estimated_min_display: string;
    estimated_expected_display: string;
    estimated_max_display: string;
    reserved_display: string;
    actual_provider_cost_nanos: number | null;
    actual_provider_cost_exact: string | null;
    actual_user_cost_nanos: number | null;
    actual_user_cost_display: string | null;
    actual_user_cost_exact: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    image_url_expired: boolean;
    error_code: string | null;
    error_message: string | null;
    created_at: string | null;
    completed_at: string | null;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: PaginationLink[];
}

export interface CreditLedgerEntryItem {
    id: number;
    type: string;
    type_label: string;
    amount_nanos: number;
    amount_display: string;
    amount_exact: string;
    balance_after_nanos: number;
    balance_after_display: string;
    description: string;
    generation_uuid: string | null;
    created_at: string | null;
}

export interface AdminDailyRow {
    date: string;
    succeeded_count: number;
    failed_count: number;
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    credit_spend_nanos: number;
    provider_cost_nanos: number;
    profit_nanos: number;
    credit_spend_usd: string;
    provider_cost_usd: string;
    profit_usd: string;
    pixelforge_generations: number | null;
    pixelforge_cost_usd: string | null;
    delta_nanos: number | null;
    delta_usd: string | null;
    reconciled: boolean;
}

export interface AdminUserRow {
    user_id: number;
    name: string;
    email: string;
    balance_nanos: number;
    balance_display: string;
    succeeded_count: number;
    failed_count: number;
    credit_spend_nanos: number;
    provider_cost_nanos: number;
    profit_nanos: number;
    credit_spend_usd: string;
    provider_cost_usd: string;
    profit_usd: string;
}
export interface AdminReportTotals {
    succeeded_count: number;
    failed_count: number;
    credit_spend_usd: string;
    credit_spend_display: string;
    provider_cost_usd: string;
    provider_cost_display: string;
    profit_usd: string;
    profit_display: string;
}

export interface AdminReconciliation {
    available: boolean;
    error: string | null;
    is_exact_match: boolean;
    pixelforge_generations: number | null;
    pixelforge_total_usd: string | null;
    delta_usd: string | null;
}
