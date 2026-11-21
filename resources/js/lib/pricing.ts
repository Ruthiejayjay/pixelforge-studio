import type { ImageQuality, ImageSize, PricingConfig, PricingRates } from '@/types';

const NANOS_PER_USD = 1_000_000_000n;
const SCALE_4 = 10_000n;

export function countUnicodeChars(text: string): number {
    return [...text].length;
}

/**
 * Convert a USD-per-1M-tokens decimal string (e.g. "12.50") to exact nanodollars per token (BigInt).
 * $1.00 / 1,000,000 tokens = 1,000 nanodollars per token.
 */
function ratePerMillionToNanosPerToken(rateUsdPerMillion: string): bigint {
    const trimmed = rateUsdPerMillion.trim();
    const [wholePart = '0', fracPart = ''] = trimmed.split('.');
    const paddedFrac = fracPart.padEnd(3, '0').slice(0, 3);
    return BigInt(wholePart) * 1000n + BigInt(paddedFrac);
}

export function calculateProviderCostNanos(
    usage: {
        input_text_tokens: number;
        input_image_tokens: number;
        output_tokens: number;
        reasoning_tokens: number;
    },
    rates: PricingRates,
): bigint {
    return (
        BigInt(Math.max(0, usage.input_text_tokens)) * ratePerMillionToNanosPerToken(rates.input_text_tokens) +
        BigInt(Math.max(0, usage.input_image_tokens)) * ratePerMillionToNanosPerToken(rates.input_image_tokens) +
        BigInt(Math.max(0, usage.output_tokens)) * ratePerMillionToNanosPerToken(rates.output_tokens) +
        BigInt(Math.max(0, usage.reasoning_tokens)) * ratePerMillionToNanosPerToken(rates.reasoning_tokens)
    );
}

export function applyMarkupCeiling(providerNanos: bigint, markupPercent: number): bigint {
    const multiplier = BigInt(100 + markupPercent);
    const numerator = providerNanos * multiplier;
    const quotient = numerator / 100n;
    const remainder = numerator % 100n;

    if (providerNanos > 0n && remainder !== 0n) {
        return quotient + 1n;
    }

    return quotient;
}

export function formatNanosExact(nanos: bigint): string {
    const sign = nanos < 0n ? '-' : '';
    const abs = nanos < 0n ? -nanos : nanos;
    const dollars = abs / NANOS_PER_USD;
    const fraction = (abs % NANOS_PER_USD).toString().padStart(9, '0');
    return `${sign}${dollars}.${fraction}`;
}

export function formatNanosDisplay(nanos: bigint, minDecimals = 2, maxDecimals = 6): string {
    const sign = nanos < 0n ? '-' : '';
    const abs = nanos < 0n ? -nanos : nanos;
    const dollars = abs / NANOS_PER_USD;
    const fractionFull = (abs % NANOS_PER_USD).toString().padStart(9, '0');
    const trimmed = fractionFull.replace(/0+$/, '');

    let fraction: string;
    if (trimmed.length <= minDecimals) {
        fraction = trimmed.padEnd(minDecimals, '0');
    } else if (trimmed.length <= maxDecimals) {
        fraction = trimmed;
    } else {
        fraction = trimmed;
    }

    return `${sign}$${dollars}.${fraction}`;
}

export interface CostEstimate {
    tokens: {
        promptCharacters: number;
        inputTextMin: number;
        inputTextExpected: number;
        inputTextMax: number;
        inputImage: number;
        output: number;
        reasoningMin: number;
        reasoningExpected: number;
        reasoningMax: number;
    };
    providerCostNanos: {
        min: bigint;
        expected: bigint;
        max: bigint;
    };
    userCostNanos: {
        min: bigint;
        expected: bigint;
        max: bigint;
    };
}

export function estimateGenerationCost(
    prompt: string,
    size: ImageSize,
    quality: ImageQuality,
    referenceImageCount: number,
    pricing: PricingConfig,
    markupPercent = 25,
): CostEstimate {
    const promptCharacters = countUnicodeChars(prompt);
    const charsPerToken = Math.max(1, pricing.tokens.characters_per_text_token || 4);
    const baseTextTokens = promptCharacters > 0 ? Math.ceil(promptCharacters / charsPerToken) : 0;

    // Exact integer scaled variance (matches PHP bcmath 4-decimal scale)
    const varianceScaled = BigInt(Math.round((pricing.tokens.text_token_variance ?? 0.15) * 10_000));
    const baseBig = BigInt(baseTextTokens);
    const minTextTokens = Number((baseBig * (SCALE_4 - varianceScaled)) / SCALE_4);
    const maxNumerator = baseBig * (SCALE_4 + varianceScaled);
    const maxTextTokens = Number((maxNumerator + SCALE_4 - 1n) / SCALE_4);

    const inputImage = Math.max(0, referenceImageCount) * (pricing.tokens.per_reference_image || 765);
    const output = pricing.tokens.output[quality]?.[size] ?? 0;
    const reasoningMin = pricing.tokens.reasoning[quality]?.min ?? 0;
    const reasoningMax = pricing.tokens.reasoning[quality]?.max ?? 0;
    const reasoningExpected = Math.floor((reasoningMin + reasoningMax) / 2);

    const minProvider = calculateProviderCostNanos(
        {
            input_text_tokens: minTextTokens,
            input_image_tokens: inputImage,
            output_tokens: output,
            reasoning_tokens: reasoningMin,
        },
        pricing.rates,
    );

    const expectedProvider = calculateProviderCostNanos(
        {
            input_text_tokens: baseTextTokens,
            input_image_tokens: inputImage,
            output_tokens: output,
            reasoning_tokens: reasoningExpected,
        },
        pricing.rates,
    );

    const maxProvider = calculateProviderCostNanos(
        {
            input_text_tokens: maxTextTokens,
            input_image_tokens: inputImage,
            output_tokens: output,
            reasoning_tokens: reasoningMax,
        },
        pricing.rates,
    );

    return {
        tokens: {
            promptCharacters,
            inputTextMin: minTextTokens,
            inputTextExpected: baseTextTokens,
            inputTextMax: maxTextTokens,
            inputImage,
            output,
            reasoningMin,
            reasoningExpected,
            reasoningMax,
        },
        providerCostNanos: {
            min: minProvider,
            expected: expectedProvider,
            max: maxProvider,
        },
        userCostNanos: {
            min: applyMarkupCeiling(minProvider, markupPercent),
            expected: applyMarkupCeiling(expectedProvider, markupPercent),
            max: applyMarkupCeiling(maxProvider, markupPercent),
        },
    };
}