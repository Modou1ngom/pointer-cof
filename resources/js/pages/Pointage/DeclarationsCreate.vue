<script setup lang="ts">
import DeclarationFormFields from '@/components/pointage/DeclarationFormFields.vue';
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, useForm } from '@inertiajs/vue3';

defineProps<{
    manager_nom?: string | null;
    validation_hint: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage', href: '/pointage' },
    { title: 'Mes déclarations', href: '/pointage/declarations' },
    { title: 'Nouvelle', href: '#' },
];

const form = useForm({
    type: 'permission_exceptionnelle',
    date_concernee: new Date().toISOString().slice(0, 10),
    date_fin: '',
    heure_debut: '',
    heure_fin: '',
    sens: '',
    lieu: '',
    motif: 'Permission exceptionnelle',
    commentaire: '',
    justificatif: null as File | null,
});

function submit() {
    form.post('/pointage/declarations', { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <PointageLayout title="Nouvelle déclaration" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-lg space-y-6">
            <h1 class="text-xl font-semibold text-[#0C447C]">Nouvelle déclaration</h1>
            <form class="space-y-4 rounded-[10px] border border-[#e2e0d8] bg-white p-6 shadow-sm" @submit.prevent="submit">
                <DeclarationFormFields v-model:form="form" />

                <div class="flex flex-wrap gap-3 pt-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                        Envoyer la déclaration →
                    </button>
                    <Link href="/pointage/declarations" class="rounded-md border border-[#e2e0d8] bg-white px-4 py-2 text-sm font-medium text-[#0C447C] hover:bg-[#FAFAF8]">Annuler</Link>
                </div>
            </form>
        </div>
    </PointageLayout>
</template>