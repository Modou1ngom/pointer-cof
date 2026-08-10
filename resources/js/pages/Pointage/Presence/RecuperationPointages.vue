<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { Calendar, Download, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Row = {
    id: number;
    user_id: number;
    date: string;
    employe: string;
    email: string;
    matricule: string;
    service: string;
    agence: string;
    type: string;
    type_label: string;
    ha_effective: string;
    ha_reelle: string;
    hd_effective: string;
    hd_reelle: string;
    total_effective: string;
    total_reelle: string;
    horodatage: string;
    qr_verified: boolean;
    statut: string;
    statut_label: string;
    auto_ferie: boolean;
    ferie_libelle: string | null;
};

const props = defineProps<{
    pointages: {
        data: Row[];
        links?: { url: string | null; label: string; active: boolean }[];
        meta?: { current_page: number; last_page: number; from: number | null; to: number | null; total: number };
        last_page?: number;
    };
    kpis: {
        total_lignes: number;
        employes: number;
        arrivees: number;
        departs: number;
        retards: number;
        ferie_auto: number;
    };
    periode_label: string;
    filters: {
        date_debut: string;
        date_fin: string;
        agence_id: number | null;
        q: string;
        type: string;
        statut: string;
    };
    agences: { id: number; nom: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Dashboard', href: '#' },
];

const searchQ = ref(props.filters.q);

watch(
    () => props.filters.q,
    (v) => {
        searchQ.value = v;
    },
);

const exportHref = computed(() => {
    const p = new URLSearchParams();
    p.set('date_debut', props.filters.date_debut);
    p.set('date_fin', props.filters.date_fin);
    if (props.filters.agence_id) {
        p.set('agence_id', String(props.filters.agence_id));
    }
    if (props.filters.q) {
        p.set('q', props.filters.q);
    }
    if (props.filters.type !== 'tous') {
        p.set('type', props.filters.type);
    }
    if (props.filters.statut !== 'tous') {
        p.set('statut', props.filters.statut);
    }
    return `/pointage/rh/presence/recuperation-pointages/export?${p.toString()}`;
});

function reload(overrides: Partial<typeof props.filters> = {}) {
    const f = { ...props.filters, ...overrides };
    router.get(
        '/pointage/rh/presence/recuperation-pointages',
        {
            date_debut: f.date_debut,
            date_fin: f.date_fin,
            agence_id: f.agence_id ?? 'tous',
            q: f.q,
            type: f.type,
            statut: f.statut,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function onSearchSubmit() {
    reload({ q: searchQ.value.trim() });
}

function statutBadgeClass(statut: string): string {
    if (statut === 'retard') {
        return 'border-[#FDBA74] bg-[#FFF4E6] text-[#C2410C]';
    }
    if (statut === 'ferie_auto') {
        return 'border-[#B5D4F4] bg-[#E6F1FB] text-[#0C447C]';
    }
    return 'border-[#BBF7D0] bg-[#EAF3DE] text-[#166534]';
}

function typeBadgeClass(_type: string): string {
    return 'border-[#B5D4F4] bg-[#E6F1FB] text-[#0C447C]';
}
</script>

<template>
    <PointageLayout title="Dashboard" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-[1400px] space-y-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-[#0C447C]">Dashboard</h1>
                    <p class="mt-1 text-sm text-[#888780]">{{ periode_label }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        href="/pointage/rh/tous-pointages"
                        class="inline-flex items-center justify-center rounded-md border border-[#e2e0d8] bg-white px-4 py-2 text-sm font-medium text-[#0C447C] shadow-sm hover:bg-[#FAFAF8]"
                    >
                        Synthèse journalière
                    </Link>
                    <a
                        :href="exportHref"
                        class="inline-flex items-center justify-center gap-2 rounded-md border border-[#185FA5] bg-white px-4 py-2 text-sm font-semibold text-[#185FA5] shadow-sm hover:bg-[#E6F1FB]"
                    >
                        <Download class="h-4 w-4" aria-hidden="true" />
                        Exporter CSV
                    </a>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 shadow-sm">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-[#888780]">Lignes pointage</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-[#0C447C]">{{ kpis.total_lignes }}</div>
                </div>
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 shadow-sm">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-[#888780]">Employés</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-[#0C447C]">{{ kpis.employes }}</div>
                </div>
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 shadow-sm">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-[#888780]">Arrivées</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-[#0C447C]">{{ kpis.arrivees }}</div>
                </div>
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 shadow-sm">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-[#888780]">Départs</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-[#0C447C]">{{ kpis.departs }}</div>
                </div>
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 shadow-sm">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-[#888780]">Retards</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-[#DC2626]">{{ kpis.retards }}</div>
                </div>
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 shadow-sm">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-[#888780]">Férié auto</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-[#185FA5]">{{ kpis.ferie_auto }}</div>
                </div>
            </div>

            <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-4 xl:flex-row xl:flex-wrap xl:items-end">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="inline-flex items-center gap-2 rounded-md border border-[#e2e0d8] bg-[#FAFAF8] px-3 py-2 text-sm">
                            <Calendar class="h-4 w-4 text-[#888780]" aria-hidden="true" />
                            <label class="sr-only" for="date-debut">Du</label>
                            <input
                                id="date-debut"
                                type="date"
                                class="border-0 bg-transparent p-0 text-sm tabular-nums text-[#0C447C] focus:outline-none"
                                :value="filters.date_debut"
                                @change="reload({ date_debut: ($event.target as HTMLInputElement).value })"
                            />
                        </div>
                        <span class="text-sm text-[#888780]">au</span>
                        <div class="inline-flex items-center gap-2 rounded-md border border-[#e2e0d8] bg-[#FAFAF8] px-3 py-2 text-sm">
                            <label class="sr-only" for="date-fin">Au</label>
                            <input
                                id="date-fin"
                                type="date"
                                class="border-0 bg-transparent p-0 text-sm tabular-nums text-[#0C447C] focus:outline-none"
                                :value="filters.date_fin"
                                @change="reload({ date_fin: ($event.target as HTMLInputElement).value })"
                            />
                        </div>
                    </div>

                    <form class="flex min-w-[200px] flex-1 items-center gap-2" @submit.prevent="onSearchSubmit">
                        <div class="relative flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#888780]" />
                            <input
                                v-model="searchQ"
                                type="search"
                                placeholder="Nom, matricule, e-mail…"
                                class="w-full rounded-md border border-[#e2e0d8] py-2 pl-9 pr-3 text-sm text-[#0C447C]"
                            />
                        </div>
                        <button
                            type="submit"
                            class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#144a84]"
                        >
                            Rechercher
                        </button>
                    </form>

                    <select
                        class="rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        :value="filters.agence_id ?? 'tous'"
                        @change="
                            reload({
                                agence_id:
                                    ($event.target as HTMLSelectElement).value === 'tous'
                                        ? null
                                        : Number(($event.target as HTMLSelectElement).value),
                            })
                        "
                    >
                        <option value="tous">Toutes les agences</option>
                        <option v-for="a in agences" :key="'ag-' + a.id" :value="a.id">{{ a.nom }}</option>
                    </select>

                    <select
                        class="rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        :value="filters.type"
                        @change="reload({ type: ($event.target as HTMLSelectElement).value })"
                    >
                        <option value="tous">Tous types</option>
                        <option value="arrivee">Arrivée</option>
                        <option value="depart">Départ</option>
                    </select>

                    <select
                        class="rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        :value="filters.statut"
                        @change="reload({ statut: ($event.target as HTMLSelectElement).value })"
                    >
                        <option value="tous">Tous statuts</option>
                        <option value="normal">Normal</option>
                        <option value="retard">Retard</option>
                        <option value="ferie_auto">Férié (auto)</option>
                    </select>
                </div>
            </div>

            <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1200px] text-sm">
                        <thead class="border-b border-[#e2e0d8] bg-[#FAFAF8] text-left text-[10px] font-bold uppercase tracking-wide text-[#888780]">
                            <tr>
                                <th class="px-3 py-3">Date</th>
                                <th class="px-3 py-3">Employé</th>
                                <th class="px-3 py-3">Matricule</th>
                                <th class="px-3 py-3">Service</th>
                                <th class="px-3 py-3">Agence</th>
                                <th class="px-3 py-3">Type</th>
                                <th class="px-3 py-3">H.A. effective</th>
                                <th class="px-3 py-3">H.A. réelle</th>
                                <th class="px-3 py-3">H.D. effective</th>
                                <th class="px-3 py-3">H.D. réelle</th>
                                <th class="px-3 py-3">Total H.eff.</th>
                                <th class="px-3 py-3">Total H.réelle</th>
                                <th class="px-3 py-3">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="p in pointages.data"
                                :key="`${p.user_id}-${p.date}`"
                                class="border-b border-[#F1EFE8] last:border-0 hover:bg-[#FAFAF8]/80"
                            >
                                <td class="px-3 py-2.5 tabular-nums text-[#0C447C]">{{ p.date }}</td>
                                <td class="px-3 py-2.5">
                                    <div class="font-semibold text-[#0C447C]">{{ p.employe }}</div>
                                    <div class="text-xs text-[#888780]">{{ p.email }}</div>
                                    <div v-if="p.auto_ferie && p.ferie_libelle" class="mt-0.5 text-[10px] text-[#185FA5]">
                                        {{ p.ferie_libelle }}
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 font-mono text-xs">{{ p.matricule }}</td>
                                <td class="px-3 py-2.5 text-[#0C447C]">{{ p.service }}</td>
                                <td class="px-3 py-2.5 text-[#0C447C]">{{ p.agence }}</td>
                                <td class="px-3 py-2.5">
                                    <span
                                        class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                                        :class="typeBadgeClass(p.type)"
                                    >
                                        {{ p.type_label }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 tabular-nums font-medium text-[#0C447C]">{{ p.ha_effective }}</td>
                                <td class="px-3 py-2.5 tabular-nums text-[#888780]">{{ p.ha_reelle }}</td>
                                <td class="px-3 py-2.5 tabular-nums font-medium text-[#0C447C]">{{ p.hd_effective }}</td>
                                <td class="px-3 py-2.5 tabular-nums text-[#888780]">{{ p.hd_reelle }}</td>
                                <td class="px-3 py-2.5 tabular-nums font-semibold text-[#0C447C]">{{ p.total_effective }}</td>
                                <td class="px-3 py-2.5 tabular-nums text-[#888780]">{{ p.total_reelle }}</td>
                                <td class="px-3 py-2.5">
                                    <span
                                        class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                                        :class="statutBadgeClass(p.statut)"
                                    >
                                        {{ p.statut_label }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!pointages.data?.length">
                                <td colspan="13" class="px-4 py-12 text-center text-[#888780]">
                                    Aucun pointage pour cette période et ces filtres.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="(pointages.meta?.last_page ?? pointages.last_page ?? 1) > 1 && pointages.links?.length"
                    class="flex flex-wrap justify-center gap-1 border-t border-[#e2e0d8] px-4 py-3"
                >
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
