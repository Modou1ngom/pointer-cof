<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    Clock,
    FileSpreadsheet,
    FileText,
    Info,
    PencilLine,
    ScrollText,
    Shield,
    Timer,
    Users,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref } from 'vue';

type GestionUi = {
    pauses_active: boolean;
    pause_obligatoire: boolean;
    pause_duree: string;
    pause_minimum: string;
    pause_maximum: string;
    pause_deduire_auto: boolean;
    pause_controler_insuffisantes: boolean;
    methode_qr: boolean;
    methode_biometrie: boolean;
    methode_mobile: boolean;
    methode_badge: boolean;
    comptage_entree: boolean;
    comptage_sortie: boolean;
    comptage_pauses: boolean;
    autoriser_plusieurs_entrees_sorties: boolean;
    empecher_double_pointage: boolean;
    arrondi_minutes: number;
    secu_gps: boolean;
    secu_wifi: boolean;
    secu_appareils: boolean;
    secu_qr: boolean;
    secu_anti_capture: boolean;
    secu_enregistrer_appareil: boolean;
    rayon_gps_metres: number;
    qr_validite_secondes: number;
    tentatives_max: number;
    retard_a_partir: string;
    depart_anticipe_apres: string;
    absence_apres: string;
    calcul_retard_auto: boolean;
    calcul_depart_anticipe_auto: boolean;
    generer_anomalies: boolean;
    anomalie_entree_sans_sortie: boolean;
    anomalie_sortie_sans_entree: boolean;
    anomalie_double_pointage: boolean;
    anomalie_hors_plage: boolean;
    anomalie_retard_important: boolean;
    anomalie_depart_anticipe: boolean;
    anomalie_journee_incomplete: boolean;
    hs_calcul_auto: boolean;
    hs_autorisation_obligatoire: boolean;
    hs_validation_par: string;
    majoration_nuit_pct: number;
    majoration_dimanche_pct: number;
    majoration_ferie_pct: number;
    profil_horaire: string;
    jours_travailles: number[];
    jours_feries_mode: string;
    correction_employe: boolean;
    validation_manager: boolean;
    validation_rh: boolean;
    conserver_ancien_pointage: boolean;
    motif_obligatoire: boolean;
    justificatif_obligatoire: boolean;
};

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
        seuil_heures_supplementaires_h_semaine: number;
        delai_validation_manager_heures: number;
        relances_automatiques_apres_heures: number;
        employe_penalty_retard_fcfa: number;
        penalite_absence_injustifiee_fcfa_jour: number;
        majoration_heures_sup_pct: number;
        mode_export_sage_paie: string;
        declaration_motifs_autorises: Record<string, boolean>;
        gestion_ui: GestionUi;
    };
    mode_export_options: { value: string; label: string }[];
    motif_labels: Record<string, string>;
    export_employes: { id: number; label: string }[];
    export_mois_defaut: string;
    meta?: {
        updated_at_label?: string;
        updated_by_name?: string;
        geofencing_radius?: number;
        qr_ttl_seconds?: number;
    };
}>();

const page = usePage<{ auth?: { user?: { name?: string }; isAdmin?: boolean; isSuperAdmin?: boolean } }>();
const isAdmin = computed(() => Boolean(page.props.auth?.isAdmin || page.props.auth?.isSuperAdmin));

const exportMois = ref(props.export_mois_defaut);
const exportUserId = ref<string>('tous');
const activeTab = ref('horaires');

const tabs = [
    { id: 'horaires', label: 'Horaires', target: 'card-horaires' },
    { id: 'regles', label: 'Règles de pointage', target: 'card-regles' },
    { id: 'securite', label: 'Sécurité', target: 'card-securite' },
    { id: 'pauses', label: 'Pauses', target: 'card-pauses' },
    { id: 'retards', label: 'Retards & Absences', target: 'card-retards' },
    { id: 'hs', label: 'Heures supplémentaires', target: 'card-hs' },
    { id: 'feries', label: 'Jours fériés', target: 'card-calendrier' },
    { id: 'notifications', label: 'Notifications', target: 'footer-info' },
] as const;

const joursSemaine = [
    { v: 1, label: 'Lun' },
    { v: 2, label: 'Mar' },
    { v: 3, label: 'Mer' },
    { v: 4, label: 'Jeu' },
    { v: 5, label: 'Ven' },
    { v: 6, label: 'Sam' },
    { v: 0, label: 'Dim' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Configuration', href: '#' },
    { title: 'Gestion des Horaires', href: '#' },
];

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
    seuil_heures_supplementaires_h_semaine: props.config.seuil_heures_supplementaires_h_semaine,
    delai_validation_manager_heures: props.config.delai_validation_manager_heures,
    relances_automatiques_apres_heures: props.config.relances_automatiques_apres_heures,
    employe_penalty_retard_fcfa: props.config.employe_penalty_retard_fcfa,
    penalite_absence_injustifiee_fcfa_jour: props.config.penalite_absence_injustifiee_fcfa_jour,
    majoration_heures_sup_pct: props.config.majoration_heures_sup_pct,
    mode_export_sage_paie: props.config.mode_export_sage_paie,
    declaration_motifs_autorises: { ...props.config.declaration_motifs_autorises },
    gestion_ui: { ...props.config.gestion_ui, jours_travailles: [...props.config.gestion_ui.jours_travailles] },
});

const ui = computed(() => form.gestion_ui);

const plagesForm = useForm({
    plage_arrivee_debut: props.config.plage_arrivee_debut,
    plage_arrivee_fin: props.config.plage_arrivee_fin,
    plage_depart_debut: props.config.plage_depart_debut,
    plage_depart_fin: props.config.plage_depart_fin,
});

const exportFicheUrl = computed(() => {
    const params = new URLSearchParams({ mois: exportMois.value });
    if (exportUserId.value !== 'tous') {
        params.set('user_id', exportUserId.value);
    }
    return `/pointage/rh/parametrage/export-fiche?${params.toString()}`;
});

const lastUpdateLabel = computed(() => props.meta?.updated_at_label || '—');
const lastUpdateBy = computed(() => props.meta?.updated_by_name || page.props.auth?.user?.name || '—');

function scrollToTab(tabId: string, target: string) {
    activeTab.value = tabId;
    const el = document.getElementById(target);
    el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function toggleJour(day: number) {
    const list = [...form.gestion_ui.jours_travailles];
    const idx = list.indexOf(day);
    if (idx >= 0) {
        list.splice(idx, 1);
    } else {
        list.push(day);
    }
    form.gestion_ui.jours_travailles = list.sort((a, b) => a - b);
}

function isJourActif(day: number) {
    return form.gestion_ui.jours_travailles.includes(day);
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
    plagesForm.post('/pointage/rh/parametrage/plages', {
        preserveScroll: true,
        onSuccess: () => {
            form.plage_arrivee_debut = plagesForm.plage_arrivee_debut;
            form.plage_arrivee_fin = plagesForm.plage_arrivee_fin;
            form.plage_depart_debut = plagesForm.plage_depart_debut;
            form.plage_depart_fin = plagesForm.plage_depart_fin;
        },
    });
}

onMounted(async () => {
    await nextTick();
    if (window.location.hash === '#gestion-horaires') {
        document.getElementById('card-horaires')?.scrollIntoView({ behavior: 'smooth' });
    }
});
</script>

<template>
    <PointageLayout title="Gestion du Pointage & des Horaires" :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1400px] space-y-5">
            <!-- En-tête -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-[#0B1F33]">Gestion du Pointage & des Horaires</h1>
                    <p class="mt-1 text-sm text-slate-500">
                        Configurez les règles de pointage, les horaires de travail et les paramètres de calcul.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="isAdmin"
                        href="/pointage/admin/logs"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                    >
                        <ScrollText class="h-4 w-4 text-[#C8102E]" />
                        Journal d'audit
                    </Link>
                </div>
            </div>

            <!-- Onglets + Enregistrer -->
            <div class="sticky top-0 z-20 -mx-1 border-b border-slate-200 bg-[#F1F5F9]/80 px-1 backdrop-blur">
                <div class="flex flex-col gap-3 py-2 lg:flex-row lg:items-center lg:justify-between">
                    <nav class="flex gap-1 overflow-x-auto pb-1 scrollbar-thin" aria-label="Sections">
                        <button
                            v-for="tab in tabs"
                            :key="tab.id"
                            type="button"
                            class="shrink-0 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors"
                            :class="
                                activeTab === tab.id
                                    ? 'border-[#C8102E] text-[#C8102E]'
                                    : 'border-transparent text-slate-500 hover:text-slate-800'
                            "
                            @click="scrollToTab(tab.id, tab.target)"
                        >
                            {{ tab.label }}
                        </button>
                    </nav>
                    <button
                        type="submit"
                        form="rh-parametrage-form"
                        class="inline-flex shrink-0 items-center justify-center rounded-lg bg-[#C8102E] px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#a50d25] disabled:opacity-60"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Enregistrement…' : 'Enregistrer tout' }}
                    </button>
                </div>
            </div>

            <form id="rh-parametrage-form" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="submit">
                <!-- 1. Horaires de Travail -->
                <section
                    id="card-horaires"
                    class="scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#C8102E]/10 text-xs font-bold text-[#C8102E]">1</span>
                        <Clock class="h-4 w-4 text-[#C8102E]" />
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Horaires de Travail</h2>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500" for="heure-arrivee">Heure d'arrivée prévue</label>
                            <input id="heure-arrivee" v-model="form.heure_arrivee" type="time" step="60" class="field-input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500" for="heure-depart">Heure de départ prévue</label>
                            <input id="heure-depart" v-model="form.heure_depart" type="time" step="60" class="field-input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500" for="heure-arrivee-aj">Heure d'arrivée ajustée</label>
                            <input id="heure-arrivee-aj" v-model="form.heure_arrivee_ajustee" type="time" step="60" class="field-input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500" for="heure-depart-aj">Heure de départ ajustée</label>
                            <input id="heure-depart-aj" v-model="form.heure_depart_ajustee" type="time" step="60" class="field-input" />
                        </div>
                    </div>

                    <div class="mt-4 rounded-lg border border-dashed border-slate-200 bg-slate-50 p-3">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Plages de Pointage (Scan)</p>
                            <button
                                type="button"
                                class="shrink-0 rounded-md bg-[#C8102E] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#a50d25] disabled:opacity-60"
                                :disabled="plagesForm.processing"
                                @click="submitPlages"
                            >
                                {{ plagesForm.processing ? '…' : 'Enregistrer' }}
                            </button>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Arrivée — début</label>
                                <input v-model="plagesForm.plage_arrivee_debut" type="time" step="60" class="field-input" />
                                <p v-if="plagesForm.errors.plage_arrivee_debut" class="mt-1 text-xs text-red-600">
                                    {{ plagesForm.errors.plage_arrivee_debut }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Arrivée — fin</label>
                                <input v-model="plagesForm.plage_arrivee_fin" type="time" step="60" class="field-input" />
                                <p v-if="plagesForm.errors.plage_arrivee_fin" class="mt-1 text-xs text-red-600">
                                    {{ plagesForm.errors.plage_arrivee_fin }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Départ — début</label>
                                <input v-model="plagesForm.plage_depart_debut" type="time" step="60" class="field-input" />
                                <p v-if="plagesForm.errors.plage_depart_debut" class="mt-1 text-xs text-red-600">
                                    {{ plagesForm.errors.plage_depart_debut }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Départ — fin</label>
                                <input v-model="plagesForm.plage_depart_fin" type="time" step="60" class="field-input" />
                                <p v-if="plagesForm.errors.plage_depart_fin" class="mt-1 text-xs text-red-600">
                                    {{ plagesForm.errors.plage_depart_fin }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Tolérance retard (minutes)</label>
                            <input v-model.number="form.tolerance_minutes" type="number" min="0" class="field-input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Seuil HS manager (h/jour)</label>
                            <input v-model.number="form.seuil_heures_supplementaires_h_jour" type="number" min="0" step="0.5" class="field-input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Relances après (heures)</label>
                            <input v-model.number="form.relances_automatiques_apres_heures" type="number" min="1" class="field-input" />
                        </div>
                    </div>
                </section>

                <!-- 2. Gestion des Pauses -->
                <section id="card-pauses" class="scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#C8102E]/10 text-xs font-bold text-[#C8102E]">2</span>
                        <Timer class="h-4 w-4 text-[#C8102E]" />
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Gestion des Pauses</h2>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Activer la gestion des pauses</span>
                            <button type="button" class="toggle" :class="{ on: ui.pauses_active }" @click="form.gestion_ui.pauses_active = !ui.pauses_active">
                                <span class="toggle-knob" />
                            </button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="flex items-center justify-between gap-3 sm:col-span-2">
                                <span class="text-sm text-slate-700">Pause obligatoire</span>
                                <button type="button" class="toggle" :class="{ on: ui.pause_obligatoire }" @click="form.gestion_ui.pause_obligatoire = !ui.pause_obligatoire">
                                    <span class="toggle-knob" />
                                </button>
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Durée de pause</label>
                                <input v-model="form.gestion_ui.pause_duree" type="time" step="60" class="field-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Pause minimum</label>
                                <input v-model="form.gestion_ui.pause_minimum" type="time" step="60" class="field-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Pause maximum</label>
                                <input v-model="form.gestion_ui.pause_maximum" type="time" step="60" class="field-input" />
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Déduire automatiquement la pause</span>
                            <button type="button" class="toggle" :class="{ on: ui.pause_deduire_auto }" @click="form.gestion_ui.pause_deduire_auto = !ui.pause_deduire_auto">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Contrôler les pauses insuffisantes</span>
                            <button type="button" class="toggle" :class="{ on: ui.pause_controler_insuffisantes }" @click="form.gestion_ui.pause_controler_insuffisantes = !ui.pause_controler_insuffisantes">
                                <span class="toggle-knob" />
                            </button>
                        </div>

                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                            Les pauses sont automatiquement déduites si elles s'inscrivent dans les heures de travail.
                        </div>

                        <Link href="/pointage/rh/presence/pauses/duree" class="inline-flex text-xs font-semibold text-[#C8102E] hover:underline">
                            Paramétrage détaillé des pauses →
                        </Link>
                    </div>
                </section>

                <!-- 3. Règles de Pointage -->
                <section id="card-regles" class="scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#C8102E]/10 text-xs font-bold text-[#C8102E]">3</span>
                        <CheckCircle2 class="h-4 w-4 text-[#C8102E]" />
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Règles de Pointage</h2>
                    </div>

                    <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-500">Méthodes autorisées</p>
                    <div class="mb-4 grid grid-cols-2 gap-2 text-sm">
                        <label class="check-row"><input v-model="form.gestion_ui.methode_qr" type="checkbox" class="accent-[#C8102E]" /> QR Code</label>
                        <label class="check-row"><input v-model="form.gestion_ui.methode_biometrie" type="checkbox" class="accent-[#C8102E]" /> Biométrie</label>
                        <label class="check-row"><input v-model="form.gestion_ui.methode_mobile" type="checkbox" class="accent-[#C8102E]" /> Application mobile</label>
                        <label class="check-row"><input v-model="form.gestion_ui.methode_badge" type="checkbox" class="accent-[#C8102E]" /> Badge</label>
                    </div>

                    <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-500">Pointages autorisés</p>
                    <div class="mb-4 flex flex-wrap gap-3 text-sm">
                        <label class="check-row"><input v-model="form.gestion_ui.comptage_entree" type="checkbox" class="accent-[#C8102E]" /> Entrée</label>
                        <label class="check-row"><input v-model="form.gestion_ui.comptage_sortie" type="checkbox" class="accent-[#C8102E]" /> Sortie</label>
                        <label class="check-row"><input v-model="form.gestion_ui.comptage_pauses" type="checkbox" class="accent-[#C8102E]" /> Pauses</label>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Autoriser plusieurs entrées/sorties</span>
                            <button type="button" class="toggle" :class="{ on: ui.autoriser_plusieurs_entrees_sorties }" @click="form.gestion_ui.autoriser_plusieurs_entrees_sorties = !ui.autoriser_plusieurs_entrees_sorties">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Empêcher un double pointage</span>
                            <button type="button" class="toggle" :class="{ on: ui.empecher_double_pointage }" @click="form.gestion_ui.empecher_double_pointage = !ui.empecher_double_pointage">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Arrondi du pointage</label>
                            <select v-model.number="form.gestion_ui.arrondi_minutes" class="field-input">
                                <option :value="0">Aucun</option>
                                <option :value="5">5 minutes</option>
                                <option :value="10">10 minutes</option>
                                <option :value="15">15 minutes</option>
                            </select>
                        </div>
                    </div>
                </section>

                <!-- 4. Sécurité -->
                <section id="card-securite" class="scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#C8102E]/10 text-xs font-bold text-[#C8102E]">4</span>
                        <Shield class="h-4 w-4 text-[#C8102E]" />
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Sécurité du Pointage</h2>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Vérification de la localisation GPS</span>
                            <button type="button" class="toggle" :class="{ on: ui.secu_gps }" @click="form.gestion_ui.secu_gps = !ui.secu_gps">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Vérification du réseau Wi-Fi</span>
                            <button type="button" class="toggle" :class="{ on: ui.secu_wifi }" @click="form.gestion_ui.secu_wifi = !ui.secu_wifi">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Appareils enregistrés uniquement</span>
                            <button type="button" class="toggle" :class="{ on: ui.secu_appareils }" @click="form.gestion_ui.secu_appareils = !ui.secu_appareils">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Vérification du QR Code</span>
                            <button type="button" class="toggle" :class="{ on: ui.secu_qr }" @click="form.gestion_ui.secu_qr = !ui.secu_qr">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Empêcher la capture d'écran du QR</span>
                            <button type="button" class="toggle" :class="{ on: ui.secu_anti_capture }" @click="form.gestion_ui.secu_anti_capture = !ui.secu_anti_capture">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Enregistrer l'appareil utilisé</span>
                            <button type="button" class="toggle" :class="{ on: ui.secu_enregistrer_appareil }" @click="form.gestion_ui.secu_enregistrer_appareil = !ui.secu_enregistrer_appareil">
                                <span class="toggle-knob" />
                            </button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Rayon GPS (m)</label>
                                <input v-model.number="form.gestion_ui.rayon_gps_metres" type="number" min="10" class="field-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Validité QR (s)</label>
                                <input v-model.number="form.gestion_ui.qr_validite_secondes" type="number" min="5" class="field-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Tentatives max</label>
                                <input v-model.number="form.gestion_ui.tentatives_max" type="number" min="1" class="field-input" />
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 5. Retards & Absences -->
                <section id="card-retards" class="scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#C8102E]/10 text-xs font-bold text-[#C8102E]">5</span>
                        <AlertTriangle class="h-4 w-4 text-[#C8102E]" />
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Retards, Départs & Absences</h2>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Tolérance arrivée (min)</label>
                            <input v-model.number="form.tolerance_minutes" type="number" min="0" class="field-input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Retard à partir de</label>
                            <input v-model="form.gestion_ui.retard_a_partir" type="time" step="60" class="field-input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Départ anticipé après</label>
                            <input v-model="form.gestion_ui.depart_anticipe_apres" type="time" step="60" class="field-input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Absence après (heures)</label>
                            <input v-model="form.gestion_ui.absence_apres" type="time" step="60" class="field-input" />
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Calculer automatiquement les retards</span>
                            <button type="button" class="toggle" :class="{ on: ui.calcul_retard_auto }" @click="form.gestion_ui.calcul_retard_auto = !ui.calcul_retard_auto">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Calculer les départs anticipés</span>
                            <button type="button" class="toggle" :class="{ on: ui.calcul_depart_anticipe_auto }" @click="form.gestion_ui.calcul_depart_anticipe_auto = !ui.calcul_depart_anticipe_auto">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Générer des anomalies</span>
                            <button type="button" class="toggle" :class="{ on: ui.generer_anomalies }" @click="form.gestion_ui.generer_anomalies = !ui.generer_anomalies">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                    </div>

                    <p class="mb-2 mt-4 text-[11px] font-bold uppercase tracking-wide text-slate-500">Anomalies à détecter</p>
                    <div class="grid grid-cols-1 gap-1.5 text-sm sm:grid-cols-2">
                        <label class="check-row text-red-700"><input v-model="form.gestion_ui.anomalie_entree_sans_sortie" type="checkbox" class="accent-[#C8102E]" /> Entrée sans sortie</label>
                        <label class="check-row text-red-700"><input v-model="form.gestion_ui.anomalie_sortie_sans_entree" type="checkbox" class="accent-[#C8102E]" /> Sortie sans entrée</label>
                        <label class="check-row text-red-700"><input v-model="form.gestion_ui.anomalie_double_pointage" type="checkbox" class="accent-[#C8102E]" /> Double pointage</label>
                        <label class="check-row text-red-700"><input v-model="form.gestion_ui.anomalie_hors_plage" type="checkbox" class="accent-[#C8102E]" /> Hors plage</label>
                        <label class="check-row text-red-700"><input v-model="form.gestion_ui.anomalie_retard_important" type="checkbox" class="accent-[#C8102E]" /> Retard important</label>
                        <label class="check-row text-red-700"><input v-model="form.gestion_ui.anomalie_depart_anticipe" type="checkbox" class="accent-[#C8102E]" /> Départ anticipé</label>
                        <label class="check-row text-red-700"><input v-model="form.gestion_ui.anomalie_journee_incomplete" type="checkbox" class="accent-[#C8102E]" /> Journée incomplète</label>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Pénalité retard (FCFA)</label>
                            <input v-model.number="form.employe_penalty_retard_fcfa" type="number" min="0" step="100" class="field-input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Pénalité absence (FCFA/jour)</label>
                            <input v-model.number="form.penalite_absence_injustifiee_fcfa_jour" type="number" min="0" step="100" class="field-input" />
                        </div>
                    </div>
                </section>

                <!-- 6. Heures supplémentaires -->
                <section id="card-hs" class="scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#C8102E]/10 text-xs font-bold text-[#C8102E]">6</span>
                        <Clock class="h-4 w-4 text-[#C8102E]" />
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Heures Supplémentaires</h2>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Calcul automatique</span>
                            <button type="button" class="toggle" :class="{ on: ui.hs_calcul_auto }" @click="form.gestion_ui.hs_calcul_auto = !ui.hs_calcul_auto">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Autorisation obligatoire</span>
                            <button type="button" class="toggle" :class="{ on: ui.hs_autorisation_obligatoire }" @click="form.gestion_ui.hs_autorisation_obligatoire = !ui.hs_autorisation_obligatoire">
                                <span class="toggle-knob" />
                            </button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Validation par</label>
                                <select v-model="form.gestion_ui.hs_validation_par" class="field-input">
                                    <option value="manager">Manager</option>
                                    <option value="rh">RH</option>
                                    <option value="manager_rh">Manager puis RH</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Délai validation manager (h)</label>
                                <input v-model.number="form.delai_validation_manager_heures" type="number" min="1" class="field-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Seuil journalier (h)</label>
                                <input v-model.number="form.seuil_heures_supplementaires_h_jour" type="number" min="0" step="0.5" class="field-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Seuil hebdomadaire (h)</label>
                                <input v-model.number="form.seuil_heures_supplementaires_h_semaine" type="number" min="0" step="0.5" class="field-input" />
                            </div>
                        </div>

                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Majorations</p>
                        <div class="space-y-2 rounded-lg border border-slate-100 bg-slate-50 p-3 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span>Heures supplémentaires</span>
                                <div class="flex items-center gap-1">
                                    <input v-model.number="form.majoration_heures_sup_pct" type="number" min="0" max="200" class="w-16 rounded border border-slate-200 px-2 py-1 text-right text-sm" />
                                    <span class="text-slate-500">%</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span>Heures de nuit</span>
                                <div class="flex items-center gap-1">
                                    <input v-model.number="form.gestion_ui.majoration_nuit_pct" type="number" min="0" max="200" class="w-16 rounded border border-slate-200 px-2 py-1 text-right text-sm" />
                                    <span class="text-slate-500">%</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span>Dimanche</span>
                                <div class="flex items-center gap-1">
                                    <input v-model.number="form.gestion_ui.majoration_dimanche_pct" type="number" min="0" max="200" class="w-16 rounded border border-slate-200 px-2 py-1 text-right text-sm" />
                                    <span class="text-slate-500">%</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span>Jours fériés</span>
                                <div class="flex items-center gap-1">
                                    <input v-model.number="form.gestion_ui.majoration_ferie_pct" type="number" min="0" max="200" class="w-16 rounded border border-slate-200 px-2 py-1 text-right text-sm" />
                                    <span class="text-slate-500">%</span>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                            Les heures supplémentaires sont calculées au-delà des seuils journalier et hebdomadaire définis.
                        </div>
                    </div>
                </section>

                <!-- 7. Calendrier de Travail -->
                <section id="card-calendrier" class="scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#C8102E]/10 text-xs font-bold text-[#C8102E]">7</span>
                        <CalendarDays class="h-4 w-4 text-[#C8102E]" />
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Calendrier de Travail</h2>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Profil horaire par défaut</label>
                            <select v-model="form.gestion_ui.profil_horaire" class="field-input">
                                <option value="bureau_standard">Bureau standard</option>
                                <option value="agence">Agence</option>
                                <option value="horaires_variables">Horaires variables</option>
                            </select>
                        </div>

                        <div>
                            <p class="mb-2 text-[11px] font-semibold text-slate-500">Jours travaillés</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="j in joursSemaine"
                                    :key="j.v"
                                    type="button"
                                    class="rounded-md px-2.5 py-1.5 text-xs font-semibold transition"
                                    :class="isJourActif(j.v) ? 'bg-[#0B1F33] text-white' : 'border border-slate-200 bg-white text-slate-500'"
                                    @click="toggleJour(j.v)"
                                >
                                    {{ j.label }}
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Jours fériés</label>
                            <select v-model="form.gestion_ui.jours_feries_mode" class="field-input">
                                <option value="bloquer">Bloquer le pointage</option>
                                <option value="autoriser">Autoriser le pointage</option>
                                <option value="majoration">Autoriser avec majoration</option>
                            </select>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Link href="/pointage/rh/presence/jours-ouvrables" class="btn-secondary">
                                <Users class="h-3.5 w-3.5" />
                                Gérer les profils horaires
                            </Link>
                            <Link href="/pointage/rh/presence/jours-feries" class="btn-secondary">
                                <CalendarDays class="h-3.5 w-3.5" />
                                Jours fériés
                            </Link>
                        </div>
                    </div>
                </section>

                <!-- 8. Correction & Validation -->
                <section id="card-correction" class="scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#C8102E]/10 text-xs font-bold text-[#C8102E]">8</span>
                        <PencilLine class="h-4 w-4 text-[#C8102E]" />
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Correction & Validation</h2>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">L'employé peut demander une correction</span>
                            <button type="button" class="toggle" :class="{ on: ui.correction_employe }" @click="form.gestion_ui.correction_employe = !ui.correction_employe">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Validation par le manager</span>
                            <button type="button" class="toggle" :class="{ on: ui.validation_manager }" @click="form.gestion_ui.validation_manager = !ui.validation_manager">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Validation par les RH</span>
                            <button type="button" class="toggle" :class="{ on: ui.validation_rh }" @click="form.gestion_ui.validation_rh = !ui.validation_rh">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Conserver l'ancien pointage</span>
                            <button type="button" class="toggle" :class="{ on: ui.conserver_ancien_pointage }" @click="form.gestion_ui.conserver_ancien_pointage = !ui.conserver_ancien_pointage">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Motif obligatoire</span>
                            <button type="button" class="toggle" :class="{ on: ui.motif_obligatoire }" @click="form.gestion_ui.motif_obligatoire = !ui.motif_obligatoire">
                                <span class="toggle-knob" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-700">Justificatif obligatoire</span>
                            <button type="button" class="toggle" :class="{ on: ui.justificatif_obligatoire }" @click="form.gestion_ui.justificatif_obligatoire = !ui.justificatif_obligatoire">
                                <span class="toggle-knob" />
                            </button>
                        </div>

                        <div class="pt-2">
                            <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-500">Motifs de déclaration autorisés</p>
                            <ul class="space-y-1.5">
                                <li v-for="(label, key) in motif_labels" :key="key" class="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        :id="'motif-' + key"
                                        v-model="form.declaration_motifs_autorises[key]"
                                        type="checkbox"
                                        class="accent-[#C8102E]"
                                    />
                                    <label :for="'motif-' + key">{{ label }}</label>
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>

                <!-- 9. Export & Rapports -->
                <section id="card-export" class="scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:col-span-2 xl:col-span-1">
                    <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#C8102E]/10 text-xs font-bold text-[#C8102E]">9</span>
                        <FileSpreadsheet class="h-4 w-4 text-[#C8102E]" />
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Export & Rapports</h2>
                    </div>

                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Mois</label>
                                <input v-model="exportMois" type="month" class="field-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-500">Employé</label>
                                <select v-model="exportUserId" class="field-input">
                                    <option value="tous">Tous les employés</option>
                                    <option v-for="e in export_employes" :key="e.id" :value="String(e.id)">{{ e.label }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-500">Mode export Sage Paie</label>
                            <select v-model="form.mode_export_sage_paie" class="field-input">
                                <option v-for="o in mode_export_options" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a :href="exportFicheUrl" class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                                <FileSpreadsheet class="h-4 w-4" />
                                Exporter Excel
                            </a>
                            <Link
                                href="/pointage/rapport/reporting"
                                class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-800 hover:bg-red-100"
                            >
                                <FileText class="h-4 w-4" />
                                Exporter PDF
                            </Link>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Link href="/pointage/rapport/reporting" class="chip">Rapport quotidien</Link>
                            <Link href="/pointage/rapport/reporting" class="chip">Rapport mensuel</Link>
                            <Link href="/pointage/rapport/reporting" class="chip">Rapport annuel</Link>
                            <Link href="/pointage/rapport/reporting" class="chip">Rapport anomalies</Link>
                        </div>
                    </div>
                </section>
            </form>

            <!-- Pied de page -->
            <div id="footer-info" class="scroll-mt-28 space-y-3">
                <div class="flex items-start gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    <p>
                        Les modifications seront appliquées à partir de leur date d'activation. Les pointages antérieurs ne
                        sont pas recalculés automatiquement.
                    </p>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                    <p>
                        Dernière mise à jour :
                        <span class="font-medium text-slate-700">{{ lastUpdateLabel }}</span>
                        · Par :
                        <span class="font-medium text-slate-700">{{ lastUpdateBy }}</span>
                    </p>
                    <Clock class="h-3.5 w-3.5" />
                </div>
            </div>
        </div>
    </PointageLayout>
</template>

<style scoped>
.field-input {
    width: 100%;
    border-radius: 0.375rem;
    border: 1px solid #e2e8f0;
    background: #fff;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    color: #1e293b;
    outline: none;
}
.field-input:focus {
    border-color: #c8102e;
    box-shadow: 0 0 0 1px rgba(200, 16, 46, 0.3);
}
.toggle {
    position: relative;
    height: 1.5rem;
    width: 2.75rem;
    flex-shrink: 0;
    border-radius: 9999px;
    background: #cbd5e1;
    transition: background 0.15s;
}
.toggle.on {
    background: #10b981;
}
.toggle-knob {
    position: absolute;
    left: 0.125rem;
    top: 0.125rem;
    height: 1.25rem;
    width: 1.25rem;
    border-radius: 9999px;
    background: #fff;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
    transition: transform 0.15s;
}
.toggle.on .toggle-knob {
    transform: translateX(1.25rem);
}
.check-row {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #334155;
}
.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    border-radius: 0.5rem;
    border: 1px solid #e2e8f0;
    background: #fff;
    padding: 0.5rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #334155;
}
.btn-secondary:hover {
    background: #f8fafc;
}
.chip {
    border-radius: 0.375rem;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    padding: 0.375rem 0.625rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: #475569;
}
.chip:hover {
    background: #f1f5f9;
}
</style>
