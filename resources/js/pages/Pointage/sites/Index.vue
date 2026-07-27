<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { QrCode as QrCodeIcon } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

interface Agence {
    id: number;
    nom: string;
    code_agent?: string | null;
    description?: string | null;
    adresse_courte?: string | null;
    region_label?: string | null;
    actif: boolean;
    rayon_geofencing_metres?: number;
    pointage_qr_type?: string;
    employes_count?: number;
    pointage_qr_activated_on?: string | null;
    pointage_qr_expires_on?: string | null;
    pointage_plage_debut?: string | null;
    pointage_plage_fin?: string | null;
    pointage_qr_enabled?: boolean;
    is_enrolled?: boolean;
    is_virtual?: boolean;
    parent_agence_id?: number | null;
    kiosk_url?: string | null;
}

interface QrAgencePayload {
    id: number;
    code_agent: string | null;
    nom: string;
    region_label: string | null;
    adresse: string | null;
    actif: boolean;
    pointage_qr_type: string;
    pointage_qr_activated_on: string | null;
    pointage_qr_expires_on: string | null;
    pointage_plage_debut: string | null;
    pointage_plage_fin: string | null;
    pointage_qr_enabled: boolean;
    is_enrolled?: boolean;
}

interface QrPreview {
    token: string;
    expires_at: string;
    scan_url?: string;
    qr_content?: string;
}

function qrEncodeContent(p: QrPreview): string {
    return p.qr_content ?? p.scan_url ?? p.token;
}

const props = defineProps<{
    agences: {
        data: Agence[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
    };
    qrPreview: Record<number, { token: string; expires_at: string }>;
    canRegenerateAllQr: boolean;
    filters: { code_agence: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Génération QR Code par agence', href: '#' },
];

const qrWizardOpen = ref(false);
const rayonDialogOpen = ref(false);
const rayonDrafts = ref<Record<number, number>>({});
const rayonSaving = ref(false);
const codeAgenceDraft = ref(props.filters.code_agence ?? '');
const wizardStep = ref<'code' | 'detail'>('code');
const lookupLoading = ref(false);
const lookupMessage = ref<string | null>(null);
const selectedAgence = ref<QrAgencePayload | null>(null);
const wizardQrPreview = ref<QrPreview | null>(null);
const wizardQrDataUrl = ref('');
const saveLoading = ref(false);

const qrForm = reactive({
    pointage_qr_activated_on: '' as string,
    pointage_qr_expires_on: '' as string,
    pointage_qr_type: 'dynamic' as 'dynamic' | 'static',
    pointage_plage_debut: '' as string,
    pointage_plage_fin: '' as string,
    pointage_qr_enabled: true,
});

const qrStatutSelect = computed({
    get: () => (qrForm.pointage_qr_enabled ? 'actif' : 'inactif'),
    set: (v: string) => {
        qrForm.pointage_qr_enabled = v === 'actif';
    },
});

watch(
    () => props.filters.code_agence,
    (v) => {
        codeAgenceDraft.value = v ?? '';
    },
);

watch(wizardQrPreview, async (p) => {
    if (!p?.token) {
        wizardQrDataUrl.value = '';
        return;
    }
    try {
        wizardQrDataUrl.value = await QRCode.toDataURL(qrEncodeContent(p), {
            width: 256,
            margin: 1,
            color: { dark: '#0C447C', light: '#FFFFFF' },
        });
    } catch {
        wizardQrDataUrl.value = '';
    }
});

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function openQrWizard() {
    qrWizardOpen.value = true;
    wizardStep.value = 'code';
    lookupMessage.value = null;
    selectedAgence.value = null;
    wizardQrPreview.value = null;
    wizardQrDataUrl.value = '';
    codeAgenceDraft.value = '';
}

function openRayonDialog() {
    const drafts: Record<number, number> = {};
    for (const a of props.agences.data) {
        drafts[a.id] = a.rayon_geofencing_metres ?? 50;
    }
    rayonDrafts.value = drafts;
    rayonDialogOpen.value = true;
}

function applyRayonPreset(metres: number) {
    const next: Record<number, number> = { ...rayonDrafts.value };
    for (const a of props.agences.data) {
        next[a.id] = metres;
    }
    rayonDrafts.value = next;
}

function saveRayons() {
    if (rayonSaving.value) {
        return;
    }
    rayonSaving.value = true;
    const rayons = props.agences.data.map((a) => ({
        id: a.id,
        rayon_geofencing_metres: Math.min(2000, Math.max(10, Number(rayonDrafts.value[a.id]) || 50)),
    }));
    router.post(
        '/pointage/sites/update-rayons',
        { rayons },
        {
            preserveScroll: true,
            onFinish: () => {
                rayonSaving.value = false;
            },
            onSuccess: () => {
                rayonDialogOpen.value = false;
            },
        },
    );
}

/** Ouvre le formulaire d’enrôlement / configuration QR pour une agence déjà listée. */
async function openQrWizardForAgence(a: Agence) {
    qrWizardOpen.value = true;
    lookupMessage.value = null;
    codeAgenceDraft.value = (a.code_agent || a.nom || '').trim();
    if (!codeAgenceDraft.value) {
        wizardStep.value = 'code';
        lookupMessage.value = 'Code agence manquant pour cette ligne.';
        return;
    }
    await rechercherAgenceDansWizard();
}

function applyPayloadToForm(a: QrAgencePayload) {
    qrForm.pointage_qr_activated_on = a.pointage_qr_activated_on ?? '';
    qrForm.pointage_qr_expires_on = a.pointage_qr_expires_on ?? '';
    qrForm.pointage_qr_type = (a.pointage_qr_type === 'static' ? 'static' : 'dynamic') as 'dynamic' | 'static';
    qrForm.pointage_plage_debut = a.pointage_plage_debut ?? '';
    qrForm.pointage_plage_fin = a.pointage_plage_fin ?? '';
    qrForm.pointage_qr_enabled = a.pointage_qr_enabled !== false;
}

async function rechercherAgenceDansWizard() {
    const code = codeAgenceDraft.value.trim();
    if (!code) {
        lookupMessage.value = 'Saisissez un code agence.';
        return;
    }
    lookupLoading.value = true;
    lookupMessage.value = null;
    try {
        const res = await fetch(`/pointage/sites/lookup-qr?code=${encodeURIComponent(code)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = (await res.json()) as {
            agence: QrAgencePayload | null;
            qr_preview?: QrPreview;
            message?: string;
        };
        if (!data.agence) {
            selectedAgence.value = null;
            wizardQrPreview.value = null;
            lookupMessage.value = data.message ?? 'Aucune agence correspondante.';
            wizardStep.value = 'code';
            return;
        }
        selectedAgence.value = data.agence;
        wizardQrPreview.value = data.qr_preview ?? null;
        applyPayloadToForm(data.agence);
        wizardStep.value = 'detail';
    } catch {
        lookupMessage.value = 'Erreur réseau lors de la recherche.';
    } finally {
        lookupLoading.value = false;
    }
}

async function postQrConfiguration(regenerateSecret: boolean) {
    if (!selectedAgence.value) return;
    saveLoading.value = true;
    lookupMessage.value = null;
    try {
        const body = {
            pointage_qr_activated_on: qrForm.pointage_qr_activated_on || null,
            pointage_qr_expires_on: qrForm.pointage_qr_expires_on || null,
            pointage_qr_type: qrForm.pointage_qr_type,
            pointage_plage_debut: qrForm.pointage_plage_debut || null,
            pointage_plage_fin: qrForm.pointage_plage_fin || null,
            pointage_qr_enabled: qrForm.pointage_qr_enabled,
            regenerate_secret: regenerateSecret,
        };
        const res = await fetch(`/pointage/sites/${selectedAgence.value.id}/qr-configuration`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        const data = (await res.json()) as {
            message?: string;
            agence?: QrAgencePayload;
            qr_preview?: QrPreview;
            errors?: Record<string, string[]>;
        };
        if (!res.ok) {
            lookupMessage.value = data.errors
                ? Object.values(data.errors)
                      .flat()
                      .join(' ')
                : (data.message ?? 'Enregistrement impossible.');
            return;
        }
        if (data.agence) {
            selectedAgence.value = data.agence;
            applyPayloadToForm(data.agence);
        }
        if (data.qr_preview) {
            wizardQrPreview.value = data.qr_preview;
        }
        lookupMessage.value = data.message ?? 'Enregistré.';
        if (data.agence?.is_enrolled) {
            qrWizardOpen.value = false;
            router.reload({ only: ['agences', 'qrPreview'], preserveScroll: true });
        }
    } catch {
        lookupMessage.value = 'Erreur réseau.';
    } finally {
        saveLoading.value = false;
    }
}

async function desactiverQrWizard() {
    if (!selectedAgence.value) return;
    if (!confirm('Désactiver le QR Code pour cette agence ? Le pointage via QR ne sera plus possible tant que le QR n’est pas réactivé.')) {
        return;
    }
    saveLoading.value = true;
    lookupMessage.value = null;
    try {
        const res = await fetch(`/pointage/sites/${selectedAgence.value.id}/desactiver-qr`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        });
        const data = (await res.json()) as { message?: string; agence?: QrAgencePayload };
        if (!res.ok) {
            lookupMessage.value = 'Action impossible.';
            return;
        }
        if (data.agence) {
            selectedAgence.value = data.agence;
            applyPayloadToForm(data.agence);
        }
        lookupMessage.value = data.message ?? 'QR désactivé.';
    } catch {
        lookupMessage.value = 'Erreur réseau.';
    } finally {
        saveLoading.value = false;
    }
}

function telechargerQrPng() {
    if (!wizardQrDataUrl.value || !selectedAgence.value) return;
    const a = document.createElement('a');
    const safeCode = (selectedAgence.value.code_agent || `agence-${selectedAgence.value.id}`).replace(/[^\w.-]+/g, '_');
    a.href = wizardQrDataUrl.value;
    a.download = `qr-pointage-${safeCode}.png`;
    a.click();
}

function imprimerQr() {
    if (!wizardQrDataUrl.value || !selectedAgence.value) return;
    const w = window.open('', '_blank');
    if (!w) return;
    w.document.write(
        `<!DOCTYPE html><html><head><title>QR ${selectedAgence.value.nom}</title></head><body style="text-align:center;font-family:sans-serif">` +
            `<h2 style="color:#0C447C">${selectedAgence.value.nom}</h2>` +
            `<p style="font-size:12px;color:#666">Code agence : ${selectedAgence.value.code_agent ?? '—'}</p>` +
            `<img src="${wizardQrDataUrl.value}" width="280" height="280" alt="QR" />` +
            '</body></html>',
    );
    w.document.close();
    w.focus();
    w.print();
}

function retourWizardCode() {
    wizardStep.value = 'code';
    lookupMessage.value = null;
}

function rechercherParCodeAgence() {
    router.get(
        '/pointage/sites',
        { code_agence: codeAgenceDraft.value.trim() },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function reinitialiserFiltreAgence() {
    codeAgenceDraft.value = '';
    router.get('/pointage/sites', {}, { preserveState: true, preserveScroll: true, replace: true });
}

const qrDataUrls = ref<Record<number, string>>({});

watch(
    () => [props.agences.data, props.qrPreview] as const,
    async () => {
        const next: Record<number, string> = {};
        for (const a of props.agences.data) {
            const prev = props.qrPreview[a.id];
            if (prev?.token) {
                try {
                    next[a.id] = await QRCode.toDataURL(qrEncodeContent(prev), {
                        width: 176,
                        margin: 1,
                        color: { dark: '#0C447C', light: '#FFFFFF' },
                    });
                } catch {
                    next[a.id] = '';
                }
            }
        }
        qrDataUrls.value = next;
    },
    { immediate: true, deep: true },
);

const qrTypeLabel = (t?: string) => (t === 'static' ? 'statique' : 'dynamique');
const qrTypeBadgeClass = (t?: string) =>
    t === 'static' ? 'bg-[#F1EFE8] text-[#6B6560]' : 'bg-[#EAF3DE] text-[#3B6D11]';

function regenOne(id: number) {
    if (!confirm('Régénérer le QR Code de ce site ? L’ancien jeton ne sera plus valide pour le pointage.')) return;
    router.post(`/pointage/sites/${id}/regenerer-qr`, {}, { preserveScroll: true });
}

function openKiosk(a: Agence) {
    if (!a.kiosk_url) {
        alert('Lien tablette indisponible pour ce site. Rechargez la page ou ré-enrôlez le site.');
        return;
    }
    window.open(a.kiosk_url, '_blank', 'noopener,noreferrer');
}

function copyKioskUrl(a: Agence) {
    if (!a.kiosk_url) return;
    void navigator.clipboard.writeText(a.kiosk_url).then(
        () => alert('Lien tablette copié. Collez-le dans le navigateur de la tablette.'),
        () => prompt('Copiez ce lien tablette :', a.kiosk_url ?? ''),
    );
}

function regenKioskLink(id: number) {
    if (
        !confirm(
            'Régénérer le lien borne / tablette ? L’ancienne URL ne fonctionnera plus : il faudra la rouvrir sur la tablette.',
        )
    ) {
        return;
    }
    router.post(`/pointage/sites/${id}/regenerer-lien-kiosk`, {}, { preserveScroll: true });
}

function createVirtual(a: Agence) {
    if (a.is_virtual) return;
    if (!confirm(`Créer une agence virtuelle liée à « ${a.nom} » ?\nBorne partagée (1 téléphone) + pointage par e-mail OTP.`)) {
        return;
    }
    router.post(`/pointage/sites/${a.id}/creer-virtuelle`, {}, { preserveScroll: true });
}

async function toggleQrPause(a: Agence) {
    const qrActif = a.pointage_qr_enabled !== false && a.actif;
    if (qrActif) {
        if (!confirm(`Mettre le QR Code en pause pour « ${a.nom} » ? Le pointage via QR sera désactivé sur ce site.`)) {
            return;
        }
        try {
            const res = await fetch(`/pointage/sites/${a.id}/desactiver-qr`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({}),
            });
            if (!res.ok) {
                return;
            }
            router.reload({ only: ['agences', 'qrPreview'], preserveScroll: true });
        } catch {
            /* ignore */
        }
        return;
    }

    if (!confirm(`Réactiver le QR Code pour « ${a.nom} » ?`)) {
        return;
    }
    try {
        const res = await fetch(`/pointage/sites/${a.id}/qr-configuration`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                pointage_qr_type: a.pointage_qr_type || 'dynamic',
                pointage_qr_enabled: true,
                regenerate_secret: false,
            }),
        });
        if (!res.ok) {
            return;
        }
        router.reload({ only: ['agences', 'qrPreview'], preserveScroll: true });
    } catch {
        /* ignore */
    }
}

function regenAll() {
    if (!confirm('Régénérer tous les secrets QR ? Cette action affecte tous les sites.')) return;
    router.post('/pointage/sites/regenerer-tous-qr', {}, { preserveScroll: true });
}

function scrollToQr(id: number) {
    document.getElementById(`qr-card-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

const disclaimerQr =
    'Aperçu admin du jeton site. Pour le pointage sur place : ouvrez « Tablette » (écran borne) — les employés scannent avec CofiPointe. Le flux web « Pointer » reste le QR personnel + OTP.';
</script>

<template>
    <PointageLayout title="Génération QR Code par agence" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-[#0C447C]">Génération QR Code par agence</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-relaxed text-[#5c5a57]">
                        Seules les agences <strong class="font-semibold">enrôlées au pointage QR</strong> apparaissent dans le
                        tableau ci-dessous. Les agences créées dans le menu « Agences » ne sont pas listées tant qu’elles n’ont
                        pas été enrôlées via « Génération QR Code » (recherche par code) ou « Nouveau site / agence » (création
                        directe dans ce module).
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md bg-[#185FA5] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#144a84]"
                            @click="openQrWizard"
                        >
                            Génération QR Code
                        </button>
                        <Link
                            href="/pointage/sites/create"
                            class="inline-flex items-center justify-center rounded-md border border-[#e2e0d8] bg-white px-4 py-2.5 text-sm font-semibold text-[#0C447C] hover:bg-[#FAFAF8]"
                        >
                            Nouveau site / agence
                        </Link>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md border border-[#185FA5] bg-[#E6F1FB] px-4 py-2.5 text-sm font-semibold text-[#185FA5] hover:bg-[#d5e8f8]"
                            title="Définir le rayon GPS autorisé pour chaque site"
                            @click="openRayonDialog"
                        >
                            Paramétrage du rayon
                        </button>
                        <button
                            v-if="filters.code_agence"
                            type="button"
                            class="text-sm font-medium text-[#185FA5] underline hover:no-underline"
                            @click="reinitialiserFiltreAgence"
                        >
                            Effacer le filtre code agence
                        </button>
                    </div>
                </div>
                <div v-if="canRegenerateAllQr" class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-[#E85D5D] bg-white px-4 py-2 text-sm font-medium text-[#C62828] hover:bg-[#FFF5F5]"
                        @click="regenAll"
                    >
                        Regénérer tous QR
                    </button>
                </div>
            </div>

            <Dialog v-model:open="rayonDialogOpen">
                <DialogContent class="max-h-[90vh] max-w-lg overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Paramétrage du rayon GPS</DialogTitle>
                        <DialogDescription>
                            Distance maximale (en mètres) entre le téléphone et le site pour autoriser le pointage.
                            Min. 10 m — max. 2000 m.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="flex flex-wrap gap-2 py-1">
                        <button
                            type="button"
                            class="rounded-md border border-[#e2e0d8] bg-white px-2.5 py-1 text-xs font-medium text-[#0C447C] hover:bg-[#FAFAF8]"
                            @click="applyRayonPreset(50)"
                        >
                            Tous à 50 m
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-[#e2e0d8] bg-white px-2.5 py-1 text-xs font-medium text-[#0C447C] hover:bg-[#FAFAF8]"
                            @click="applyRayonPreset(100)"
                        >
                            Tous à 100 m
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-[#185FA5] bg-[#E6F1FB] px-2.5 py-1 text-xs font-medium text-[#185FA5] hover:bg-[#d5e8f8]"
                            @click="applyRayonPreset(2000)"
                        >
                            Tous à 2000 m (tests)
                        </button>
                    </div>
                    <div class="max-h-[50vh] space-y-3 overflow-y-auto py-2">
                        <div
                            v-for="a in agences.data"
                            :key="'rayon-' + a.id"
                            class="flex items-center justify-between gap-3 rounded-md border border-[#e2e0d8] bg-[#FAFAF8] px-3 py-2"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-[#0C447C]">{{ a.nom }}</p>
                                <p class="text-[11px] text-[#888780]">{{ a.code_agent || '—' }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <input
                                    :id="'rayon-input-' + a.id"
                                    v-model.number="rayonDrafts[a.id]"
                                    type="number"
                                    min="10"
                                    max="2000"
                                    class="w-24 rounded-md border border-[#e2e0d8] bg-white px-2 py-1.5 text-sm"
                                />
                                <span class="text-xs text-[#888780]">m</span>
                            </div>
                        </div>
                    </div>
                    <DialogFooter class="gap-2 sm:justify-end">
                        <button
                            type="button"
                            class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm"
                            @click="rayonDialogOpen = false"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="rayonSaving"
                            @click="saveRayons"
                        >
                            {{ rayonSaving ? 'Enregistrement…' : 'Enregistrer' }}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog v-model:open="qrWizardOpen">
                <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Génération QR Code par agence</DialogTitle>
                        <DialogDescription>
                            Saisissez le code agence, puis validez pour afficher les informations et paramétrer le QR.
                        </DialogDescription>
                    </DialogHeader>

                    <div v-if="wizardStep === 'code'" class="space-y-4 py-2">
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]" for="wiz-code-agence">Code agence</label>
                            <input
                                id="wiz-code-agence"
                                v-model="codeAgenceDraft"
                                type="text"
                                class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                                placeholder="Code exact ou partie du nom"
                                @keyup.enter="rechercherAgenceDansWizard"
                            />
                        </div>
                        <p v-if="lookupMessage && wizardStep === 'code'" class="text-sm text-red-600">{{ lookupMessage }}</p>
                    </div>

                    <div v-else-if="wizardStep === 'detail' && selectedAgence" class="space-y-5 py-2">
                        <p
                            v-if="!selectedAgence.is_enrolled"
                            class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                        >
                            Cette agence existe dans le référentiel mais n’est pas encore enrôlée au pointage. Cliquez sur
                            « Générer QR Code » pour finaliser l’enrôlement et l’afficher dans la liste.
                        </p>
                        <div class="rounded-lg border border-[#e2e0d8] bg-[#FAFAF8] p-4">
                            <h3 class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Informations affichées automatiquement</h3>
                            <dl class="mt-3 grid gap-2 text-sm text-[#0C447C] sm:grid-cols-2">
                                <div><dt class="text-[#888780]">Code agence</dt> <dd class="font-mono font-semibold">{{ selectedAgence.code_agent ?? '—' }}</dd></div>
                                <div><dt class="text-[#888780]">Nom agence</dt> <dd class="font-semibold">{{ selectedAgence.nom }}</dd></div>
                                <div><dt class="text-[#888780]">Région</dt> <dd>{{ selectedAgence.region_label ?? '—' }}</dd></div>
                                <div><dt class="text-[#888780]">Statut agence</dt> <dd>{{ selectedAgence.actif ? 'Active' : 'Inactive' }}</dd></div>
                                <div class="sm:col-span-2">
                                    <dt class="text-[#888780]">Adresse</dt>
                                    <dd class="mt-0.5 text-[#5c5a57]">{{ selectedAgence.adresse ?? '—' }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="space-y-3 rounded-lg border border-[#e2e0d8] bg-white p-4">
                            <h3 class="text-[11px] font-bold uppercase tracking-wide text-[#888780]">Informations complémentaires à renseigner</h3>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="text-[10px] font-bold uppercase text-[#888780]" for="qr-act">Date d’activation du QR Code</label>
                                    <input
                                        id="qr-act"
                                        v-model="qrForm.pointage_qr_activated_on"
                                        type="date"
                                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold uppercase text-[#888780]" for="qr-exp">Date d’expiration</label>
                                    <input
                                        id="qr-exp"
                                        v-model="qrForm.pointage_qr_expires_on"
                                        type="date"
                                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold uppercase text-[#888780]" for="qr-type">Type de pointage autorisé</label>
                                    <select
                                        id="qr-type"
                                        v-model="qrForm.pointage_qr_type"
                                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                                    >
                                        <option value="dynamic">Dynamique (jeton court)</option>
                                        <option value="static">Statique (validité longue)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold uppercase text-[#888780]" for="qr-stat">Statut du QR Code</label>
                                    <select
                                        id="qr-stat"
                                        v-model="qrStatutSelect"
                                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                                    >
                                        <option value="actif">Actif</option>
                                        <option value="inactif">Inactif</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold uppercase text-[#888780]" for="qr-deb">Heure de début autorisée</label>
                                    <input
                                        id="qr-deb"
                                        v-model="qrForm.pointage_plage_debut"
                                        type="time"
                                        step="60"
                                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold uppercase text-[#888780]" for="qr-fin">Heure de fin autorisée</label>
                                    <input
                                        id="qr-fin"
                                        v-model="qrForm.pointage_plage_fin"
                                        type="time"
                                        step="60"
                                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col items-center gap-3 rounded-lg border border-[#e2e0d8] bg-white p-4">
                            <p class="text-xs text-[#888780]">Aperçu du jeton (après génération / recherche)</p>
                            <div class="flex h-56 w-56 items-center justify-center rounded-lg bg-[#FAFAF8]">
                                <img v-if="wizardQrDataUrl" :src="wizardQrDataUrl" alt="QR Code" class="h-52 w-52 rounded-md bg-white p-1" />
                                <QrCodeIcon v-else class="h-16 w-16 text-[#e2e0d8]" />
                            </div>
                        </div>

                        <p v-if="lookupMessage" class="text-sm text-[#0C447C]">{{ lookupMessage }}</p>
                    </div>

                    <DialogFooter class="flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-between">
                        <div class="flex flex-wrap gap-2">
                            <template v-if="wizardStep === 'detail' && selectedAgence">
                                <button
                                    type="button"
                                    class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#144a84] disabled:opacity-50"
                                    :disabled="saveLoading"
                                    @click="postQrConfiguration(true)"
                                >
                                    Générer QR Code
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm text-[#0C447C] hover:bg-[#FAFAF8] disabled:opacity-50"
                                    :disabled="saveLoading"
                                    @click="postQrConfiguration(false)"
                                >
                                    Enregistrer les paramètres
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm text-[#0C447C] hover:bg-[#FAFAF8] disabled:opacity-50"
                                    :disabled="saveLoading || !wizardQrDataUrl"
                                    @click="telechargerQrPng"
                                >
                                    Télécharger QR Code
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm text-[#0C447C] hover:bg-[#FAFAF8] disabled:opacity-50"
                                    :disabled="saveLoading || !wizardQrDataUrl"
                                    @click="imprimerQr"
                                >
                                    Imprimer QR Code
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-[#DC2626] px-4 py-2 text-sm font-medium text-[#B91C1C] hover:bg-[#FEF2F2] disabled:opacity-50"
                                    :disabled="saveLoading"
                                    @click="desactiverQrWizard"
                                >
                                    Désactiver QR Code
                                </button>
                            </template>
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button
                                v-if="wizardStep === 'detail'"
                                type="button"
                                class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm text-[#0C447C]"
                                @click="retourWizardCode"
                            >
                                Retour
                            </button>
                            <button type="button" class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm text-[#0C447C]" @click="qrWizardOpen = false">
                                Fermer
                            </button>
                            <button
                                v-if="wizardStep === 'code'"
                                type="button"
                                class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#144a84] disabled:opacity-50"
                                :disabled="lookupLoading"
                                @click="rechercherAgenceDansWizard"
                            >
                                {{ lookupLoading ? 'Recherche…' : 'Rechercher l’agence' }}
                            </button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <div class="rounded-lg border border-[#e2e0d8] bg-white p-4 shadow-sm">
                <p class="text-sm font-medium text-[#0C447C]">Filtrer la liste par code agence</p>
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <div class="min-w-[200px] flex-1">
                        <label class="text-[11px] font-bold uppercase text-[#888780]" for="liste-code">Code agence</label>
                        <input
                            id="liste-code"
                            v-model="codeAgenceDraft"
                            type="text"
                            class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                            @keyup.enter="rechercherParCodeAgence"
                        />
                    </div>
                    <button
                        type="button"
                        class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#144a84]"
                        @click="rechercherParCodeAgence"
                    >
                        Filtrer la liste
                    </button>
                </div>
            </div>

            <p v-if="filters.code_agence" class="rounded-lg border border-[#E6F1FB] bg-[#F5FAFF] px-4 py-2 text-sm text-[#0C447C]">
                Filtre actif : code agence « <span class="font-mono font-semibold">{{ filters.code_agence }}</span> » —
                {{ agences.data.length }} résultat{{ agences.data.length > 1 ? 's' : '' }} sur cette page.
            </p>

            <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1040px] text-sm">
                        <thead class="border-b border-[#e2e0d8] bg-[#FAFAF8] text-left text-[10px] font-bold uppercase tracking-wide text-[#888780]">
                            <tr>
                                <th class="px-4 py-3">Code agence</th>
                                <th class="px-4 py-3">Site</th>
                                <th class="px-4 py-3">Région / filiale</th>
                                <th class="px-4 py-3">Adresse</th>
                                <th class="px-4 py-3 text-center">Employés</th>
                                <th class="px-4 py-3 text-center">Geofencing</th>
                                <th class="px-4 py-3 text-center">QR type</th>
                                <th class="px-4 py-3 text-center">Statut QR</th>
                                <th class="px-4 py-3 text-right" title="Éditer, voir, régénérer ou mettre en pause le QR Code">
                                    Actions QR
                                </th>
                        </tr>
                    </thead>
                    <tbody>
                            <tr v-for="a in agences.data" :key="a.id" class="border-b border-[#F1EFE8] last:border-0 hover:bg-[#FAFAF8]/80">
                                <td class="px-4 py-3 font-mono text-xs text-[#0C447C]">{{ a.code_agent || '—' }}</td>
                                <td class="px-4 py-3 font-semibold text-[#0C447C]">
                                    <span class="inline-flex flex-wrap items-center gap-1.5">
                                        {{ a.nom }}
                                        <span
                                            v-if="a.is_virtual"
                                            class="rounded-full bg-[#FFF3D0] px-2 py-0.5 text-[10px] font-bold uppercase text-[#854F0B]"
                                        >
                                            Virtuelle
                                        </span>
                                    </span>
                                </td>
                                <td class="max-w-[160px] px-4 py-3 text-sm text-[#888780]">{{ a.region_label || '—' }}</td>
                                <td class="max-w-[280px] px-4 py-3 text-[#888780]">
                                    <span class="line-clamp-2" :title="a.description ?? ''">{{ a.adresse_courte ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3 text-center tabular-nums text-[#0C447C]">{{ a.employes_count ?? 0 }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex rounded-full bg-[#E6F1FB] px-2.5 py-0.5 text-[11px] font-semibold text-[#185FA5]">
                                        {{ a.rayon_geofencing_metres ?? 50 }} m
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold capitalize" :class="qrTypeBadgeClass(a.pointage_qr_type)">
                                        {{ qrTypeLabel(a.pointage_qr_type) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                        :class="
                                            a.actif && a.pointage_qr_enabled !== false
                                                ? 'bg-[#EAF3DE] text-[#3B6D11]'
                                                : 'bg-[#F1EFE8] text-[#888780]'
                                        "
                                    >
                                        {{
                                            !a.actif
                                                ? 'Site inactif'
                                                : a.pointage_qr_enabled === false
                                                  ? 'QR en pause'
                                                  : 'QR actif'
                                        }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs font-medium">
                                        <button
                                            type="button"
                                            class="text-[#185FA5] underline hover:no-underline"
                                            title="Modifier les paramètres du QR Code"
                                            @click="openQrWizardForAgence(a)"
                                        >
                                            Éditer QR
                                        </button>
                                        <span class="text-[#e2e0d8]" aria-hidden="true">·</span>
                                        <Link
                                            :href="`/pointage/sites/${a.id}/edit`"
                                            class="text-[#0C447C] underline hover:no-underline"
                                            title="Modifier le site (GPS, rayon…)"
                                        >
                                            Site
                                        </Link>
                                        <template v-if="!a.is_virtual">
                                            <span class="text-[#e2e0d8]" aria-hidden="true">·</span>
                                            <button
                                                type="button"
                                                class="text-[#27500A] underline hover:no-underline"
                                                title="Créer une agence virtuelle liée (borne + e-mail OTP)"
                                                @click="createVirtual(a)"
                                            >
                                                + Virtuelle
                                            </button>
                                        </template>
                                        <span class="text-[#e2e0d8]" aria-hidden="true">·</span>
                                        <button
                                            type="button"
                                            class="text-[#854F0B] underline hover:no-underline"
                                            title="Afficher l’aperçu du QR en bas de page"
                                            @click="scrollToQr(a.id)"
                                        >
                                            Voir QR
                                        </button>
                                        <span class="text-[#e2e0d8]" aria-hidden="true">·</span>
                                        <button
                                            type="button"
                                            class="text-[#0C447C] underline hover:no-underline"
                                            title="Ouvrir l’écran borne / tablette (plein écran)"
                                            :disabled="!a.kiosk_url"
                                            @click="openKiosk(a)"
                                        >
                                            Tablette
                                        </button>
                                        <span class="text-[#e2e0d8]" aria-hidden="true">·</span>
                                        <button
                                            type="button"
                                            class="text-[#185FA5] underline hover:no-underline"
                                            title="Copier l’URL à coller sur la tablette"
                                            :disabled="!a.kiosk_url"
                                            @click="copyKioskUrl(a)"
                                        >
                                            Copier lien
                                        </button>
                                        <span class="text-[#e2e0d8]" aria-hidden="true">·</span>
                                        <button
                                            type="button"
                                            class="text-[#185FA5] underline hover:no-underline"
                                            title="Régénérer un nouveau jeton QR"
                                            @click="regenOne(a.id)"
                                        >
                                            Régénérer QR
                                        </button>
                                        <span class="text-[#e2e0d8]" aria-hidden="true">·</span>
                                        <button
                                            type="button"
                                            class="underline hover:no-underline"
                                            :class="
                                                a.actif && a.pointage_qr_enabled !== false
                                                    ? 'text-[#A32D2D]'
                                                    : 'text-[#3B6D11]'
                                            "
                                            :title="
                                                a.actif && a.pointage_qr_enabled !== false
                                                    ? 'Désactiver temporairement le QR Code'
                                                    : 'Réactiver le QR Code'
                                            "
                                            @click="toggleQrPause(a)"
                                        >
                                            {{
                                                a.actif && a.pointage_qr_enabled !== false ? 'Pause QR' : 'Activer QR'
                                            }}
                                        </button>
                                    </div>
                            </td>
                            </tr>
                            <tr v-if="!agences.data?.length">
                                <td colspan="9" class="px-4 py-12 text-center text-[#888780]">
                                    <template v-if="filters.code_agence">
                                        Aucun site ne correspond à ce critère. Vérifiez le code agence ou
                                        <Link href="/pointage/sites/create" class="font-medium text-[#185FA5] underline">créez un nouveau site</Link>.
                                    </template>
                                    <template v-else>
                                        Aucune agence enrôlée au pointage QR. Utilisez « Génération QR Code » pour enrôler une
                                        agence existante (menu Agences) ou « Nouveau site / agence ».
                                    </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>

                <div v-if="agences.last_page > 1" class="flex flex-wrap justify-center gap-1 border-t border-[#e2e0d8] px-4 py-3">
                    <template v-for="(link, i) in agences.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="min-w-[2.25rem] rounded-md px-2 py-1 text-center text-xs"
                            :class="
                                link.active
                                    ? 'bg-[#185FA5] font-semibold text-white'
                                    : 'border border-[#e2e0d8] text-[#0C447C] hover:bg-[#FAFAF8]'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span
                            v-else
                            class="min-w-[2.25rem] cursor-not-allowed rounded-md px-2 py-1 text-center text-xs text-[#ccc]"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>

            <div>
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-[#888780]">Aperçu QR par site</h2>
                <p class="mb-4 text-xs text-[#888780]">{{ disclaimerQr }}</p>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="a in agences.data"
                        :id="'qr-card-' + a.id"
                        :key="'card-' + a.id"
                        class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 text-center shadow-sm"
                        :class="{ 'opacity-60': !a.actif }"
                    >
                        <div class="mb-3 text-sm font-semibold text-[#0C447C]">{{ a.nom }}</div>
                        <div class="mx-auto flex h-44 w-44 items-center justify-center rounded-lg bg-[#FAFAF8]">
                            <img v-if="qrDataUrls[a.id]" :src="qrDataUrls[a.id]" alt="" class="h-40 w-40 rounded-md bg-white p-1" />
                            <QrCodeIcon v-else class="h-16 w-16 text-[#e2e0d8]" />
                        </div>
                        <div class="mt-3">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold capitalize" :class="qrTypeBadgeClass(a.pointage_qr_type)">
                                {{ qrTypeLabel(a.pointage_qr_type) }}
                            </span>
                        </div>
                        <p class="mt-3 text-[11px] text-[#888780]">
                            {{ a.employes_count ?? 0 }} employés · {{ a.rayon_geofencing_metres ?? 50 }} m
                        </p>
                        <div class="mt-3 flex flex-wrap justify-center gap-2">
                            <button
                                type="button"
                                class="rounded-md bg-[#0C447C] px-2.5 py-1.5 text-[11px] font-semibold text-white hover:bg-[#185FA5] disabled:opacity-40"
                                :disabled="!a.kiosk_url || !a.actif || a.pointage_qr_enabled === false"
                                @click="openKiosk(a)"
                            >
                                Ouvrir tablette
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-[#e2e0d8] px-2.5 py-1.5 text-[11px] font-medium text-[#0C447C] hover:bg-[#FAFAF8] disabled:opacity-40"
                                :disabled="!a.kiosk_url"
                                @click="copyKioskUrl(a)"
                            >
                                Copier lien
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-[#e2e0d8] px-2.5 py-1.5 text-[11px] font-medium text-[#A32D2D] hover:bg-[#FAFAF8]"
                                title="Invalide l’ancienne URL borne"
                                @click="regenKioskLink(a.id)"
                            >
                                Nouveau lien
                            </button>
                        </div>
                        <p class="mt-2 text-[10px] leading-snug text-[#888780]">
                            Sur Android : ouvrir le lien → « Plein écran » → épingler l’écran (ou Fully Kiosk).
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </PointageLayout>
</template>
