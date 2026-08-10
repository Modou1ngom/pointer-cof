<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Calendar, Check, Download, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    pointages: {
        data: {
            user_id: number;
            employe: string;
            email: string;
            matricule: string;
            service: string;
            site: string;
            arrivee: string;
            depart: string;
            heures: string;
            gps_ok: boolean;
            biometric_ok: boolean;
            statut: string;
            statut_label: string;
        }[];
        links?: { url: string | null; label: string; active: boolean }[];
        last_page?: number;
    };
    filtreDate: string;
    date_label: string;
    total_enregistrements: number;
    filters: { agence: string; statut: string };
    agences: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Tous les pointages', href: '#' },
];

const exportHref = computed(() => {
    const p = new URLSearchParams();
    p.set('date', props.filtreDate);
    p.set('agence', props.filters.agence);
    p.set('statut', props.filters.statut);
    return `/pointage/rh/tous-pointages/export?${p.toString()}`;
});

function changeDate(e: Event) {
    const v = (e.target as HTMLInputElement).value;
    router.get(
        '/pointage/rh/tous-pointages',
        { date: v, agence: props.filters.agence, statut: props.filters.statut },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function applyFilters(overrides: Partial<{ agence: string; statut: string }>) {
    router.get(
        '/pointage/rh/tous-pointages',
        {
            date: props.filtreDate,
            agence: overrides.agence ?? props.filters.agence,
            statut: overrides.statut ?? props.filters.statut,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function statutBadgeClass(statut: string): string {
    if (statut === 'absent') {
        return 'border-[#FECACA] bg-[#FEF2F2] text-[#B91C1C]';
    }
    if (statut === 'retard') {
        return 'border-[#FDBA74] bg-[#FFF4E6] text-[#C2410C]';
    }
    return 'border-[#BBF7D0] bg-[#EAF3DE] text-[#166534]';
}
</script>

<template>
    <PointageLayout title="Tous les pointages" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="text-xl font-semibold text-[#0C447C]">Tous les pointages</h1>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="inline-flex items-center gap-2 rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C] shadow-sm">
                        <Calendar class="h-4 w-4 shrink-0 text-[#888780]" aria-hidden="true" />
                        <input
                            :value="filtreDate"
                            type="date"
                            class="border-0 bg-transparent p-0 text-sm tabular-nums text-[#0C447C] focus:outline-none focus:ring-0"
                            @change="changeDate"
                        />
                    </div>
                    <a
                        :href="exportHref"
                        class="inline-flex items-center justify-center gap-2 rounded-md border border-[#185FA5] bg-white px-4 py-2 text-sm font-semibold text-[#185FA5] shadow-sm hover:bg-[#E6F1FB]"
                    >
                        <Download class="h-4 w-4" aria-hidden="true" />
                        Exporter
                    </a>
                </div>
            </div>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-3">
                    <p class="text-sm font-medium text-[#0C447C]">Pointages — {{ date_label }}</p>
                    <span class="inline-flex rounded-full border border-[#e2e0d8] bg-[#FAFAF8] px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-[#0C447C]">
                        {{ total_enregistrements }} enregistrement{{ total_enregistrements > 1 ? 's' : '' }}
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="sr-only" for="filtre-agence-tp">Agence</label>
                    <select
                        id="filtre-agence-tp"
                        class="rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        :value="filters.agence"
                        @change="applyFilters({ agence: ($event.target as HTMLSelectElement).value })"
                    >
                        <option value="tous">Toutes les agences</option>
                        <option v-for="s in agences" :key="'tp-agence-' + s" :value="s">{{ s }}</option>
                    </select>
                    <label class="sr-only" for="filtre-statut-tp">Statut</label>
                    <select
                        id="filtre-statut-tp"
                        class="rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        :value="filters.statut"
                        @change="applyFilters({ statut: ($event.target as HTMLSelectElement).value })"
                    >
                        <option value="tous">Tous statuts</option>
                        <option value="normal">Normal</option>
                        <option value="retard">Retard</option>
                        <option value="absent">Absent</option>
                        <option value="justifie">Justifié (déclaration validée)</option>
                    </select>
                </div>
            </div>

            <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-sm">
                        <thead class="border-b border-[#e2e0d8] bg-[#FAFAF8] text-left text-[10px] font-bold uppercase tracking-wide text-[#888780]">
                            <tr>
                                <th class="px-4 py-3">Employé</th>
                                <th class="px-4 py-3">Matricule</th>
                                <th class="px-4 py-3">Service</th>
                                <th class="px-4 py-3">Arrivée</th>
                                <th class="px-4 py-3">Départ</th>
                                <th class="px-4 py-3">Heures</th>
                                <th class="px-4 py-3 text-center">GPS</th>
                                <th class="px-4 py-3 text-center">Biom.</th>
                                <th class="px-4 py-3">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in pointages.data" :key="`${p.user_id}-${filtreDate}`" class="border-b border-[#F1EFE8] last:border-0 hover:bg-[#FAFAF8]/80">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-[#0C447C]">{{ p.employe }}</div>
                                    <div class="mt-0.5 text-xs text-[#888780]">{{ p.email }}</div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-[#0C447C]">{{ p.matricule }}</td>
                                <td class="px-4 py-3 text-[#0C447C]">{{ p.service }}</td>
                                <td class="px-4 py-3 tabular-nums text-[#0C447C]">{{ p.arrivee }}</td>
                                <td class="px-4 py-3 tabular-nums text-[#0C447C]">{{ p.depart }}</td>
                                <td class="px-4 py-3 tabular-nums text-[#0C447C]">{{ p.heures }}</td>
                                <td class="px-4 py-3 text-center">
                                    <Check v-if="p.gps_ok" class="mx-auto inline h-5 w-5 text-[#3B6D11]" aria-label="GPS OK" />
                                    <X v-else class="mx-auto inline h-5 w-5 text-[#DC2626]" aria-label="GPS KO" />
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <Check v-if="p.biometric_ok" class="mx-auto inline h-5 w-5 text-[#3B6D11]" aria-label="Biométrie OK" />
                                    <X v-else class="mx-auto inline h-5 w-5 text-[#DC2626]" aria-label="Biométrie KO" />
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full border px-2.5 py-0.5 text-[11px] font-semibold" :class="statutBadgeClass(p.statut)">
                                        {{ p.statut_label }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!pointages.data?.length">
                                <td colspan="9" class="px-4 py-12 text-center text-[#888780]">Aucun enregistrement pour cette date et ces filtres.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="(pointages.last_page ?? 1) > 1 && pointages.links?.length" class="flex flex-wrap justify-center gap-1 border-t border-[#e2e0d8] px-4 py-3">
                    <template v-for="(link, i) in pointages.links ?? []" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="min-w-[2.25rem] rounded-md px-2 py-1 text-center text-xs"
                            :class="
                                link.active
                                    ? 'bg-[#185FA5] font-semibold text-white'
                                    : 'border border-[#e2e0d8] text-[#0C447C] hover:bg-[#FAFAF8]'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span
                            v-else
                            class="min-w-[2.25rem] cursor-not-allowed rounded-md px-2 py-1 text-center text-xs text-[#ccc]"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </PointageLayout>
</template>
