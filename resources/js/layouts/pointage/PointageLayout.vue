<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { computed } from 'vue';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
        title?: string;
    }>(),
    { breadcrumbs: () => [], title: '' },
);

const page = usePage();
const flash = computed(() => page.props.flash as { success?: string; error?: string } | undefined);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head v-if="title" :title="title" />

        <div class="min-h-[calc(100vh-8rem)] w-full bg-[#F1F5F9] px-3 pb-10 pt-2 text-slate-800 sm:px-4 md:-mx-2 md:px-4 lg:px-5">
            <div
                v-if="flash?.success"
                class="mb-4 rounded-lg border border-[#C0DD97] bg-[#EAF3DE] px-4 py-3 text-sm text-[#27500A]"
            >
                {{ flash.success }}
            </div>
            <div
                v-if="flash?.error"
                class="mb-4 rounded-lg border border-[#F7C1C1] bg-[#FCEBEB] px-4 py-3 text-sm text-[#791F1F]"
            >
                {{ flash.error }}
            </div>
            <slot />
        </div>
    </AppLayout>
</template>
