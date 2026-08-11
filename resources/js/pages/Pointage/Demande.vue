<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, ChevronLeft, ChevronRight, CircleAlert, Eye, Pencil, Timer, Trash2, X } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

interface ProcessStep {
    key: string;
    label: string;
    done: boolean;
    current: boolean;
}

interface Decl {
    id: number;
    type: string;
    type_label: string;
    date_concernee: string;
    date_concernee_display: string;
    date_concernee_short?: string | null;
    date_fin?: string | null;
    date_fin_display?: string | null;
    date_fin_short?: string | null;
    nb_jours?: number;
    date_reprise_display?: string | null;
    heure_debut?: string | null;
    heure_fin?: string | null;
    lieu?: string | null;
    motif: string;
    commentaire?: string | null;
    statut: string;
    statut_label: string;
    has_justificatif?: boolean;
    justificatif_filename?: string | null;
    user?: { id?: number; name: string; email: string; fonction?: string | null } | null;
    manager_user?: { name: string } | null;
    rh_user?: { name: string } | null;
    manager_comment?: string | null;
    rh_comment?: string | null;
    manager_decided_at?: string | null;
    rh_decided_at?: string | null;
    created_at_display?: string | null;
    processus: ProcessStep[];
}

type Onglet = 'attente' | 'historique' | 'toutes';

const props = defineProps<{
    declarations: {
        data: Decl[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
    };
    historique: Decl[];
    periode_mois: string;
    periode_label: string;
    filters: { type: string; statut: string; q: string; onglet?: string };
    types: { value: string; label: string }[];
    counts: Record<string, number>;
    can_manage: boolean;
    can_validate_rh: boolean;
}>();

const page = usePage();
const flashSuccess = computed(() => {
    const flash = page.props.flash as { success?: string; error?: string } | undefined;
    return flash?.success ?? null;
});
const flashError = computed(() => {
    const flash = page.props.flash as { success?: string; error?: string } | undefined;
    return flash?.error ?? null;
});

const typesFiltres = computed(() =>
    (props.types || []).filter((t) => !['regularisation', 'conge', 'retard'].includes(t.value)),
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage', href: '/pointage' },
    { title: 'Demande', href: '#' },
];

const localFilters = reactive({
    type: props.filters.type || 'tous',
    statut: props.filters.statut || 'tous',
    q: props.filters.q || '',
    onglet: (props.filters.onglet as Onglet) || 'attente',
});

watch(
    () => props.filters,
    (f) => {
        localFilters.type = f.type || 'tous';
        localFilters.statut = f.statut || 'tous';
        localFilters.q = f.q || '';
        localFilters.onglet = (f.onglet as Onglet) || 'attente';
    },
    { deep: true },
);

const pendingCount = computed(() => {
    const c = props.counts || {};
    if (typeof c.en_attente === 'number') {
        return c.en_attente;
    }
    return (c.en_attente_manager ?? 0) + (c.en_attente_rh ?? 0);
});

function listUrl(overrides: Record<string, string> = {}): string {
    const merged: Record<string, string> = {
        mois: props.periode_mois,
        onglet: localFilters.onglet,
        type: localFilters.type,
        statut: localFilters.statut,
        q: localFilters.q,
        ...overrides,
    };
    Object.keys(merged).forEach((k) => {
        if (!merged[k] || merged[k] === 'tous') {
            if (k === 'type' || k === 'statut' || k === 'q') {
                delete merged[k];
            }
        }
    });
    const p = new URLSearchParams(merged);
    return `/pointage/demandes?${p.toString()}`;
}

function applyFilters() {
    router.get(listUrl(), {}, { preserveState: true, preserveScroll: true });
}

function setOnglet(onglet: Onglet) {
    localFilters.onglet = onglet;
    if (onglet !== 'toutes') {
        localFilters.statut = 'tous';
    }
    applyFilters();
}

/** Filtre par type via les en-têtes de colonnes (re-clic = tous). */
function setTypeFilter(type: string) {
    localFilters.type = localFilters.type === type ? 'tous' : type;
    applyFilters();
}

function typeHeaderClass(type: string): string {
    const active = localFilters.type === type;
    return [
        'px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wide transition',
        active
            ? 'bg-[#F3E8FF] text-[#5B2C8F] underline decoration-2 underline-offset-4'
            : 'text-[#888780] hover:bg-[#F8F5FC] hover:text-[#5B2C8F]',
    ].join(' ');
}

function shiftMonth(ym: string, delta: number): string {
    const [yStr, mStr] = ym.split('-');
    let y = parseInt(yStr, 10);
    let m = parseInt(mStr, 10) - 1 + delta;
    while (m < 0) {
        m += 12;
        y -= 1;
    }
    while (m > 11) {
        m -= 12;
        y += 1;
    }
    return `${y}-${String(m + 1).padStart(2, '0')}`;
}

const prevMonthUrl = computed(() => listUrl({ mois: shiftMonth(props.periode_mois, -1) }));
const nextMonthUrl = computed(() => listUrl({ mois: shiftMonth(props.periode_mois, 1) }));

function periode(d: Decl): string {
    if (d.date_fin_display) {
        return `${d.date_concernee_display} → ${d.date_fin_display}`;
    }
    let s = d.date_concernee_display || d.date_concernee;
    if (d.heure_debut || d.heure_fin) {
        s += ` (${[d.heure_debut, d.heure_fin].filter(Boolean).join('–')})`;
    }
    return s;
}

function joursLabel(n: number | undefined): string {
    const v = n ?? 1;
    return `${v} jour${v > 1 ? 's' : ''}`;
}

const viewDecl = ref<Decl | null>(null);
const editDecl = ref<Decl | null>(null);

const editForm = useForm({
    type: 'permission_exceptionnelle',
    date_concernee: '',
    date_fin: '',
    heure_debut: '',
    heure_fin: '',
    lieu: '',
    motif: '',
    commentaire: '',
    statut: 'en_attente_manager',
    justificatif: null as File | null,
});

function openView(d: Decl) {
    viewDecl.value = d;
}

function openEdit(d: Decl) {
    editDecl.value = d;
    editForm.type = d.type;
    editForm.date_concernee = d.date_concernee;
    editForm.date_fin = d.date_fin || '';
    editForm.heure_debut = d.heure_debut || '';
    editForm.heure_fin = d.heure_fin || '';
    editForm.lieu = d.lieu || '';
    editForm.motif = d.motif;
    editForm.commentaire = d.commentaire || '';
    editForm.statut = d.statut;
    editForm.justificatif = null;
    editForm.clearErrors();
}

function submitEdit() {
    if (!editDecl.value) return;
    editForm
        .transform((data) => ({
            ...data,
            _method: 'put',
            date_fin: data.date_fin || null,
            heure_debut: data.heure_debut || null,
            heure_fin: data.heure_fin || null,
            lieu: data.lieu || null,
            commentaire: data.commentaire || null,
        }))
        .post(`/pointage/declarations/${editDecl.value.id}`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                editDecl.value = null;
            },
        });
}

function destroyDecl(d: Decl) {
    if (!confirm(`Supprimer la déclaration #${d.id} de ${d.user?.name ?? 'cet employé'} ?`)) {
        return;
    }
    router.delete(`/pointage/declarations/${d.id}`, { preserveScroll: true });
}

const confirmDecision = ref<{ decl: Decl; accept: boolean } | null>(null);
const showSuccessModal = ref(false);
const deciding = ref(false);

function askDecideRh(d: Decl, accept: boolean) {
    confirmDecision.value = { decl: d, accept };
}

function decideRh(id: number, accept: boolean) {
    deciding.value = true;
    router.post(
        `/pointage/declarations/${id}/decision-rh`,
        { accept, comment: '' },
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmDecision.value = null;
                showSuccessModal.value = true;
            },
            onFinish: () => {
                deciding.value = false;
            },
        },
    );
}

function confirmDecide() {
    if (!confirmDecision.value) return;
    decideRh(confirmDecision.value.decl.id, confirmDecision.value.accept);
}

function onEditFile(e: Event) {
    const t = e.target as HTMLInputElement;
    editForm.justificatif = t.files?.[0] ?? null;
}

function canDecide(d: Decl): boolean {
    // RH : uniquement après le N+1 (ou envoi direct RH si pas de N+1).
    return props.can_validate_rh && d.statut === 'en_attente_rh';
}

function isTermine(d: Decl): boolean {
    return d.statut === 'valide' || d.statut === 'rejete';
}

function n1Done(d: Decl): boolean {
    return Boolean(d.manager_decided_at) || ['en_attente_rh', 'valide', 'rejete'].includes(d.statut);
}

function rhDone(d: Decl): boolean {
    return Boolean(d.rh_decided_at) || d.statut === 'valide' || d.statut === 'rejete';
}

function dateEnregistrement(d: Decl): string {
    const raw = d.created_at_display || '';
    return raw.includes(' ') ? raw.split(' ')[0] : raw || '—';
}

const isToutes = computed(() => localFilters.onglet === 'toutes');
</script>

<template>
    <PointageLayout title="Demande" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-[#0C447C]">Demande</h1>
                    <p class="mt-1 text-sm text-[#888780]">
                        Double validation : N+1 puis RH. Actions : voir, rejeter, valider, modifier, supprimer.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="prevMonthUrl" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-[#e2e0d8]" preserve-scroll>
                        <ChevronLeft class="h-4 w-4" />
                    </Link>
                    <span class="min-w-[9rem] text-center text-sm font-semibold text-[#0C447C]">{{ periode_label }}</span>
                    <Link :href="nextMonthUrl" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-[#e2e0d8]" preserve-scroll>
                        <ChevronRight class="h-4 w-4" />
                    </Link>
                </div>
            </div>

            <p v-if="flashSuccess" class="rounded-md bg-[#EAF3DE] px-3 py-2 text-sm text-[#3B6D11]">{{ flashSuccess }}</p>
            <p v-if="flashError" class="rounded-md bg-[#FCEBEB] px-3 py-2 text-sm text-[#791F1F]">{{ flashError }}</p>

            <!-- Onglets style image 1 -->
            <div class="rounded-[12px] border border-[#e2e0d8] bg-white shadow-sm">
                <div class="flex flex-wrap items-center gap-6 border-b border-[#F1EFE8] px-5 pt-3">
                    <button
                        type="button"
                        class="relative pb-3 text-sm font-semibold transition"
                        :class="localFilters.onglet === 'attente' ? 'text-[#5B2C8F]' : 'text-[#888780] hover:text-[#0C447C]'"
                        @click="setOnglet('attente')"
                    >
                        En attente de Validation
                        <span
                            class="ml-2 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#E11D48] px-1.5 text-[11px] font-bold leading-none text-white"
                        >
                            {{ pendingCount }}
                        </span>
                        <span
                            v-if="localFilters.onglet === 'attente'"
                            class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-[#5B2C8F]"
                        />
                    </button>
                    <button
                        type="button"
                        class="relative pb-3 text-sm font-semibold transition"
                        :class="localFilters.onglet === 'historique' ? 'text-[#5B2C8F]' : 'text-[#888780] hover:text-[#0C447C]'"
                        @click="setOnglet('historique')"
                    >
                        Historique
                        <span
                            v-if="localFilters.onglet === 'historique'"
                            class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-[#5B2C8F]"
                        />
                    </button>
                    <button
                        type="button"
                        class="relative pb-3 text-sm font-semibold transition"
                        :class="localFilters.onglet === 'toutes' ? 'text-[#5B2C8F]' : 'text-[#888780] hover:text-[#0C447C]'"
                        @click="setOnglet('toutes')"
                    >
                        Toutes les demandes
                        <span
                            v-if="localFilters.onglet === 'toutes'"
                            class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-[#5B2C8F]"
                        />
                    </button>
                </div>

                <form class="flex flex-wrap items-end gap-3 border-b border-[#F1EFE8] px-5 py-4" @submit.prevent="applyFilters">
                    <div class="min-w-[10rem] flex-1">
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Recherche</label>
                        <input v-model="localFilters.q" type="search" placeholder="Employé, email…" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Type</label>
                        <select v-model="localFilters.type" class="mt-1 rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                            <option value="tous">Tous</option>
                            <option v-for="t in typesFiltres" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <div v-if="localFilters.onglet === 'toutes'">
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Statut</label>
                        <select v-model="localFilters.statut" class="mt-1 rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                            <option value="tous">Tous</option>
                            <option value="en_attente_manager">En attente N+1</option>
                            <option value="en_attente_rh">En attente RH</option>
                            <option value="valide">Validé</option>
                            <option value="rejete">Rejeté</option>
                        </select>
                    </div>
                    <button type="submit" class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-medium text-white">Filtrer</button>
                </form>

                <div class="overflow-x-auto">
                    <!-- Layout image 3 : Toutes les demandes -->
                    <table v-if="isToutes" class="w-full min-w-[1100px] text-sm">
                        <thead class="bg-[#FAFAF8] text-left text-[11px] font-bold uppercase tracking-wide text-[#888780]">
                            <tr>
                                <th class="px-5 py-3">Agent</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Début</th>
                                <th class="px-4 py-3">Fin</th>
                                <th class="px-4 py-3">Circuit validation</th>
                                <th class="px-4 py-3">État</th>
                                <th class="px-4 py-3">Date enregistrement</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="d in declarations.data"
                                :key="'t-' + d.id"
                                class="border-t border-dashed border-[#E5E7EB] transition hover:bg-[#FCFBFA]"
                            >
                                <td class="px-5 py-4">
                                    <div class="font-semibold capitalize text-[#1a1a1a]">{{ d.user?.name }}</div>
                                    <div class="text-xs uppercase tracking-wide text-[#888780]">
                                        {{ d.user?.fonction || d.user?.email || '—' }}
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-medium text-[#1a1a1a]">{{ d.type_label }}</div>
                                    <div class="text-xs font-semibold text-[#E11D48]">{{ joursLabel(d.nb_jours) }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-[#374151]">{{ d.date_concernee_display }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-[#374151]">{{ d.date_fin_display || d.date_concernee_display }}</td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex flex-col items-center gap-1">
                                            <span
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-full"
                                                :class="n1Done(d) ? 'bg-[#10B981] text-white' : 'bg-[#E5E7EB] text-[#4B5563]'"
                                                :title="n1Done(d) ? 'N+1 validé' : 'N+1 en attente'"
                                            >
                                                <Check v-if="n1Done(d)" class="h-4 w-4" />
                                                <Timer v-else class="h-4 w-4" />
                                            </span>
                                            <span class="text-[10px] font-semibold text-[#6B7280]">N+1</span>
                                        </div>
                                        <div class="flex flex-col items-center gap-1">
                                            <span
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-full"
                                                :class="rhDone(d) ? 'bg-[#10B981] text-white' : 'bg-[#E5E7EB] text-[#4B5563]'"
                                                :title="rhDone(d) ? 'RH validé' : 'RH en attente'"
                                            >
                                                <Check v-if="rhDone(d)" class="h-4 w-4" />
                                                <Timer v-else class="h-4 w-4" />
                                            </span>
                                            <span class="text-[10px] font-semibold text-[#6B7280]">RH</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <span
                                        class="text-sm font-semibold"
                                        :class="isTermine(d) ? 'text-[#E11D48]' : 'text-[#6B7280]'"
                                    >
                                        {{ isTermine(d) ? 'Terminé' : 'En attente' }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-[#374151]">{{ dateEnregistrement(d) }}</td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#F3F4F6] text-[#4B5563] hover:bg-[#E5E7EB]"
                                            title="Voir"
                                            @click="openView(d)"
                                        >
                                            <Eye class="h-4 w-4" />
                                        </button>
                                        <template v-if="canDecide(d)">
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#FFE4E6] text-[#E11D48] hover:bg-[#FECDD3]"
                                                title="Rejeter"
                                                @click="askDecideRh(d, false)"
                                            >
                                                <X class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#D1FAE5] text-[#059669] hover:bg-[#A7F3D0]"
                                                title="Valider"
                                                @click="askDecideRh(d, true)"
                                            >
                                                <Check class="h-4 w-4" />
                                            </button>
                                        </template>
                                        <template v-if="can_manage">
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[#e2e0d8] text-[#185FA5] hover:bg-[#E6F1FB]"
                                                title="Modifier"
                                                @click="openEdit(d)"
                                            >
                                                <Pencil class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[#FCEBEB] text-[#A32D2D] hover:bg-[#FCEBEB]"
                                                title="Supprimer"
                                                @click="destroyDecl(d)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!declarations.data?.length">
                                <td colspan="8" class="px-5 py-12 text-center text-[#888780]">Aucune déclaration pour ces filtres.</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Layout image 1 : En attente / Historique -->
                    <table v-else class="w-full min-w-[1100px] text-sm">
                        <thead class="bg-[#FAFAF8]">
                            <tr>
                                <th class="px-5 py-3">
                                    <button type="button" class="w-full text-left" :class="typeHeaderClass('absence')" @click="setTypeFilter('absence')">
                                        Absence
                                    </button>
                                </th>
                                <th class="px-0 py-0">
                                    <button type="button" class="w-full px-4 py-3 text-left" :class="typeHeaderClass('conge_annuel')" @click="setTypeFilter('conge_annuel')">
                                        Congé annuel
                                    </button>
                                </th>
                                <th class="px-0 py-0">
                                    <button type="button" class="w-full px-4 py-3 text-left" :class="typeHeaderClass('conge_maladie')" @click="setTypeFilter('conge_maladie')">
                                        Congé maladie
                                    </button>
                                </th>
                                <th class="px-0 py-0">
                                    <button type="button" class="w-full px-4 py-3 text-left" :class="typeHeaderClass('permission_exceptionnelle')" @click="setTypeFilter('permission_exceptionnelle')">
                                        Permission exceptionnelle
                                    </button>
                                </th>
                                <th class="px-0 py-0">
                                    <button type="button" class="w-full px-4 py-3 text-left" :class="typeHeaderClass('mission')" @click="setTypeFilter('mission')">
                                        Mission
                                    </button>
                                </th>
                                <th class="px-0 py-0">
                                    <button type="button" class="w-full px-4 py-3 text-left" :class="typeHeaderClass('formation')" @click="setTypeFilter('formation')">
                                        Formation
                                    </button>
                                </th>
                                <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-[#888780]">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="d in declarations.data"
                                :key="d.id"
                                class="border-t border-[#F1EFE8] transition hover:bg-[#FCFBFA]"
                            >
                                <td class="px-5 py-4">
                                    <div class="font-semibold capitalize text-[#1a1a1a]">{{ d.user?.name }}</div>
                                    <div class="text-xs uppercase tracking-wide text-[#888780]">
                                        {{ d.user?.fonction || d.user?.email || '—' }}
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-full bg-[#FCE7F3] px-2.5 text-sm font-bold text-[#BE185D]">
                                        {{ d.nb_jours ?? 1 }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-[#1a1a1a]">{{ d.type_label }}</div>
                                    <div class="text-xs text-[#888780]">{{ joursLabel(d.nb_jours) }}</div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="rounded-md bg-[#F3F4F6] px-2.5 py-1 text-xs font-medium text-[#4B5563]">
                                            {{ d.date_concernee_short || d.date_concernee_display }}
                                        </span>
                                        <span class="text-[#9CA3AF]">→</span>
                                        <span class="rounded-md bg-[#F3F4F6] px-2.5 py-1 text-xs font-medium text-[#4B5563]">
                                            {{ d.date_fin_short || d.date_concernee_short || d.date_concernee_display }}
                                        </span>
                                    </div>
                                    <div v-if="d.heure_debut || d.heure_fin" class="mt-1 text-[11px] text-[#888780]">
                                        {{ [d.heure_debut, d.heure_fin].filter(Boolean).join(' – ') }}
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-[#6B7280]">
                                    {{ d.lieu || d.manager_user?.name || '—' }}
                                </td>
                                <td class="px-4 py-4 font-semibold text-[#1a1a1a]">
                                    {{ d.date_reprise_display || '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#EDE9FE] text-[#5B2C8F] hover:bg-[#DDD6FE]"
                                            title="Voir"
                                            @click="openView(d)"
                                        >
                                            <Eye class="h-4 w-4" />
                                        </button>
                                        <template v-if="canDecide(d)">
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#FFE4E6] text-[#E11D48] hover:bg-[#FECDD3]"
                                                title="Rejeter"
                                                @click="askDecideRh(d, false)"
                                            >
                                                <X class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#D1FAE5] text-[#059669] hover:bg-[#A7F3D0]"
                                                title="Valider"
                                                @click="askDecideRh(d, true)"
                                            >
                                                <Check class="h-4 w-4" />
                                            </button>
                                        </template>
                                        <template v-if="can_manage">
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[#e2e0d8] text-[#185FA5] hover:bg-[#E6F1FB]"
                                                title="Modifier"
                                                @click="openEdit(d)"
                                            >
                                                <Pencil class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[#FCEBEB] text-[#A32D2D] hover:bg-[#FCEBEB]"
                                                title="Supprimer"
                                                @click="destroyDecl(d)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!declarations.data?.length">
                                <td colspan="7" class="px-5 py-12 text-center text-[#888780]">Aucune déclaration pour ces filtres.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="declarations.last_page > 1" class="flex flex-wrap justify-center gap-1 border-t border-[#e2e0d8] px-4 py-3">
                    <template v-for="(link, i) in declarations.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="min-w-[2.25rem] rounded-md px-2 py-1 text-center text-xs"
                            :class="link.active ? 'bg-[#5B2C8F] font-semibold text-white' : 'border border-[#e2e0d8] text-[#0C447C]'"
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span v-else class="min-w-[2.25rem] px-2 py-1 text-center text-xs text-[#ccc]" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>

        <!-- Voir -->
        <div v-if="viewDecl" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="viewDecl = null">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-[10px] bg-white p-5 shadow-xl">
                <h2 class="text-lg font-semibold text-[#0C447C]">Déclaration #{{ viewDecl.id }}</h2>
                <dl class="mt-4 space-y-2 text-sm">
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Employé</dt><dd>{{ viewDecl.user?.name }} — {{ viewDecl.user?.email }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Type</dt><dd>{{ viewDecl.type_label }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Période</dt><dd>{{ periode(viewDecl) }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Jours</dt><dd>{{ viewDecl.nb_jours ?? 1 }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Date de reprise</dt><dd>{{ viewDecl.date_reprise_display || '—' }}</dd></div>
                    <div v-if="viewDecl.lieu"><dt class="text-[11px] font-bold uppercase text-[#888780]">Lieu / Mission</dt><dd>{{ viewDecl.lieu }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Motif</dt><dd>{{ viewDecl.motif }}</dd></div>
                    <div v-if="viewDecl.commentaire"><dt class="text-[11px] font-bold uppercase text-[#888780]">Commentaire</dt><dd>{{ viewDecl.commentaire }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Statut</dt><dd>{{ viewDecl.statut_label }}</dd></div>
                </dl>
                <div class="mt-5 flex justify-end">
                    <button type="button" class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm" @click="viewDecl = null">Fermer</button>
                </div>
            </div>
        </div>

        <!-- Modifier -->
        <div v-if="editDecl" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="editDecl = null">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-[10px] bg-white p-5 shadow-xl">
                <h2 class="text-lg font-semibold text-[#0C447C]">Modifier #{{ editDecl.id }}</h2>
                <form class="mt-4 space-y-3" @submit.prevent="submitEdit">
                    <div>
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Type</label>
                        <select v-model="editForm.type" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Date début</label>
                            <input v-model="editForm.date_concernee" type="date" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Date fin</label>
                            <input v-model="editForm.date_fin" type="date" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Heure début</label>
                            <input v-model="editForm.heure_debut" type="time" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Heure fin</label>
                            <input v-model="editForm.heure_fin" type="time" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Lieu</label>
                        <input v-model="editForm.lieu" type="text" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Motif</label>
                        <input v-model="editForm.motif" type="text" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Commentaire</label>
                        <textarea v-model="editForm.commentaire" rows="2" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Statut</label>
                        <select v-model="editForm.statut" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                            <option value="en_attente_manager">En attente N+1</option>
                            <option value="en_attente_rh">En attente RH</option>
                            <option value="valide">Validé</option>
                            <option value="rejete">Rejeté</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Nouveau justificatif</label>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 w-full text-sm" @change="onEditFile" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm" @click="editDecl = null">Annuler</button>
                        <button type="submit" :disabled="editForm.processing" class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-medium text-white disabled:opacity-50">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Confirmation validation / rejet (image 1) -->
        <div
            v-if="confirmDecision"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/45 p-4"
            @click.self="confirmDecision = null"
        >
            <div class="w-full max-w-md rounded-2xl bg-white px-6 py-8 text-center shadow-xl">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#FFF4E5]">
                    <CircleAlert class="h-9 w-9 text-[#F59E0B]" />
                </div>
                <h2 class="mt-4 text-lg font-semibold text-[#1F2937]">
                    {{ confirmDecision.accept ? 'Validation demande' : 'Rejet de la demande' }}
                </h2>
                <p class="mt-3 text-sm leading-relaxed text-[#4B5563]">
                    Tu vas {{ confirmDecision.accept ? 'valider' : 'rejeter' }} la demande
                    <strong>{{ confirmDecision.decl.type_label }}</strong>
                    de <strong class="capitalize">{{ confirmDecision.decl.user?.name }}</strong>
                    du <strong>{{ confirmDecision.decl.date_concernee_display }}</strong>
                    au <strong>{{ confirmDecision.decl.date_fin_display || confirmDecision.decl.date_concernee_display }}</strong>.
                    Merci de confirmer !
                </p>
                <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
                    <button
                        type="button"
                        class="rounded-lg bg-[#10B981] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#059669] disabled:opacity-50"
                        :disabled="deciding"
                        @click="confirmDecide"
                    >
                        Oui, Je confirme !
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-[#D1D5DB] bg-white px-5 py-2.5 text-sm font-semibold text-[#374151] hover:bg-[#F9FAFB]"
                        :disabled="deciding"
                        @click="confirmDecision = null"
                    >
                        Annuler
                    </button>
                </div>
            </div>
        </div>

        <!-- Succès (image 2) -->
        <div
            v-if="showSuccessModal"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/45 p-4"
            @click.self="showSuccessModal = false"
        >
            <div class="w-full max-w-sm rounded-2xl bg-white px-6 py-8 text-center shadow-xl">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full border-2 border-[#86EFAC] bg-[#ECFDF5]">
                    <Check class="h-8 w-8 text-[#059669]" />
                </div>
                <p class="mt-5 text-base font-semibold text-[#1F2937]">Mise à jour effectuée avec succes</p>
                <button
                    type="button"
                    class="mt-6 rounded-lg bg-[#10B981] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#059669]"
                    @click="showSuccessModal = false"
                >
                    Ok,compris!
                </button>
            </div>
        </div>
    </PointageLayout>
</template>
