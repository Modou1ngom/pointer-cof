<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { router } from '@inertiajs/vue3';
import { onClickOutside } from '@vueuse/core';
import {
    AlertTriangle,
    BarChart3,
    Bell,
    Briefcase,
    Building2,
    CalendarDays,
    CalendarRange,
    CheckCircle2,
    ChevronDown,
    Clock,
    FileSpreadsheet,
    FileText,
    Filter,
    RotateCcw,
    Settings,
    Timer,
    Umbrella,
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

type Dashboard = {
    date_label: string;
    date_short: string;
    kpis: {
        effectif: number;
        effectif_delta: number;
        presents: number;
        presents_pct: number;
        presents_delta_pct: number;
        retards: number;
        retards_pct: number;
        retards_delta_pct: number;
        absents: number;
        absents_pct: number;
        absents_delta_pct: number;
        conges: number;
        conges_pct: number;
        conges_delta: number;
        missions: number;
        missions_pct: number;
        missions_delta: number;
        heures_sup: string;
        heures_sup_delta: string;
        heures_sup_delta_positive: boolean;
    };
    evolution_7j: { date: string; label: string; taux: number }[];
    evolution_meta: {
        moyenne_7j: number;
        mois_en_cours: number;
        mois_delta_pct: number;
        objectif: number;
    };
    presence_departements: { nom: string; taux: number; presents: number; effectif: number }[];
    repartition: {
        total: number;
        items: { value: string; label: string; count: number; pct: number; color: string }[];
    };
    alertes: number;
    alertes_list: { id: string; label: string; count: number; severity: string }[];
    statut_types: { value: string; label: string }[];
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
    dashboard: Dashboard;
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
const showExportOptions = ref(false);
const showNotifPanel = ref(false);
const notifPanelRef = ref<HTMLElement | null>(null);

onClickOutside(notifPanelRef, () => {
    showNotifPanel.value = false;
});

const selected = computed(() => props.rapports.find((r) => r.id === selectedId.value) ?? props.rapports[0]);
const featuredRapports = computed(() => props.rapports.filter((r) => r.featured));
const extraRapports = computed(() => props.rapports.filter((r) => !r.featured));
const visibleRapports = computed(() =>
    showMoreReports.value ? [...featuredRapports.value, ...extraRapports.value] : featuredRapports.value,
);
const alertesActives = computed(() => (props.dashboard.alertes_list ?? []).filter((a) => a.count > 0));
const alertesCount = computed(() => props.dashboard.alertes ?? 0);

const needsUser = computed(() => selected.value?.period === 'range_user');
const needsDate = computed(() => selected.value?.period === 'date' || selected.value?.period === 'semaine');
const needsMois = computed(() => selected.value?.period === 'mois');
const needsAnnee = computed(() => selected.value?.period === 'annee');
const needsRange = computed(
    () => selected.value?.period === 'range' || selected.value?.period === 'range_user',
);

const k = computed(() => props.dashboard.kpis);
const rep = computed(() => props.dashboard.repartition);
const meta = computed(() => props.dashboard.evolution_meta);

const evolutionGeom = computed(() => {
    const data = props.dashboard.evolution_7j ?? [];
    const w = 340;
    const h = 150;
    const padL = 36;
    const padR = 12;
    const padT = 22;
    const padB = 18;
    const rates = data.map((d) => Number(d.taux) || 0);
    let min = rates.length ? Math.min(...rates) : 0;
    let max = rates.length ? Math.max(...rates) : 100;
    if (max === min) {
        min = Math.max(0, min - 10);
        max = Math.min(100, max + 10);
        if (max === min) {
            max = min + 10;
        }
    } else {
        const pad = Math.max(2, (max - min) * 0.15);
        min = Math.max(0, Math.floor(min - pad));
        max = Math.min(100, Math.ceil(max + pad));
        if (max <= min) {
            max = min + 1;
        }
    }
    const span = max - min;
    const yFor = (taux: number) => padT + ((max - taux) / span) * (h - padT - padB);
    const points = data.map((d, i) => {
        const x = padL + (i * (w - padL - padR)) / Math.max(1, data.length - 1);
        const y = yFor(Number(d.taux) || 0);
        return { x, y, ...d };
    });
    const polyline = points.map((p) => `${p.x},${p.y}`).join(' ');
    const last = points[points.length - 1] ?? null;
    const ticks = [min, Math.round((min + max) / 2), max].filter(
        (v, i, arr) => arr.indexOf(v) === i,
    );
    return { w, h, padL, padR, padT, padB, min, max, span, yFor, points, polyline, last, ticks };
});

const donutStyle = computed(() => {
    const items = rep.value.items ?? [];
    if (items.length === 0 || rep.value.total <= 0) {
        return { background: 'conic-gradient(#E2E8F0 0% 100%)' };
    }
    let cursor = 0;
    const parts: string[] = [];
    for (const item of items) {
        const start = cursor;
        cursor += Number(item.pct) || 0;
        parts.push(`${item.color} ${start}% ${cursor}%`);
    }
    if (cursor < 100) {
        parts.push(`#E2E8F0 ${cursor}% 100%`);
    }
    return { background: `conic-gradient(${parts.join(', ')})` };
});

function barColor(taux: number): string {
    if (taux >= 95) return 'bg-emerald-500';
    if (taux >= 92) return 'bg-amber-500';
    return 'bg-rose-500';
}

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

function trendClass(delta: number, invert = false): string {
    const up = invert ? delta < 0 : delta > 0;
    const down = invert ? delta > 0 : delta < 0;
    if (up) return 'text-emerald-600';
    if (down) return 'text-rose-600';
    return 'text-slate-500';
}

function trendArrow(delta: number): string {
    if (delta > 0) return '↑';
    if (delta < 0) return '↓';
    return '=';
}

function applyFilters() {
    router.get(
        '/pointage/rapport/reporting',
        {
            date: filters.date,
            mois: filters.mois,
            annee: filters.annee,
            date_debut: filters.date_debut,
            date_fin: filters.date_fin,
            agence_id: filters.agence_id || undefined,
            departement: filters.departement || undefined,
            user_id: filters.user_id || undefined,
            statut: filters.statut || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function resetFilters() {
    filters.date = new Date().toISOString().slice(0, 10);
    filters.mois = filters.date.slice(0, 7);
    filters.annee = filters.date.slice(0, 4);
    filters.date_debut = filters.mois + '-01';
    filters.date_fin = filters.date;
    filters.agence_id = '';
    filters.departement = '';
    filters.user_id = '';
    filters.statut = '';
    applyFilters();
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
        <div class="w-full space-y-5">
            <!-- Header -->
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-10 w-10 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                        <BarChart3 class="h-5 w-5" />
                    </span>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Reporting RH</h1>
                        <p class="mt-0.5 text-sm text-slate-500">
                            Analysez la présence et la ponctualité de vos collaborateurs.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm">
                        <CalendarDays class="h-4 w-4 text-slate-400" />
                        <input
                            v-model="filters.date"
                            type="date"
                            class="border-0 bg-transparent p-0 text-sm font-medium text-slate-800 outline-none"
                            @change="applyFilters"
                        />
                    </label>

                    <!-- Notifications dynamiques -->
                    <div ref="notifPanelRef" class="relative">
                        <button
                            type="button"
                            class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50"
                            title="Alertes RH"
                            @click="showNotifPanel = !showNotifPanel"
                        >
                            <Bell class="h-4 w-4" />
                            <span
                                v-if="alertesCount > 0"
                                class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white"
                            >
                                {{ alertesCount }}
                            </span>
                        </button>
                        <div
                            v-if="showNotifPanel"
                            class="absolute right-0 z-30 mt-2 w-80 rounded-xl border border-slate-200 bg-white p-3 shadow-lg"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-sm font-semibold text-slate-900">Alertes RH</span>
                                <span class="text-xs text-slate-500">{{ alertesCount }} au total</span>
                            </div>
                            <ul v-if="alertesActives.length > 0" class="max-h-64 space-y-2 overflow-y-auto">
                                <li
                                    v-for="a in alertesActives"
                                    :key="a.id"
                                    class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs"
                                >
                                    <span class="font-medium text-slate-700">{{ a.label }}</span>
                                    <span
                                        class="inline-flex min-w-6 items-center justify-center rounded-full px-1.5 py-0.5 text-[11px] font-bold text-white"
                                        :class="a.severity === 'red' ? 'bg-rose-500' : 'bg-orange-500'"
                                    >
                                        {{ a.count }}
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="px-1 py-3 text-center text-xs text-slate-400">Aucune alerte pour cette date</p>
                            <button
                                type="button"
                                class="mt-2 w-full text-left text-xs font-semibold text-rose-600 hover:underline"
                                @click="showNotifPanel = false; selectReport('anomalies')"
                            >
                                Voir le rapport anomalies →
                            </button>
                        </div>
                    </div>

                    <!-- Agence dynamique (filtre) -->
                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm">
                        <Building2 class="h-4 w-4 shrink-0 text-slate-400" />
                        <select
                            v-model="filters.agence_id"
                            class="max-w-[180px] border-0 bg-transparent p-0 text-sm font-medium text-slate-800 outline-none"
                            @change="applyFilters"
                        >
                            <option value="">Toutes les agences</option>
                            <option v-for="a in agences" :key="a.id" :value="String(a.id)">{{ a.nom }}</option>
                        </select>
                    </label>
                </div>
            </div>

            <!-- Filtres -->
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[130px] flex-1">
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-400" for="f-date">Période</label>
                        <input id="f-date" v-model="filters.date" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-800" />
                    </div>
                    <div class="min-w-[130px] flex-1">
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-400" for="f-agence">Agence</label>
                        <select id="f-agence" v-model="filters.agence_id" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
                            <option value="">Toutes</option>
                            <option v-for="a in agences" :key="a.id" :value="String(a.id)">{{ a.nom }}</option>
                        </select>
                    </div>
                    <div class="min-w-[130px] flex-1">
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-400" for="f-dept">Département</label>
                        <select id="f-dept" v-model="filters.departement" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
                            <option value="">Tous</option>
                            <option v-for="d in departements" :key="d" :value="d">{{ d }}</option>
                        </select>
                    </div>
                    <div class="min-w-[150px] flex-1">
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-400" for="f-user">Employé</label>
                        <select id="f-user" v-model="filters.user_id" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
                            <option value="">Tous</option>
                            <option v-for="c in collaborateurs" :key="c.id" :value="String(c.id)">{{ c.label }}</option>
                        </select>
                    </div>
                    <div class="min-w-[180px] flex-1">
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-400" for="f-statut">Statut</label>
                        <select id="f-statut" v-model="filters.statut" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
                            <option value="">Tous</option>
                            <option value="present">Présent</option>
                            <option value="retard">Retard</option>
                            <option v-for="t in dashboard.statut_types" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        @click="resetFilters"
                    >
                        <RotateCcw class="h-3.5 w-3.5" />
                        Réinitialiser
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-[#E11D48] px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#BE123C]"
                        @click="applyFilters"
                    >
                        <Filter class="h-3.5 w-3.5" />
                        Appliquer
                    </button>
                </div>
            </div>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
                <div class="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 text-blue-600"><Users class="h-4 w-4" /></span>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Effectif total</span>
                    </div>
                    <div class="mt-2 text-2xl font-bold text-slate-900">{{ k.effectif }}</div>
                    <div class="mt-1 text-xs text-emerald-600">+{{ k.effectif_delta }} ce mois ↑</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"><CheckCircle2 class="h-4 w-4" /></span>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Présents</span>
                    </div>
                    <div class="mt-2 text-2xl font-bold text-slate-900">{{ k.presents }} <span class="text-sm font-semibold text-slate-500">({{ k.presents_pct }}%)</span></div>
                    <div class="mt-1 text-xs" :class="trendClass(k.presents_delta_pct)">{{ k.presents_delta_pct > 0 ? '+' : '' }}{{ k.presents_delta_pct }}% {{ trendArrow(k.presents_delta_pct) }} vs hier</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-orange-100 text-orange-600"><Clock class="h-4 w-4" /></span>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Retards</span>
                    </div>
                    <div class="mt-2 text-2xl font-bold text-slate-900">{{ k.retards }} <span class="text-sm font-semibold text-slate-500">({{ k.retards_pct }}%)</span></div>
                    <div class="mt-1 text-xs" :class="trendClass(k.retards_delta_pct, true)">{{ k.retards_delta_pct > 0 ? '+' : '' }}{{ k.retards_delta_pct }}% {{ trendArrow(k.retards_delta_pct) }} vs hier</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-rose-100 text-rose-600"><UserX class="h-4 w-4" /></span>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Absents</span>
                    </div>
                    <div class="mt-2 text-2xl font-bold text-slate-900">{{ k.absents }} <span class="text-sm font-semibold text-slate-500">({{ k.absents_pct }}%)</span></div>
                    <div class="mt-1 text-xs" :class="trendClass(k.absents_delta_pct, true)">{{ k.absents_delta_pct > 0 ? '+' : '' }}{{ k.absents_delta_pct }}% {{ trendArrow(k.absents_delta_pct) }} vs hier</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-violet-100 text-violet-600"><Umbrella class="h-4 w-4" /></span>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Congés</span>
                    </div>
                    <div class="mt-2 text-2xl font-bold text-slate-900">{{ k.conges }} <span class="text-sm font-semibold text-slate-500">({{ k.conges_pct }}%)</span></div>
                    <div class="mt-1 text-xs text-emerald-600">{{ k.conges_delta > 0 ? '+' : '' }}{{ k.conges_delta }} vs hier ↑</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-teal-100 text-teal-600"><Briefcase class="h-4 w-4" /></span>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">En mission</span>
                    </div>
                    <div class="mt-2 text-2xl font-bold text-slate-900">{{ k.missions }} <span class="text-sm font-semibold text-slate-500">({{ k.missions_pct }}%)</span></div>
                    <div class="mt-1 text-xs text-slate-500">{{ k.missions_delta === 0 ? '=' : ((k.missions_delta > 0 ? '+' : '') + k.missions_delta) }} vs hier</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-amber-100 text-amber-600"><Timer class="h-4 w-4" /></span>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Heures sup.</span>
                    </div>
                    <div class="mt-2 text-2xl font-bold text-slate-900">{{ k.heures_sup }}</div>
                    <div class="mt-1 text-xs" :class="k.heures_sup_delta_positive ? 'text-emerald-600' : 'text-rose-600'">
                        {{ k.heures_sup_delta_positive ? '+' : '' }}{{ k.heures_sup_delta }} ce mois ↑
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">Évolution du Taux de Présence</h2>
                            <p class="text-xs text-slate-500">7 derniers jours</p>
                        </div>
                    </div>
                    <svg :viewBox="`0 0 ${evolutionGeom.w} ${evolutionGeom.h}`" class="mt-2 h-44 w-full" role="img" aria-label="Évolution du taux de présence">
                        <line
                            v-for="y in evolutionGeom.ticks"
                            :key="'g'+y"
                            :x1="evolutionGeom.padL"
                            :x2="evolutionGeom.w - evolutionGeom.padR"
                            :y1="evolutionGeom.yFor(y)"
                            :y2="evolutionGeom.yFor(y)"
                            stroke="#F1F5F9"
                            stroke-width="1"
                        />
                        <text
                            v-for="y in evolutionGeom.ticks"
                            :key="'t'+y"
                            :x="4"
                            :y="evolutionGeom.yFor(y) + 3"
                            font-size="9"
                            fill="#94A3B8"
                        >{{ y }}%</text>
                        <polyline
                            v-if="evolutionGeom.polyline"
                            fill="none"
                            stroke="#3B82F6"
                            stroke-width="3"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            :points="evolutionGeom.polyline"
                        />
                        <template v-if="evolutionGeom.last">
                            <circle :cx="evolutionGeom.last.x" :cy="evolutionGeom.last.y" r="5" fill="#3B82F6" />
                            <rect
                                :x="Math.min(evolutionGeom.last.x - 22, evolutionGeom.w - 48)"
                                :y="Math.max(4, evolutionGeom.last.y - 28)"
                                width="44"
                                height="20"
                                rx="6"
                                fill="#2563EB"
                            />
                            <text
                                :x="Math.min(evolutionGeom.last.x, evolutionGeom.w - 26)"
                                :y="Math.max(18, evolutionGeom.last.y - 14)"
                                text-anchor="middle"
                                fill="white"
                                font-size="11"
                                font-weight="700"
                            >{{ evolutionGeom.last.taux }}%</text>
                        </template>
                    </svg>
                    <div class="mt-1 flex justify-between px-8 text-[10px] text-slate-400">
                        <span v-for="d in dashboard.evolution_7j" :key="d.date">{{ d.label }}</span>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 border-t border-slate-100 pt-3 text-center text-[11px]">
                        <div>
                            <div class="text-slate-400">Moyenne 7 jours</div>
                            <div class="font-semibold text-slate-800">{{ meta.moyenne_7j }}%</div>
                        </div>
                        <div>
                            <div class="text-slate-400">Mois en cours</div>
                            <div class="font-semibold text-slate-800">{{ meta.mois_en_cours }}% <span class="text-emerald-600">({{ meta.mois_delta_pct > 0 ? '+' : '' }}{{ meta.mois_delta_pct }}%)</span></div>
                        </div>
                        <div>
                            <div class="text-slate-400">Objectif</div>
                            <div class="font-semibold text-slate-800">{{ meta.objectif }}%</div>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-sm font-semibold text-slate-900">Présence par Département</h2>
                    <div class="mt-4 space-y-3">
                        <div v-for="d in dashboard.presence_departements" :key="d.nom">
                            <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                                <span class="font-medium text-slate-700">{{ d.nom }}</span>
                                <span class="shrink-0 text-slate-500">{{ d.presents }}/{{ d.effectif }} <span class="font-semibold text-slate-900">{{ d.taux }}%</span></span>
                            </div>
                            <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full" :class="barColor(d.taux)" :style="{ width: d.taux + '%' }" />
                            </div>
                        </div>
                        <p v-if="dashboard.presence_departements.length === 0" class="text-sm text-slate-400">Aucune donnée</p>
                    </div>
                    <button type="button" class="mt-4 text-xs font-semibold text-blue-600 hover:underline" @click="selectReport('departement')">
                        Voir le détail par département →
                    </button>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-sm font-semibold text-slate-900">Répartition des Statuts</h2>
                    <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row">
                        <div class="relative h-36 w-36 shrink-0 rounded-full" :style="donutStyle">
                            <div class="absolute inset-4 flex flex-col items-center justify-center rounded-full bg-white">
                                <span class="text-[10px] uppercase text-slate-400">Total</span>
                                <span class="text-xl font-bold text-slate-900">{{ rep.total }}</span>
                            </div>
                        </div>
                        <ul class="w-full space-y-2 text-xs">
                            <li
                                v-for="item in rep.items"
                                :key="item.value"
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: item.color }" />
                                    <span class="truncate">{{ item.label }}</span>
                                </span>
                                <span class="shrink-0 font-semibold">{{ item.count }} ({{ item.pct }}%)</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Rapports + Alertes + Export -->
            <div class="grid gap-4 xl:grid-cols-[1fr_280px]">
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
                        <button type="button" class="inline-flex items-center gap-1 text-sm font-medium text-slate-600 hover:text-slate-900" @click="showMoreReports = !showMoreReports">
                            {{ showMoreReports ? 'Voir moins de rapports' : 'Voir plus de rapports' }}
                            <ChevronDown class="h-4 w-4 transition" :class="{ 'rotate-180': showMoreReports }" />
                        </button>
                    </div>
                </div>

                <div class="space-y-4">
                    <!-- Alertes RH -->
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-slate-900">Alertes RH</h2>
                            <span v-if="dashboard.alertes > 0" class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white">
                                {{ dashboard.alertes }}
                            </span>
                        </div>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="a in dashboard.alertes_list"
                                :key="a.id"
                                class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs"
                            >
                                <span class="font-medium text-slate-700">{{ a.label }}</span>
                                <span
                                    class="inline-flex min-w-6 items-center justify-center rounded-full px-1.5 py-0.5 text-[11px] font-bold text-white"
                                    :class="a.severity === 'red' ? 'bg-rose-500' : 'bg-orange-500'"
                                >
                                    {{ a.count }}
                                </span>
                            </li>
                        </ul>
                        <button type="button" class="mt-3 text-xs font-semibold text-rose-600 hover:underline" @click="selectReport('anomalies')">
                            Voir toutes les alertes →
                        </button>
                    </div>

                    <!-- Export -->
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h2 class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Exporter le rapport</h2>
                        <div class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800">{{ selected?.title }}</div>

                        <div v-if="showExportOptions || selected" class="mt-3 space-y-2">
                            <div v-if="needsDate">
                                <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-date">{{ selected?.period === 'semaine' ? 'Semaine' : 'Date' }}</label>
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
                            <div v-if="needsRange" class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-debut">Du</label>
                                    <input id="exp-debut" v-model="filters.date_debut" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-fin">Au</label>
                                    <input id="exp-fin" v-model="filters.date_fin" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                                </div>
                            </div>
                            <div v-if="needsUser">
                                <label class="mb-1 block text-[10px] font-bold uppercase text-slate-400" for="exp-user">Collaborateur</label>
                                <select id="exp-user" v-model="filters.user_id" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm" required>
                                    <option value="">Sélectionner…</option>
                                    <option v-for="c in collaborateurs" :key="c.id" :value="String(c.id)">{{ c.label }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-col gap-2">
                            <a
                                :href="exportUrl('csv')"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700"
                                :class="{ 'pointer-events-none opacity-50': needsUser && !filters.user_id }"
                            >
                                <FileSpreadsheet class="h-4 w-4" />
                                Excel (CSV)
                            </a>
                            <a
                                :href="exportUrl('pdf')"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#E11D48] px-3 py-2.5 text-sm font-semibold text-white hover:bg-[#BE123C]"
                                :class="{ 'pointer-events-none opacity-50': needsUser && !filters.user_id }"
                            >
                                <FileText class="h-4 w-4" />
                                PDF
                            </a>
                        </div>
                        <button type="button" class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 hover:text-slate-800" @click="showExportOptions = !showExportOptions">
                            <Settings class="h-3.5 w-3.5" />
                            Options d’export avancées
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </PointageLayout>
</template>
