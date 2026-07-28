<script setup lang="ts">
import { useDeviceGeolocation } from '@/composables/useDeviceGeolocation';
import { useForm, Link } from '@inertiajs/vue3';
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { ref, watch } from 'vue';

const { loading: gpsLoading, error: gpsError, capture: captureDeviceGps } = useDeviceGeolocation();

interface Filiale {
    id: number;
    nom: string;
}

interface SiteDepuisProfil {
    site: string;
    nom: string;
    code_agent: string;
    filiale_id: number | null;
    latitude: number | null;
    longitude: number | null;
    profils_count: number;
    employes_enroles_count: number;
    echantillon: string;
}

const props = defineProps<{
    filiales: Filiale[];
    sitesDepuisProfils: SiteDepuisProfil[];
    agencesExistantes?: { id: number; nom: string; code_agent: string | null; filiale_id: number | null }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Sites', href: '/pointage/sites' },
    { title: 'Créer', href: '#' },
];

const selectedSite = ref<string>('');

const form = useForm({
    nom: '',
    code_agent: '',
    description: '' as string,
    latitude: '' as string | number | '',
    longitude: '' as string | number | '',
    rayon_geofencing_metres: 50,
    pointage_qr_type: 'dynamic' as 'dynamic' | 'static',
    is_virtual: false,
    parent_agence_id: null as number | null,
    actif: 'actif' as 'actif' | 'inactif',
    chef_agence_id: null as number | null,
    filiale_id: null as number | null,
});

watch(
    () => form.is_virtual,
    (v) => {
        if (v) {
            form.pointage_qr_type = 'static';
        }
    },
);

function applySiteDepuisProfil(siteKey: string) {
    if (!siteKey) {
        return;
    }
    const row = props.sitesDepuisProfils.find((s) => s.site === siteKey);
    if (!row) {
        return;
    }
    form.nom = row.nom;
    form.code_agent = row.code_agent ?? '';
    form.filiale_id = row.filiale_id;
    form.latitude = row.latitude ?? '';
    form.longitude = row.longitude ?? '';
}

watch(selectedSite, (siteKey) => {
    if (!siteKey) {
        return;
    }
    applySiteDepuisProfil(siteKey);
});

async function fillGpsFromDevice() {
    const coords = await captureDeviceGps();
    if (coords) {
        form.latitude = coords.latitude;
        form.longitude = coords.longitude;
    }
}

function submit() {
    form.post('/pointage/sites');
}
</script>

<template>
    <PointageLayout title="Nouveau site" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-lg space-y-6">
            <h1 class="text-xl font-semibold text-[#0C447C]">Nouveau site</h1>
            <form class="space-y-4 rounded-[10px] border border-[#e2e0d8] bg-white p-6" @submit.prevent="submit">
                <div v-if="sitesDepuisProfils.length">
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Site (profils RH) *</label>
                    <select
                        v-model="selectedSite"
                        class="mt-1 w-full rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm"
                        required
                    >
                        <option value="" disabled>— Choisir un site issu des profils —</option>
                        <option
                            v-for="s in sitesDepuisProfils"
                            :key="s.site"
                            :value="s.site"
                            :title="s.echantillon ? `Ex. profils : ${s.echantillon}` : undefined"
                        >
                            {{ s.nom }}
                            <template v-if="s.employes_enroles_count > 0">
                                ({{ s.employes_enroles_count }} employé{{ s.employes_enroles_count > 1 ? 's' : '' }} enrôlé{{ s.employes_enroles_count > 1 ? 's' : '' }})
                            </template>
                            <template v-else-if="s.profils_count > 1"> ({{ s.profils_count }} profils)</template>
                        </option>
                    </select>
                </div>
                <div v-else>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Nom du site *</label>
                    <input
                        v-model="form.nom"
                        required
                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                        placeholder="Aucun site profil disponible — saisie manuelle"
                    />
                    <p class="mt-1 text-xs text-[#8a6a2a]">
                        Aucun site distinct dans les profils (ou tous existent déjà). Renseignez le nom, puis les autres
                        champs.
                    </p>
                </div>

                <div v-if="sitesDepuisProfils.length">
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Nom *</label>
                    <input
                        v-model="form.nom"
                        required
                        readonly
                        class="mt-1 w-full cursor-not-allowed rounded-md border border-[#e2e0d8] bg-[#f7f6f3] px-3 py-2 text-sm text-[#2C2C2A]"
                    />
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
                        placeholder="Visible dans Sites & QR Codes…"
                    />
                </div>
                <div class="space-y-2">
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
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Rayon géofencing (m)</label>
                    <input
                        v-model.number="form.rayon_geofencing_metres"
                        type="number"
                        min="10"
                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                    />
                </div>
                <div class="rounded-md border border-dashed border-[#e2e0d8] bg-[#f8f7f4] p-3 space-y-3">
                    <label class="flex items-start gap-2 text-sm text-[#0C447C]">
                        <input v-model="form.is_virtual" type="checkbox" class="mt-1 rounded border-[#e2e0d8]" />
                        <span>
                            <strong>Agence virtuelle</strong>
                        </span>
                    </label>
                    <div v-if="form.is_virtual && (agencesExistantes?.length ?? 0) > 0">
                        <label class="text-[11px] font-bold uppercase text-[#888780]">Agence parente (optionnel)</label>
                        <select v-model="form.parent_agence_id" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                            <option :value="null">— Aucune —</option>
                            <option v-for="a in agencesExistantes" :key="a.id" :value="a.id">{{ a.nom }}</option>
                        </select>
                    </div>
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
                    <button
                        type="submit"
                        :disabled="form.processing || (sitesDepuisProfils.length > 0 && !selectedSite)"
                        class="rounded-md bg-[#185FA5] px-4 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Créer
                    </button>
                    <Link href="/pointage/sites" class="rounded-md border px-4 py-2 text-sm">Annuler</Link>
                </div>
            </form>
        </div>
    </PointageLayout>
</template>
