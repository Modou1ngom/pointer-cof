<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { router } from '@inertiajs/vue3';

interface Decl {
    id: number;
    type: string;
    type_label: string;
    date_concernee: string;
    date_concernee_display: string;
    date_fin?: string | null;
    date_fin_display?: string | null;
    heure_debut?: string | null;
    heure_fin?: string | null;
    lieu?: string | null;
    motif: string;
    statut?: string;
    statut_label?: string;
    has_justificatif?: boolean;
    user?: { name: string; email: string } | null;
    manager_user?: { name: string } | null;
}

defineProps<{
    pending: Decl[];
    history: Decl[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage', href: '/pointage' },
    { title: 'Validations RH', href: '#' },
];

function periode(d: Decl): string {
    if (d.date_fin_display) {
        return `${d.date_concernee_display} → ${d.date_fin_display}`;
    }
    let s = d.date_concernee_display || d.date_concernee;
    if (d.heure_debut && d.heure_fin) {
        s += ` (${d.heure_debut}–${d.heure_fin})`;
    }
    return s;
}

function decide(id: number, accept: boolean) {
    router.post(`/pointage/declarations/${id}/decision-rh`, { accept, comment: '' }, { preserveScroll: true });
}
</script>

<template>
    <PointageLayout title="Validations RH" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-8">
            <h1 class="text-xl font-semibold text-[#0C447C]">Déclarations à valider (RH)</h1>
            <p class="text-sm text-[#888780]">
                Après validation, les absences justifiées (congé, mission, formation…) ne sont plus considérées comme absences injustifiées.
            </p>

            <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white">
                <div class="border-b border-[#e2e0d8] px-4 py-3 text-sm font-semibold">En attente RH</div>
                <table class="w-full text-sm">
                    <thead class="bg-[#FAFAF8] text-left text-[10px] font-bold uppercase text-[#888780]">
                        <tr>
                            <th class="px-4 py-2">Employé</th>
                            <th class="px-4 py-2">Type</th>
                            <th class="px-4 py-2">Période</th>
                            <th class="px-4 py-2">Motif / Lieu</th>
                            <th class="px-4 py-2">N+1</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in pending" :key="d.id" class="border-t border-[#F1EFE8]">
                            <td class="px-4 py-2">{{ d.user?.name }}</td>
                            <td class="px-4 py-2">{{ d.type_label }}</td>
                            <td class="px-4 py-2">{{ periode(d) }}</td>
                            <td class="px-4 py-2">
                                <div>{{ d.motif }}</div>
                                <div v-if="d.lieu" class="text-xs text-[#888780]">{{ d.lieu }}</div>
                                <div v-if="d.has_justificatif" class="text-xs text-[#185FA5]">Justificatif joint</div>
                            </td>
                            <td class="px-4 py-2">{{ d.manager_user?.name || '—' }}</td>
                            <td class="space-x-2 px-4 py-2 whitespace-nowrap">
                                <button type="button" class="rounded bg-[#EAF3DE] px-2 py-1 text-xs text-[#3B6D11]" @click="decide(d.id, true)">
                                    Valider
                                </button>
                                <button type="button" class="rounded bg-[#FCEBEB] px-2 py-1 text-xs text-[#A32D2D]" @click="decide(d.id, false)">
                                    Rejeter
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!pending?.length">
                            <td colspan="6" class="px-4 py-8 text-center text-[#888780]">Rien en attente.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white">
                <div class="border-b border-[#e2e0d8] px-4 py-3 text-sm font-semibold">Historique récent</div>
                <table class="w-full text-sm">
                    <thead class="bg-[#FAFAF8] text-left text-[10px] font-bold uppercase text-[#888780]">
                        <tr>
                            <th class="px-4 py-2">Employé</th>
                            <th class="px-4 py-2">Type</th>
                            <th class="px-4 py-2">Période</th>
                            <th class="px-4 py-2">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in history" :key="'h-' + d.id" class="border-t border-[#F1EFE8]">
                            <td class="px-4 py-2">{{ d.user?.name }}</td>
                            <td class="px-4 py-2">{{ d.type_label }}</td>
                            <td class="px-4 py-2">{{ periode(d) }}</td>
                            <td class="px-4 py-2">{{ d.statut_label || d.statut }}</td>
                        </tr>
                        <tr v-if="!history?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-[#888780]">Aucun historique.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PointageLayout>
</template>
