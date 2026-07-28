<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

type PausesRegle = {
    dejeuner_duree_minutes: number;
    dejeuner_fenetre_debut: string;
    dejeuner_fenetre_fin: string;
    dejeuner_mode: string;
    technique_nb_max: number;
    technique_duree_max_minutes: number;
    technique_decompte_temps_travail: boolean;
    pause_totale_max_minutes: number | null;
    alerte_depassement_pause: boolean;
};

type Profile = {
    id: number;
    libelle: string;
    pauses_regle?: PausesRegle | null;
};

const props = defineProps<{
    pauseTab: 'dejeuner' | 'technique' | 'duree';
    profiles: Profile[];
    selected_profile_id: number;
    profile: Profile;
    departements: { id: number; nom: string }[];
    profils: { id: number; nom: string; prenom: string; matricule: string | null; departement: string | null }[];
}>();

const page = usePage<{ flash?: { success?: string } }>();

const tabTitles: Record<string, string> = {
    dejeuner: 'Pause déjeuner',
    technique: 'Pause technique',
    duree: 'Temps de pause autorisé',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Configuration', href: '#' },
    { title: 'Gestion des pauses', href: '#' },
    { title: tabTitles[props.pauseTab] ?? 'Pauses', href: '#' },
];

const defaults: PausesRegle = {
    dejeuner_duree_minutes: 60,
    dejeuner_fenetre_debut: '11:30',
    dejeuner_fenetre_fin: '14:30',
    dejeuner_mode: 'auto_deduct',
    technique_nb_max: 2,
    technique_duree_max_minutes: 15,
    technique_decompte_temps_travail: true,
    pause_totale_max_minutes: 120,
    alerte_depassement_pause: true,
};

function sliceTime(v: string | null | undefined): string {
    if (!v) return '';
    return String(v).slice(0, 5);
}

function mergePauses(p: Profile): PausesRegle {
    const r = p.pauses_regle;
    return {
        dejeuner_duree_minutes: r?.dejeuner_duree_minutes ?? defaults.dejeuner_duree_minutes,
        dejeuner_fenetre_debut: sliceTime(r?.dejeuner_fenetre_debut) || defaults.dejeuner_fenetre_debut,
        dejeuner_fenetre_fin: sliceTime(r?.dejeuner_fenetre_fin) || defaults.dejeuner_fenetre_fin,
        dejeuner_mode: r?.dejeuner_mode ?? defaults.dejeuner_mode,
        technique_nb_max: r?.technique_nb_max ?? defaults.technique_nb_max,
        technique_duree_max_minutes: r?.technique_duree_max_minutes ?? defaults.technique_duree_max_minutes,
        technique_decompte_temps_travail: r?.technique_decompte_temps_travail ?? defaults.technique_decompte_temps_travail,
        pause_totale_max_minutes: r?.pause_totale_max_minutes ?? defaults.pause_totale_max_minutes,
        alerte_depassement_pause: r?.alerte_depassement_pause ?? defaults.alerte_depassement_pause,
    };
}

const form = useForm({
    profile_id: props.selected_profile_id,
    ...mergePauses(props.profile),
});

watch(
    () => props.profile,
    (p) => {
        form.profile_id = props.selected_profile_id;
        Object.assign(form, mergePauses(p));
    },
    { deep: true },
);

watch(
    () => props.selected_profile_id,
    (id) => {
        form.profile_id = id;
    },
);

const flashOk = computed(() => page.props.flash?.success);

const pausePath = computed(() => {
    if (props.pauseTab === 'technique') return '/pointage/rh/presence/pauses/technique';
    if (props.pauseTab === 'duree') return '/pointage/rh/presence/pauses/duree';

    return '/pointage/rh/presence/pauses/dejeuner';
});

const pauseLinks = [
    { tab: 'dejeuner' as const, label: 'Pause déjeuner', href: '/pointage/rh/presence/pauses/dejeuner' },
    { tab: 'technique' as const, label: 'Pause technique', href: '/pointage/rh/presence/pauses/technique' },
    { tab: 'duree' as const, label: 'Temps autorisé', href: '/pointage/rh/presence/pauses/duree' },
];

function changeProfile(id: number) {
    router.get(pausePath.value, { profile_id: id }, { preserveScroll: true, replace: true });
}

function submit() {
    form.post(pausePath.value, { preserveScroll: true });
}
</script>

<template>
    <PointageLayout :title="tabTitles[pauseTab]" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-3xl space-y-6">
            <p v-if="flashOk" class="rounded-lg border border-[#C0DD97] bg-[#EAF3DE] px-3 py-2 text-sm text-[#27500A]">{{ flashOk }}</p>

            <div class="flex flex-wrap gap-2 border-b border-[#e2e0d8] pb-2">
                <Link
                    v-for="l in pauseLinks"
                    :key="l.tab"
                    :href="l.href"
                    class="rounded-full px-3 py-1 text-sm font-medium"
                    :class="l.tab === pauseTab ? 'bg-[#185FA5] text-white' : 'text-[#185FA5] hover:bg-[#F5FAFF]'"
                >
                    {{ l.label }}
                </Link>
            </div>

            <div class="flex flex-wrap items-end gap-3 rounded-xl border border-[#e2e0d8] bg-white p-4 shadow-sm">
                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Profil horaire</label>
                    <select
                        class="mt-1 block rounded-lg border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                        :value="selected_profile_id"
                        @change="changeProfile(Number(($event.target as HTMLSelectElement).value))"
                    >
                        <option v-for="p in profiles" :key="p.id" :value="p.id">{{ p.libelle }}</option>
                    </select>
                </div>
            </div>

            <form class="space-y-8 rounded-xl border border-[#e2e0d8] bg-white p-6 shadow-sm" @submit.prevent="submit">
                <section :class="{ 'opacity-40': pauseTab !== 'dejeuner' }">
                    <h2 class="text-sm font-semibold text-[#0C447C]">3.1 — Pause déjeuner</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Durée standard (min)</label>
                            <input v-model.number="form.dejeuner_duree_minutes" type="number" min="0" max="180" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1 text-sm" />
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Mode</label>
                            <select v-model="form.dejeuner_mode" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1 text-sm">
                                <option value="auto_deduct">Déduite automatiquement</option>
                                <option value="pointage_reel">Sur pointage réel</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Fenêtre début</label>
                            <input v-model="form.dejeuner_fenetre_debut" type="time" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1 text-sm" />
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Fenêtre fin</label>
                            <input v-model="form.dejeuner_fenetre_fin" type="time" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1 text-sm" />
                        </div>
                    </div>
                </section>

                <section :class="{ 'opacity-40': pauseTab !== 'technique' }">
                    <h2 class="text-sm font-semibold text-[#0C447C]">3.2 — Pauses techniques</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Nombre max / jour</label>
                            <input v-model.number="form.technique_nb_max" type="number" min="0" max="20" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1 text-sm" />
                        </div>
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Durée max par pause (min)</label>
                            <input v-model.number="form.technique_duree_max_minutes" type="number" min="0" max="120" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1 text-sm" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="flex items-center gap-2 text-sm text-[#0C447C]">
                                <input v-model="form.technique_decompte_temps_travail" type="checkbox" class="rounded border-[#e2e0d8]" />
                                Déduites du temps de travail
                            </label>
                        </div>
                    </div>
                </section>

                <section :class="{ 'opacity-40': pauseTab !== 'duree' }">
                    <h2 class="text-sm font-semibold text-[#0C447C]">3.3 — Plafond journalier & alertes</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="text-[11px] font-bold uppercase text-[#888780]">Pause totale max / jour (min)</label>
                            <input v-model.number="form.pause_totale_max_minutes" type="number" min="0" max="600" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1 text-sm" />
                        </div>
                        <div class="flex items-center gap-2 pt-6">
                            <input id="al" v-model="form.alerte_depassement_pause" type="checkbox" class="rounded border-[#e2e0d8]" />
                            <label for="al" class="text-sm text-[#0C447C]">Alerter si dépassement (rapports présence)</label>
                        </div>
                    </div>
                </section>

                <div class="flex justify-end border-t border-[#F1EFE8] pt-4">
                    <button type="submit" class="rounded-lg bg-[#185FA5] px-5 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="form.processing">
                        Enregistrer toutes les règles de pause
                    </button>
                </div>
            </form>
        </div>
    </PointageLayout>
</template>
