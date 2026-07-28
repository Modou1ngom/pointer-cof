<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

type Profile = {
    id: number;
    libelle: string;
    scope_type: string;
    weekend_jours?: number[];
    weekend_samedi_matin_ouvrable?: boolean;
    weekend_samedi_matin_fin?: string | null;
    weekend_dimanche_matin_ouvrable?: boolean;
    weekend_dimanche_matin_fin?: string | null;
    weekend_travail_majoration_pct?: number | string;
};

const props = defineProps<{
    profiles: Profile[];
    selected_profile_id: number;
    profile: Profile;
    departements: { id: number; nom: string }[];
    profils: { id: number; nom: string; prenom: string; matricule: string | null; departement: string | null }[];
}>();

const page = usePage<{ flash?: { success?: string } }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Configuration', href: '#' },
    { title: 'Jour ouvrable', href: '#' },
    { title: 'Gestion des week-ends', href: '#' },
];

const jours = [
    { dow: 1, label: 'Lundi' },
    { dow: 2, label: 'Mardi' },
    { dow: 3, label: 'Mercredi' },
    { dow: 4, label: 'Jeudi' },
    { dow: 5, label: 'Vendredi' },
    { dow: 6, label: 'Samedi' },
    { dow: 0, label: 'Dimanche' },
];

function sliceTime(v: string | null | undefined): string {
    if (!v) return '';
    return String(v).slice(0, 5);
}

const form = useForm({
    profile_id: props.selected_profile_id,
    weekend_jours: [...(props.profile.weekend_jours ?? [0, 6])].map(Number),
    weekend_samedi_matin_ouvrable: Boolean(props.profile.weekend_samedi_matin_ouvrable),
    weekend_samedi_matin_fin: sliceTime(props.profile.weekend_samedi_matin_fin as string),
    weekend_dimanche_matin_ouvrable: Boolean(props.profile.weekend_dimanche_matin_ouvrable),
    weekend_dimanche_matin_fin: sliceTime(props.profile.weekend_dimanche_matin_fin as string),
    weekend_travail_majoration_pct: Number(props.profile.weekend_travail_majoration_pct ?? 25),
});

watch(
    () => props.profile,
    (p) => {
        form.profile_id = props.selected_profile_id;
        form.weekend_jours = [...(p.weekend_jours ?? [0, 6])].map(Number);
        form.weekend_samedi_matin_ouvrable = Boolean(p.weekend_samedi_matin_ouvrable);
        form.weekend_samedi_matin_fin = sliceTime(p.weekend_samedi_matin_fin as string);
        form.weekend_dimanche_matin_ouvrable = Boolean(p.weekend_dimanche_matin_ouvrable);
        form.weekend_dimanche_matin_fin = sliceTime(p.weekend_dimanche_matin_fin as string);
        form.weekend_travail_majoration_pct = Number(p.weekend_travail_majoration_pct ?? 25);
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

function toggle(dow: number) {
    const i = form.weekend_jours.indexOf(dow);
    if (i >= 0) form.weekend_jours.splice(i, 1);
    else form.weekend_jours.push(dow);
}

function changeProfile(id: number) {
    router.get('/pointage/rh/presence/week-ends', { profile_id: id }, { preserveScroll: true, replace: true });
}

function submit() {
    form.post('/pointage/rh/presence/week-ends', { preserveScroll: true });
}
</script>

<template>
    <PointageLayout title="Gestion des week-ends" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-4xl space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-[#0C447C]">Gestion des week-ends</h1>
            </div>

            <p v-if="flashOk" class="rounded-lg border border-[#C0DD97] bg-[#EAF3DE] px-3 py-2 text-sm text-[#27500A]">{{ flashOk }}</p>

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
                <Link href="/pointage/rh/presence/jours-ouvrables" class="text-sm font-medium text-[#185FA5] underline">← Jours ouvrables</Link>
            </div>

            <form class="space-y-6 rounded-xl border border-[#e2e0d8] bg-white p-6 shadow-sm" @submit.prevent="submit">
                <div>
                    <h2 class="text-sm font-semibold text-[#0C447C]">Jours de week-end</h2>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            v-for="j in jours"
                            :key="j.dow"
                            type="button"
                            class="rounded-lg border px-3 py-2 text-sm font-medium transition"
                            :class="
                                form.weekend_jours.includes(j.dow)
                                    ? 'border-[#185FA5] bg-[#E6F1FB] text-[#0C447C]'
                                    : 'border-[#e2e0d8] bg-white text-[#5c5a57]'
                            "
                            @click="toggle(j.dow)"
                        >
                            {{ j.label }}
                        </button>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-[#F1EFE8] p-4">
                        <label class="flex items-center gap-2 text-sm font-medium text-[#0C447C]">
                            <input v-model="form.weekend_samedi_matin_ouvrable" type="checkbox" class="rounded border-[#e2e0d8]" />
                            Samedi matin ouvrable (week-end partiel)
                        </label>
                        <label class="mt-2 block text-[11px] font-bold uppercase text-[#888780]">Fin matinée samedi</label>
                        <input v-model="form.weekend_samedi_matin_fin" type="time" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1 text-sm" />
                    </div>
                    <div class="rounded-lg border border-[#F1EFE8] p-4">
                        <label class="flex items-center gap-2 text-sm font-medium text-[#0C447C]">
                            <input v-model="form.weekend_dimanche_matin_ouvrable" type="checkbox" class="rounded border-[#e2e0d8]" />
                            Dimanche matin ouvrable
                        </label>
                        <label class="mt-2 block text-[11px] font-bold uppercase text-[#888780]">Fin matinée dimanche</label>
                        <input v-model="form.weekend_dimanche_matin_fin" type="time" class="mt-1 w-full rounded border border-[#e2e0d8] px-2 py-1 text-sm" />
                    </div>
                </div>

                <div>
                    <label class="text-[11px] font-bold uppercase text-[#888780]">Majoration week-end travaillé (%)</label>
                    <input
                        v-model.number="form.weekend_travail_majoration_pct"
                        type="number"
                        min="0"
                        max="500"
                        step="0.5"
                        class="mt-1 w-40 rounded border border-[#e2e0d8] px-3 py-2 text-sm"
                    />
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="rounded-lg bg-[#185FA5] px-5 py-2 text-sm font-semibold text-white hover:bg-[#144a84] disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </PointageLayout>
</template>
