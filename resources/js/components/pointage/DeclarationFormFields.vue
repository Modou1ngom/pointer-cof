<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

export interface DeclarationFormShape {
    type: string;
    date_concernee: string;
    date_fin: string;
    heure_debut: string;
    heure_fin: string;
    sens: string;
    lieu: string;
    motif: string;
    commentaire: string;
    justificatif: File | null;
}

const form = defineModel<InertiaForm<DeclarationFormShape>>('form', { required: true });

const TYPES = [
    { value: 'absence', label: 'Absence' },
    { value: 'conge_annuel', label: 'Congé annuel' },
    { value: 'conge_maladie', label: 'Congé maladie' },
    { value: 'permission_exceptionnelle', label: 'Permission exceptionnelle' },
    { value: 'allaitement', label: 'Allaitement' },
    { value: 'mission', label: 'Mission' },
    { value: 'formation', label: 'Formation' },
    { value: 'regularisation', label: 'Régularisation' },
];

const MOTIFS = [
    'Transport perturbé',
    'Réunion imprévue',
    'Rendez-vous médical',
    'Panne véhicule',
    'Grève / perturbation transport',
    'Maladie',
    'Mission extérieure',
    'Formation',
    'Congé annuel',
    'Permission exceptionnelle',
    'Allaitement',
    'Autre (préciser dans le commentaire)',
];

const needsDateRange = computed(() =>
    [
        'absence',
        'conge_annuel',
        'conge_maladie',
        'permission_exceptionnelle',
        'allaitement',
        'mission',
        'formation',
        'conge',
    ].includes(form.value.type),
);

const needsHeures = computed(() => form.value.type === 'permission_exceptionnelle');
const needsAllaitement = computed(() => form.value.type === 'allaitement');
const needsLieu = computed(() => form.value.type === 'mission');

const dateDebutLabel = computed(() => {
    if (form.value.type === 'mission') {
        return 'Date de départ';
    }
    if (needsDateRange.value) {
        return 'Date de début';
    }
    return 'Date concernée';
});

const dateFinLabel = computed(() => (form.value.type === 'mission' ? 'Date de retour' : 'Date de fin'));

const allaitementHeureLabel = computed(() =>
    form.value.sens === 'sortie' ? 'Heure (après-midi)' : 'Heure (matin)',
);

function applyAllaitementHeureDefaults() {
    form.value.heure_fin = '';
    form.value.heure_debut = form.value.sens === 'sortie' ? '16:00' : '09:00';
}

watch(
    () => form.value.type,
    (type) => {
        if (type !== 'allaitement') {
            form.value.sens = '';
        } else if (!form.value.sens) {
            form.value.sens = 'entree';
        }
        if (type !== 'permission_exceptionnelle' && type !== 'allaitement') {
            form.value.heure_debut = '';
            form.value.heure_fin = '';
        }
        if (type === 'allaitement') {
            applyAllaitementHeureDefaults();
        }
    },
);

watch(
    () => form.value.sens,
    (sens) => {
        if (form.value.type !== 'allaitement' || !sens) {
            return;
        }
        applyAllaitementHeureDefaults();
    },
);

function onFile(e: Event) {
    const t = e.target as HTMLInputElement;
    form.value.justificatif = t.files?.[0] ?? null;
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Type de déclaration</label>
            <select v-model="form.type" class="mt-1 w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]">
                <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <p v-if="form.errors.type" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.type }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">{{ dateDebutLabel }}</label>
                <input v-model="form.date_concernee" type="date" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                <p v-if="form.errors.date_concernee" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.date_concernee }}</p>
            </div>
            <div v-if="needsDateRange">
                <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">{{ dateFinLabel }}</label>
                <input v-model="form.date_fin" type="date" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                <p v-if="form.errors.date_fin" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.date_fin }}</p>
            </div>
        </div>

        <div v-if="needsHeures" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Heure début</label>
                <input v-model="form.heure_debut" type="time" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                <p v-if="form.errors.heure_debut" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.heure_debut }}</p>
            </div>
            <div>
                <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Heure fin</label>
                <input v-model="form.heure_fin" type="time" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                <p v-if="form.errors.heure_fin" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.heure_fin }}</p>
            </div>
        </div>

        <div v-if="needsAllaitement" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Sens horaire *</label>
                    <select v-model="form.sens" class="mt-1 w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm">
                        <option value="entree">Matin</option>
                        <option value="sortie">Après-midi</option>
                    </select>
                    <p v-if="form.errors.sens" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.sens }}</p>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">{{ allaitementHeureLabel }} *</label>
                    <input v-model="form.heure_debut" type="time" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                    <p v-if="form.errors.heure || form.errors.heure_debut" class="mt-1 text-sm text-[#A32D2D]">
                        {{ form.errors.heure || form.errors.heure_debut }}
                    </p>
                </div>
            </div>
        </div>

        <div v-if="needsLieu">
            <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Lieu</label>
            <input
                v-model="form.lieu"
                type="text"
                class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                placeholder="Ville, agence, client…"
            />
            <p v-if="form.errors.lieu" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.lieu }}</p>
        </div>

        <div>
            <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Motif</label>
            <select v-model="form.motif" class="mt-1 w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm">
                <option disabled value="">Sélectionner un motif</option>
                <option v-for="m in MOTIFS" :key="m" :value="m">{{ m }}</option>
            </select>
            <p v-if="form.errors.motif" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.motif }}</p>
        </div>

        <div>
            <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Justificatif (photo / PDF / JPG / PNG — max 10 Mo)</label>
            <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png,image/*"
                capture="environment"
                class="mt-1 w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-[#E6F1FB] file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-[#185FA5]"
                @change="onFile"
            />
            <p v-if="form.justificatif" class="mt-1 text-xs text-[#888780]">Fichier : {{ form.justificatif.name }}</p>
            <p v-if="form.errors.justificatif" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.justificatif }}</p>
        </div>

        <div>
            <label class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Commentaire additionnel</label>
            <textarea
                v-model="form.commentaire"
                rows="3"
                class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm placeholder:text-[#888780]"
                placeholder="Précisions supplémentaires pour votre manager…"
            />
            <p v-if="form.errors.commentaire" class="mt-1 text-sm text-[#A32D2D]">{{ form.errors.commentaire }}</p>
        </div>
    </div>
</template>
