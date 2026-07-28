<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Cell = {
    date: string;
    dow: number;
    type: string;
    ferie_subtype?: string | null;
    ferie_source?: string | null;
    ferie_id?: number | null;
    libelle: string | null;
    majoration_pct: number | null;
    partiel: boolean;
};

type FerieDetail = {
    id: number;
    libelle: string;
    date_unique: string;
    date_fin: string | null;
    type: string;
    source: string;
    country_code: string | null;
    pays_region: string | null;
    travaille_avec_majoration: boolean;
    taux_majoration_pct: number;
    recurrence_annuelle: boolean;
    notes: string | null;
};

type Profile = { id: number; libelle: string };

const props = defineProps<{
    profiles: Profile[];
    selected_profile_id: number;
    profile: Profile;
    departements: { id: number; nom: string }[];
    year: number;
    month: number;
    view: 'month' | 'year';
    grille: Cell[] | Record<number, Cell[]>;
    feries_list: FerieDetail[];
    pays_disponibles: { code: string; label: string }[];
    filters: { country_code: string; departement_id: number | null };
}>();

const page = usePage<{ flash?: { success?: string } }>();
const flashOk = computed(() => page.props.flash?.success);

const filterCountry = ref(props.filters.country_code);
const filterDept = ref(props.filters.departement_id ?? ('' as number | ''));
const selectedFerie = ref<FerieDetail | null>(null);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Jours fériés', href: '/pointage/rh/presence/jours-feries' },
    { title: 'Calendrier', href: '#' },
];

const monthNames = [
    'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
    'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',
];

const isYearView = computed(() => props.view === 'year');

const monthGrille = computed((): Cell[] => (isYearView.value || !Array.isArray(props.grille) ? [] : props.grille));

const yearGrilles = computed((): Record<number, Cell[]> => {
    if (!isYearView.value || Array.isArray(props.grille)) return {};
    return props.grille as Record<number, Cell[]>;
});

function queryParams(extra: Record<string, string | number | undefined> = {}) {
    return {
        year: props.year,
        month: props.month,
        view: props.view,
        profile_id: props.selected_profile_id,
        country_code: filterCountry.value,
        departement_id: filterDept.value || undefined,
        ...extra,
    };
}

function applyFilters() {
    router.get('/pointage/rh/presence/jours-feries-calendrier', queryParams(), { preserveScroll: true, replace: true });
}

function setView(v: 'month' | 'year') {
    router.get('/pointage/rh/presence/jours-feries-calendrier', queryParams({ view: v }), { preserveScroll: true, replace: true });
}

function navMonth(delta: number) {
    let m = props.month + delta;
    let y = props.year;
    while (m < 1) { m += 12; y -= 1; }
    while (m > 12) { m -= 12; y += 1; }
    router.get('/pointage/rh/presence/jours-feries-calendrier', queryParams({ year: y, month: m, view: 'month' }), { preserveScroll: true, replace: true });
}

function changeYear(delta: number) {
    router.get('/pointage/rh/presence/jours-feries-calendrier', queryParams({ year: props.year + delta }), { preserveScroll: true, replace: true });
}

function changeProfile(id: number) {
    router.get('/pointage/rh/presence/jours-feries-calendrier', queryParams({ profile_id: id }), { preserveScroll: true, replace: true });
}

function pdfUrl() {
    const p = new URLSearchParams();
    p.set('year', String(props.year));
    p.set('profile_id', String(props.selected_profile_id));
    p.set('country_code', filterCountry.value);
    if (filterDept.value) p.set('departement_id', String(filterDept.value));
    return `/pointage/rh/presence/jours-feries-calendrier/pdf?${p.toString()}`;
}

function printCal() {
    window.print();
}

function paddedCells(cells: Cell[], y: number, m: number) {
    const first = new Date(y, m - 1, 1);
    const startPad = (first.getDay() + 6) % 7;
    const byDate = Object.fromEntries(cells.map((c) => [c.date, c]));
    const last = new Date(y, m, 0).getDate();
    const out: ({ empty: true } | Cell)[] = [];
    for (let i = 0; i < startPad; i++) out.push({ empty: true } as { empty: true });
    for (let d = 1; d <= last; d++) {
        const iso = `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        out.push(byDate[iso] ?? { date: iso, dow: new Date(y, m - 1, d).getDay(), type: 'ouvrable', libelle: null, majoration_pct: null, partiel: false });
    }
    while (out.length % 7 !== 0) out.push({ empty: true } as { empty: true });
    return out;
}

function cellClass(c: Cell | { empty: true }) {
    if ('empty' in c && c.empty) return 'bg-transparent border-transparent';
    const cell = c as Cell;
    if (cell.type === 'weekend') return 'bg-[#f1efe8] text-[#5c5a57] border-[#e2e0d8]';
    if (cell.type === 'ferie') {
        if (cell.ferie_source === 'official') return 'bg-[#dbeafe] text-[#1e40af] border-[#93c5fd] cursor-pointer';
        if (cell.ferie_subtype === 'majore') return 'bg-[#fff3e0] text-[#854F0B] border-[#f5d9a8] cursor-pointer';
        return 'bg-[#fde8e8] text-[#8b1a1a] border-[#f5bcbc] cursor-pointer';
    }
    return 'bg-white text-[#0C447C] border-[#e2e0d8]';
}

function onCellClick(c: Cell | { empty: true }) {
    if ('empty' in c && c.empty) return;
    const cell = c as Cell;
    if (cell.type !== 'ferie' || !cell.ferie_id) {
        selectedFerie.value = null;
        return;
    }
    selectedFerie.value = props.feries_list.find((f) => f.id === cell.ferie_id) ?? null;
}
</script>

<template>
    <PointageLayout :title="`Calendrier — ${year}`" :breadcrumbs="breadcrumbs">
        <div id="cal-print" class="mx-auto max-w-6xl space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4 print:hidden">
                <div>
                    <h1 class="text-xl font-semibold text-[#0C447C]">Calendrier des jours fériés</h1>
                </div>
                <Link href="/pointage/rh/presence/jours-feries" class="text-sm text-[#185FA5] underline">Liste & import</Link>
            </div>

            <div class="flex flex-wrap items-end gap-3 rounded-lg border border-[#e2e0d8] bg-[#FAFAF8] p-3 print:hidden">
                <div>
                    <label class="text-[10px] font-bold uppercase text-[#888780]">Profil horaire</label>
                    <select class="mt-1 block rounded border border-[#e2e0d8] px-2 py-1 text-sm" :value="selected_profile_id" @change="changeProfile(Number(($event.target as HTMLSelectElement).value))">
                        <option v-for="p in profiles" :key="p.id" :value="p.id">{{ p.libelle }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] font-bold uppercase text-[#888780]">Pays</label>
                    <select v-model="filterCountry" class="mt-1 block rounded border border-[#e2e0d8] px-2 py-1 text-sm">
                        <option value="all">Tous</option>
                        <option v-for="p in pays_disponibles" :key="'c-' + p.code" :value="p.code">{{ p.code }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] font-bold uppercase text-[#888780]">Département</label>
                    <select v-model="filterDept" class="mt-1 block min-w-[8rem] rounded border border-[#e2e0d8] px-2 py-1 text-sm">
                        <option value="">Tous</option>
                        <option v-for="d in departements" :key="d.id" :value="d.id">{{ d.nom }}</option>
                    </select>
                </div>
                <button type="button" class="rounded bg-[#0C447C] px-3 py-1.5 text-sm text-white" @click="applyFilters">Filtrer</button>
                <div class="ml-auto flex gap-1">
                    <button type="button" class="rounded border px-3 py-1 text-sm" :class="view === 'month' ? 'border-[#185FA5] bg-[#E6F1FB] font-semibold' : 'border-[#e2e0d8]'" @click="setView('month')">Mensuel</button>
                    <button type="button" class="rounded border px-3 py-1 text-sm" :class="view === 'year' ? 'border-[#185FA5] bg-[#E6F1FB] font-semibold' : 'border-[#e2e0d8]'" @click="setView('year')">Annuel</button>
                </div>
            </div>

            <p v-if="flashOk" class="rounded-lg border border-[#C0DD97] bg-[#EAF3DE] px-3 py-2 text-sm print:hidden">{{ flashOk }}</p>

            <div class="flex flex-wrap gap-4 text-xs">
                <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded border bg-white" /> Ouvrable</span>
                <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded border bg-[#f1efe8]" /> Week-end</span>
                <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded border bg-[#fde8e8]" /> Férié chômé</span>
                <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded border bg-[#fff3e0]" /> Férié majoré</span>
                <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded border bg-[#dbeafe]" /> Import officiel</span>
            </div>

            <!-- Vue mensuelle -->
            <div v-if="!isYearView" class="rounded-xl border border-[#e2e0d8] bg-white p-4 shadow-sm">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2 print:hidden">
                    <h2 class="font-semibold text-[#0C447C]">{{ monthNames[month - 1] }} {{ year }}</h2>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded border px-2 py-1 text-sm" @click="navMonth(-1)">← Mois</button>
                        <button type="button" class="rounded border px-2 py-1 text-sm" @click="navMonth(1)">Mois →</button>
                        <a :href="pdfUrl()" target="_blank" rel="noopener" class="rounded bg-[#185FA5] px-3 py-1 text-sm text-white">PDF</a>
                        <button type="button" class="rounded border px-3 py-1 text-sm" @click="printCal">Imprimer</button>
                    </div>
                </div>
                <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase text-[#888780]">
                    <div v-for="d in ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']" :key="d" class="py-1">{{ d }}</div>
                </div>
                <div class="grid grid-cols-7 gap-1 text-xs">
                    <div
                        v-for="(c, i) in paddedCells(monthGrille, year, month)"
                        :key="'m-' + i"
                        class="min-h-[4rem] rounded border p-1 text-left"
                        :class="cellClass(c)"
                        @click="onCellClick(c)"
                    >
                        <template v-if="!('empty' in c && c.empty)">
                            <div class="font-bold tabular-nums">{{ (c as Cell).date.slice(8, 10) }}</div>
                            <div v-if="(c as Cell).libelle" class="mt-0.5 line-clamp-2 text-[10px] leading-tight">{{ (c as Cell).libelle }}</div>
                            <div v-if="(c as Cell).majoration_pct" class="text-[9px]">+{{ (c as Cell).majoration_pct }}%</div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Vue annuelle -->
            <div v-else class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
                    <h2 class="font-semibold text-[#0C447C]">Année {{ year }}</h2>
                    <div class="flex gap-2">
                        <button type="button" class="rounded border px-2 py-1 text-sm" @click="changeYear(-1)">←</button>
                        <button type="button" class="rounded border px-2 py-1 text-sm" @click="changeYear(1)">→</button>
                        <a :href="pdfUrl()" target="_blank" rel="noopener" class="rounded bg-[#185FA5] px-3 py-1 text-sm text-white">PDF annuel</a>
                        <button type="button" class="rounded border px-3 py-1 text-sm" @click="printCal">Imprimer</button>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <div v-for="m in 12" :key="'y-' + m" class="rounded-lg border border-[#e2e0d8] p-2">
                        <h3 class="mb-1 text-center text-xs font-bold text-[#0C447C]">{{ monthNames[m - 1] }}</h3>
                        <div class="grid grid-cols-7 gap-px text-[7px]">
                            <div
                                v-for="(c, i) in paddedCells(yearGrilles[m] ?? [], year, m)"
                                :key="i"
                                class="min-h-[0.9rem] rounded border text-center leading-tight"
                                :class="cellClass(c)"
                                :title="('empty' in c && c.empty) ? '' : (c as Cell).libelle ?? ''"
                                @click="onCellClick(c)"
                            >
                                <template v-if="!('empty' in c && c.empty)">{{ (c as Cell).date.slice(8, 10) }}</template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="selectedFerie" class="rounded-xl border border-[#185FA5] bg-[#F5FAFF] p-4 print:hidden">
                <h3 class="font-semibold text-[#0C447C]">{{ selectedFerie.libelle }}</h3>
                <dl class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                    <div><dt class="text-[#888780]">Date</dt><dd>{{ selectedFerie.date_unique?.slice(0, 10) }}</dd></div>
                    <div><dt class="text-[#888780]">Type</dt><dd>{{ selectedFerie.type }}</dd></div>
                    <div><dt class="text-[#888780]">Source</dt><dd>{{ selectedFerie.source === 'official' ? 'Officiel' : 'Manuel' }}</dd></div>
                    <div><dt class="text-[#888780]">Majoration</dt><dd>{{ selectedFerie.travaille_avec_majoration ? `${selectedFerie.taux_majoration_pct}%` : 'Chômé (absence non comptée)' }}</dd></div>
                </dl>
            </div>
        </div>
    </PointageLayout>
</template>

<style scoped>
@media print {
    #cal-print { max-width: 100%; }
}
</style>
