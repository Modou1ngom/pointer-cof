<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Eye, History, Pencil, Trash2 } from 'lucide-vue-next';
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
    date_fin?: string | null;
    date_fin_display?: string | null;
    heure_debut?: string | null;
    heure_fin?: string | null;
    lieu?: string | null;
    motif: string;
    commentaire?: string | null;
    statut: string;
    statut_label: string;
    has_justificatif?: boolean;
    justificatif_filename?: string | null;
    user?: { id?: number; name: string; email: string } | null;
    manager_user?: { name: string } | null;
    rh_user?: { name: string } | null;
    manager_comment?: string | null;
    rh_comment?: string | null;
    manager_decided_at?: string | null;
    rh_decided_at?: string | null;
    created_at_display?: string | null;
    processus: ProcessStep[];
}

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
    filters: { type: string; statut: string; q: string };
    types: { value: string; label: string }[];
    counts: Record<string, number>;
    can_manage: boolean;
    can_validate_rh: boolean;
}>();

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
});

watch(
    () => props.filters,
    (f) => {
        localFilters.type = f.type || 'tous';
        localFilters.statut = f.statut || 'tous';
        localFilters.q = f.q || '';
    },
    { deep: true },
);

function listUrl(overrides: Record<string, string> = {}): string {
    const merged: Record<string, string> = {
        mois: props.periode_mois,
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

function statutBadgeClass(statut: string): string {
    if (statut === 'valide') return 'bg-[#EAF3DE] text-[#3B6D11]';
    if (statut === 'rejete') return 'bg-[#FCEBEB] text-[#A32D2D]';
    if (statut === 'en_attente_rh') return 'bg-[#E6F1FB] text-[#185FA5]';
    return 'bg-[#FFF4E6] text-[#C2410C]';
}

const viewDecl = ref<Decl | null>(null);
const editDecl = ref<Decl | null>(null);
const showHistorique = ref(false);

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

function decideRh(id: number, accept: boolean) {
    router.post(`/pointage/declarations/${id}/decision-rh`, { accept, comment: '' }, { preserveScroll: true });
}

function onEditFile(e: Event) {
    const t = e.target as HTMLInputElement;
    editForm.justificatif = t.files?.[0] ?? null;
}
</script>

<template>
    <PointageLayout title="Demande" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-xl font-semibold text-[#0C447C]">Demande</h1>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md border border-[#e2e0d8] bg-white px-3 py-1.5 text-sm font-medium text-[#0C447C] hover:bg-[#FAFAF8]"
                            @click="showHistorique = true"
                        >
                            <History class="h-4 w-4" />
                            Historique
                        </button>
                    </div>
                    <p class="mt-1 text-sm text-[#888780]">
                        Suivi et validation RH des déclarations (mission, absence, permission, congé, formation…).
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

            <div class="grid gap-3 sm:grid-cols-4">
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white px-4 py-3">
                    <div class="text-[10px] font-bold uppercase text-[#888780]">En attente de validation N+1</div>
                    <div class="text-lg font-semibold text-[#C2410C]">{{ counts.en_attente_manager ?? 0 }}</div>
                </div>
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white px-4 py-3">
                    <div class="text-[10px] font-bold uppercase text-[#888780]">En attente de validation RH</div>
                    <div class="text-lg font-semibold text-[#185FA5]">{{ counts.en_attente_rh ?? 0 }}</div>
                </div>
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white px-4 py-3">
                    <div class="text-[10px] font-bold uppercase text-[#888780]">Validées</div>
                    <div class="text-lg font-semibold text-[#3B6D11]">{{ counts.valide ?? 0 }}</div>
                </div>
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white px-4 py-3">
                    <div class="text-[10px] font-bold uppercase text-[#888780]">Rejetées</div>
                    <div class="text-lg font-semibold text-[#A32D2D]">{{ counts.rejete ?? 0 }}</div>
                </div>
            </div>

            <form class="flex flex-wrap items-end gap-3 rounded-[10px] border border-[#e2e0d8] bg-white p-4" @submit.prevent="applyFilters">
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
                <div>
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

            <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-sm">
                        <thead class="bg-[#FAFAF8] text-left text-[10px] font-bold uppercase text-[#888780]">
                            <tr>
                                <th class="px-4 py-3">Employé</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Période</th>
                                <th class="px-4 py-3">Processus</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in declarations.data" :key="d.id" class="border-t border-[#F1EFE8]">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-[#0C447C]">{{ d.user?.name }}</div>
                                    <div class="text-xs text-[#888780]">{{ d.user?.email }}</div>
                                    <div class="text-xs text-[#888780]">{{ d.created_at_display }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div>{{ d.type_label }}</div>
                                    <div class="line-clamp-1 text-xs text-[#888780]" :title="d.motif">{{ d.motif }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ periode(d) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="step in d.processus"
                                            :key="step.key"
                                            class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="
                                                step.current
                                                    ? 'bg-[#185FA5] text-white'
                                                    : step.done
                                                      ? 'bg-[#EAF3DE] text-[#3B6D11]'
                                                      : 'bg-[#F1EFE8] text-[#888780]'
                                            "
                                        >
                                            {{ step.label }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statutBadgeClass(d.statut)">
                                        {{ d.statut_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <button type="button" class="inline-flex items-center gap-1 rounded border border-[#e2e0d8] px-2 py-1 text-xs hover:bg-[#FAFAF8]" @click="openView(d)">
                                            <Eye class="h-3.5 w-3.5" /> Voir
                                        </button>
                                        <template v-if="can_validate_rh && d.statut === 'en_attente_rh'">
                                            <button type="button" class="rounded bg-[#EAF3DE] px-2 py-1 text-xs text-[#3B6D11]" @click="decideRh(d.id, true)">
                                                Valider
                                            </button>
                                            <button type="button" class="rounded bg-[#FCEBEB] px-2 py-1 text-xs text-[#A32D2D]" @click="decideRh(d.id, false)">
                                                Rejeter
                                            </button>
                                        </template>
                                        <template v-if="can_manage">
                                            <button type="button" class="inline-flex items-center gap-1 rounded border border-[#e2e0d8] px-2 py-1 text-xs text-[#185FA5] hover:bg-[#E6F1FB]" @click="openEdit(d)">
                                                <Pencil class="h-3.5 w-3.5" /> Modifier
                                            </button>
                                            <button type="button" class="inline-flex items-center gap-1 rounded border border-[#FCEBEB] px-2 py-1 text-xs text-[#A32D2D] hover:bg-[#FCEBEB]" @click="destroyDecl(d)">
                                                <Trash2 class="h-3.5 w-3.5" /> Suppr.
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!declarations.data?.length">
                                <td colspan="6" class="px-4 py-10 text-center text-[#888780]">Aucune déclaration pour ces filtres.</td>
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
                            :class="link.active ? 'bg-[#185FA5] font-semibold text-white' : 'border border-[#e2e0d8] text-[#0C447C]'"
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span v-else class="min-w-[2.25rem] px-2 py-1 text-center text-xs text-[#ccc]" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>

        <!-- Historique des validations -->
        <div v-if="showHistorique" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showHistorique = false">
            <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-[10px] bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-[#e2e0d8] px-5 py-4">
                    <h2 class="text-lg font-semibold text-[#0C447C]">Historique des validations</h2>
                    <button type="button" class="rounded-md border border-[#e2e0d8] px-3 py-1.5 text-sm" @click="showHistorique = false">Fermer</button>
                </div>
                <div class="overflow-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="sticky top-0 bg-[#FAFAF8] text-left text-[10px] font-bold uppercase text-[#888780]">
                            <tr>
                                <th class="px-4 py-3">Employé</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Période</th>
                                <th class="px-4 py-3">N+1</th>
                                <th class="px-4 py-3">RH</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in historique" :key="'h-' + d.id" class="border-t border-[#F1EFE8]">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-[#0C447C]">{{ d.user?.name }}</div>
                                    <div class="text-xs text-[#888780]">{{ d.created_at_display }}</div>
                                </td>
                                <td class="px-4 py-3">{{ d.type_label }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ periode(d) }}</td>
                                <td class="px-4 py-3">
                                    <div>{{ d.manager_user?.name || '—' }}</div>
                                    <div v-if="d.manager_decided_at" class="text-xs text-[#888780]">{{ d.manager_decided_at }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div>{{ d.rh_user?.name || '—' }}</div>
                                    <div v-if="d.rh_decided_at" class="text-xs text-[#888780]">{{ d.rh_decided_at }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statutBadgeClass(d.statut)">
                                        {{ d.statut_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <button type="button" class="inline-flex items-center gap-1 rounded border border-[#e2e0d8] px-2 py-1 text-xs hover:bg-[#FAFAF8]" @click="openView(d)">
                                        <Eye class="h-3.5 w-3.5" /> Voir
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!historique?.length">
                                <td colspan="7" class="px-4 py-10 text-center text-[#888780]">Aucun historique de validation.</td>
                            </tr>
                        </tbody>
                    </table>
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
                    <div v-if="viewDecl.lieu"><dt class="text-[11px] font-bold uppercase text-[#888780]">Lieu</dt><dd>{{ viewDecl.lieu }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Motif</dt><dd>{{ viewDecl.motif }}</dd></div>
                    <div v-if="viewDecl.commentaire"><dt class="text-[11px] font-bold uppercase text-[#888780]">Commentaire</dt><dd>{{ viewDecl.commentaire }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Statut</dt><dd>{{ viewDecl.statut_label }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">N+1</dt><dd>{{ viewDecl.manager_user?.name || '—' }} <span v-if="viewDecl.manager_decided_at" class="text-[#888780]">({{ viewDecl.manager_decided_at }})</span></dd></div>
                    <div v-if="viewDecl.manager_comment"><dt class="text-[11px] font-bold uppercase text-[#888780]">Commentaire N+1</dt><dd>{{ viewDecl.manager_comment }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">RH</dt><dd>{{ viewDecl.rh_user?.name || '—' }} <span v-if="viewDecl.rh_decided_at" class="text-[#888780]">({{ viewDecl.rh_decided_at }})</span></dd></div>
                    <div v-if="viewDecl.rh_comment"><dt class="text-[11px] font-bold uppercase text-[#888780]">Commentaire RH</dt><dd>{{ viewDecl.rh_comment }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-[#888780]">Justificatif</dt><dd>{{ viewDecl.has_justificatif ? (viewDecl.justificatif_filename || 'Oui') : 'Non' }}</dd></div>
                </dl>
                <div class="mt-5 flex justify-end">
                    <button type="button" class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm" @click="viewDecl = null">Fermer</button>
                </div>
            </div>
        </div>

        <!-- Modifier (superadmin) -->
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
    </PointageLayout>
</template>
