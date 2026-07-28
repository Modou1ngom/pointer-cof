<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

type Ferie = {
    id: number;
    libelle: string;
    date_unique: string;
    date_fin: string | null;
    recurrence_annuelle: boolean;
    pays_region: string | null;
    country_code: string | null;
    departement_id: number | null;
    type: string;
    source: string;
    travaille_avec_majoration: boolean;
    taux_majoration_pct: string | number;
    notes: string | null;
};

type PreviewItem = {
    date: string;
    libelle: string;
    country_code: string;
    already_imported: boolean;
    selected: boolean;
};

const props = defineProps<{
    feries: Ferie[];
    types: { value: string; label: string }[];
    pays_disponibles: { code: string; label: string }[];
    departements: { id: number; nom: string }[];
    filters: { country_code: string; departement_id: number | null; import_year: number };
    import_pref: { country_code: string; auto_importer_annuel: boolean } | null;
}>();

const page = usePage<{ flash?: { success?: string; error?: string } }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Configuration', href: '#' },
    { title: 'Jours fériés', href: '#' },
];

const flashOk = computed(() => page.props.flash?.success);
const flashErr = computed(() => page.props.flash?.error);

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function applyListFilters() {
    router.get(
        '/pointage/rh/presence/jours-feries',
        {
            country_code: listFilter.country_code,
            departement_id: listFilter.departement_id || undefined,
            import_year: importYear.value,
        },
        { preserveScroll: true, replace: true },
    );
}

const listFilter = reactive({
    country_code: props.filters.country_code,
    departement_id: props.filters.departement_id ?? ('' as number | ''),
});

const importCountry = ref(props.filters.country_code || 'SN');
const importYear = ref(props.filters.import_year);
const autoImportAnnuel = ref(props.import_pref?.auto_importer_annuel ?? false);
const previewItems = ref<PreviewItem[]>([]);
const previewLoading = ref(false);
const previewError = ref<string | null>(null);
const showPreview = ref(false);

async function fetchPreview() {
    previewLoading.value = true;
    previewError.value = null;
    previewItems.value = [];
    try {
        const res = await fetch('/pointage/rh/presence/jours-feries/nager/preview', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ year: importYear.value, country_code: importCountry.value }),
        });
        const data = (await res.json()) as { ok?: boolean; message?: string; items?: PreviewItem[] };
        if (!res.ok || !data.ok) {
            previewError.value = data.message ?? 'Impossible de récupérer les jours fériés.';
            showPreview.value = false;
            return;
        }
        previewItems.value = data.items ?? [];
        showPreview.value = true;
    } catch {
        previewError.value = 'Réseau indisponible. Utilisez la saisie manuelle.';
    } finally {
        previewLoading.value = false;
    }
}

const confirmImportForm = useForm({
    year: importYear.value,
    country_code: importCountry.value,
    items: [] as { date: string; libelle: string }[],
    auto_importer_annuel: false,
});

function submitConfirmImport() {
    const selected = previewItems.value.filter((i) => i.selected && !i.already_imported);
    if (!selected.length) {
        previewError.value = 'Sélectionnez au moins un jour férié à importer.';
        return;
    }
    confirmImportForm.year = importYear.value;
    confirmImportForm.country_code = importCountry.value;
    confirmImportForm.items = selected.map((i) => ({ date: i.date, libelle: i.libelle }));
    confirmImportForm.auto_importer_annuel = autoImportAnnuel.value;
    confirmImportForm.post('/pointage/rh/presence/jours-feries/nager/confirm', {
        preserveScroll: true,
        onSuccess: () => {
            showPreview.value = false;
            previewItems.value = [];
        },
    });
}

const createForm = useForm({
    libelle: '',
    date_unique: '',
    date_fin: '',
    recurrence_annuelle: false,
    pays_region: '',
    departement_id: '' as number | '',
    country_code: '',
    type: 'national',
    travaille_avec_majoration: false,
    taux_majoration_pct: 50,
    notes: '',
});

function submitCreate() {
    createForm.post('/pointage/rh/presence/jours-feries', {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
}

const editId = ref<number | null>(null);
const cloneId = ref<number | null>(null);
const cloneDeptIds = ref<number[]>([]);

const editForm = reactive({
    libelle: '',
    date_unique: '',
    date_fin: '',
    recurrence_annuelle: false,
    pays_region: '',
    departement_id: '' as number | '',
    country_code: '',
    type: 'national',
    travaille_avec_majoration: false,
    taux_majoration_pct: 0,
    notes: '',
});

function openEdit(f: Ferie) {
    editId.value = f.id;
    editForm.libelle = f.libelle;
    editForm.date_unique = f.date_unique?.slice(0, 10) ?? '';
    editForm.date_fin = f.date_fin ? f.date_fin.slice(0, 10) : '';
    editForm.recurrence_annuelle = Boolean(f.recurrence_annuelle);
    editForm.pays_region = f.pays_region ?? '';
    editForm.departement_id = f.departement_id ?? '';
    editForm.country_code = f.country_code ?? '';
    editForm.type = f.type;
    editForm.travaille_avec_majoration = Boolean(f.travaille_avec_majoration);
    editForm.taux_majoration_pct = Number(f.taux_majoration_pct ?? 0);
    editForm.notes = f.notes ?? '';
}

function submitEdit() {
    if (editId.value === null) return;
    router.put(`/pointage/rh/presence/jours-feries/${editId.value}`, { ...editForm }, { preserveScroll: true, onSuccess: () => (editId.value = null) });
}

function openClone(f: Ferie) {
    cloneId.value = f.id;
    cloneDeptIds.value = [];
}

function submitClone() {
    if (cloneId.value === null || !cloneDeptIds.value.length) return;
    router.post(
        `/pointage/rh/presence/jours-feries/${cloneId.value}/clone`,
        { departement_ids: cloneDeptIds.value },
        { preserveScroll: true, onSuccess: () => (cloneId.value = null) },
    );
}

function destroy(id: number) {
    if (!confirm('Supprimer ce jour férié ?')) return;
    router.delete(`/pointage/rh/presence/jours-feries/${id}`, { preserveScroll: true });
}

function sourceLabel(s: string) {
    return s === 'official' ? 'Officiel' : 'Manuel';
}

function toggleDeptClone(id: number, checked: boolean) {
    if (checked) {
        if (!cloneDeptIds.value.includes(id)) cloneDeptIds.value.push(id);
    } else {
        cloneDeptIds.value = cloneDeptIds.value.filter((x) => x !== id);
    }
}
</script>

<template>
    <PointageLayout title="Gestion des jours fériés" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold text-[#0C447C]">Jours fériés</h1>
                </div>
                <Link href="/pointage/rh/presence/jours-feries-calendrier" class="text-sm font-semibold text-[#185FA5] underline">Vue calendrier</Link>
            </div>

            <p v-if="flashOk" class="rounded-lg border border-[#C0DD97] bg-[#EAF3DE] px-3 py-2 text-sm text-[#27500A]">{{ flashOk }}</p>
            <p v-if="flashErr" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ flashErr }}</p>

            <!-- PARTIE 1 — Import Nager -->
            <section class="rounded-xl border border-[#e2e0d8] bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold text-[#0C447C]">Import automatique (API officielle)</h2>
                <div class="mt-4 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Pays</label>
                        <select v-model="importCountry" class="mt-1 block rounded border border-[#e2e0d8] px-2 py-1.5 text-sm">
                            <option v-for="p in pays_disponibles" :key="p.code" :value="p.code">{{ p.label }} ({{ p.code }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Année</label>
                        <input v-model.number="importYear" type="number" min="2000" max="2100" class="mt-1 block w-24 rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                    </div>
                    <button
                        type="button"
                        class="rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#144a84] disabled:opacity-50"
                        :disabled="previewLoading"
                        @click="fetchPreview"
                    >
                        {{ previewLoading ? 'Chargement…' : 'Importer les jours fériés officiels' }}
                    </button>
                    <label class="flex items-center gap-2 text-sm text-[#0C447C]">
                        <input v-model="autoImportAnnuel" type="checkbox" class="rounded border-[#e2e0d8]" />
                        Importer automatiquement chaque année
                    </label>
                </div>
                <p v-if="previewError" class="mt-3 text-sm text-red-600">{{ previewError }}</p>

                <div v-if="showPreview && previewItems.length" class="mt-4 overflow-x-auto rounded-lg border border-[#e2e0d8]">
                    <table class="w-full min-w-[600px] text-sm">
                        <thead class="bg-[#FAFAF8] text-left text-[10px] font-bold uppercase text-[#888780]">
                            <tr>
                                <th class="px-3 py-2">Importer</th>
                                <th class="px-3 py-2">Date</th>
                                <th class="px-3 py-2">Libellé</th>
                                <th class="px-3 py-2">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, idx) in previewItems" :key="idx" class="border-t border-[#F1EFE8]">
                                <td class="px-3 py-2">
                                    <input v-model="item.selected" type="checkbox" :disabled="item.already_imported" class="rounded" />
                                </td>
                                <td class="px-3 py-2 tabular-nums">{{ item.date }}</td>
                                <td class="px-3 py-2">{{ item.libelle }}</td>
                                <td class="px-3 py-2 text-xs">
                                    <span v-if="item.already_imported" class="text-[#888780]">Déjà en base</span>
                                    <span v-else class="text-[#185FA5]">Nouveau</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="flex justify-end gap-2 border-t border-[#e2e0d8] p-3">
                        <button type="button" class="rounded border border-[#e2e0d8] px-3 py-1.5 text-sm" @click="showPreview = false">Annuler</button>
                        <button
                            type="button"
                            class="rounded bg-[#185FA5] px-4 py-1.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="confirmImportForm.processing"
                            @click="submitConfirmImport"
                        >
                            Confirmer l’import
                        </button>
                    </div>
                </div>
            </section>

            <!-- Filtres liste -->
            <section class="flex flex-wrap items-end gap-3 rounded-xl border border-[#e2e0d8] bg-[#FAFAF8] p-3">
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Filtrer pays</label>
                    <select v-model="listFilter.country_code" class="mt-1 block rounded border border-[#e2e0d8] px-2 py-1.5 text-sm">
                        <option value="all">Tous</option>
                        <option v-for="p in pays_disponibles" :key="'f-' + p.code" :value="p.code">{{ p.code }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Département</label>
                    <select v-model="listFilter.departement_id" class="mt-1 block min-w-[10rem] rounded border border-[#e2e0d8] px-2 py-1.5 text-sm">
                        <option value="">Tous</option>
                        <option v-for="d in departements" :key="d.id" :value="d.id">{{ d.nom }}</option>
                    </select>
                </div>
                <button type="button" class="rounded bg-[#0C447C] px-3 py-2 text-sm text-white" @click="applyListFilters">Appliquer</button>
            </section>

            <!-- PARTIE 2 — Saisie manuelle -->
            <form class="grid gap-3 rounded-xl border border-[#e2e0d8] bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="submitCreate">
                <h2 class="sm:col-span-2 lg:col-span-4 text-sm font-semibold text-[#0C447C]">Saisie manuelle</h2>
                <div class="sm:col-span-2">
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Nom du jour férié</label>
                    <input v-model="createForm.libelle" required class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Date début</label>
                    <input v-model="createForm.date_unique" type="date" required class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Date fin (optionnel)</label>
                    <input v-model="createForm.date_fin" type="date" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Type</label>
                    <select v-model="createForm.type" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm">
                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Pays / région</label>
                    <input v-model="createForm.pays_region" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Code pays ISO</label>
                    <input v-model="createForm.country_code" maxlength="3" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Département (scope)</label>
                    <select v-model="createForm.departement_id" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm">
                        <option value="">Tous / global</option>
                        <option v-for="d in departements" :key="'c-' + d.id" :value="d.id">{{ d.nom }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 pt-5">
                    <input id="rec-new" v-model="createForm.recurrence_annuelle" type="checkbox" class="rounded border-[#e2e0d8]" />
                    <label for="rec-new" class="text-sm text-[#0C447C]">Récurrence annuelle</label>
                </div>
                <div class="flex items-center gap-2 pt-5">
                    <input id="maj-new" v-model="createForm.travaille_avec_majoration" type="checkbox" class="rounded border-[#e2e0d8]" />
                    <label for="maj-new" class="text-sm text-[#0C447C]">Travaillé avec majoration</label>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Taux majoration (%)</label>
                    <input v-model.number="createForm.taux_majoration_pct" type="number" min="0" max="500" step="0.5" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Notes</label>
                    <input v-model="createForm.notes" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                </div>
                <div class="sm:col-span-2 lg:col-span-4 flex justify-end">
                    <button type="submit" class="rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white" :disabled="createForm.processing">Ajouter (Manuel)</button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-xl border border-[#e2e0d8] bg-white shadow-sm">
                <table class="w-full min-w-[1000px] text-sm">
                    <thead class="border-b border-[#e2e0d8] bg-[#FAFAF8] text-left text-[10px] font-bold uppercase text-[#888780]">
                        <tr>
                            <th class="px-3 py-2">Libellé</th>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Source</th>
                            <th class="px-3 py-2">Réc.</th>
                            <th class="px-3 py-2">Maj.</th>
                            <th class="px-3 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in feries" :key="f.id" class="border-b border-[#F1EFE8]">
                            <td class="px-3 py-2 font-medium text-[#0C447C]">{{ f.libelle }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ f.date_unique?.slice(0, 10) }}</td>
                            <td class="px-3 py-2">{{ f.type }}</td>
                            <td class="px-3 py-2">
                                <span
                                    class="rounded px-1.5 py-0.5 text-[10px] font-semibold"
                                    :class="f.source === 'official' ? 'bg-[#E6F1FB] text-[#185FA5]' : 'bg-[#F1EFE8] text-[#5c5a57]'"
                                >
                                    {{ sourceLabel(f.source) }}
                                </span>
                            </td>
                            <td class="px-3 py-2">{{ f.recurrence_annuelle ? 'Oui' : 'Non' }}</td>
                            <td class="px-3 py-2">{{ f.travaille_avec_majoration ? `${f.taux_majoration_pct}%` : '—' }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <button type="button" class="text-xs font-medium text-[#185FA5] underline" @click="openEdit(f)">Modifier</button>
                                <button type="button" class="ml-2 text-xs font-medium text-[#854F0B] underline" @click="openClone(f)">Cloner dépt.</button>
                                <button type="button" class="ml-2 text-xs font-medium text-red-600 underline" @click="destroy(f.id)">Supprimer</button>
                            </td>
                        </tr>
                        <tr v-if="!feries.length">
                            <td colspan="7" class="px-3 py-8 text-center text-[#888780]">Aucun jour férié pour ces filtres.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Modal édition -->
            <div v-if="editId !== null" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="editId = null">
                <form class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="submitEdit">
                    <h2 class="text-lg font-semibold text-[#0C447C]">Modifier le jour férié</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Libellé</label>
                            <input v-model="editForm.libelle" required class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Date</label>
                            <input v-model="editForm.date_unique" type="date" required class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Fin</label>
                            <input v-model="editForm.date_fin" type="date" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Type</label>
                            <select v-model="editForm.type" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm">
                                <option v-for="t in types" :key="'e-' + t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Code pays</label>
                            <input v-model="editForm.country_code" maxlength="3" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                        </div>
                        <div class="flex items-center gap-2 pt-5">
                            <input id="rec-ed" v-model="editForm.recurrence_annuelle" type="checkbox" class="rounded border-[#e2e0d8]" />
                            <label for="rec-ed" class="text-sm">Récurrence annuelle</label>
                        </div>
                        <div class="flex items-center gap-2 pt-5">
                            <input id="maj-ed" v-model="editForm.travaille_avec_majoration" type="checkbox" class="rounded border-[#e2e0d8]" />
                            <label for="maj-ed" class="text-sm">Travaillé majoré</label>
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Taux (%)</label>
                            <input v-model.number="editForm.taux_majoration_pct" type="number" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Notes</label>
                            <input v-model="editForm.notes" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1.5 text-sm" />
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" class="rounded border border-[#e2e0d8] px-4 py-2 text-sm" @click="editId = null">Annuler</button>
                        <button type="submit" class="rounded bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
                    </div>
                </form>
            </div>

            <!-- Modal clone -->
            <div v-if="cloneId !== null" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="cloneId = null">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                    <h2 class="text-lg font-semibold text-[#0C447C]">Cloner vers départements</h2>
                    <ul class="mt-4 max-h-48 space-y-2 overflow-y-auto">
                        <li v-for="d in departements" :key="'cl-' + d.id">
                            <label class="flex cursor-pointer items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    :checked="cloneDeptIds.includes(d.id)"
                                    class="rounded"
                                    @change="toggleDeptClone(d.id, ($event.target as HTMLInputElement).checked)"
                                />
                                {{ d.nom }}
                            </label>
                        </li>
                    </ul>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" class="rounded border border-[#e2e0d8] px-4 py-2 text-sm" @click="cloneId = null">Annuler</button>
                        <button type="button" class="rounded bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white" @click="submitClone">Cloner</button>
                    </div>
                </div>
            </div>
        </div>
    </PointageLayout>
</template>
