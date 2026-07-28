<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Fingerprint } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    profil: {
        matricule?: string | null;
        prenom?: string | null;
        nom?: string | null;
        email?: string | null;
        departement?: string | null;
        site?: string | null;
        fonction?: string | null;
        telephone?: string | null;
    } | null;
    horaire_standard: string;
    stats_mois: {
        periode_label: string;
        semaines: { label: string; heures: number; tone: string }[];
        presence_pct: number;
        presence_message: string;
    };
    telephone_affiche: string | null;
    biometric_registered: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage', href: '/pointage' },
    { title: 'Mon profil', href: '#' },
];

const initiales = computed(() => {
    const p = (props.profil?.prenom ?? '').trim();
    const n = (props.profil?.nom ?? '').trim();
    const a = p ? p.charAt(0).toUpperCase() : '';
    const b = n ? n.charAt(0).toUpperCase() : '';
    return (a + b) || '?';
});

const nomComplet = computed(() => {
    if (!props.profil) return '';
    return [props.profil.prenom, props.profil.nom].filter(Boolean).join(' ').trim();
});

const ligneMetier = computed(() => {
    const svc = props.profil?.departement || props.profil?.fonction || '—';
    return `Employé — ${svc}`;
});

const maxBarHeures = computed(() => Math.max(1, ...props.stats_mois.semaines.map((s) => s.heures)));

function barHeightPx(heures: number): number {
    const maxPx = 120;
    return Math.max(6, Math.round((heures / maxBarHeures.value) * maxPx));
}

function barToneClass(tone: string): string {
    if (tone === 'good') return 'bg-[#3B6D11]';
    if (tone === 'warn') return 'bg-[#D97706]';
    if (tone === 'bad') return 'bg-[#DC2626]';
    return 'bg-[#E2E0D8]';
}
</script>

<template>
    <PointageLayout title="Mon profil" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6">
            <h1 class="text-xl font-semibold text-[#0C447C]">Mon profil</h1>

            <div v-if="!profil" class="rounded-[10px] border border-[#FAC775] bg-[#FAEEDA] px-4 py-3 text-sm text-[#633806]">
                Aucun profil RH n'est lié à votre adresse e-mail. Contactez l'administration.
            </div>

            <div v-else class="grid gap-6 lg:grid-cols-12">
                <!-- Colonne gauche : infos -->
                <div class="lg:col-span-7">
                    <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-start gap-4 border-b border-[#F1EFE8] pb-5">
                            <div
                                class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-[#185FA5] text-lg font-bold text-white"
                            >
                                {{ initiales }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="text-lg font-semibold text-[#0C447C]">{{ nomComplet }}</h2>
                                <p class="mt-0.5 text-sm text-[#888780]">{{ ligneMetier }}</p>
                                <span
                                    v-if="profil.matricule"
                                    class="mt-2 inline-flex rounded-md border border-[#e2e0d8] bg-[#FAFAF8] px-2.5 py-0.5 font-mono text-xs font-semibold text-[#0C447C]"
                                >
                                    {{ profil.matricule }}
                                </span>
                            </div>
                        </div>

                        <dl class="mt-5 space-y-0 divide-y divide-[#F1EFE8] text-sm">
                            <div class="flex flex-wrap justify-between gap-2 py-3">
                                <dt class="text-[#888780]">Agence</dt>
                                <dd class="font-medium text-[#0C447C]">{{ profil.site ?? '—' }}</dd>
                            </div>
                            <div class="flex flex-wrap justify-between gap-2 py-3">
                                <dt class="text-[#888780]">Service</dt>
                                <dd class="font-medium text-[#0C447C]">{{ profil.departement ?? profil.fonction ?? '—' }}</dd>
                            </div>
                            <div class="flex flex-wrap justify-between gap-2 py-3">
                                <dt class="text-[#888780]">Horaire standard</dt>
                                <dd class="font-medium tabular-nums text-[#0C447C]">{{ horaire_standard }}</dd>
                            </div>
                            <div class="flex flex-wrap justify-between gap-2 py-3">
                                <dt class="text-[#888780]">Email</dt>
                                <dd class="break-all text-right font-medium text-[#0C447C]">{{ profil.email ?? '—' }}</dd>
                            </div>
                            <div class="flex flex-wrap justify-between gap-2 py-3">
                                <dt class="text-[#888780]">Téléphone</dt>
                                <dd class="font-medium tabular-nums text-[#0C447C]">{{ telephone_affiche ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Colonne droite : stats + bio -->
                <div class="space-y-6 lg:col-span-5">
                    <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-semibold text-[#0C447C]">Statistiques {{ stats_mois.periode_label }}</h3>
                        <div class="mt-4 flex items-end justify-between gap-2 border-b border-[#F1EFE8] pb-2">
                            <div v-for="s in stats_mois.semaines" :key="s.label" class="flex min-w-0 flex-1 flex-col items-center gap-2">
                                <span class="text-xs font-bold tabular-nums text-[#0C447C]">{{ s.heures }}h</span>
                                <div class="flex h-[128px] w-full flex-col justify-end">
                                    <div
                                        class="mx-auto w-[70%] rounded-t-md transition-all"
                                        :class="barToneClass(s.tone)"
                                        :style="{ height: barHeightPx(s.heures) + 'px' }"
                                    />
                                </div>
                                <span class="text-[10px] font-bold uppercase text-[#888780]">{{ s.label }}</span>
                            </div>
                        </div>
                        <div
                            class="mt-4 rounded-lg border border-[#C0DD97] bg-[#EAF3DE] px-3 py-2.5 text-sm text-[#27500A]"
                        >
                            Votre présence ce mois :
                            <strong>{{ stats_mois.presence_pct }}%</strong>
                            — {{ stats_mois.presence_message }}
                        </div>
                    </div>

                    <div class="rounded-[10px] border border-[#e2e0d8] bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-semibold text-[#0C447C]">Paramètres biométriques</h3>
                        <div
                            v-if="biometric_registered"
                            class="mt-3 flex gap-3 rounded-lg border border-[#C0DD97] bg-[#EAF3DE] px-3 py-3 text-sm text-[#27500A]"
                        >
                            <span class="text-lg leading-none">✓</span>
                            <p>Empreinte digitale enregistrée sur cet appareil</p>
                        </div>
                        <Link
                            href="/pointage/pointer"
                            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md border border-[#185FA5] bg-white px-4 py-2.5 text-sm font-medium text-[#185FA5] hover:bg-[#E6F1FB]"
                        >
                            <Fingerprint class="h-4 w-4" />
                            Réenregistrer
                        </Link>
                    </div>
                </div>
            </div>

            <Link href="/pointage" class="text-sm text-[#185FA5] underline hover:no-underline">← Retour au tableau de bord</Link>
        </div>
    </PointageLayout>
</template>
