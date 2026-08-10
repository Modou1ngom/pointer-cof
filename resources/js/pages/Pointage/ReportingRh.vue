<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Download, FileSpreadsheet, FileText } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

type Rapport = {
    id: string;
    title: string;
    description: string;
    period: 'date' | 'mois' | 'range' | 'range_user';
};

const props = defineProps<{
    defaults: {
        date: string;
        mois: string;
        date_debut: string;
        date_fin: string;
        agence_id: number | null;
        departement: string | null;
        user_id: number | null;
        format: string;
    };
    rapports: Rapport[];
    agences: { id: number; nom: string }[];
    departements: string[];
    collaborateurs: { id: number; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Reporting RH', href: '#' },
];

const selectedId = ref(props.rapports[0]?.id ?? 'quotidien');
const filters = reactive({
    date: props.defaults.date,
    mois: props.defaults.mois,
    date_debut: props.defaults.date_debut,
    date_fin: props.defaults.date_fin,
    agence_id: props.defaults.agence_id ? String(props.defaults.agence_id) : '',
    departement: props.defaults.departement ?? '',
    user_id: props.defaults.user_id ? String(props.defaults.user_id) : '',
});

const selected = computed(() => props.rapports.find((r) => r.id === selectedId.value) ?? props.rapports[0]);

function exportUrl(format: 'csv' | 'pdf'): string {
    const p = new URLSearchParams();
    p.set('type', selectedId.value);
    p.set('format', format);
    p.set('date', filters.date);
    p.set('mois', filters.mois);
    p.set('date_debut', filters.date_debut);
    p.set('date_fin', filters.date_fin);
    if (filters.agence_id) p.set('agence_id', filters.agence_id);
    if (filters.departement) p.set('departement', filters.departement);
    if (filters.user_id) p.set('user_id', filters.user_id);
    return `/pointage/rapport/reporting/export?${p.toString()}`;
}

const needsUser = computed(() => selected.value?.period === 'range_user');
const needsDate = computed(() => selected.value?.period === 'date');
const needsMois = computed(() => selected.value?.period === 'mois');
const needsRange = computed(
    () => selected.value?.period === 'range' || selected.value?.period === 'range_user',
);
</script>

<template>
    <PointageLayout title="Reporting RH" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-[#0C447C]">Reporting RH</h1>
                <p class="mt-1 text-sm text-[#888780]">
                    Générez et exportez les rapports de présence (Excel / PDF).
                </p>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="space-y-3">
                    <button
                        v-for="r in rapports"
                        :key="r.id"
                        type="button"
                        class="w-full rounded-[10px] border bg-white p-4 text-left shadow-sm transition"
                        :class="
                            selectedId === r.id
                                ? 'border-[#185FA5] ring-1 ring-[#185FA5]/30'
                                : 'border-[#e2e0d8] hover:border-[#B5D4F4]'
                        "
                        @click="selectedId = r.id"
                    >
                        <div class="font-semibold text-[#0C447C]">{{ r.title }}</div>
                        <div class="mt-1 text-sm text-[#888780]">{{ r.description }}</div>
                    </button>
                </div>

                <div class="h-fit space-y-4 rounded-[10px] border border-[#e2e0d8] bg-white p-5 shadow-sm lg:sticky lg:top-4">
                    <div>
                        <h2 class="text-sm font-semibold text-[#0C447C]">{{ selected?.title }}</h2>
                        <p class="mt-1 text-sm text-[#888780]">{{ selected?.description }}</p>
                    </div>

                    <div class="grid gap-3">
                        <div v-if="needsDate">
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="rep-date">
                                Date
                            </label>
                            <input
                                id="rep-date"
                                v-model="filters.date"
                                type="date"
                                class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                            />
                        </div>

                        <div v-if="needsMois">
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="rep-mois">
                                Mois
                            </label>
                            <input
                                id="rep-mois"
                                v-model="filters.mois"
                                type="month"
                                class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                            />
                        </div>

                        <div v-if="needsRange" class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="rep-debut">
                                    Du
                                </label>
                                <input
                                    id="rep-debut"
                                    v-model="filters.date_debut"
                                    type="date"
                                    class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="rep-fin">
                                    Au
                                </label>
                                <input
                                    id="rep-fin"
                                    v-model="filters.date_fin"
                                    type="date"
                                    class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                                />
                            </div>
                        </div>

                        <div v-if="needsUser">
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="rep-user">
                                Collaborateur
                            </label>
                            <select
                                id="rep-user"
                                v-model="filters.user_id"
                                class="w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                                required
                            >
                                <option value="">Sélectionner…</option>
                                <option v-for="c in collaborateurs" :key="c.id" :value="String(c.id)">
                                    {{ c.label }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="rep-agence">
                                Agence (optionnel)
                            </label>
                            <select
                                id="rep-agence"
                                v-model="filters.agence_id"
                                class="w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                            >
                                <option value="">Toutes</option>
                                <option v-for="a in agences" :key="a.id" :value="String(a.id)">{{ a.nom }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="rep-dept">
                                Département (optionnel)
                            </label>
                            <select
                                id="rep-dept"
                                v-model="filters.departement"
                                class="w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                            >
                                <option value="">Tous</option>
                                <option v-for="d in departements" :key="d" :value="d">{{ d }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 border-t border-[#e2e0d8] pt-4 sm:flex-row">
                        <a
                            :href="exportUrl('csv')"
                            class="inline-flex flex-1 items-center justify-center gap-2 rounded-md bg-[#185FA5] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#144a84]"
                            :class="{ 'pointer-events-none opacity-50': needsUser && !filters.user_id }"
                        >
                            <FileSpreadsheet class="h-4 w-4" aria-hidden="true" />
                            Export Excel (CSV)
                        </a>
                        <a
                            :href="exportUrl('pdf')"
                            class="inline-flex flex-1 items-center justify-center gap-2 rounded-md border border-[#185FA5] bg-white px-4 py-2.5 text-sm font-semibold text-[#185FA5] hover:bg-[#E6F1FB]"
                            :class="{ 'pointer-events-none opacity-50': needsUser && !filters.user_id }"
                        >
                            <FileText class="h-4 w-4" aria-hidden="true" />
                            Export PDF
                        </a>
                    </div>

                    <p class="flex items-start gap-2 text-xs text-[#888780]">
                        <Download class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        Les exports Excel/PDF reprennent les filtres sélectionnés ci-dessus.
                    </p>
                </div>
            </div>
        </div>
    </PointageLayout>
</template>
