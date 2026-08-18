<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import DataTable, { type Column } from '@/components/DataTable.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { computed, ref } from 'vue';
import { Eye, Pencil, Trash2, Filter, Upload, Download, Lock, Unlock, UserPlus, Plus } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';

interface CompteUtilisateur {
    id: number;
    is_active: boolean;
    roles_label: string;
    agence_label: string;
}

interface Profil {
    id: number;
    matricule: string;
    prenom: string;
    nom: string;
    fonction?: string;
    departement?: string;
    email?: string;
    telephone?: string;
    site?: string;
    type_contrat: string;
    statut: string;
    compte?: CompteUtilisateur | null;
}

interface Props {
    profils: {
        data: Profil[];
        links?: any[];
        meta?: any;
        total?: number;
        current_page?: number;
        per_page?: number;
        last_page?: number;
    };
    departements?: Array<{ id: number; nom: string }>;
    agences?: Array<{ id: number; nom: string }>;
    filiales?: Array<{ id: number; nom: string }>;
    roles?: Array<{ id: number; nom: string }>;
    canManageComptes?: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const flash = computed(() => page.props.flash as { success?: string; error?: string } | undefined);

// Filtres
const filters = ref({
    statut: '',
    departement: '',
    fonction: '',
    site: '',
    filiale: '',
    type_contrat: '',
    compte: '',
    role: '',
    activation: '',
    search: '',
});

const applyFilters = () => {
    const params = new URLSearchParams();
    Object.entries(filters.value).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });
    params.set('page', '1');
    router.visit(`/profils?${params.toString()}`, { preserveScroll: true });
};

const exportProfils = () => {
    const params = new URLSearchParams();
    Object.entries(filters.value).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });
    window.location.href = `/profils/export?${params.toString()}`;
};

// Initialiser les filtres depuis l'URL
const initializeFilters = () => {
    const urlParams = new URLSearchParams(window.location.search);
    filters.value.statut = urlParams.get('statut') || '';
    filters.value.departement = urlParams.get('departement') || '';
    filters.value.fonction = urlParams.get('fonction') || '';
    filters.value.site = urlParams.get('site') || '';
    filters.value.filiale = urlParams.get('filiale') || '';
    filters.value.type_contrat = urlParams.get('type_contrat') || '';
    filters.value.compte = urlParams.get('compte') || '';
    filters.value.role = urlParams.get('role') || '';
    filters.value.activation = urlParams.get('activation') || '';
    filters.value.search = urlParams.get('search') || '';
};

// Initialiser au chargement
initializeFilters();

const currentPage = computed(() => props.profils.current_page || props.profils.meta?.current_page || 1);
const totalItems = computed(() => props.profils.total || props.profils.meta?.total || 0);
const perPage = computed(() => props.profils.per_page || props.profils.meta?.per_page || 5);

const handlePageChange = (page: number) => {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('page', page.toString());
    if (perPage.value) {
        urlParams.set('per_page', perPage.value.toString());
    }
    const newUrl = `/profils?${urlParams.toString()}`;
    router.get(newUrl, {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['profils'],
        replace: false,
    });
};

const handleItemsPerPageChange = (items: number) => {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('per_page', items.toString());
    urlParams.set('page', '1');
    const newUrl = `/profils?${urlParams.toString()}`;
    router.get(newUrl, {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['profils'],
        replace: false,
    });
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profil',
        href: '/profils',
    },
];

const deleteProfil = (id: number) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer ce profil ?')) {
        router.delete(`/profils/${id}`);
    }
};

const creerCompte = (id: number) => {
    if (confirm('Créer un compte utilisateur pour ce profil ? Un mot de passe temporaire sera affiché.')) {
        router.post(`/profils/${id}/creer-compte`, {}, {
            preserveScroll: true,
        });
    }
};

const toggleCompte = (userId: number) => {
    if (confirm('Changer le statut d’activation de ce compte utilisateur ?')) {
        router.post(`/users/${userId}/toggle`, {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['profils'],
        });
    }
};

const getAvatarColor = (name: string) => {
    const colors = [
        'bg-purple-500',
        'bg-blue-500',
        'bg-green-500',
        'bg-yellow-500',
        'bg-pink-500',
        'bg-indigo-500',
        'bg-red-500',
        'bg-teal-500',
    ];
    const index = name.charCodeAt(0) % colors.length;
    return colors[index];
};

const getStatusBadge = (statut: string) => {
    if (statut === 'actif') {
        return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
    }
    return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200';
};

const getStatusLabel = (statut: string) => {
    return statut === 'actif' ? 'Actif' : 'Inactif';
};

const columns: Column[] = [
    { key: 'name', title: 'COLLABORATEUR', sortable: true },
    { key: 'matricule', title: 'MATRICULE', sortable: true },
    { key: 'email', title: 'E-MAIL' },
    { key: 'agence', title: 'AGENCE' },
    { key: 'departement', title: 'DÉPARTEMENT' },
    { key: 'compte', title: 'COMPTE' },
    { key: 'roles', title: 'RÔLE' },
    { key: 'activation', title: 'ACTIVATION' },
    { key: 'statut', title: 'STATUT RH' },
    { key: 'actions', title: 'ACTIONS' },
];

const tableData = computed(() => {
    const profilsData = props.profils.data || props.profils;
    const profilsArray = Array.isArray(profilsData) ? profilsData : [];
    return profilsArray.map((profil) => ({
        id: profil.id,
        name: `${profil.prenom} ${profil.nom}`,
        matricule: profil.matricule,
        email: profil.email || '—',
        agence: profil.compte?.agence_label || profil.site || '—',
        departement: profil.departement || '—',
        compte: profil.compte ? 'Oui' : 'Non',
        roles: profil.compte?.roles_label || '—',
        activation: profil.compte ? (profil.compte.is_active ? 'Actif' : 'Inactif') : '—',
        statut: profil.statut,
        profil,
        compteId: profil.compte?.id ?? null,
        compteActif: profil.compte?.is_active ?? null,
    }));
});
</script>

<template>
    <Head title="Profils & comptes" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 sm:gap-6 p-4 sm:p-6">
            <div
                v-if="flash?.success"
                class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                {{ flash.success }}
            </div>
            <div
                v-if="flash?.error"
                class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
            >
                {{ flash.error }}
            </div>
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Profils & comptes</h1>
                    <p class="mt-1 text-sm text-gray-500">Fiche RH et compte de connexion sur une seule vue.</p>
                </div>
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-3 w-full sm:w-auto">
                    <Button
                        variant="outline"
                        class="border-[#185FA5] text-[#185FA5] hover:bg-blue-50 w-full sm:w-auto"
                        @click="router.visit('/pointage/rh/employes?create=1')"
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        Créer un profil
                    </Button>
                    <Button
                        @click="exportProfils"
                        class="bg-purple-600 hover:bg-purple-700 w-full sm:w-auto"
                    >
                        <Download class="mr-2 h-4 w-4" />
                        <span class="hidden sm:inline">Exporter Excel</span>
                        <span class="sm:hidden">Exporter</span>
                    </Button>
                    <Button
                        @click="router.visit('/profils/import')"
                        class="bg-green-600 hover:bg-green-700 w-full sm:w-auto"
                    >
                        <Upload class="mr-2 h-4 w-4" />
                        <span class="hidden sm:inline">Importer Excel</span>
                        <span class="sm:hidden">Importer</span>
                    </Button>
                </div>
            </div>

            <!-- Section Filtres -->
            <div class="rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                <div class="mb-4 flex items-center gap-2">
                    <Filter class="h-4 w-4 sm:h-5 sm:w-5 text-gray-500" />
                    <h2 class="text-base font-semibold text-gray-700">Filtres</h2>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Statut</label>
                        <select
                            v-model="filters.statut"
                            class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-3 py-1 text-base text-gray-900 shadow-sm transition-[color,box-shadow] outline-none focus-visible:border-gray-400 focus-visible:ring-1 focus-visible:ring-gray-400"
                        >
                            <option value="">Tous les statuts</option>
                            <option value="actif">Actif</option>
                            <option value="inactif">Inactif</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Département</label>
                        <select
                            v-model="filters.departement"
                            class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-3 py-1 text-base text-gray-900 shadow-sm transition-[color,box-shadow] outline-none focus-visible:border-gray-400 focus-visible:ring-1 focus-visible:ring-gray-400"
                        >
                            <option value="">Tous les départements</option>
                            <option
                                v-for="dept in props.departements || []"
                                :key="dept.id"
                                :value="dept.id"
                            >
                                {{ dept.nom }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Agence</label>
                        <select
                            v-model="filters.site"
                            class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-3 py-1 text-base text-gray-900 shadow-sm transition-[color,box-shadow] outline-none focus-visible:border-gray-400 focus-visible:ring-1 focus-visible:ring-gray-400"
                        >
                            <option value="">Toutes les agences</option>
                            <option
                                v-for="agence in props.agences || []"
                                :key="agence.id"
                                :value="agence.id"
                            >
                                {{ agence.nom }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Filiale</label>
                        <select
                            v-model="filters.filiale"
                            class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-3 py-1 text-base text-gray-900 shadow-sm transition-[color,box-shadow] outline-none focus-visible:border-gray-400 focus-visible:ring-1 focus-visible:ring-gray-400"
                        >
                            <option value="">Toutes les filiales</option>
                            <option
                                v-for="filiale in props.filiales || []"
                                :key="filiale.id"
                                :value="filiale.id"
                            >
                                {{ filiale.nom }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Fonction</label>
                        <Input
                            v-model="filters.fonction"
                            type="text"
                            placeholder="Rechercher une fonction"
                            class="border-gray-300 focus-visible:border-gray-400"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Type de contrat</label>
                        <select
                            v-model="filters.type_contrat"
                            class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-3 py-1 text-base text-gray-900 shadow-sm transition-[color,box-shadow] outline-none focus-visible:border-gray-400 focus-visible:ring-1 focus-visible:ring-gray-400"
                        >
                            <option value="">Tous les types</option>
                            <option value="CDI">CDI</option>
                            <option value="CDD">CDD</option>
                            <option value="Stagiaire">Stagiaire</option>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Compte utilisateur</label>
                        <select
                            v-model="filters.compte"
                            class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-3 py-1 text-base text-gray-900 shadow-sm transition-[color,box-shadow] outline-none focus-visible:border-gray-400 focus-visible:ring-1 focus-visible:ring-gray-400"
                        >
                            <option value="">Tous</option>
                            <option value="avec">Avec compte</option>
                            <option value="sans">Sans compte</option>
                        </select>
                    </div>
                    <div v-if="props.canManageComptes">
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Rôle</label>
                        <select
                            v-model="filters.role"
                            class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-3 py-1 text-base text-gray-900 shadow-sm transition-[color,box-shadow] outline-none focus-visible:border-gray-400 focus-visible:ring-1 focus-visible:ring-gray-400"
                        >
                            <option value="">Tous les rôles</option>
                            <option v-for="r in props.roles || []" :key="r.id" :value="r.id">{{ r.nom }}</option>
                        </select>
                    </div>
                    <div v-if="props.canManageComptes">
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Activation compte</label>
                        <select
                            v-model="filters.activation"
                            class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-3 py-1 text-base text-gray-900 shadow-sm transition-[color,box-shadow] outline-none focus-visible:border-gray-400 focus-visible:ring-1 focus-visible:ring-gray-400"
                        >
                            <option value="">Tous</option>
                            <option value="1">Compte actif</option>
                            <option value="0">Compte inactif</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-base font-medium text-gray-700">Recherche</label>
                        <Input
                            v-model="filters.search"
                            type="text"
                            placeholder="Nom, prénom, matricule, email"
                            class="border-gray-300 focus-visible:border-gray-400"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                </div>
                <div class="mt-4 flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                    <Button @click="applyFilters" class="bg-blue-600 hover:bg-blue-700 w-full sm:w-auto">
                        Appliquer les filtres
                    </Button>
                    <Button variant="outline" @click="() => { filters.statut = ''; filters.departement = ''; filters.fonction = ''; filters.site = ''; filters.filiale = ''; filters.type_contrat = ''; filters.compte = ''; filters.role = ''; filters.activation = ''; filters.search = ''; applyFilters(); }" class="border-gray-300 w-full sm:w-auto">
                        Réinitialiser
                    </Button>
                </div>
            </div>

            <DataTable
                :headers="columns"
                :items="tableData"
                :current-page="currentPage"
                :items-per-page="perPage"
                :total-items="totalItems"
                show-select
                @page-change="handlePageChange"
                @items-per-page-change="handleItemsPerPageChange"
            >
                <template #item.name="{ item }">
                    <div class="flex items-center gap-3">
                        <Avatar class="h-10 w-10">
                            <AvatarImage :src="`https://ui-avatars.com/api/?name=${encodeURIComponent(item.name)}&background=random`" />
                            <AvatarFallback :class="getAvatarColor(item.name)">
                                {{ getInitials(item.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <div class="font-medium text-gray-900">{{ item.name }}</div>
                            <div class="text-xs text-gray-500">{{ item.profil.fonction || 'Employé' }}</div>
                        </div>
                    </div>
                </template>

                <template #item.matricule="{ item }">
                    <span class="font-mono text-sm text-gray-900">{{ item.matricule }}</span>
                </template>

                <template #item.email="{ item }">
                    <span class="text-gray-900">{{ item.email }}</span>
                </template>

                <template #item.agence="{ item }">
                    <span class="text-gray-900">{{ item.agence }}</span>
                </template>

                <template #item.departement="{ item }">
                    <span class="text-gray-900">{{ item.departement }}</span>
                </template>

                <template #item.compte="{ item }">
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="item.compte === 'Oui' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600'"
                    >
                        {{ item.compte }}
                    </span>
                </template>

                <template #item.roles="{ item }">
                    <span class="text-gray-900">{{ item.roles }}</span>
                </template>

                <template #item.activation="{ item }">
                    <span
                        v-if="item.activation !== '—'"
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="item.compteActif ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                    >
                        {{ item.activation }}
                    </span>
                    <span v-else class="text-gray-400">—</span>
                </template>

                <template #item.statut="{ item }">
                    <span
                        :class="[
                            'rounded-full px-3 py-1 text-xs font-medium',
                            getStatusBadge(item.statut),
                        ]"
                    >
                        {{ getStatusLabel(item.statut) }}
                    </span>
                </template>

                <template #item.actions="{ item }">
                    <div class="flex items-center gap-1">
                        <Link
                            :href="`/profils/${item.id}`"
                            class="inline-flex items-center justify-center rounded-md p-2 text-gray-600 hover:bg-gray-100 hover:text-gray-900 transition-colors"
                            title="Voir le profil"
                        >
                            <Eye class="h-5 w-5" />
                        </Link>
                        <Link
                            :href="`/profils/${item.id}/edit`"
                            class="inline-flex items-center justify-center rounded-md p-2 text-gray-600 hover:bg-gray-100 hover:text-gray-900 transition-colors"
                            title="Modifier le profil"
                        >
                            <Pencil class="h-5 w-5" />
                        </Link>
                        <button
                            v-if="props.canManageComptes && !item.compteId"
                            type="button"
                            class="inline-flex items-center justify-center rounded-md p-2 text-blue-600 hover:bg-blue-50 hover:text-blue-700 transition-colors"
                            title="Créer le compte utilisateur"
                            @click="creerCompte(item.id)"
                        >
                            <UserPlus class="h-5 w-5" />
                        </button>
                        <button
                            v-if="props.canManageComptes && item.compteId"
                            type="button"
                            class="inline-flex items-center justify-center rounded-md p-2 text-amber-600 hover:bg-amber-50 hover:text-amber-700 transition-colors"
                            :title="item.compteActif ? 'Désactiver le compte' : 'Activer le compte'"
                            @click="toggleCompte(item.compteId!)"
                        >
                            <Unlock v-if="item.compteActif" class="h-5 w-5" />
                            <Lock v-else class="h-5 w-5" />
                        </button>
                        <button
                            type="button"
                            @click="deleteProfil(item.id)"
                            class="inline-flex items-center justify-center rounded-md p-2 text-red-600 hover:bg-red-50 hover:text-red-700 transition-colors"
                            title="Supprimer le profil"
                        >
                            <Trash2 class="h-5 w-5" />
                        </button>
                    </div>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>

