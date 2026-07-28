<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { Clock, FileSpreadsheet } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    config: {
        heure_arrivee: string;
        heure_depart: string;
        heure_arrivee_ajustee: string;
        heure_depart_ajustee: string;
        plage_arrivee_debut: string;
        plage_arrivee_fin: string;
        plage_depart_debut: string;
        plage_depart_fin: string;
        tolerance_minutes: number;
        base_heures_jour_reference: number;
        seuil_heures_supplementaires_h_jour: number;
        delai_validation_manager_heures: number;
        relances_automatiques_apres_heures: number;
        employe_penalty_retard_fcfa: number;
        penalite_absence_injustifiee_fcfa_jour: number;
        majoration_heures_sup_pct: number;
        mode_export_sage_paie: string;
        declaration_motifs_autorises: Record<string, boolean>;
    };
    mode_export_options: { value: string; label: string }[];
    motif_labels: Record<string, string>;
    export_employes: { id: number; label: string }[];
    export_mois_defaut: string;
}>();

const exportMois = ref(props.export_mois_defaut);
const exportUserId = ref<string>('tous');

const exportFicheUrl = computed(() => {
    const params = new URLSearchParams({ mois: exportMois.value });
    if (exportUserId.value !== 'tous') {
        params.set('user_id', exportUserId.value);
    }
    return `/pointage/rh/parametrage/export-fiche?${params.toString()}`;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Configuration', href: '#' },
    { title: 'Gestion des Horaires', href: '#' },
];

const motifKeys = computed(() => Object.keys(props.motif_labels));

const form = useForm({
    heure_arrivee: props.config.heure_arrivee,
    heure_depart: props.config.heure_depart,
    heure_arrivee_ajustee: props.config.heure_arrivee_ajustee,
    heure_depart_ajustee: props.config.heure_depart_ajustee,
    plage_arrivee_debut: props.config.plage_arrivee_debut,
    plage_arrivee_fin: props.config.plage_arrivee_fin,
    plage_depart_debut: props.config.plage_depart_debut,
    plage_depart_fin: props.config.plage_depart_fin,
    tolerance_minutes: props.config.tolerance_minutes,
    seuil_heures_supplementaires_h_jour: props.config.seuil_heures_supplementaires_h_jour,
    delai_validation_manager_heures: props.config.delai_validation_manager_heures,
    relances_automatiques_apres_heures: props.config.relances_automatiques_apres_heures,
    employe_penalty_retard_fcfa: props.config.employe_penalty_retard_fcfa,
    penalite_absence_injustifiee_fcfa_jour: props.config.penalite_absence_injustifiee_fcfa_jour,
    majoration_heures_sup_pct: props.config.majoration_heures_sup_pct,
    mode_export_sage_paie: props.config.mode_export_sage_paie,
    declaration_motifs_autorises: { ...props.config.declaration_motifs_autorises },
});

const plagesForm = useForm({
    plage_arrivee_debut: props.config.plage_arrivee_debut,
    plage_arrivee_fin: props.config.plage_arrivee_fin,
    plage_depart_debut: props.config.plage_depart_debut,
    plage_depart_fin: props.config.plage_depart_fin,
});

function motifModel(key: string): boolean {
    return !!form.declaration_motifs_autorises[key];
}

function setMotif(key: string, value: boolean) {
    form.declaration_motifs_autorises[key] = value;
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            plage_arrivee_debut: plagesForm.plage_arrivee_debut,
            plage_arrivee_fin: plagesForm.plage_arrivee_fin,
            plage_depart_debut: plagesForm.plage_depart_debut,
            plage_depart_fin: plagesForm.plage_depart_fin,
        }))
        .post('/pointage/rh/parametrage', { preserveScroll: true });
}

function submitPlages() {
    plagesForm.post('/pointage/rh/parametrage/plages', { preserveScroll: true });
}
</script>

<template>
    <PointageLayout title="Gestion des Horaires" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-[#0C447C]">Gestion des Horaires</h1>
                </div>
                <button
                    type="submit"
                    form="rh-parametrage-form"
                    class="inline-flex items-center justify-center rounded-md bg-[#185FA5] px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#144a84] disabled:opacity-60"
                    :disabled="form.processing"
                >
                    Sauvegarder tout
                </button>
            </div>

            <form id="rh-parametrage-form" class="grid gap-6 lg:grid-cols-2" @submit.prevent="submit">
                <!-- Horaires -->
                <div id="gestion-horaires" class="rounded-[10px] border border-[#e2e0d8] bg-white p-5 shadow-sm">
                    <h2 class="border-b border-[#e2e0d8] pb-3 text-sm font-semibold text-[#0C447C]">Horaires prévus &amp; ajustés</h2>
                    <div class="mt-4 space-y-4">
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="heure-arrivee">
                                Heure d'arrivée prévue
                            </label>
                            <div class="relative">
                                <Clock class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#888780]" aria-hidden="true" />
                                <input
                                    id="heure-arrivee"
                                    v-model="form.heure_arrivee"
                                    type="time"
                                    step="60"
                                    class="w-full rounded-md border border-[#e2e0d8] py-2 pl-10 pr-3 text-sm text-[#0C447C]"
                                />
                            </div>
                            <p v-if="form.errors.heure_arrivee" class="mt-1 text-xs text-red-600">{{ form.errors.heure_arrivee }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="heure-depart">
                                Heure de départ prévue
                            </label>
                            <div class="relative">
                                <Clock class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#888780]" aria-hidden="true" />
                                <input
                                    id="heure-depart"
                                    v-model="form.heure_depart"
                                    type="time"
                                    step="60"
                                    class="w-full rounded-md border border-[#e2e0d8] py-2 pl-10 pr-3 text-sm text-[#0C447C]"
                                />
                            </div>
                            <p v-if="form.errors.heure_depart" class="mt-1 text-xs text-red-600">{{ form.errors.heure_depart }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="heure-arrivee-aj">
                                Heure d'arrivée ajustée (H. ajust arrivée)
                            </label>
                            <div class="relative">
                                <Clock class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#888780]" aria-hidden="true" />
                                <input
                                    id="heure-arrivee-aj"
                                    v-model="form.heure_arrivee_ajustee"
                                    type="time"
                                    step="60"
                                    class="w-full rounded-md border border-[#e2e0d8] py-2 pl-10 pr-3 text-sm text-[#0C447C]"
                                />
                            </div>
                            <p v-if="form.errors.heure_arrivee_ajustee" class="mt-1 text-xs text-red-600">{{ form.errors.heure_arrivee_ajustee }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="heure-depart-aj">
                                Heure de départ ajustée
                            </label>
                            <div class="relative">
                                <Clock class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#888780]" aria-hidden="true" />
                                <input
                                    id="heure-depart-aj"
                                    v-model="form.heure_depart_ajustee"
                                    type="time"
                                    step="60"
                                    class="w-full rounded-md border border-[#e2e0d8] py-2 pl-10 pr-3 text-sm text-[#0C447C]"
                                />
                            </div>
                            <p v-if="form.errors.heure_depart_ajustee" class="mt-1 text-xs text-red-600">{{ form.errors.heure_depart_ajustee }}</p>
                        </div>
                        <div class="rounded-md border border-dashed border-[#e2e0d8] bg-[#f8f7f4] p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wide text-[#888780]">Plages de pointage (scan)</p>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-md bg-[#185FA5] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#144a84] disabled:opacity-60"
                                    :disabled="plagesForm.processing"
                                    @click="submitPlages"
                                >
                                    {{ plagesForm.processing ? '…' : 'Enregistrer' }}
                                </button>
                            </div>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="plage-arr-debut">
                                        Arrivée — début
                                    </label>
                                    <input
                                        id="plage-arr-debut"
                                        v-model="plagesForm.plage_arrivee_debut"
                                        type="time"
                                        step="60"
                                        class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                                    />
                                    <p v-if="plagesForm.errors.plage_arrivee_debut" class="mt-1 text-xs text-red-600">
                                        {{ plagesForm.errors.plage_arrivee_debut }}
                                    </p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="plage-arr-fin">
                                        Arrivée — fin
                                    </label>
                                    <input
                                        id="plage-arr-fin"
                                        v-model="plagesForm.plage_arrivee_fin"
                                        type="time"
                                        step="60"
                                        class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                                    />
                                    <p v-if="plagesForm.errors.plage_arrivee_fin" class="mt-1 text-xs text-red-600">
                                        {{ plagesForm.errors.plage_arrivee_fin }}
                                    </p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="plage-dep-debut">
                                        Départ — début
                                    </label>
                                    <input
                                        id="plage-dep-debut"
                                        v-model="plagesForm.plage_depart_debut"
                                        type="time"
                                        step="60"
                                        class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                                    />
                                    <p v-if="plagesForm.errors.plage_depart_debut" class="mt-1 text-xs text-red-600">
                                        {{ plagesForm.errors.plage_depart_debut }}
                                    </p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="plage-dep-fin">
                                        Départ — fin
                                    </label>
                                    <input
                                        id="plage-dep-fin"
                                        v-model="plagesForm.plage_depart_fin"
                                        type="time"
                                        step="60"
                                        class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                                    />
                                    <p v-if="plagesForm.errors.plage_depart_fin" class="mt-1 text-xs text-red-600">
                                        {{ plagesForm.errors.plage_depart_fin }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="tolerance">Tolérance retard (minutes)</label>
                            <input
                                id="tolerance"
                                v-model.number="form.tolerance_minutes"
                                type="number"
                                min="0"
                                class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                            />
                            <p v-if="form.errors.tolerance_minutes" class="mt-1 text-xs text-red-600">{{ form.errors.tolerance_minutes }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="seuil-hs">
                                Seuil heures supplémentaires (h/jour)
                            </label>
                            <input
                                id="seuil-hs"
                                v-model.number="form.seuil_heures_supplementaires_h_jour"
                                type="number"
                                min="0"
                                step="0.5"
                                class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                            />
                            <p v-if="form.errors.seuil_heures_supplementaires_h_jour" class="mt-1 text-xs text-red-600">
                                {{ form.errors.seuil_heures_supplementaires_h_jour }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="delai-manager">
                                Délai de validation manager (heures)
                            </label>
                            <input
                                id="delai-manager"
                                v-model.number="form.delai_validation_manager_heures"
                                type="number"
                                min="1"
                                class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                            />
                            <p v-if="form.errors.delai_validation_manager_heures" class="mt-1 text-xs text-red-600">
                                {{ form.errors.delai_validation_manager_heures }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="relances">
                                Relances automatiques après (heures)
                            </label>
                            <input
                                id="relances"
                                v-model.number="form.relances_automatiques_apres_heures"
                                type="number"
                                min="1"
                                class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                            />
                            <p v-if="form.errors.relances_automatiques_apres_heures" class="mt-1 text-xs text-red-600">
                                {{ form.errors.relances_automatiques_apres_heures }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Pénalités -->
                <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-5 shadow-sm">
                    <h2 class="border-b border-[#e2e0d8] pb-3 text-sm font-semibold text-[#0C447C]">Pénalités & rémunération</h2>
                    <div class="mt-4 space-y-4">
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="pen-retard">
                                Pénalité retard (FCFA / retard)
                            </label>
                            <input
                                id="pen-retard"
                                v-model.number="form.employe_penalty_retard_fcfa"
                                type="number"
                                min="0"
                                step="100"
                                class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                            />
                            <p v-if="form.errors.employe_penalty_retard_fcfa" class="mt-1 text-xs text-red-600">{{ form.errors.employe_penalty_retard_fcfa }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="pen-abs">
                                Pénalité absence injustifiée (FCFA / jour)
                            </label>
                            <input
                                id="pen-abs"
                                v-model.number="form.penalite_absence_injustifiee_fcfa_jour"
                                type="number"
                                min="0"
                                step="100"
                                class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                            />
                            <p v-if="form.errors.penalite_absence_injustifiee_fcfa_jour" class="mt-1 text-xs text-red-600">
                                {{ form.errors.penalite_absence_injustifiee_fcfa_jour }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="maj-hs">
                                Majoration heures sup. (%)
                            </label>
                            <input
                                id="maj-hs"
                                v-model.number="form.majoration_heures_sup_pct"
                                type="number"
                                min="0"
                                max="200"
                                class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                            />
                            <p v-if="form.errors.majoration_heures_sup_pct" class="mt-1 text-xs text-red-600">{{ form.errors.majoration_heures_sup_pct }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="mode-export">Mode export Sage Paie</label>
                            <select
                                id="mode-export"
                                v-model="form.mode_export_sage_paie"
                                class="w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                            >
                                <option v-for="o in mode_export_options" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                            <p v-if="form.errors.mode_export_sage_paie" class="mt-1 text-xs text-red-600">{{ form.errors.mode_export_sage_paie }}</p>
                        </div>
                        <div>
                            <p class="mb-2 text-[10px] font-bold uppercase tracking-wide text-[#888780]">Motifs de déclaration autorisés</p>
                            <ul class="space-y-2">
                                <li v-for="key in motifKeys" :key="key" class="flex items-center gap-2 text-sm text-[#0C447C]">
                                    <input
                                        :id="'motif-' + key"
                                        type="checkbox"
                                        class="rounded border-[#e2e0d8]"
                                        :checked="motifModel(key)"
                                        @change="setMotif(key, ($event.target as HTMLInputElement).checked)"
                                    />
                                    <label :for="'motif-' + key">{{ motif_labels[key] }}</label>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </form>

            <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-5 shadow-sm">
                <h2 class="border-b border-[#e2e0d8] pb-3 text-sm font-semibold text-[#0C447C]">Export fiche horaires (Excel)</h2>
                <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end">
                    <div class="min-w-[10rem] flex-1">
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="export-mois">Mois</label>
                        <input
                            id="export-mois"
                            v-model="exportMois"
                            type="month"
                            class="w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                        />
                    </div>
                    <div class="min-w-[12rem] flex-[2]">
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#888780]" for="export-employe">Employé</label>
                        <select
                            id="export-employe"
                            v-model="exportUserId"
                            class="w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        >
                            <option value="tous">Tous les employés (avec pointages)</option>
                            <option v-for="e in export_employes" :key="e.id" :value="String(e.id)">{{ e.label }}</option>
                        </select>
                    </div>
                    <a
                        :href="exportFicheUrl"
                        class="inline-flex items-center justify-center gap-2 rounded-md border border-[#185FA5] bg-white px-5 py-2.5 text-sm font-semibold text-[#185FA5] shadow-sm hover:bg-[#f0f6fc]"
                    >
                        <FileSpreadsheet class="h-4 w-4" aria-hidden="true" />
                        Télécharger la fiche Excel
                    </a>
                </div>
            </div>
        </div>
    </PointageLayout>
</template>
