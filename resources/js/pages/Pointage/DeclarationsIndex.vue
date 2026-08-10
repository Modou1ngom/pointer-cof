<script setup lang="ts">
import DeclarationFormFields from '@/components/pointage/DeclarationFormFields.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Paperclip } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    declarations: {
        data: {
            id: number;
            type: string;
            type_label: string;
            date_concernee: string;
            date_concernee_display: string;
            date_fin?: string | null;
            date_fin_display?: string | null;
            heure_debut?: string | null;
            heure_fin?: string | null;
            lieu?: string | null;
            motif: string;
            commentaire: string | null;
            has_justificatif: boolean;
            justificatif_filename: string | null;
            statut: string;
            statut_label: string;
            validateur_label: string;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
    };
    periode_mois: string;
    periode_label: string;
    validation_hint: string;
    declarationListQuery: Record<string, string>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage', href: '/pointage' },
    { title: 'Mes déclarations', href: '#' },
];

const showModal = ref(false);

const form = useForm({
    type: 'permission_exceptionnelle',
    date_concernee: new Date().toISOString().slice(0, 10),
    date_fin: '',
    heure_debut: '',
    heure_fin: '',
    lieu: '',
    motif: 'Permission exceptionnelle',
    commentaire: '',
    justificatif: null as File | null,
});

function resetForm() {
    form.defaults({
        type: 'permission_exceptionnelle',
        date_concernee: new Date().toISOString().slice(0, 10),
        date_fin: '',
        heure_debut: '',
        heure_fin: '',
        lieu: '',
        motif: 'Permission exceptionnelle',
        commentaire: '',
        justificatif: null,
    });
    form.reset();
}

function openModal() {
    resetForm();
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
}

function submitDeclaration() {
    form.post('/pointage/declarations', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            resetForm();
        },
    });
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('nouvelle') === '1') {
        openModal();
        const q = new URLSearchParams(window.location.search);
        q.delete('nouvelle');
        const next = q.toString();
        window.history.replaceState({}, '', next ? `${window.location.pathname}?${next}` : window.location.pathname);
    }
});

watch(showModal, (open) => {
    if (!open && form.processing === false) {
        form.clearErrors();
    }
});

function listUrl(overrides: Record<string, string>): string {
    const merged = { ...props.declarationListQuery, ...overrides };
    const p = new URLSearchParams(merged);
    return `/pointage/declarations?${p.toString()}`;
}

function shiftMonth(ym: string, delta: number): string {
    const [yStr, mStr] = ym.split('-');
    let y = parseInt(yStr, 10);
    let m = parseInt(mStr, 10) - 1 + delta;
    while (m < 0) {
        m += 12;
        y -= 1;
    }
    while (m > 11) {
        m -= 12;
        y += 1;
    }
    return `${y}-${String(m + 1).padStart(2, '0')}`;
}

const prevMonthUrl = computed(() => listUrl({ mois: shiftMonth(props.periode_mois, -1) }));
const nextMonthUrl = computed(() => listUrl({ mois: shiftMonth(props.periode_mois, 1) }));

function statutBadgeClass(statut: string): string {
    if (statut === 'valide') {
        return 'bg-[#EAF3DE] text-[#3B6D11]';
    }
    if (statut === 'rejete') {
        return 'bg-[#FCEBEB] text-[#A32D2D]';
    }
    return 'bg-[#FFF4E6] text-[#C2410C]';
}
</script>

<template>
    <PointageLayout title="Pointage — Mes déclarations" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-semibold text-[#0C447C]">Mes déclarations</h1>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md bg-[#185FA5] px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-[#144a84]"
                    @click="openModal"
                >
                    + Nouvelle déclaration
                </button>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-[#e2e0d8] bg-white px-4 py-3">
                <div class="flex items-center gap-2">
                    <Link
                        :href="prevMonthUrl"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-[#e2e0d8] text-[#0C447C] hover:bg-[#FAFAF8]"
                        preserve-scroll
                    >
                        <ChevronLeft class="h-4 w-4" />
                    </Link>
                    <span class="min-w-[10rem] text-center text-sm font-semibold text-[#0C447C]">{{ periode_label }}</span>
                    <Link
                        :href="nextMonthUrl"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-[#e2e0d8] text-[#0C447C] hover:bg-[#FAFAF8]"
                        preserve-scroll
                    >
                        <ChevronRight class="h-4 w-4" />
                    </Link>
                </div>
            </div>

            <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white shadow-sm">
                <div class="border-b border-[#e2e0d8] bg-[#FAFAF8] px-4 py-3">
                    <h2 class="text-sm font-semibold text-[#0C447C]">Mes déclarations — {{ periode_label }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="border-b border-[#e2e0d8] bg-[#FAFAF8] text-left text-[10px] font-bold uppercase tracking-wide text-[#888780]">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Motif</th>
                                <th class="px-4 py-3">Justificatif</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Validateur</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in declarations.data" :key="d.id" class="border-b border-[#F1EFE8] last:border-0 hover:bg-[#FAFAF8]/80">
                                <td class="whitespace-nowrap px-4 py-3 text-[#0C447C]">
                                    <template v-if="d.date_fin_display">{{ d.date_concernee_display }} → {{ d.date_fin_display }}</template>
                                    <template v-else>
                                        {{ d.date_concernee_display }}
                                        <span v-if="d.heure_debut && d.heure_fin" class="block text-xs text-[#888780]">{{ d.heure_debut }}–{{ d.heure_fin }}</span>
                                    </template>
                                    <span v-if="d.lieu" class="block text-xs text-[#888780]">{{ d.lieu }}</span>
                                </td>
                                <td class="px-4 py-3">{{ d.type_label }}</td>
                                <td class="max-w-[200px] px-4 py-3">
                                    <span class="line-clamp-2" :title="d.motif">{{ d.motif }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span v-if="d.has_justificatif" class="inline-flex items-center gap-1 text-[#185FA5]" :title="d.justificatif_filename ?? ''">
                                        <Paperclip class="h-4 w-4 shrink-0" />
                                        <span class="max-w-[120px] truncate text-xs">{{ d.justificatif_filename ?? 'Pièce jointe' }}</span>
                                    </span>
                                    <span v-else class="text-[#888780]">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statutBadgeClass(d.statut)">
                                        {{ d.statut_label }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-[#888780]">{{ d.validateur_label }}</td>
                            </tr>
                            <tr v-if="!declarations.data?.length">
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-[#888780]">Aucune déclaration pour cette période.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="declarations.last_page > 1" class="flex flex-wrap justify-center gap-1 border-t border-[#e2e0d8] px-4 py-3">
                    <template v-for="(link, i) in declarations.links" :key="i">
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
        </div>

        <Dialog v-model:open="showModal">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle class="text-lg font-semibold text-[#0C447C]">Nouvelle déclaration</DialogTitle>
                    <DialogDescription class="sr-only">Nouvelle déclaration</DialogDescription>
                </DialogHeader>

                <form class="space-y-4 pt-2" @submit.prevent="submitDeclaration">
                    <DeclarationFormFields v-model:form="form" />

                    <div class="flex flex-wrap justify-end gap-2 border-t border-[#e2e0d8] pt-4">
                        <button type="button" class="rounded-md border border-[#e2e0d8] bg-white px-4 py-2 text-sm font-medium text-[#0C447C] hover:bg-[#FAFAF8]" @click="closeModal">
                            Annuler
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-2 rounded-md bg-[#185FA5] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        >
                            Envoyer la déclaration
                            <span aria-hidden="true">→</span>
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </PointageLayout>
</template>
