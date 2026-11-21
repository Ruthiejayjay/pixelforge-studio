<script setup lang="ts">
import { Sparkles } from 'lucide-vue-next';

interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <div class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
        <Sparkles class="size-4" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate font-semibold leading-none">Pixelforge Studio</span>
        <span class="truncate text-xs text-muted-foreground">AI Image Generator</span>
    </div>
</template>