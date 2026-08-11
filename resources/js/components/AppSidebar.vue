<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building,
    Building2,
    Calendar,
    Clock,
    Coffee,
    FileSpreadsheet,
    LayoutGrid,
    ListChecks,
    MapPin,
    QrCode,
    Scale,
    ShieldCheck,
    SlidersHorizontal,
    Timer,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage();
const auth = computed(() => page.props.auth as any);

const adminNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Tableau de matière',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (auth.value?.isSuperAdmin) {
        items.push(
            {
                title: 'Profil',
                href: '/profils',
                icon: Users,
            },
            {
                title: 'Rôles',
                href: '/roles',
                icon: ShieldCheck,
            },
            {
                title: 'Départements',
                href: '/departements',
                icon: Building2,
            },
            {
                title: 'Agences',
                href: '/agences',
                icon: MapPin,
            },
            {
                title: 'Filiales',
                href: '/filiales',
                icon: Building,
            },
        );
    }

    return items;
});

const moduleNavItems = computed<NavItem[]>(() => {
    if (!auth.value?.user || !(auth.value?.isRh || auth.value?.isAdmin || auth.value?.isSuperAdmin)) {
        return [];
    }

    /** Menu opérationnel RH (ce que le rôle RH doit voir). */
    const pointageItems: NavItem[] = [
        { title: 'Dashboard', href: '/pointage/rh/presence/recuperation-pointages', icon: LayoutGrid },
        { title: 'Demande', href: '/pointage/demandes', icon: Scale },
        { title: 'Validation N+1', href: '/pointage/declarations/validation-manager', icon: ShieldCheck },
        { title: 'Reporting RH', href: '/pointage/rapport/reporting', icon: FileSpreadsheet },
        { title: 'Synthèse journalière', href: '/pointage/rh/tous-pointages', icon: ListChecks },
    ];

    /** QR / affectations / config : admin & super-admin uniquement. */
    const canConfigurePointage = Boolean(auth.value?.isAdmin || auth.value?.isSuperAdmin);
    if (canConfigurePointage) {
        const configurationItems: NavItem[] = [
            {
                title: 'Gestion des Horaires',
                href: '/pointage/rh/parametrage#gestion-horaires',
                icon: SlidersHorizontal,
            },
            {
                title: 'Jour ouvrable',
                icon: Clock,
                items: [
                    { title: 'Définition des jours ouvrables', href: '/pointage/rh/presence/jours-ouvrables' },
                    { title: 'Gestion des week-ends', href: '/pointage/rh/presence/week-ends' },
                ],
            },
            {
                title: 'Jours fériés',
                icon: Calendar,
                items: [
                    { title: 'Paramétrage des jours fériés', href: '/pointage/rh/presence/jours-feries' },
                    { title: 'Calendrier annuel', href: '/pointage/rh/presence/jours-feries-calendrier' },
                ],
            },
            {
                title: 'Gestion des pauses',
                icon: Coffee,
                items: [
                    { title: 'Pause déjeuner', href: '/pointage/rh/presence/pauses/dejeuner' },
                    { title: 'Pause technique', href: '/pointage/rh/presence/pauses/technique' },
                    { title: 'Temps de pause autorisé', href: '/pointage/rh/presence/pauses/duree' },
                ],
            },
        ];

        pointageItems.push(
            { title: 'Génération QR Code par agence', href: '/pointage/sites', icon: QrCode },
            { title: 'Affectation des services/agences', href: '/pointage/rh/employes', icon: Users },
            { title: 'Configuration', items: configurationItems },
        );
    }

    return [
        {
            title: 'Pointage',
            icon: Timer,
            items: pointageItems,
        },
    ];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader class="pb-4">
            <SidebarMenu>
                <SidebarMenuItem>
                    <Link :href="dashboard()" class="flex items-center p-2">
                        <AppLogo />
                    </Link>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="pt-4">
            <NavMain :items="adminNavItems" />
            <NavMain v-if="moduleNavItems.length" :items="moduleNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
