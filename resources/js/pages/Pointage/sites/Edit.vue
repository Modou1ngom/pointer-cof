<script setup lang="ts">
import { useDeviceGeolocation } from '@/composables/useDeviceGeolocation';
import { useForm, Link } from '@inertiajs/vue3';
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { loading: gpsLoading, error: gpsError, capture: captureDeviceGps } = useDeviceGeolocation();

interface Agence {
    id: number;
    nom: string;
    code_agent: string;
    description?: string | null;
    latitude?: number | null;
    longitude?: number | null;
    actif: boolean;
    rayon_geofencing_metres?: number;
    pointage_qr_type?: string;
    is_virtual?: boolean;
    parent_agence_id?: number | null;
    kiosk_serial_number?: string | null;
    chef_agence_id?: number | null;
    filiale_id?: number | null;
}

interface Filiale {
    id: number;
    nom: string;
}

const props = defineProps<{
    agence: Agence;
    profils: { id: number; nom: string; prenom: string; matricule: string }[];
    filiales: Filiale[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Sites', href: '/pointage/sites' },
    { title: 'Éditer', href: '#' },
];

const form = useForm({
    nom: props.agence.nom,
    code_agent: props.agence.code_agent,
    description: props.agence.description ?? '',
    latitude: props.agence.latitude ?? '',
    longitude: props.agence.longitude ?? '',
    rayon_geofencing_metres: props.agence.rayon_geofencing_metres ?? 50,
    pointage_qr_type: (props.agence.pointage_qr_type ?? 'dynamic') as 'dynamic' | 'static',
    is_virtual: Boolean(props.agence.is_virtual),
    parent_agence_id: props.agence.parent_agence_id ?? null,
    kiosk_serial_number: props.agence.kiosk_serial_number ?? '',
    clear_kiosk_serial: false as boolean,
    actif: props.agence.actif ? ('actif' as const) : ('inactif' as const),
    chef_agence_id: props.agence.chef_agence_id ?? null,
    filiale_id: props.agence.filiale_id ?? null,
});

async function fillGpsFromDevice() {
    const coords = await captureDeviceGps();
    if (coords) {
        form.latitude = coords.latitude;
        form.longitude = coords.longitude;
    }
}

function submit() {
    form.patch(`/pointage/sites/${props.agence.id}`);
}
</script>

<template>
    <PointageLayout title="Éditer site" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-lg space-y-6">
            <h1 class="text-xl font-semibold text-[#0C447C]">Éditer — {{ agence.nom }}</h1>
            <form class="space-y-4 rounded-[10px] border border-[#e2e0d8] bg-white p-6" @submit.prevent="submit">
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Nom *</label>
                    <input v-model="form.nom" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Code agent</label>
                    <input v-model="form.code_agent" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Adresse / description</label>
                    <textarea
                        v-model="form.description"
                        rows="2"
                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                        placeholder="Adresse affichée dans Sites & QR Codes…"
                    />
                </div>
                <div class="space-y-2">
                    <p class="text-xs text-[#888780]">
                        Coordonnées de référence du site. Au scan QR, seule la position GPS de l’appareil de l’employé est utilisée.
                    </p>
                    <button
                        type="button"
                        class="w-full rounded-md border border-[#185FA5] bg-[#E6F1FB] px-3 py-2 text-sm font-medium text-[#185FA5] disabled:opacity-50"
                        :disabled="gpsLoading"
                        @click="fillGpsFromDevice"
                    >
                        {{ gpsLoading ? 'Lecture GPS…' : 'Utiliser la position GPS de cet appareil (sur place)' }}
                    </button>
                    <p v-if="gpsError" class="text-xs text-[#A32D2D]">{{ gpsError }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Latitude bureau</label>
                            <input v-model="form.latitude" type="number" step="any" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Longitude bureau</label>
                            <input v-model="form.longitude" type="number" step="any" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                        </div>
                    </div>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Rayon (m)</label>
                    <input v-model.number="form.rayon_geofencing_metres" type="number" min="10" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                </div>
                <div class="rounded-md border border-dashed border-[#e2e0d8] bg-[#f8f7f4] p-3">
                    <label class="flex items-start gap-2 text-sm text-[#0C447C]">
                        <input v-model="form.is_virtual" type="checkbox" class="mt-1 rounded border-[#e2e0d8]" />
                        <span>
                            <strong>Agence virtuelle</strong>
                            <span class="mt-0.5 block text-xs text-[#5c5a57]">
                                Borne partagée : un seul téléphone (n° de série), pointage des enrôlés par e-mail + OTP.
                            </span>
                        </span>
                    </label>
                </div>
                <div v-if="form.is_virtual" class="rounded-md border border-[#e2e0d8] bg-[#f8f7f4] p-3 space-y-2">
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Téléphone borne (n° de série)</label>
                    <p class="text-xs text-[#5c5a57]">
                        Verrouillé au premier scan depuis l’app. Un seul appareil peut pointer sur cette agence.
                    </p>
                    <input
                        v-model="form.kiosk_serial_number"
                        type="text"
                        readonly
                        class="mt-1 w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#5c5a57]"
                        placeholder="Aucun appareil enregistré"
                    />
                    <label class="flex items-center gap-2 text-sm text-[#0C447C]">
                        <input v-model="form.clear_kiosk_serial" type="checkbox" class="rounded border-[#e2e0d8]" />
                        Réinitialiser le téléphone autorisé (prochain scan = nouvel appareil)
                    </label>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Type QR</label>
                    <select
                        v-model="form.pointage_qr_type"
                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                        :disabled="form.is_virtual"
                    >
                        <option value="dynamic">Dynamique</option>
                        <option value="static">Statique</option>
                    </select>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Filiale</label>
                    <select v-model="form.filiale_id" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                        <option :value="null">—</option>
                        <option v-for="f in filiales" :key="f.id" :value="f.id">{{ f.nom }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Statut</label>
                    <select v-model="form.actif" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                        <option value="actif">Actif</option>
                        <option value="inactif">Inactif</option>
                    </select>
                </div>
                <div class="flex gap-3">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-[#185FA5] px-4 py-2 text-sm text-white">Enregistrer</button>
                    <Link href="/pointage/sites" class="rounded-md border px-4 py-2 text-sm">Retour</Link>
                </div>
            </form>
        </div>
    </PointageLayout>
</template>
