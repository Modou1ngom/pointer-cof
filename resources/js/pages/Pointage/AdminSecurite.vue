<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Sécurité', href: '#' },
];

const lignes = [
    ['HTTPS / sessions', 'Fortify + cookies sécurisés'],
    ['QR dynamique', 'Jetons HMAC + expiration configurable'],
    ['Géofencing', 'Contrôle de distance site / position GPS'],
    ['Journalisation', 'Table pointage_audit_logs (signature métier à étendre)'],
    ['Anti-doublon', 'Blocage arrivée/départ déjà pointés pour la journée'],
];
</script>

<template>
    <PointageLayout title="Sécurité pointage" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-3xl space-y-6">
            <h1 class="text-xl font-semibold text-[#0C447C]">Sécurité & conformité</h1>
            <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white">
                <div v-for="(row, i) in lignes" :key="i" class="flex justify-between border-b border-[#F1EFE8] px-4 py-3 text-sm last:border-0">
                    <span class="font-medium">{{ row[0] }}</span>
                    <span class="max-w-xs text-right text-[#888780]">{{ row[1] }}</span>
                </div>
            </div>
        </div>
    </PointageLayout>
</template>
