<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        title: string;
        disabled?: boolean;
        variant?: 'default' | 'danger' | 'success';
        class?: HTMLAttributes['class'];
    }>(),
    {
        disabled: false,
        variant: 'default',
    },
);

const baseClass =
    'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#185FA5]/40 disabled:pointer-events-none disabled:opacity-40';

const variantClass: Record<'default' | 'danger' | 'success', string> = {
    default: 'text-[#0C447C] hover:bg-[#F1EFE8]',
    danger: 'text-[#A32D2D] hover:bg-[#FCEBEB]',
    success: 'text-[#3B6D11] hover:bg-[#EAF3DE]',
};
</script>

<template>
    <button
        type="button"
        :title="title"
        :aria-label="title"
        :disabled="disabled"
        :class="cn(baseClass, variantClass[variant], props.class)"
    >
        <slot />
    </button>
</template>
