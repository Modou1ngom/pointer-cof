<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3';
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { useDeviceGeolocation } from '@/composables/useDeviceGeolocation';
import { ref, computed, watch, nextTick } from 'vue';
import QRCode from 'qrcode';

interface Agence {
    id: number;
    nom: string;
    rayon_geofencing_metres: number;
    pointage_qr_type: string;
    latitude: number | null;
    longitude: number | null;
    actif: boolean;
}

interface ContactHints {
    email_masked: string;
    phone_masked: string;
}

const props = defineProps<{
    agence: Agence | null;
    qr: { token: string; expires_at: string; scan_url?: string; qr_content?: string } | null;
    todayPointages: { type: string; clocked_at: string; statut: string }[];
    contact_hints: ContactHints | null;
    plages_pointage?: {
        arrivee: { debut: string; fin: string };
        depart: { debut: string; fin: string };
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage', href: '/pointage' },
    { title: 'Pointer', href: '#' },
];

/** 0 QR — 1 OTP — 2 GPS — 3 biométrie — 4 envoi */
const step = ref(0);
const { loading: gpsLoading, error: geoError, capture: captureDeviceGps } = useDeviceGeolocation();
const gpsCaptured = ref(false);
const qrDataUrl = ref<string | null>(null);
const qrGenError = ref<string | null>(null);
const otpSending = ref(false);
const otpVerifying = ref(false);
const otpInput = ref('');
const otpInfo = ref<string | null>(null);
/** Après le premier envoi (auto ou manuel), le bouton devient « Renvoyer ». */
const otpAlreadySent = ref(false);

const plagesInfo = computed(() => ({
    arrivee: `${props.plages_pointage?.arrivee?.debut ?? '07:00'} – ${props.plages_pointage?.arrivee?.fin ?? '12:00'}`,
    depart: `${props.plages_pointage?.depart?.debut ?? '15:00'} – ${props.plages_pointage?.depart?.fin ?? '20:00'}`,
}));

const form = useForm({
    type: null as 'arrivee' | 'depart' | null,
    qr_token: '',
    unlock_code: '',
    otp_session_token: '',
    latitude: 0 as number,
    longitude: 0 as number,
    biometric_ok: false,
});

const qrReady = computed(() => !!props.qr?.token);

watch(
    () => props.qr,
    async (q) => {
        qrGenError.value = null;
        if (!q?.token) {
            qrDataUrl.value = null;
            form.qr_token = '';
            return;
        }
        form.qr_token = q.token;
        try {
            qrDataUrl.value = await QRCode.toDataURL(q.qr_content ?? q.scan_url ?? q.token, {
                width: 240,
                margin: 2,
                color: { dark: '#0C447C', light: '#FFFFFF' },
            });
        } catch {
            qrGenError.value = 'Impossible de générer le QR sur cet appareil.';
            qrDataUrl.value = null;
        }
    },
    { immediate: true },
);

function nextFromQr() {
    step.value = 1;
    otpInput.value = '';
    otpInfo.value = null;
    otpAlreadySent.value = false;
    /** Envoi automatique du code dès la validation du QR (étape « Continuer » après scan). */
    void nextTick(() => sendOtp());
}

function sendOtp() {
    if (!props.qr?.token) return;
    otpSending.value = true;
    otpInfo.value = null;
    router.post(
        '/pointage/otp/send',
        { qr_token: props.qr.token },
        {
            preserveScroll: true,
            onFinish: () => {
                otpSending.value = false;
            },
            onSuccess: (page) => {
                const flash = page.props.flash as { success?: string; error?: string } | undefined;
                if (flash?.error) {
                    return;
                }
                if (flash?.success) {
                    otpAlreadySent.value = true;
                    otpInfo.value = flash.success;
                }
            },
        },
    );
}

function verifyOtp() {
    const code = otpInput.value.replace(/\s/g, '');
    if (code.length !== 6 || !props.qr?.token) return;
    otpVerifying.value = true;
    otpInfo.value = null;
    router.post(
        '/pointage/otp/verify',
        { qr_token: props.qr.token, otp_code: code },
        {
            preserveScroll: true,
            onFinish: () => {
                otpVerifying.value = false;
            },
            onSuccess: (page) => {
                const flash = page.props.flash as {
                    otp_session_token?: string | null;
                    success?: string;
                    error?: string;
                } | undefined;
                if (flash?.error) {
                    return;
                }
                const t = flash?.otp_session_token;
                if (typeof t === 'string' && t.length === 64) {
                    form.otp_session_token = t;
                    step.value = 2;
                    otpInfo.value = flash?.success ?? 'Code validé. Récupération de votre position GPS…';
                    void captureGps();
                }
            },
        },
    );
}

function skipOtpWithPin() {
    step.value = 2;
    otpInfo.value = 'Saisissez votre PIN ou les 4 derniers chiffres du téléphone à l’étape d’enregistrement (secours).';
    void captureGps();
}

async function captureGps() {
    gpsCaptured.value = false;
    const coords = await captureDeviceGps();
    if (!coords) {
        return;
    }
    form.latitude = coords.latitude;
    form.longitude = coords.longitude;
    gpsCaptured.value = true;
    step.value = 3;
}

watch(step, (s) => {
    if (s === 2 && !gpsCaptured.value && form.latitude === 0 && form.longitude === 0) {
        void captureGps();
    }
});

function confirmBio() {
    form.biometric_ok = true;
    step.value = 4;
}

function submitPointage() {
    form.post('/pointage/enregistrer', { preserveScroll: true });
}
</script>

<template>
    <PointageLayout title="Pointage — Pointer" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-lg space-y-6">
            <h1 class="text-xl font-semibold text-[#0C447C]">Pointage sécurisé</h1>

            <div v-if="!agence" class="rounded-lg border border-[#FAC775] bg-[#FAEEDA] px-4 py-3 text-sm text-[#633806]">
                Aucune agence par défaut n'est associée à votre compte. Contactez l'administrateur ou rattachez une agence à votre profil.
            </div>

            <div v-else class="space-y-4 rounded-[10px] border border-[#e2e0d8] bg-white p-5">
                <div class="rounded-lg border border-[#B5D4F4] bg-[#E6F1FB] px-3 py-2 text-sm">
                    <strong>Site :</strong> {{ agence.nom }} · Rayon {{ agence.rayon_geofencing_metres }} m · QR
                    {{ agence.pointage_qr_type === 'dynamic' ? 'dynamique' : 'statique' }}
                </div>

                <div class="flex justify-between gap-1 text-center text-[10px] font-bold leading-tight">
                    <div :class="step >= 0 ? 'text-[#185FA5]' : 'text-[#888780]'">1. QR</div>
                    <div :class="step >= 1 ? 'text-[#854F0B]' : 'text-[#888780]'">2. OTP</div>
                    <div :class="step >= 2 ? 'text-[#3B6D11]' : 'text-[#888780]'">3. GPS</div>
                    <div :class="step >= 3 ? 'text-[#633806]' : 'text-[#888780]'">4. Bio</div>
                    <div :class="step >= 4 ? 'text-[#3B6D11]' : 'text-[#888780]'">5. Envoi</div>
                </div>

                <div v-if="step === 0" class="space-y-4 text-center">
                    <p v-if="qrGenError" class="text-sm text-[#A32D2D]">{{ qrGenError }}</p>
                    <div v-else-if="qrDataUrl" class="flex flex-col items-center gap-2">
                        <img :src="qrDataUrl" alt="QR code pointage" class="rounded-lg border border-[#e2e0d8] bg-white p-2 shadow-sm" />
                    </div>
                    <div v-else class="py-8 text-sm text-[#888780]">Chargement du QR…</div>
                    <button
                        type="button"
                        :disabled="!qrReady || !qrDataUrl"
                        class="w-full rounded-md bg-[#185FA5] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        @click="nextFromQr"
                    >
                        Continuer
                    </button>
                </div>

                <div v-else-if="step === 1" class="space-y-3">
                    <p class="text-sm font-medium text-[#0C447C]">Vérification par e-mail et SMS</p>
                    <div v-if="contact_hints" class="rounded-md border border-[#e2e0d8] bg-[#FAFAF8] px-3 py-2 text-xs text-[#57534E]">
                        <div><span class="text-[#888780]">E-mail :</span> {{ contact_hints.email_masked }}</div>
                        <div class="mt-1"><span class="text-[#888780]">Téléphone :</span> {{ contact_hints.phone_masked }}</div>
                    </div>
                    <button
                        type="button"
                        :disabled="otpSending || !qrReady"
                        class="w-full rounded-md bg-[#185FA5] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        @click="sendOtp"
                    >
                        {{ otpSending ? 'Envoi…' : 'Recevoir le code par e-mail et SMS' }}
                    </button>
                    <p class="text-xs text-[#888780]">
                        Après le scan du QR, un <strong>code à 6 chiffres identique</strong> est envoyé automatiquement sur votre e-mail et sur
                        votre mobile (vous pouvez le renvoyer avec le bouton ci-dessous). Saisissez ensuite le code pour le
                        <strong>valider</strong> — rien n’est validé tant que vous n’avez pas cliqué sur « Valider ».
                    </p>
                    <div class="flex flex-wrap items-stretch gap-2">
                        <input
                            v-model="otpInput"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="8"
                            placeholder="Code 6 chiffres"
                            class="min-w-0 flex-1 rounded-md border border-[#e2e0d8] px-3 py-2 text-center text-sm tracking-[0.35em]"
                            @keyup.enter="verifyOtp"
                        />
                        <button
                            type="button"
                            :disabled="otpSending || !qrReady"
                            class="shrink-0 rounded-md bg-[#854F0B] px-4 py-2 text-sm text-white disabled:opacity-50"
                            @click="sendOtp"
                        >
                            {{
                                otpSending
                                    ? 'Envoi…'
                                    : otpAlreadySent
                                      ? 'Renvoyer le code par e-mail et SMS'
                                      : 'Recevoir le code par e-mail et SMS'
                            }}
                        </button>
                        <button
                            type="button"
                            :disabled="otpVerifying"
                            class="shrink-0 rounded-md bg-[#185FA5] px-4 py-2 text-sm text-white disabled:opacity-50"
                            @click="verifyOtp"
                        >
                            {{ otpVerifying ? '…' : 'Valider' }}
                        </button>
                    </div>
                    <p v-if="otpInfo" class="text-xs text-[#166534]">{{ otpInfo }}</p>
                    <button type="button" class="w-full text-xs text-[#185FA5] underline" @click="skipOtpWithPin">
                        Secours : passer au GPS et utiliser PIN / 4 derniers chiffres à l’enregistrement
                    </button>
                </div>

                <div v-else-if="step === 2" class="space-y-3">
                    <p class="text-sm font-medium">Vérification GPS</p>
                    <p v-if="gpsLoading" class="text-sm text-[#185FA5]">Acquisition de la position GPS en cours…</p>
                    <p v-if="geoError" class="text-sm text-[#A32D2D]">{{ geoError }}</p>
                    <div
                        v-if="gpsCaptured && form.latitude && form.longitude"
                        class="rounded-md border border-[#B5D4F4] bg-[#E6F1FB] px-3 py-2 text-xs text-[#0C447C]"
                    >
                        Position appareil : {{ form.latitude.toFixed(6) }}, {{ form.longitude.toFixed(6) }}
                    </div>
                    <button
                        type="button"
                        class="w-full rounded-md bg-[#185FA5] px-4 py-2 text-sm text-white disabled:opacity-50"
                        :disabled="gpsLoading"
                        @click="captureGps"
                    >
                        {{ gpsLoading ? 'Lecture GPS…' : 'Relire la position GPS' }}
                    </button>
                </div>

                <div v-else-if="step === 3" class="space-y-3 text-center">
                    <p class="text-sm font-medium">Face ID / empreinte digitale</p>
                    <button
                        type="button"
                        class="mx-auto flex h-24 w-24 items-center justify-center rounded-full border-2 border-dashed border-[#185FA5] bg-[#E6F1FB] text-3xl"
                        @click="confirmBio"
                    >
                        ☝️
                    </button>
                </div>

                <div v-else class="space-y-4 text-center">
                    <div class="text-5xl">✅</div>
                    <p class="font-semibold text-[#3B6D11]">Prêt à enregistrer</p>
                    <div v-if="!form.otp_session_token" class="rounded-md border border-[#FAC775] bg-[#FAEEDA] px-3 py-2 text-left text-xs text-[#633806]">
                        <p class="font-medium">Secours — code OTP non validé</p>
                        <input
                            v-model="form.unlock_code"
                            type="password"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            maxlength="16"
                            class="mt-2 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm tracking-widest"
                            placeholder="PIN ou 4 chiffres"
                        />
                    </div>
                    <button
                        type="button"
                        :disabled="
                            form.processing ||
                            !qrReady ||
                            (!form.otp_session_token && !form.unlock_code.trim())
                        "
                        class="w-full rounded-md bg-[#185FA5] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        @click="submitPointage"
                    >
                        Enregistrer le pointage
                    </button>
                </div>

                <p v-if="Object.keys(form.errors).length" class="text-sm text-[#A32D2D]">{{ Object.values(form.errors)[0] }}</p>
            </div>

            <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-4 text-sm">
                <div class="font-semibold text-[#0C447C]">Aujourd'hui</div>
                <ul class="mt-2 space-y-1 text-[#888780]">
                    <li v-for="(t, i) in todayPointages" :key="i">
                        {{ t.type }} — {{ t.heure_effective ?? t.clocked_at }}
                        <span v-if="t.heure_reelle && t.heure_reelle !== t.heure_effective" class="text-[#888780]">
                            (réel {{ t.heure_reelle }})
                        </span>
                        ({{ t.statut }})
                    </li>
                    <li v-if="!todayPointages?.length">Aucun pointage encore.</li>
                </ul>
            </div>

            <Link href="/pointage" class="text-sm text-[#185FA5] underline">← Retour tableau de bord</Link>
        </div>
    </PointageLayout>
</template>
