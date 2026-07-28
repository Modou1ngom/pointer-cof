<script setup lang="ts">
import { useDeviceGeolocation } from '@/composables/useDeviceGeolocation';
import { Head } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface AgenceInfo {
    id: number;
    nom: string;
    code_agent?: string | null;
    pointage_qr_type: string;
    is_virtual?: boolean;
    rayon_geofencing_metres: number;
    has_site_gps?: boolean;
}

interface QrPayload {
    token: string;
    expires_at: string;
    scan_url?: string;
    qr_content?: string;
}

const props = defineProps<{
    agence: AgenceInfo | null;
    qr: QrPayload | null;
    refresh_url: string | null;
    location_url?: string | null;
    auto_site_gps?: boolean;
    kiosk_unavailable?: boolean;
    unavailable_message?: string | null;
    qr_inactif_jour?: boolean;
    qr_inactif_message?: string | null;
}>();

/** Rouge / jaune / gris de la charte affiche Cofina */
const BRAND = {
    red: '#C8102E',
    redDark: '#9B0C24',
    yellow: '#F5C518',
    dark: '#2B2B2B',
    muted: '#6B6B6B',
    side: '#C5C5C5',
};

const { capture: captureDeviceGps } = useDeviceGeolocation();

const qrDataUrl = ref<string | null>(null);
const currentQr = ref<QrPayload | null>(props.qr);
const error = ref<string | null>(null);
const refreshing = ref(false);
const isFullscreen = ref(false);
const adminOpen = ref(false);
const rootEl = ref<HTMLElement | null>(null);
const siteGpsSynced = ref(Boolean(props.agence?.has_site_gps));
const syncingSiteGps = ref(false);

let refreshTimer: ReturnType<typeof setTimeout> | null = null;
let wakeLock: WakeLockSentinel | null = null;
let logoTapCount = 0;
let logoTapTimer: ReturnType<typeof setTimeout> | null = null;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function syncSiteGpsFromTablet() {
    if (!props.auto_site_gps || !props.location_url || syncingSiteGps.value) {
        return;
    }
    if (siteGpsSynced.value) {
        return;
    }

    syncingSiteGps.value = true;
    try {
        const coords = await captureDeviceGps();
        if (!coords) {
            return;
        }

        const res = await fetch(props.location_url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                latitude: coords.latitude,
                longitude: coords.longitude,
                accuracy_metres: coords.accuracy_metres,
            }),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok) {
            siteGpsSynced.value = true;
        }
    } catch {
        /* silencieux sur la borne */
    } finally {
        syncingSiteGps.value = false;
    }
}

const referenceCode = computed(() => {
    const code = (props.agence?.code_agent || props.agence?.nom || 'COFINA').toString().toUpperCase();
    const cleaned = code.replace(/[^A-Z0-9]/g, '');
    return cleaned.startsWith('COFINA') ? cleaned : `COFINA${cleaned}`;
});

const expiresAtMs = computed(() => {
    if (!currentQr.value?.expires_at) return Date.now();
    return new Date(currentQr.value.expires_at).getTime();
});

const pageTitle = computed(() =>
    props.agence ? `Borne pointage — ${props.agence.nom}` : 'Borne pointage',
);

function qrEncodeContent(q: QrPayload): string {
    return q.qr_content ?? q.scan_url ?? q.token;
}

async function renderQr(q: QrPayload) {
    try {
        qrDataUrl.value = await QRCode.toDataURL(qrEncodeContent(q), {
            width: 560,
            margin: 1,
            errorCorrectionLevel: 'H',
            color: { dark: '#1A1A1A', light: '#FFFFFF' },
        });
        error.value = null;
    } catch {
        qrDataUrl.value = null;
        error.value = 'Impossible d’afficher le QR Code.';
    }
}

async function refreshQr() {
    if (refreshing.value || props.qr_inactif_jour || props.kiosk_unavailable || !props.refresh_url) {
        return;
    }
    refreshing.value = true;
    try {
        const res = await fetch(props.refresh_url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok || !data.ok || !data.qr) {
            error.value = data.message ?? 'Échec du rafraîchissement du QR.';
            scheduleNextRefresh(30_000);
            return;
        }
        currentQr.value = data.qr as QrPayload;
        await renderQr(currentQr.value);
        scheduleNextRefresh();
    } catch {
        error.value = 'Connexion perdue — nouvelle tentative…';
        scheduleNextRefresh(15_000);
    } finally {
        refreshing.value = false;
    }
}

function scheduleNextRefresh(forceMs?: number) {
    if (refreshTimer) {
        clearTimeout(refreshTimer);
        refreshTimer = null;
    }
    if (props.qr_inactif_jour || props.kiosk_unavailable || !currentQr.value) {
        return;
    }
    // Static / virtuel : pas de rotation automatique du QR.
    if (!props.refresh_url || props.agence?.pointage_qr_type === 'static' || props.agence?.is_virtual) {
        return;
    }
    const msUntilExpiry = expiresAtMs.value - Date.now();
    const delay = forceMs ?? Math.max(10_000, msUntilExpiry - 45_000);
    refreshTimer = setTimeout(() => {
        void refreshQr();
    }, delay);
}

async function requestWakeLock() {
    if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') return;
    try {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', () => {
            /* no-op */
        });
    } catch {
        /* ignore */
    }
}

async function toggleFullscreen() {
    const el = rootEl.value ?? document.documentElement;
    try {
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else if (el.requestFullscreen) {
            await el.requestFullscreen();
        }
    } catch {
        /* geste utilisateur requis sur certains navigateurs */
    }
}

function syncFullscreenState() {
    isFullscreen.value = !!document.fullscreenElement;
}

function onVisibility() {
    if (document.visibilityState === 'visible') {
        void requestWakeLock();
        void refreshQr();
        void syncSiteGpsFromTablet();
    }
}

function onContextMenu(e: Event) {
    e.preventDefault();
}

/** Triple tap sur le logo → panneau admin (plein écran / guide) */
function onLogoTap() {
    logoTapCount += 1;
    if (logoTapTimer) clearTimeout(logoTapTimer);
    logoTapTimer = setTimeout(() => {
        logoTapCount = 0;
    }, 900);
    if (logoTapCount >= 3) {
        logoTapCount = 0;
        adminOpen.value = !adminOpen.value;
    }
}

watch(
    () => props.qr,
    (q) => {
        currentQr.value = q;
        if (q) {
            void renderQr(q);
            scheduleNextRefresh();
        } else {
            qrDataUrl.value = null;
        }
    },
    { immediate: true },
);

onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility);
    document.addEventListener('fullscreenchange', syncFullscreenState);
    document.addEventListener('contextmenu', onContextMenu);
    scheduleNextRefresh();
    void requestWakeLock();
    void syncSiteGpsFromTablet();
});

onUnmounted(() => {
    if (refreshTimer) clearTimeout(refreshTimer);
    if (logoTapTimer) clearTimeout(logoTapTimer);
    document.removeEventListener('visibilitychange', onVisibility);
    document.removeEventListener('fullscreenchange', syncFullscreenState);
    document.removeEventListener('contextmenu', onContextMenu);
    void wakeLock?.release();
    wakeLock = null;
});
</script>

<template>
    <Head :title="pageTitle">
        <meta name="mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="theme-color" content="#C8102E" />
    </Head>

    <div
        ref="rootEl"
        class="kiosk-poster relative flex min-h-dvh flex-col overflow-hidden select-none"
        :style="{ background: BRAND.dark }"
    >
        <!-- En-tête rouge -->
        <header
            class="relative z-10 flex items-center justify-between gap-4 px-5 py-4 sm:px-8 sm:py-5"
            :style="{ background: BRAND.red }"
        >
            <button type="button" class="flex items-center rounded-sm bg-white px-2.5 py-1.5" @click="onLogoTap">
                <img src="/logo_Cofina.png" alt="cofina" class="h-10 w-auto object-contain sm:h-12" draggable="false" />
            </button>
            <p
                class="text-2xl font-black uppercase tracking-[0.08em] sm:text-4xl"
                :style="{ color: BRAND.yellow, fontFamily: 'Arial Black, Arial, sans-serif' }"
            >
                Pointez
            </p>
        </header>

        <!-- Corps : bandes grises latérales + colonne blanche centrée -->
        <section
            class="relative z-10 flex flex-1 justify-center"
            :style="{ background: BRAND.side }"
        >
            <div
                class="flex w-full max-w-[560px] flex-1 flex-col items-center bg-white px-4 pb-8 pt-8 shadow-[0_0_40px_rgba(0,0,0,0.12)] sm:px-8 sm:pt-10"
            >
                <template v-if="kiosk_unavailable">
                    <div class="my-auto max-w-md px-4 text-center">
                        <p class="text-xl font-bold uppercase" :style="{ color: BRAND.red }">Borne indisponible</p>
                        <p class="mt-3 text-sm" :style="{ color: BRAND.muted }">{{ unavailable_message }}</p>
                    </div>
                </template>

                <template v-else-if="qr_inactif_jour">
                    <div class="my-auto max-w-md px-4 text-center">
                        <p class="text-xl font-bold uppercase" :style="{ color: BRAND.red }">Pointage fermé</p>
                        <p class="mt-3 text-sm" :style="{ color: BRAND.muted }">{{ qr_inactif_message }}</p>
                    </div>
                </template>

                <template v-else>
                    <h1
                        class="text-center text-3xl font-black uppercase leading-none tracking-tight sm:text-5xl"
                        :style="{ color: BRAND.red, fontFamily: 'Arial Black, Arial, sans-serif' }"
                    >
                        Scannez et pointez
                    </h1>
                    <p class="mt-3 text-center text-sm font-medium sm:text-base" :style="{ color: '#808080' }">
                        One goal, one tech, one team
                    </p>
                    <p v-if="agence" class="mt-2 text-center text-xs uppercase tracking-wide text-[#9a9a9a]">
                        {{ agence.nom }}
                    </p>

                    <!-- Cadre QR + coins L -->
                    <div class="relative mx-auto mt-8 w-[min(72vw,340px)] sm:mt-10 sm:w-[min(58vw,380px)]">
                        <span class="corner corner-tl" />
                        <span class="corner corner-tr" />
                        <span class="corner corner-bl" />
                        <span class="corner corner-br" />

                        <div class="relative aspect-square bg-white p-4 sm:p-5">
                            <img
                                v-if="qrDataUrl"
                                :src="qrDataUrl"
                                :alt="`QR pointage ${agence?.nom ?? ''}`"
                                class="pointer-events-none h-full w-full object-contain"
                                draggable="false"
                            />
                            <div
                                v-else
                                class="flex h-full w-full items-center justify-center text-sm"
                                :style="{ color: BRAND.muted }"
                            >
                                Génération…
                            </div>
                        </div>
                    </div>

                    <p
                        class="mt-5 text-center text-base font-semibold tracking-wide sm:text-lg"
                        :style="{ color: BRAND.dark }"
                    >
                        {{ referenceCode }}
                    </p>

                    <p
                        class="mt-6 text-center text-sm font-bold uppercase tracking-[0.12em] sm:text-base"
                        :style="{ color: BRAND.red }"
                    >
                        Merci pour votre confiance
                    </p>

                    <p v-if="error" class="mt-4 text-center text-sm font-medium" :style="{ color: BRAND.red }">
                        {{ error }}
                    </p>
                </template>
            </div>
        </section>

        <!-- Vague rouge -->
        <div class="relative z-10 -mb-px leading-[0]" :style="{ background: BRAND.side }">
            <div class="mx-auto max-w-[560px] bg-white leading-[0]">
                <svg viewBox="0 0 1440 56" class="block w-full" preserveAspectRatio="none" aria-hidden="true">
                    <path
                        fill="#C8102E"
                        d="M0,28 C180,56 360,0 540,24 C720,48 900,8 1080,28 C1260,48 1360,40 1440,24 L1440,56 L0,56 Z"
                    />
                </svg>
            </div>
        </div>
        <div class="h-2" :style="{ background: BRAND.red }" />

        <!-- Pied sombre (aide + site — sans « Comment payer ») -->
        <footer
            class="relative z-10 flex flex-col items-center gap-4 px-5 py-6 sm:flex-row sm:justify-between sm:px-8 sm:py-7"
            :style="{ background: BRAND.dark }"
        >
            <div class="flex items-center gap-3 text-white">
                <span
                    class="flex h-10 w-10 items-center justify-center rounded-full text-lg font-bold"
                    :style="{ background: BRAND.red }"
                    aria-hidden="true"
                >
                    ☎
                </span>
                <div class="text-sm leading-tight">
                    <p class="font-bold uppercase tracking-wide">Besoin d’aide ?</p>
                    <p class="mt-0.5 text-white/85">Appelez le <span class="font-semibold">80 200 11 11</span></p>
                </div>
            </div>
            <div class="text-center sm:text-right">
                <a
                    href="https://www.cofina.sn"
                    class="text-sm font-semibold text-white underline-offset-2 hover:underline"
                    target="_blank"
                    rel="noopener"
                >
                    www.cofina.sn
                </a>
            </div>
        </footer>

        <!-- Admin discret (triple clic logo) -->
        <div
            v-if="adminOpen"
            class="absolute inset-x-3 top-20 z-50 rounded-lg bg-white p-4 text-sm shadow-xl ring-1 ring-black/10 sm:inset-x-auto sm:right-4 sm:w-96"
        >
            <p class="font-semibold" :style="{ color: BRAND.red }">Réglages borne</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-md px-3 py-2 text-xs font-semibold text-white"
                    :style="{ background: BRAND.red }"
                    @click="toggleFullscreen"
                >
                    {{ isFullscreen ? 'Quitter plein écran' : 'Plein écran' }}
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#ddd] px-3 py-2 text-xs font-medium"
                    @click="adminOpen = false"
                >
                    Fermer
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.kiosk-poster {
    -webkit-user-select: none;
    user-select: none;
    touch-action: manipulation;
    font-family: Arial, Helvetica, sans-serif;
}

.corner {
    position: absolute;
    width: 28px;
    height: 28px;
    border-color: #c8102e;
    border-style: solid;
    z-index: 2;
    pointer-events: none;
}

.corner-tl {
    top: -2px;
    left: -2px;
    border-width: 5px 0 0 5px;
}
.corner-tr {
    top: -2px;
    right: -2px;
    border-width: 5px 5px 0 0;
}
.corner-bl {
    bottom: -2px;
    left: -2px;
    border-width: 0 0 5px 5px;
}
.corner-br {
    bottom: -2px;
    right: -2px;
    border-width: 0 5px 5px 0;
}

@media (min-width: 640px) {
    .corner {
        width: 36px;
        height: 36px;
    }
}
</style>
