<script setup lang="ts">
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    heading: string;
    description: string;
    /** Groupe du menu (ex. « Jour ouvrable », « Jours fériés ») entre Configuration et la page. */
    breadcrumbGroupTitle?: string | null;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const items: BreadcrumbItem[] = [
        { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
        { title: 'Configuration', href: '#' },
    ];
    if (props.breadcrumbGroupTitle) {
        items.push({ title: props.breadcrumbGroupTitle, href: '#' });
    }
    items.push({ title: props.heading, href: '#' });
    return items;
});
</script>

<template>
    <PointageLayout :title="props.heading" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-3xl space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-[#0C447C]">{{ props.heading }}</h1>
            </div>
            <Link href="/pointage/rh/parametrage" class="text-sm font-medium text-[#185FA5] underline hover:no-underline">
                Paramétrage RH (horaires &amp; tolérances)
            </Link>
        </div>
    </PointageLayout>
</template>
