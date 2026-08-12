<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Briefcase,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    Clock,
    Download,
    FileWarning,
    List,
    Umbrella,
    UserX,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

export type ReportingDashboard = {
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
        permissions: number;
        permissions_pct: number;
        permissions_delta: number;
        allaitements?: number;
        allaitements_pct?: number;
        allaitements_delta?: number;
        heures_sup?: string;
        heures_sup_delta?: string;
        heures_sup_delta_positive?: boolean;
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
    alertes?: number;
    alertes_list?: { id: string; label: string; count: number; severity: string }[];
    pointages_temps_reel?: {
        id: number;
        employe: string;
        type: string;
        type_label: string;
        heure: string;
        agence: string;
    }[];
};

const props = withDefaults(
    defineProps<{
        dashboard: ReportingDashboard;
        showCharts?: boolean;
        fullLayout?: boolean;
        detailHref?: string;
        exportHref?: string;
        syntheseHref?: string;
        reportingHref?: string;
        absencesHref?: string;
    }>(),
    {
        showCharts: true,
        fullLayout: false,
        detailHref: '/pointage/rh/presence/recuperation-pointages?mode=lignes',
        exportHref: '/pointage/rh/presence/recuperation-pointages/export',
        syntheseHref: '/pointage/rh/tous-pointages',
        reportingHref: '/pointage/rapport/reporting',
        absencesHref: '/pointage/demandes',
    },
);

const k = computed(() => props.dashboard.kpis);
const rep = computed(() => props.dashboard.repartition);
const meta = computed(() => props.dashboard.evolution_meta);
const alertes = computed(() => props.dashboard.alertes_list ?? []);
const showAllAlertes = ref(false);
/** Types de demande (hors présence / absence déjà couvertes par les alertes). */
const alertesDemandes = computed(() => {
    const skip = new Set(['present', 'absence']);
    return (rep.value.items ?? [])
        .filter((item) => !skip.has(item.value))
        .map((item) => ({
            id: `demande_${item.value}`,
            label: item.label,
            count: item.count,
            severity: item.value === 'permission_exceptionnelle' || item.value === 'allaitement' ? 'orange' : 'blue',
        }));
});
const alertesVisibles = computed(() =>
    showAllAlertes.value ? [...alertes.value, ...alertesDemandes.value] : alertes.value,
);
const realtime = computed(() => props.dashboard.pointages_temps_reel ?? []);
const topDepts = computed(() =>
    [...(props.dashboard.presence_departements ?? [])]
        .sort((a, b) => b.taux - a.taux || b.presents - a.presents)
        .slice(0, 5),
);

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
        if (max === min) max = min + 10;
    } else {
        const pad = Math.max(2, (max - min) * 0.15);
        min = Math.max(0, Math.floor(min - pad));
        max = Math.min(100, Math.ceil(max + pad));
        if (max <= min) max = min + 1;
    }
    const span = max - min;
    const yFor = (taux: number) => padT + ((max - taux) / span) * (h - padT - padB);
    const points = data.map((d, i) => {
        const x = padL + (i * (w - padL - padR)) / Math.max(1, data.length - 1);
        return { x, y: yFor(Number(d.taux) || 0), ...d };
    });
    const polyline = points.map((p) => `${p.x},${p.y}`).join(' ');
    const last = points[points.length - 1] ?? null;
    const ticks = [min, Math.round((min + max) / 2), max].filter((v, i, arr) => arr.indexOf(v) === i);
    return { w, h, padL, padR, padT, padB, yFor, points, polyline, last, ticks };
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
    if (cursor < 100) parts.push(`#E2E8F0 ${cursor}% 100%`);
    return { background: `conic-gradient(${parts.join(', ')})` };
});

function barColor(taux: number): string {
    if (taux >= 95) return 'bg-emerald-500';
    if (taux >= 92) return 'bg-amber-500';
    return 'bg-rose-500';
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

function alertIconClass(severity: string): string {
    if (severity === 'red') return 'bg-rose-100 text-rose-600';
    if (severity === 'orange') return 'bg-orange-100 text-orange-600';
    return 'bg-blue-100 text-blue-600';
}

function alertBadgeClass(severity: string): string {
    if (severity === 'red') return 'bg-rose-500';
    if (severity === 'orange') return 'bg-orange-500';
    return 'bg-blue-500';
}
</script>

<template>
    <div class="space-y-5">
        <!-- KPI -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-8">
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
                <div class="mt-1 text-xs text-slate-500">{{ k.conges_delta > 0 ? '+' : '' }}{{ k.conges_delta }} vs hier</div>
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
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-amber-100 text-amber-600"><FileWarning class="h-4 w-4" /></span>
                    <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Permission</span>
                </div>
                <div class="mt-2 text-2xl font-bold text-slate-900">{{ k.permissions ?? 0 }} <span class="text-sm font-semibold text-slate-500">({{ k.permissions_pct ?? 0 }}%)</span></div>
                <div class="mt-1 text-xs text-slate-500">{{ (k.permissions_delta ?? 0) === 0 ? '=' : (((k.permissions_delta ?? 0) > 0 ? '+' : '') + (k.permissions_delta ?? 0)) }} vs hier</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-rose-100 text-rose-700"><CalendarDays class="h-4 w-4" /></span>
                    <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Allaitement</span>
                </div>
                <div class="mt-2 text-2xl font-bold text-slate-900">{{ k.allaitements ?? 0 }} <span class="text-sm font-semibold text-slate-500">({{ k.allaitements_pct ?? 0 }}%)</span></div>
                <div class="mt-1 text-xs text-slate-500">{{ (k.allaitements_delta ?? 0) === 0 ? '=' : (((k.allaitements_delta ?? 0) > 0 ? '+' : '') + (k.allaitements_delta ?? 0)) }} vs hier</div>
            </div>
        </div>

        <!-- Charts -->
        <div v-if="showCharts" class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900">Évolution du Taux de Présence</h3>
                <p class="text-xs text-slate-500">7 derniers jours</p>
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
                    <text v-for="y in evolutionGeom.ticks" :key="'t'+y" :x="4" :y="evolutionGeom.yFor(y) + 3" font-size="9" fill="#94A3B8">{{ y }}%</text>
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
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-slate-900">Présence par Département</h3>
                    <Link :href="detailHref" class="text-xs font-semibold text-blue-600 hover:underline">Voir tout</Link>
                </div>
                <div class="mt-4 space-y-3">
                    <div v-for="d in dashboard.presence_departements.slice(0, 6)" :key="d.nom">
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span class="font-medium text-slate-700">{{ d.nom }}</span>
                            <span class="shrink-0 text-slate-500">{{ d.presents }}/{{ d.effectif }} <span class="font-semibold text-slate-900">{{ d.taux }}%</span></span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full" :class="barColor(d.taux)" :style="{ width: Math.min(100, d.taux) + '%' }" />
                        </div>
                    </div>
                    <p v-if="dashboard.presence_departements.length === 0" class="text-sm text-slate-400">Aucune donnée</p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900">Répartition des Statuts</h3>
                <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row">
                    <div class="relative h-36 w-36 shrink-0 rounded-full" :style="donutStyle">
                        <div class="absolute inset-4 flex flex-col items-center justify-center rounded-full bg-white">
                            <span class="text-[10px] uppercase text-slate-400">Total</span>
                            <span class="text-xl font-bold text-slate-900">{{ rep.total }}</span>
                        </div>
                    </div>
                    <ul class="w-full space-y-2 text-xs">
                        <li v-for="item in rep.items" :key="item.value" class="flex items-center justify-between gap-2">
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

        <!-- Alertes / Temps réel / Top -->
        <div v-if="fullLayout" class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900">Alertes & Anomalies</h3>
                <ul class="mt-3 space-y-2">
                    <li
                        v-for="a in alertesVisibles"
                        :key="a.id"
                        class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2.5"
                    >
                        <span class="flex min-w-0 items-center gap-2 text-xs font-medium text-slate-700">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full" :class="alertIconClass(a.severity)">
                                <AlertTriangle class="h-3.5 w-3.5" />
                            </span>
                            <span class="truncate">{{ a.label }}</span>
                        </span>
                        <span class="inline-flex min-w-6 items-center justify-center rounded-full px-1.5 py-0.5 text-[11px] font-bold text-white" :class="alertBadgeClass(a.severity)">
                            {{ a.count }}
                        </span>
                    </li>
                </ul>
                <button
                    type="button"
                    class="mt-3 text-xs font-semibold text-rose-600 hover:underline"
                    @click="showAllAlertes = !showAllAlertes"
                >
                    {{ showAllAlertes ? 'Masquer les types de demande ←' : 'Voir toutes les alertes →' }}
                </button>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-slate-900">Pointages en temps réel</h3>
                    <Link :href="detailHref" class="text-xs font-semibold text-blue-600 hover:underline">Voir tout</Link>
                </div>
                <ul v-if="realtime.length > 0" class="mt-3 space-y-2">
                    <li
                        v-for="p in realtime"
                        :key="p.id"
                        class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs"
                    >
                        <div class="min-w-0">
                            <div class="truncate font-semibold text-slate-800">{{ p.employe }}</div>
                            <div class="truncate text-slate-500">{{ p.agence }} · {{ p.type_label }}</div>
                        </div>
                        <span class="shrink-0 font-semibold tabular-nums text-slate-900">{{ p.heure }}</span>
                    </li>
                </ul>
                <div v-else class="mt-6 flex flex-col items-center px-4 py-6 text-center">
                    <span class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                        <ClipboardList class="h-7 w-7" />
                    </span>
                    <p class="text-sm font-medium text-slate-700">Aucun pointage enregistré aujourd’hui</p>
                    <p class="mt-1 text-xs text-slate-400">Les pointages des employés s’afficheront ici en temps réel.</p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-slate-900">Top Présence (Départements)</h3>
                    <Link :href="detailHref" class="text-xs font-semibold text-blue-600 hover:underline">Voir tout</Link>
                </div>
                <ol class="mt-3 space-y-2">
                    <li
                        v-for="(d, i) in topDepts"
                        :key="d.nom"
                        class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2.5 text-xs"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-[11px] font-bold text-blue-700">{{ i + 1 }}</span>
                            <span class="truncate font-medium text-slate-800">{{ d.nom }}</span>
                        </span>
                        <span class="shrink-0 font-semibold text-slate-900">{{ d.taux }}%</span>
                    </li>
                    <li v-if="topDepts.length === 0" class="py-6 text-center text-sm text-slate-400">Aucune donnée</li>
                </ol>
            </div>
        </div>

        <!-- Actions rapides -->
        <div v-if="fullLayout">
            <h3 class="mb-3 text-sm font-semibold text-slate-900">Actions rapides</h3>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Link
                    :href="detailHref"
                    class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-300 hover:bg-blue-50/40"
                >
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-blue-600"><Clock class="h-5 w-5" /></span>
                    <span>
                        <span class="block text-sm font-semibold text-slate-900">Pointage en temps réel</span>
                        <span class="block text-xs text-slate-500">Voir les pointages du jour</span>
                    </span>
                </Link>
                <Link
                    :href="syntheseHref"
                    class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-300 hover:bg-blue-50/40"
                >
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600"><ClipboardList class="h-5 w-5" /></span>
                    <span>
                        <span class="block text-sm font-semibold text-slate-900">Synthèse journalière</span>
                        <span class="block text-xs text-slate-500">Résumé de la journée</span>
                    </span>
                </Link>
                <Link
                    :href="absencesHref"
                    class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-300 hover:bg-blue-50/40"
                >
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-100 text-violet-600"><CalendarDays class="h-5 w-5" /></span>
                    <span>
                        <span class="block text-sm font-semibold text-slate-900">Planning des absences</span>
                        <span class="block text-xs text-slate-500">Voir les absences & congés</span>
                    </span>
                </Link>
                <Link
                    :href="detailHref"
                    class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-300 hover:bg-blue-50/40"
                >
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-700"><List class="h-5 w-5" /></span>
                    <span>
                        <span class="block text-sm font-semibold text-slate-900">Détail des lignes</span>
                        <span class="block text-xs text-slate-500">Tableau des pointages</span>
                    </span>
                </Link>
            </div>
        </div>
    </div>
</template>
