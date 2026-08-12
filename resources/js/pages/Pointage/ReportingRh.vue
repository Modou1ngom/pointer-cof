<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import {
    AlertTriangle,
    BarChart3,
    Building2,
    CalendarDays,
    CalendarRange,
    ChevronDown,
    Clock,
    FileSpreadsheet,
    FileText,
    Settings,
    Timer,
    User,
    Users,
    UserX,
} from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

type Rapport = {
    id: string;
    title: string;
    description: string;
    period: 'date' | 'semaine' | 'mois' | 'annee' | 'range' | 'range_user';
    icon: string;
    color: string;
    featured: boolean;
};

const props = defineProps<{
    defaults: {
        date: string;
        mois: string;
        annee: string;
        date_debut: string;
        date_fin: string;
        agence_id: number | null;
        departement: string | null;
        user_id: number | null;
        statut: string | null;
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

const filters = reactive({
    date: props.defaults.date,
    mois: props.defaults.mois,
    annee: props.defaults.annee ?? props.defaults.mois.slice(0, 4),
    date_debut: props.defaults.date_debut,
    date_fin: props.defaults.date_fin,
    agence_id: props.defaults.agence_id ? String(props.defaults.agence_id) : '',
    departement: props.defaults.departement ?? '',
    user_id: props.defaults.user_id ? String(props.defaults.user_id) : '',
    statut: props.defaults.statut ?? '',
});

const selectedId = ref(props.rapports.find((r) => r.featured)?.id ?? props.rapports[0]?.id ?? 'quotidien');
const showMoreReports = ref(false);
const showExportOptions = ref(true);

const selected = computed(() => props.rapports.find((r) => r.id === selectedId.value) ?? props.rapports[0]);
const featuredRapports = computed(() => props.rapports.filter((r) => r.featured));
const extraRapports = computed(() => props.rapports.filter((r) => !r.featured));
const visibleRapports = computed(() =>
    showMoreReports.value ? [...featuredRapports.value, ...extraRapports.value] : featuredRapports.value,
);

const needsUser = computed(() => selected.value?.period === 'range_user');
const needsDate = computed(() => selected.value?.period === 'date' || selected.value?.period === 'semaine');
const needsMois = computed(() => selected.value?.period === 'mois');
const needsAnnee = computed(() => selected.value?.period === 'annee');
const needsRange = computed(
    () => selected.value?.period === 'range' || selected.value?.period === 'range_user',
);

function iconFor(rapport: Rapport) {
    const map: Record<string, typeof CalendarDays> = {
        'calendar-day': CalendarDays,
        'calendar-week': CalendarRange,
        'calendar-month': CalendarDays,
        'calendar-range': CalendarRange,
        'user-x': UserX,
        clock: Clock,
        timer: Timer,
        alert: AlertTriangle,
        building: Building2,
        users: Users,
        user: User,
    };
    return map[rapport.icon] ?? FileSpreadsheet;
}

function colorClasses(color: string) {
    const map: Record<string, string> = {
        blue: 'bg-blue-500',
        green: 'bg-emerald-500',
        purple: 'bg-violet-500',
        orange: 'bg-orange-500',
        red: 'bg-rose-500',
        teal: 'bg-teal-500',
    };
    return map[color] ?? map.blue;
}

function selectReport(id: string) {
    selectedId.value = id;
    showExportOptions.value = true;
}

function exportUrl(format: 'csv' | 'pdf'): string {
    const p = new URLSearchParams();
    p.set('type', selectedId.value);
    p.set('format', format);
    p.set('date', filters.date);
    p.set('mois', filters.mois);
    p.set('annee', filters.annee);
    p.set('date_debut', filters.date_debut);
    p.set('date_fin', filters.date_fin);
    if (filters.agence_id) p.set('agence_id', filters.agence_id);
    if (filters.departement) p.set('departement', filters.departement);
    if (filters.user_id) p.set('user_id', filters.user_id);
    return `/pointage/rapport/reporting/export?${p.toString()}`;
}
</script>

<template>
    <PointageLayout title="Reporting RH" :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1400px] space-y-5">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-10 w-10 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                    <BarChart3 class="h-5 w-5" />
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Reporting RH</h1>
                    <p class="mt-0.5 text-sm text-slate-500">Téléchargez les rapports de présence et de ponctualité.</p>
                </div>
            </div>

            <div v-if="selected" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Exporter le rapport</h2>
                <div class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800">{{ selected.title }}</div>

                <div v-if="showExportOptions" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-if="needsDate">
                        <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-date">{{ selected.period === 'semaine' ? 'Semaine' : 'Date' }}</label>
                        <input id="exp-date" v-model="filters.date" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                    </div>
                    <div v-if="needsMois">
                        <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-mois">Mois</label>
                        <input id="exp-mois" v-model="filters.mois" type="month" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                    </div>
                    <div v-if="needsAnnee">
                        <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-annee">Année</label>
                        <input id="exp-annee" v-model="filters.annee" type="number" min="2020" max="2100" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                    </div>
                    <div v-if="needsRange">
                        <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-debut">Du</label>
                        <input id="exp-debut" v-model="filters.date_debut" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                    </div>
                    <div v-if="needsRange">
                        <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-fin">Au</label>
                        <input id="exp-fin" v-model="filters.date_fin" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                    </div>
                    <div v-if="needsUser">
                        <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-user">Collaborateur</label>
                        <select id="exp-user" v-model="filters.user_id" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm" required>
                            <option value="">Sélectionner…</option>
                            <option v-for="c in collaborateurs" :key="c.id" :value="String(c.id)">{{ c.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-agence">Agence</label>
                        <select id="exp-agence" v-model="filters.agence_id" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                            <option value="">Toutes</option>
                            <option v-for="a in agences" :key="a.id" :value="String(a.id)">{{ a.nom }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-dept">Département</label>
                        <select id="exp-dept" v-model="filters.departement" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                            <option value="">Tous</option>
                            <option v-for="d in departements" :key="d" :value="d">{{ d }}</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a
                        :href="exportUrl('csv')"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700"
                        :class="{ 'pointer-events-none opacity-50': needsUser && !filters.user_id }"
                    >
                        <FileSpreadsheet class="h-4 w-4" />
                        Excel (CSV)
                    </a>
                    <a
                        :href="exportUrl('pdf')"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#E11D48] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#BE123C]"
                        :class="{ 'pointer-events-none opacity-50': needsUser && !filters.user_id }"
                    >
                        <FileText class="h-4 w-4" />
                        PDF
                    </a>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
                        @click="showExportOptions = !showExportOptions"
                    >
                        <Settings class="h-3.5 w-3.5" />
                        {{ showExportOptions ? 'Masquer les options' : 'Options d’export' }}
                    </button>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold text-slate-900">Rapports Disponibles</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <button
                        v-for="r in visibleRapports"
                        :key="r.id"
                        type="button"
                        class="rounded-xl border p-3.5 text-left transition"
                        :class="selectedId === r.id ? 'border-blue-500 bg-blue-50/40 ring-1 ring-blue-500/20' : 'border-slate-200 hover:border-slate-300'"
                        @click="selectReport(r.id)"
                    >
                        <span class="mb-2 inline-flex h-9 w-9 items-center justify-center rounded-lg text-white" :class="colorClasses(r.color)">
                            <component :is="iconFor(r)" class="h-4 w-4" />
                        </span>
                        <div class="text-sm font-semibold text-slate-900">{{ r.title }}</div>
                        <div class="mt-1 line-clamp-2 text-xs text-slate-500">{{ r.description }}</div>
                        <div class="mt-2 text-xs font-semibold text-blue-600">Voir le rapport →</div>
                    </button>
                </div>
                <div class="mt-4 flex justify-center">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-sm font-medium text-slate-600 hover:text-slate-900"
                        @click="showMoreReports = !showMoreReports"
                    >
                        {{ showMoreReports ? 'Voir moins de rapports' : 'Voir plus de rapports' }}
                        <ChevronDown class="h-4 w-4 transition" :class="{ 'rotate-180': showMoreReports }" />
                    </button>
                </div>
            </div>
        </div>
    </PointageLayout>
</template>
