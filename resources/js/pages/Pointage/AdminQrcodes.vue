<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';

interface Agence {
    id: number;
    nom: string;
    pointage_qr_type?: string;
    actif: boolean;
}

defineProps<{
    agences: Agence[];
    qrPreview: Record<string, { token: string; expires_at: string }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'QR Codes', href: '#' },
];
</script>

<template>
    <PointageLayout title="QR Codes sites" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6">
            <h1 class="text-xl font-semibold text-[#0C447C]">QR Codes par site</h1>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="a in agences"
                    :key="a.id"
                    class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 text-center shadow-sm"
                    :class="{ 'opacity-50': !a.actif }"
                >
                    <div class="font-semibold">{{ a.nom }}</div>
                    <div class="mt-3 inline-flex h-24 w-24 items-center justify-center rounded-lg bg-[#F1EFE8] text-3xl">▦</div>
                    <div class="mt-2 text-[11px] text-[#888780]">{{ a.pointage_qr_type ?? 'dynamic' }}</div>
                    <div v-if="qrPreview[String(a.id)]" class="mt-2 break-all font-mono text-[10px] text-[#185FA5]">
                        {{ qrPreview[String(a.id)].token.slice(0, 48) }}…
                    </div>
                </div>
            </div>
        </div>
    </PointageLayout>
</template>
