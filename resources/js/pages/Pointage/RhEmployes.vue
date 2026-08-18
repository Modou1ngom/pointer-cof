<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import ActionIconButton from '@/components/pointage/ActionIconButton.vue';
import PointageLayout from '@/layouts/pointage/PointageLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Deferred, Link, router, usePage } from '@inertiajs/vue3';
import { Eye, PauseCircle, Pencil, PlayCircle, Umbrella } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { readCsrfTokenFromDom } from '@/lib/csrf';

type Option = { value: string; label: string };

type AgencePicker = { id: number; nom: string; code_agent: string | null; filiale_id: number | null };

type AgenceAutorisee = {
    id: number;
    code_agent: string | null;
    nom: string;
    date_debut_autorisation: string | null;
    date_fin_autorisation: string | null;
    statut_agence: string;
    niveau_acces: string;
    is_default: boolean;
};

type ProfilPayload = {
    id: number;
    matricule: string | null;
    nom: string;
    prenom: string;
    fonction: string | null;
    departement: string | null;
    service: string | null;
    email: string | null;
    telephone: string | null;
    statut: string | null;
};

type UserLite = { id: number; email: string; name: string };

type AffectationRow = {
    id: number;
    profil_id: number;
    user_id: number | null;
    nom: string;
    prenom: string;
    matricule: string | null;
    email: string | null;
    departement: string | null;
    agence: string | null;
    statut_activation: boolean;
    type_pointage: string;
    date_affectation: string | null;
    enrolled_at: string | null;
    has_user_account: boolean;
    note_type?: string | null;
    note_label?: string | null;
    note_declaration_id?: number | null;
};

const props = defineProps<{
    affectations?: {
        data: AffectationRow[];
        links?: { url: string | null; label: string; active: boolean }[];
        current_page?: number;
        last_page?: number;
    } | null;
    total_enroles: number;
    total_actifs: number;
    filters: { agence: string; service: string; statut: string; filiale?: string; search?: string };
    agences: string[];
    services: string[];
    horaire_display: string;
    agences_picker: AgencePicker[];
    type_pointage_options: Option[];
    mode_validation_options: Option[];
    niveau_acces_options: Option[];
    profil_form: {
        departements: { id: number; nom: string }[];
        profils: { id: number; nom: string; prenom: string; matricule: string }[];
        filiales: { id: number; nom: string }[];
        user_filiale_id: number | null;
        is_super_admin: boolean;
        next_matricule?: string;
    };
    n1_profils?: { id: number; nom: string; prenom: string; matricule: string | null }[] | null;
}>();

const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pointage & Présence', href: '/pointage/rh/presence/recuperation-pointages' },
    { title: 'Affectation des services/agences', href: '#' },
];

const enrollOpen = ref(false);
const creationOpen = ref(false);
const congeOpen = ref(false);
const congeRow = ref<AffectationRow | null>(null);
const congeTypes = [
    { value: 'conge_annuel', label: 'Congé annuel' },
    { value: 'conge_maladie', label: 'Congé maladie' },
    { value: 'permission_exceptionnelle', label: 'Permission exceptionnelle' },
    { value: 'allaitement', label: 'Allaitement' },
    { value: 'mission', label: 'Mission' },
] as const;
const congeForm = reactive({
    type: 'conge_annuel',
    date_concernee: new Date().toISOString().slice(0, 10),
    date_fin: new Date().toISOString().slice(0, 10),
    heure_debut: '08:00',
    heure_fin: '17:00',
    sens: 'entree',
    lieu: '',
    motif: 'Congé annuel',
    commentaire: '',
});
const congeLoading = ref(false);
const congeNeedsHeures = computed(() => congeForm.type === 'permission_exceptionnelle');
const congeNeedsAllaitement = computed(() => congeForm.type === 'allaitement');
const congeNeedsLieu = computed(() => congeForm.type === 'mission');
const congeDialogHint = computed(() =>
    congeForm.type === 'allaitement'
        ? '— horaire d’entrée/sortie ajusté sur la période (l’employé continue de pointer).'
        : '— l’employé sera justifié, plus compté en absence.',
);
function congeTypeLabel(value: string): string {
    return congeTypes.find((t) => t.value === value)?.label ?? value;
}
const emailSearch = ref('');
const lookupLoading = ref(false);
const enrollLoading = ref(false);
const createProfilLoading = ref(false);
const profilCreateErrors = ref<Record<string, string>>({});
const actionLoading = ref(false);
const flashMessage = ref<{ type: 'ok' | 'err'; text: string } | null>(null);
const creationFlashMessage = ref<{ type: 'ok' | 'err'; text: string } | null>(null);

const affectationVue = ref<{ id: number; profil_id: number; user_id: number | null } | null>(null);
const alreadyEnrolled = ref(false);
const profilVue = ref<ProfilPayload | null>(null);
const userVue = ref<UserLite | null>(null);
const agencesVue = ref<AgenceAutorisee[]>([]);
/** Agences choisies avant confirmation d’enrôlement (table pointage_affectation_agences). */
const agencesPending = ref<AgenceAutorisee[]>([]);
/** Ouvre la modale depuis « Voir » : formulaire lecture seule. */
const enrollmentModalReadOnly = ref(false);

const paramForm = reactive({
    type_pointage: 'qr_et_gps',
    mode_validation: 'validation_manager',
    date_affectation: '',
    date_fin_affectation: '',
    statut_activation: true,
});

const addAgenceForm = reactive({
    agence_id: '' as string | number,
    date_debut_autorisation: '',
    date_fin_autorisation: '',
    statut_agence: 'actif' as 'actif' | 'inactif',
    niveau_acces: 'pointage_complet',
    is_default: false,
});

const editRows = reactive<Record<number, Partial<AgenceAutorisee>>>({});

const selectedFiliale = ref<number | null>(
    props.profil_form.user_filiale_id && !props.profil_form.is_super_admin ? props.profil_form.user_filiale_id : null,
);

const profilCreateForm = reactive({
    matricule: props.profil_form.next_matricule ?? '',
    nom: '',
    prenom: '',
    fonction: '',
    departement: '',
    email: '',
    telephone: '',
    agence_id: null as number | null,
    filiale_id: null as number | null,
    type_contrat: 'CDI' as 'CDI' | 'CDD' | 'Stagiaire' | 'Autre',
    statut: 'actif' as 'actif' | 'inactif',
    n_plus_1_id: null as string | number | null,
});

const userCreateForm = reactive({
    password: '',
    password_confirmation: '',
    must_change_password: true,
});

if (props.profil_form.user_filiale_id && !props.profil_form.is_super_admin) {
    profilCreateForm.filiale_id = props.profil_form.user_filiale_id;
}

const agencesFiltrees = computed(() => {
    const filialeId = selectedFiliale.value || profilCreateForm.filiale_id;
    if (filialeId) {
        return props.agences_picker.filter((a) => a.filiale_id === filialeId);
    }
    return props.agences_picker;
});

const agencesListeFiltrees = computed(() => {
    const fid = props.filters.filiale;
    if (!fid || fid === 'tous') {
        return props.agences;
    }
    const allowed = new Set(
        props.agences_picker.filter((a) => String(a.filiale_id) === String(fid)).map((a) => a.nom),
    );
    return props.agences.filter((nom) => allowed.has(nom));
});

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('create') === '1') {
        openCreation();
    }
});

watch(selectedFiliale, (v) => {
    profilCreateForm.agence_id = null;
    profilCreateForm.filiale_id = v;
});

watch(
    () => profilCreateForm.agence_id,
    (agenceId) => {
        if (!agenceId) return;
        const agence = props.agences_picker.find((a) => a.id === agenceId);
        if (agence?.filiale_id) {
            profilCreateForm.filiale_id = agence.filiale_id;
            selectedFiliale.value = agence.filiale_id;
        }
    },
);

function resetProfilCreateForm() {
    profilCreateForm.matricule = props.profil_form.next_matricule ?? '';
    profilCreateForm.nom = '';
    profilCreateForm.prenom = '';
    profilCreateForm.fonction = '';
    profilCreateForm.departement = '';
    profilCreateForm.email = '';
    profilCreateForm.telephone = '';
    profilCreateForm.agence_id = null;
    profilCreateForm.type_contrat = 'CDI';
    profilCreateForm.statut = 'actif';
    profilCreateForm.n_plus_1_id = null;
    profilCreateForm.filiale_id =
        props.profil_form.user_filiale_id && !props.profil_form.is_super_admin ? props.profil_form.user_filiale_id : null;
    selectedFiliale.value =
        props.profil_form.user_filiale_id && !props.profil_form.is_super_admin ? props.profil_form.user_filiale_id : null;
    profilCreateErrors.value = {};
    userCreateForm.password = '';
    userCreateForm.password_confirmation = '';
    userCreateForm.must_change_password = true;
}

function openCreation() {
    creationOpen.value = true;
    creationFlashMessage.value = null;
    resetProfilCreateForm();
}

function openEnroll() {
    enrollOpen.value = true;
    flashMessage.value = null;
    emailSearch.value = '';
    affectationVue.value = null;
    alreadyEnrolled.value = false;
    profilVue.value = null;
    userVue.value = null;
    agencesVue.value = [];
    agencesPending.value = [];
    addAgenceForm.agence_id = '';
    addAgenceForm.date_debut_autorisation = '';
    addAgenceForm.date_fin_autorisation = '';
    addAgenceForm.statut_agence = 'actif';
    addAgenceForm.niveau_acces = 'pointage_complet';
    addAgenceForm.is_default = false;
    paramForm.type_pointage = 'qr_et_gps';
    paramForm.mode_validation = 'validation_manager';
    paramForm.date_affectation = '';
    paramForm.date_fin_affectation = '';
    paramForm.statut_activation = true;
    Object.keys(editRows).forEach((k) => delete editRows[Number(k)]);
    enrollmentModalReadOnly.value = false;
}

async function openEnrollWithEmail(email: string) {
    openEnroll();
    emailSearch.value = email.trim();
    if (emailSearch.value) {
        await rechercherCollaborateur();
    }
}

function csrfToken(): string {
    const shared = page.props.csrf_token as string | undefined;
    if (shared?.trim()) {
        return shared.trim();
    }

    return readCsrfTokenFromDom();
}

function messageFromApi(data: { message?: string; errors?: Record<string, string[]> }): string {
    if (data.errors) {
        return Object.values(data.errors).flat().join(' ');
    }

    return data.message ?? 'Erreur.';
}

const searchInput = ref(props.filters.search ?? '');

function applyFilters(overrides: Partial<{ agence: string; service: string; statut: string; filiale: string; search: string }>) {
    router.get(
        '/pointage/rh/employes',
        {
            agence: overrides.agence ?? props.filters.agence,
            service: overrides.service ?? props.filters.service,
            statut: overrides.statut ?? props.filters.statut,
            filiale: overrides.filiale ?? props.filters.filiale ?? 'tous',
            search: overrides.search ?? searchInput.value ?? props.filters.search ?? '',
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/** Met à jour le tableau principal sans fermer la modale (agences / paramètres). */
function reloadListeRhEmployes() {
    router.reload({
        only: ['affectations', 'total_enroles', 'total_actifs', 'agences'],
        preserveScroll: true,
        preserveState: true,
    });
}

function statutBadgeClass(actif: boolean): string {
    return actif ? 'bg-[#EAF3DE] text-[#3B6D11]' : 'bg-[#F1EFE8] text-[#888780]';
}

function statutLabel(actif: boolean): string {
    return actif ? 'Actif' : 'Inactif';
}

function noteBadgeClass(type: string | null | undefined): string {
    switch (type) {
        case 'conge_annuel':
            return 'bg-violet-100 text-violet-700';
        case 'conge_maladie':
            return 'bg-pink-100 text-pink-700';
        case 'permission_exceptionnelle':
            return 'bg-amber-100 text-amber-800';
        case 'allaitement':
            return 'bg-rose-100 text-rose-800';
        case 'mission':
            return 'bg-teal-100 text-teal-700';
        case 'formation':
            return 'bg-blue-100 text-blue-700';
        default:
            return 'bg-[#F5FAFF] text-[#185FA5]';
    }
}

function profilStatutLabel(statut: string | null | undefined): string {
    if (statut === 'actif') return 'Actif (habilitations)';
    if (statut === 'inactif') return 'Inactif (habilitations)';
    return statut ?? '—';
}

/** Champs non modifiables (mode « Voir » une fois les données chargées). */
const modaleLectureSeule = computed(() => enrollmentModalReadOnly.value && !!profilVue.value);

function libelleOption(opts: Option[], value: string): string {
    return opts.find((o) => o.value === value)?.label ?? value;
}

function formaterDateCourt(d: string | null | undefined): string {
    if (!d) return '—';
    if (/^\d{4}-\d{2}-\d{2}$/.test(d)) {
        const [y, m, day] = d.split('-');
        return `${day}/${m}/${y}`;
    }
    return d;
}

async function creerProfil() {
    createProfilLoading.value = true;
    creationFlashMessage.value = null;
    profilCreateErrors.value = {};
    const token = csrfToken();
    if (!token) {
        creationFlashMessage.value = {
            type: 'err',
            text: 'Jeton de sécurité absent. Rechargez la page (F5), puis réessayez.',
        };
        createProfilLoading.value = false;
        return;
    }
    try {
        const res = await fetch('/pointage/rh/affectations/profil', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                ...profilCreateForm,
                ...userCreateForm,
                n_plus_1_id: profilCreateForm.n_plus_1_id || null,
                _token: token,
            }),
        });
        if (res.status === 419) {
            creationFlashMessage.value = {
                type: 'err',
                text: 'Session expirée. Rechargez la page (F5), reconnectez-vous si besoin, puis réessayez.',
            };
            return;
        }
        const data = (await res.json()) as {
            ok?: boolean;
            message?: string;
            errors?: Record<string, string[]>;
            profil?: ProfilPayload;
        };
        if (!res.ok || !data.ok) {
            if (data.errors) {
                profilCreateErrors.value = Object.fromEntries(
                    Object.entries(data.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)]),
                );
            }
            creationFlashMessage.value = { type: 'err', text: data.message ?? messageFromApi(data) };
            return;
        }
        const email = data.profil?.email ?? profilCreateForm.email.trim();
        creationOpen.value = false;
        await openEnrollWithEmail(email);
        flashMessage.value = {
            type: 'ok',
            text: data.message ?? 'Profil et compte créés. Finalisez l’enrôlement pointage ci-dessous.',
        };
    } catch {
        creationFlashMessage.value = { type: 'err', text: 'Erreur réseau.' };
    } finally {
        createProfilLoading.value = false;
    }
}

function onStatutActivationChange(e: Event) {
    const t = e.target as HTMLSelectElement;
    paramForm.statut_activation = t.value === 'actif';
}

function applySettingsFromApi(s: Record<string, unknown> | null | undefined) {
    if (!s) {
        paramForm.type_pointage = 'qr_et_gps';
        paramForm.mode_validation = 'validation_manager';
        paramForm.date_affectation = '';
        paramForm.date_fin_affectation = '';
        paramForm.statut_activation = true;
        return;
    }
    paramForm.type_pointage = (s.type_pointage as string) || 'qr_et_gps';
    paramForm.mode_validation = (s.mode_validation as string) || 'validation_manager';
    paramForm.date_affectation = (s.date_affectation as string) || '';
    paramForm.date_fin_affectation = (s.date_fin_affectation as string) || '';
    paramForm.statut_activation = s.statut_activation !== false;
}

async function rechercherCollaborateur() {
    const email = emailSearch.value.trim();
    if (!email) {
        flashMessage.value = { type: 'err', text: 'Saisissez l’adresse e-mail du collaborateur.' };
        return;
    }
    lookupLoading.value = true;
    flashMessage.value = null;
    try {
        const res = await fetch('/pointage/rh/affectations/lookup', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ email }),
        });
        const data = (await res.json()) as {
            ok?: boolean;
            message?: string;
            profil?: ProfilPayload;
            user?: UserLite;
            affectation?: { id: number; profil_id: number; user_id: number | null };
            already_enrolled?: boolean;
            settings?: Record<string, unknown> | null;
            agences_autorisees?: AgenceAutorisee[];
        };
        if (!res.ok || !data.ok) {
            profilVue.value = null;
            userVue.value = null;
            agencesVue.value = [];
            agencesPending.value = [];
            flashMessage.value = {
                type: 'err',
                text: res.status === 404
                    ? `${data.message ?? 'Collaborateur introuvable.'} Utilisez « Création profil ».`
                    : (data.message ?? 'Collaborateur introuvable.'),
            };
            return;
        }
        profilVue.value = data.profil ?? null;
        userVue.value = data.user ?? null;
        affectationVue.value = data.affectation ?? null;
        alreadyEnrolled.value = Boolean(data.already_enrolled);
        agencesVue.value = data.agences_autorisees ?? [];
        agencesPending.value = [];
        applySettingsFromApi(data.settings ?? null);
    } catch {
        flashMessage.value = { type: 'err', text: 'Erreur réseau.' };
    } finally {
        lookupLoading.value = false;
    }
}

async function enrollerCollaborateur() {
    const email = emailSearch.value.trim();
    if (!email) {
        flashMessage.value = { type: 'err', text: 'Saisissez l’e-mail du collaborateur.' };
        return;
    }
    enrollLoading.value = true;
    flashMessage.value = null;
    try {
        const res = await fetch('/pointage/rh/affectations/enroll', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                email,
                type_pointage: paramForm.type_pointage,
                mode_validation: paramForm.mode_validation,
                date_affectation: paramForm.date_affectation || null,
                date_fin_affectation: paramForm.date_fin_affectation || null,
                statut_activation: paramForm.statut_activation,
                agences: agencesPending.value.map((a) => ({
                    agence_id: a.id,
                    date_debut_autorisation: a.date_debut_autorisation || null,
                    date_fin_autorisation: a.date_fin_autorisation || null,
                    statut_agence: a.statut_agence,
                    niveau_acces: a.niveau_acces,
                    is_default: a.is_default,
                })),
            }),
        });
        let data: {
            ok?: boolean;
            message?: string;
            errors?: Record<string, string[]>;
            affectation?: { id: number; profil_id: number; user_id: number | null };
            profil?: ProfilPayload;
            user?: UserLite;
            settings?: Record<string, unknown>;
            agences_autorisees?: AgenceAutorisee[];
        };
        try {
            data = await res.json();
        } catch {
            flashMessage.value = {
                type: 'err',
                text: res.status === 419 ? 'Session expirée : rechargez la page puis réessayez.' : 'Réponse serveur invalide.',
            };
            return;
        }
        if (!res.ok || data.ok === false) {
            flashMessage.value = { type: 'err', text: messageFromApi(data) };
            return;
        }
        affectationVue.value = data.affectation ?? null;
        alreadyEnrolled.value = true;
        agencesVue.value = data.agences_autorisees ?? agencesVue.value;
        agencesPending.value = [];
        profilVue.value = data.profil ?? profilVue.value;
        userVue.value = data.user ?? null;
        applySettingsFromApi(data.settings ?? null);
        flashMessage.value = { type: 'ok', text: data.message ?? 'Collaborateur enrôlé.' };
        enrollOpen.value = false;
        // Sans query string : sinon les filtres site/service/statut peuvent masquer la ligne nouvellement créée.
        router.get('/pointage/rh/employes', {}, { preserveScroll: false, preserveState: false });
    } catch {
        flashMessage.value = { type: 'err', text: 'Erreur réseau.' };
    } finally {
        enrollLoading.value = false;
    }
}

async function openAffectation(id: number, readOnly: boolean) {
    openEnroll();
    enrollmentModalReadOnly.value = readOnly;
    actionLoading.value = true;
    try {
        const res = await fetch(`/pointage/rh/affectations/${id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = (await res.json()) as {
            ok?: boolean;
            affectation?: { id: number; profil_id: number; user_id: number | null };
            profil?: ProfilPayload;
            user?: UserLite;
            settings?: Record<string, unknown>;
            agences_autorisees?: AgenceAutorisee[];
        };
        if (!res.ok || !data.ok) return;
        affectationVue.value = data.affectation ?? null;
        alreadyEnrolled.value = true;
        profilVue.value = data.profil ?? null;
        userVue.value = data.user ?? null;
        emailSearch.value = data.profil?.email ?? '';
        agencesVue.value = data.agences_autorisees ?? [];
        applySettingsFromApi(data.settings ?? null);
    } finally {
        actionLoading.value = false;
    }
}

function toggleStatutAffectation(row: AffectationRow) {
    router.patch(`/pointage/rh/affectations/${row.id}/statut`, {}, { preserveScroll: true });
}

function openConge(row: AffectationRow) {
    if (!row.has_user_account) {
        return;
    }
    // Bascule : si une demande couvre déjà aujourd’hui, la retirer.
    if (row.note_type && row.note_declaration_id) {
        if (!window.confirm(`Retirer « ${row.note_label ?? 'la demande'} » pour ${row.prenom} ${row.nom} ?`)) {
            return;
        }
        router.delete(`/pointage/rh/affectations/${row.id}/conge`, {
            data: { declaration_id: row.note_declaration_id },
            preserveScroll: true,
        });
        return;
    }
    const today = new Date().toISOString().slice(0, 10);
    congeRow.value = row;
    congeForm.type = 'conge_annuel';
    congeForm.date_concernee = today;
    congeForm.date_fin = today;
    congeForm.heure_debut = '08:00';
    congeForm.heure_fin = '17:00';
    congeForm.sens = 'entree';
    congeForm.lieu = '';
    congeForm.motif = 'Congé annuel';
    congeForm.commentaire = '';
    congeOpen.value = true;
}

function onCongeAllaitementSens() {
    if (congeForm.type !== 'allaitement') {
        return;
    }
    congeForm.heure_fin = '';
    congeForm.heure_debut = congeForm.sens === 'sortie' ? '16:00' : '09:00';
}

function submitConge() {
    if (!congeRow.value) {
        return;
    }
    congeLoading.value = true;
    const payload: Record<string, string | null> = {
        type: congeForm.type,
        date_concernee: congeForm.date_concernee,
        date_fin: congeForm.date_fin,
        heure_debut: null,
        heure_fin: null,
        sens: null,
        lieu: congeNeedsLieu.value ? congeForm.lieu : null,
        motif: congeForm.motif,
        commentaire: congeForm.commentaire || null,
    };
    if (congeNeedsHeures.value) {
        payload.heure_debut = congeForm.heure_debut;
        payload.heure_fin = congeForm.heure_fin;
    }
    if (congeNeedsAllaitement.value) {
        payload.sens = congeForm.sens;
        payload.heure_debut = congeForm.heure_debut;
    }
    router.post(
        `/pointage/rh/affectations/${congeRow.value.id}/conge`,
        payload,
        {
            preserveScroll: true,
            onFinish: () => {
                congeLoading.value = false;
            },
            onSuccess: () => {
                congeOpen.value = false;
                congeRow.value = null;
            },
        },
    );
}

async function saveParametrage() {
    if (enrollmentModalReadOnly.value) {
        return;
    }
    if (!affectationVue.value) {
        flashMessage.value = { type: 'err', text: 'Enrôlez d’abord le collaborateur.' };
        return;
    }
    actionLoading.value = true;
    flashMessage.value = null;
    try {
        const res = await fetch(`/pointage/rh/affectations/${affectationVue.value.id}/parametrage`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                type_pointage: paramForm.type_pointage,
                mode_validation: paramForm.mode_validation,
                date_affectation: paramForm.date_affectation || null,
                date_fin_affectation: paramForm.date_fin_affectation || null,
                statut_activation: paramForm.statut_activation,
            }),
        });
        const data = (await res.json()) as { message?: string; settings?: Record<string, unknown>; errors?: Record<string, string[]> };
        if (!res.ok) {
            const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message ?? 'Erreur.';
            flashMessage.value = { type: 'err', text: msg };
            return;
        }
        applySettingsFromApi(data.settings ?? null);
        flashMessage.value = { type: 'ok', text: data.message ?? 'Enregistré.' };
        reloadListeRhEmployes();
    } catch {
        flashMessage.value = { type: 'err', text: 'Erreur réseau.' };
    } finally {
        actionLoading.value = false;
    }
}

const agencesAffichees = computed(() =>
    alreadyEnrolled.value ? agencesVue.value : [...agencesPending.value, ...agencesVue.value],
);

const agencesDisponiblesPourAjout = computed(() => {
    const pris = new Set(agencesAffichees.value.map((a) => a.id));
    return props.agences_picker.filter((a) => !pris.has(a.id));
});

function agenceDepuisFormulaire(agenceId: number): AgenceAutorisee | null {
    const picker = props.agences_picker.find((a) => a.id === agenceId);
    if (!picker) {
        return null;
    }
    return {
        id: picker.id,
        code_agent: picker.code_agent,
        nom: picker.nom,
        date_debut_autorisation: addAgenceForm.date_debut_autorisation || null,
        date_fin_autorisation: addAgenceForm.date_fin_autorisation || null,
        statut_agence: addAgenceForm.statut_agence,
        niveau_acces: addAgenceForm.niveau_acces,
        is_default: addAgenceForm.is_default,
    };
}

function reinitialiserFormulaireAgence() {
    addAgenceForm.agence_id = '';
    addAgenceForm.date_debut_autorisation = '';
    addAgenceForm.date_fin_autorisation = '';
    addAgenceForm.is_default = false;
}

async function ajouterAgence() {
    if (enrollmentModalReadOnly.value) {
        return;
    }
    const id = Number(addAgenceForm.agence_id);
    if (!id) {
        flashMessage.value = { type: 'err', text: 'Choisissez une agence.' };
        return;
    }
    if (agencesAffichees.value.some((a) => a.id === id)) {
        flashMessage.value = { type: 'err', text: 'Cette agence est déjà dans la liste pointage.' };
        return;
    }

    if (!affectationVue.value) {
        const row = agenceDepuisFormulaire(id);
        if (!row) {
            flashMessage.value = { type: 'err', text: 'Agence introuvable.' };
            return;
        }
        if (row.is_default || agencesPending.value.length === 0) {
            agencesPending.value.forEach((a) => {
                a.is_default = false;
            });
            row.is_default = true;
        }
        agencesPending.value.push(row);
        reinitialiserFormulaireAgence();
        flashMessage.value = { type: 'ok', text: 'Agence ajoutée (sera enregistrée à la confirmation).' };
        return;
    }

    actionLoading.value = true;
    flashMessage.value = null;
    try {
        const res = await fetch(`/pointage/rh/affectations/${affectationVue.value.id}/agences`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                agence_id: id,
                date_debut_autorisation: addAgenceForm.date_debut_autorisation || null,
                date_fin_autorisation: addAgenceForm.date_fin_autorisation || null,
                statut_agence: addAgenceForm.statut_agence,
                niveau_acces: addAgenceForm.niveau_acces,
                is_default: addAgenceForm.is_default,
            }),
        });
        const data = (await res.json()) as { message?: string; agences_autorisees?: AgenceAutorisee[] };
        if (!res.ok) {
            flashMessage.value = { type: 'err', text: data.message ?? 'Ajout impossible.' };
            return;
        }
        agencesVue.value = data.agences_autorisees ?? agencesVue.value;
        reinitialiserFormulaireAgence();
        flashMessage.value = { type: 'ok', text: data.message ?? 'Agence ajoutée.' };
        reloadListeRhEmployes();
    } catch {
        flashMessage.value = { type: 'err', text: 'Erreur réseau.' };
    } finally {
        actionLoading.value = false;
    }
}

function rowDraft(a: AgenceAutorisee): Partial<AgenceAutorisee> {
    if (!editRows[a.id]) {
        editRows[a.id] = {
            date_debut_autorisation: a.date_debut_autorisation,
            date_fin_autorisation: a.date_fin_autorisation,
            statut_agence: a.statut_agence,
            niveau_acces: a.niveau_acces,
        };
    }
    return editRows[a.id];
}

async function enregistrerLigneAgence(a: AgenceAutorisee) {
    if (enrollmentModalReadOnly.value) {
        return;
    }
    const d = rowDraft(a);
    if (!affectationVue.value) {
        const idx = agencesPending.value.findIndex((x) => x.id === a.id);
        if (idx >= 0) {
            agencesPending.value[idx] = {
                ...agencesPending.value[idx],
                date_debut_autorisation: (d.date_debut_autorisation as string) || null,
                date_fin_autorisation: (d.date_fin_autorisation as string) || null,
                statut_agence: (d.statut_agence as string) || 'actif',
                niveau_acces: (d.niveau_acces as string) || 'pointage_complet',
            };
        }
        delete editRows[a.id];
        flashMessage.value = { type: 'ok', text: 'Ligne mise à jour (enregistrement à la confirmation).' };
        return;
    }
    actionLoading.value = true;
    flashMessage.value = null;
    try {
        const res = await fetch(`/pointage/rh/affectations/${affectationVue.value.id}/agences/${a.id}`, {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                date_debut_autorisation: d.date_debut_autorisation || null,
                date_fin_autorisation: d.date_fin_autorisation || null,
                statut_agence: d.statut_agence,
                niveau_acces: d.niveau_acces,
            }),
        });
        const data = (await res.json()) as { message?: string; agences_autorisees?: AgenceAutorisee[] };
        if (!res.ok) {
            flashMessage.value = { type: 'err', text: data.message ?? 'Mise à jour impossible.' };
            return;
        }
        agencesVue.value = data.agences_autorisees ?? agencesVue.value;
        delete editRows[a.id];
        flashMessage.value = { type: 'ok', text: data.message ?? 'Mis à jour.' };
        reloadListeRhEmployes();
    } catch {
        flashMessage.value = { type: 'err', text: 'Erreur réseau.' };
    } finally {
        actionLoading.value = false;
    }
}

async function supprimerAgence(a: AgenceAutorisee) {
    if (enrollmentModalReadOnly.value) {
        return;
    }
    if (!confirm(`Retirer l’agence « ${a.nom} » des autorisations de pointage ?`)) return;
    if (!affectationVue.value) {
        agencesPending.value = agencesPending.value.filter((x) => x.id !== a.id);
        delete editRows[a.id];
        flashMessage.value = { type: 'ok', text: 'Agence retirée de la liste.' };
        return;
    }
    actionLoading.value = true;
    flashMessage.value = null;
    try {
        const res = await fetch(`/pointage/rh/affectations/${affectationVue.value.id}/agences/${a.id}`, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        const data = (await res.json()) as { message?: string; agences_autorisees?: AgenceAutorisee[] };
        if (!res.ok) {
            flashMessage.value = { type: 'err', text: data.message ?? 'Suppression impossible.' };
            return;
        }
        agencesVue.value = data.agences_autorisees ?? [];
        delete editRows[a.id];
        flashMessage.value = { type: 'ok', text: data.message ?? 'Agence retirée.' };
        reloadListeRhEmployes();
    } catch {
        flashMessage.value = { type: 'err', text: 'Erreur réseau.' };
    } finally {
        actionLoading.value = false;
    }
}

async function definirPrincipale(a: AgenceAutorisee) {
    if (enrollmentModalReadOnly.value) {
        return;
    }
    if (!affectationVue.value) {
        agencesPending.value.forEach((x) => {
            x.is_default = x.id === a.id;
        });
        flashMessage.value = { type: 'ok', text: 'Agence principale définie (enregistrement à la confirmation).' };
        return;
    }
    actionLoading.value = true;
    flashMessage.value = null;
    try {
        const res = await fetch(`/pointage/rh/affectations/${affectationVue.value.id}/agences/${a.id}/principal`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        });
        const data = (await res.json()) as { message?: string; agences_autorisees?: AgenceAutorisee[] };
        if (!res.ok) {
            flashMessage.value = { type: 'err', text: data.message ?? 'Action impossible.' };
            return;
        }
        agencesVue.value = data.agences_autorisees ?? agencesVue.value;
        flashMessage.value = { type: 'ok', text: data.message ?? 'Agence principale mise à jour.' };
        reloadListeRhEmployes();
    } catch {
        flashMessage.value = { type: 'err', text: 'Erreur réseau.' };
    } finally {
        actionLoading.value = false;
    }
}

</script>

<template>
    <PointageLayout title="Affectation des services/agences" :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-[#0C447C]">Affectation des services/agences</h1>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-md border border-[#185FA5] bg-white px-4 py-2.5 text-sm font-semibold text-[#185FA5] shadow-sm hover:bg-[#F5FAFF]"
                        @click="openCreation()"
                    >
                        Création profil
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-md bg-[#185FA5] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#144a84]"
                        @click="openEnroll()"
                    >
                        Enrôler
                    </button>
                </div>
            </div>

            <Dialog v-model:open="creationOpen">
                <DialogContent class="max-h-[92vh] max-w-4xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Création profil et compte utilisateur</DialogTitle>
                        <DialogDescription class="sr-only">Création profil et compte utilisateur</DialogDescription>
                    </DialogHeader>
                    <div class="space-y-4 py-2">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-matricule">Matricule *</label>
                                <input id="pc-matricule" v-model="profilCreateForm.matricule" type="text" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 font-mono text-sm" placeholder="M123" />
                                <p v-if="profilCreateErrors.matricule" class="mt-1 text-xs text-red-600">{{ profilCreateErrors.matricule }}</p>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-prenom">Prénom *</label>
                                <input id="pc-prenom" v-model="profilCreateForm.prenom" type="text" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                                <p v-if="profilCreateErrors.prenom" class="mt-1 text-xs text-red-600">{{ profilCreateErrors.prenom }}</p>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-nom">Nom *</label>
                                <input id="pc-nom" v-model="profilCreateForm.nom" type="text" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                                <p v-if="profilCreateErrors.nom" class="mt-1 text-xs text-red-600">{{ profilCreateErrors.nom }}</p>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-email">E-mail professionnel *</label>
                                <input id="pc-email" v-model="profilCreateForm.email" type="email" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" placeholder="prenom.nom@cofina.sn" />
                                <p v-if="profilCreateErrors.email" class="mt-1 text-xs text-red-600">{{ profilCreateErrors.email }}</p>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-tel">Téléphone</label>
                                <input id="pc-tel" v-model="profilCreateForm.telephone" type="tel" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" placeholder="+221 XX XXX XX XX" />
                                <p v-if="profilCreateErrors.telephone" class="mt-1 text-xs text-red-600">{{ profilCreateErrors.telephone }}</p>
                            </div>
                            <div v-if="profil_form.is_super_admin && profil_form.filiales.length">
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-filiale">Filiale</label>
                                <select id="pc-filiale" v-model="selectedFiliale" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                    <option :value="null">— Choisir —</option>
                                    <option v-for="f in profil_form.filiales" :key="f.id" :value="f.id">{{ f.nom }}</option>
                                </select>
                            </div>
                            <div v-else-if="profil_form.user_filiale_id">
                                <span class="text-[10px] font-bold uppercase text-[#888780]">Filiale</span>
                                <p class="mt-1 rounded-md border border-[#e2e0d8] bg-[#FAFAF8] px-3 py-2 text-sm text-[#0C447C]">
                                    {{ profil_form.filiales.find((f) => f.id === profil_form.user_filiale_id)?.nom ?? 'Filiale assignée' }}
                                </p>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-agence">Agence</label>
                                <select id="pc-agence" v-model="profilCreateForm.agence_id" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                    <option :value="null">— Choisir —</option>
                                    <option v-for="ag in agencesFiltrees" :key="'agence-' + ag.id" :value="ag.id">{{ ag.nom }}</option>
                                </select>
                                <p v-if="profilCreateErrors.agence_id" class="mt-1 text-xs text-red-600">{{ profilCreateErrors.agence_id }}</p>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-dept">Département</label>
                                <select id="pc-dept" v-model="profilCreateForm.departement" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                    <option value="">— Choisir —</option>
                                    <option v-for="d in profil_form.departements" :key="d.id" :value="d.nom">{{ d.nom }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-fonction">Fonction</label>
                                <input id="pc-fonction" v-model="profilCreateForm.fonction" type="text" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-contrat">Type de contrat</label>
                                <select id="pc-contrat" v-model="profilCreateForm.type_contrat" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                    <option value="CDI">CDI</option>
                                    <option value="CDD">CDD</option>
                                    <option value="Stagiaire">Stagiaire</option>
                                    <option value="Autre">Autre</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-statut">Statut profil</label>
                                <select id="pc-statut" v-model="profilCreateForm.statut" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                    <option value="actif">Actif</option>
                                    <option value="inactif">Inactif</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-n1">N+1</label>
                                <select id="pc-n1" v-model="profilCreateForm.n_plus_1_id" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                    <option :value="null">— Aucun —</option>
                                    <option v-for="p in (n1_profils ?? profil_form.profils)" :key="p.id" :value="p.id">{{ p.prenom }} {{ p.nom }} ({{ p.matricule }})</option>
                                </select>
                            </div>
                        </div>
                        <div class="rounded-lg border border-[#e2e0d8] bg-[#FAFAF8] p-4">
                            <h3 class="text-[11px] font-bold uppercase text-[#888780]">Compte utilisateur</h3>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-password">Mot de passe *</label>
                                    <input id="pc-password" v-model="userCreateForm.password" type="password" autocomplete="new-password" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                                    <p v-if="profilCreateErrors.password" class="mt-1 text-xs text-red-600">{{ profilCreateErrors.password }}</p>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold uppercase text-[#888780]" for="pc-password2">Confirmer le mot de passe *</label>
                                    <input id="pc-password2" v-model="userCreateForm.password_confirmation" type="password" autocomplete="new-password" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="flex cursor-pointer items-center gap-2 text-sm text-[#0C447C]">
                                        <input v-model="userCreateForm.must_change_password" type="checkbox" class="rounded border-[#e2e0d8]" />
                                        Obliger le changement de mot de passe à la première connexion
                                    </label>
                                </div>
                            </div>
                        </div>
                        <p v-if="creationFlashMessage" class="text-sm" :class="creationFlashMessage.type === 'ok' ? 'text-[#3B6D11]' : 'text-red-600'">
                            {{ creationFlashMessage.text }}
                        </p>
                    </div>
                    <DialogFooter>
                        <button type="button" class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm" @click="creationOpen = false">Annuler</button>
                        <button
                            type="button"
                            class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#144a84] disabled:opacity-50"
                            :disabled="createProfilLoading"
                            @click="creerProfil"
                        >
                            {{ createProfilLoading ? 'Création…' : 'Créer profil et utilisateur' }}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog v-model:open="enrollOpen">
                <DialogContent class="max-h-[92vh] max-w-4xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {{
                                enrollmentModalReadOnly
                                    ? 'Consultation — Affectation pointage'
                                    : profilVue
                                      ? 'Enrôlement — Affectation pointage'
                                      : 'Enrôlement — Recherche collaborateur'
                            }}
                        </DialogTitle>
                        <DialogDescription class="sr-only">
                            {{
                                enrollmentModalReadOnly
                                    ? 'Consultation — Affectation pointage'
                                    : profilVue
                                      ? 'Enrôlement — Affectation pointage'
                                      : 'Enrôlement — Recherche collaborateur'
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-4 py-2">
                        <p v-if="actionLoading && enrollmentModalReadOnly" class="text-sm text-[#888780]">Chargement…</p>

                        <template v-if="!enrollmentModalReadOnly && !profilVue">
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="min-w-[240px] flex-1">
                                    <label class="text-[11px] font-bold uppercase text-[#888780]" for="aff-email">E-mail professionnel</label>
                                    <input
                                        id="aff-email"
                                        v-model="emailSearch"
                                        type="email"
                                        class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm disabled:bg-[#F1EFE8] disabled:text-[#888780]"
                                        placeholder="prenom.nom@cofina.sn"
                                        :disabled="lookupLoading || actionLoading"
                                        @keyup.enter="rechercherCollaborateur"
                                    />
                                </div>
                                <button
                                    type="button"
                                    class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#144a84] disabled:opacity-50"
                                    :disabled="lookupLoading || actionLoading"
                                    @click="rechercherCollaborateur"
                                >
                                    {{ lookupLoading ? 'Recherche…' : 'Rechercher' }}
                                </button>
                            </div>
                        </template>

                        <template v-else-if="!enrollmentModalReadOnly && profilVue && !alreadyEnrolled">
                            <div class="flex flex-wrap items-end gap-3">
                                <button
                                    type="button"
                                    class="rounded-md border border-[#185FA5] bg-[#F5FAFF] px-4 py-2 text-sm font-semibold text-[#185FA5] hover:bg-[#E6F1FB] disabled:opacity-50"
                                    :disabled="enrollLoading"
                                    @click="enrollerCollaborateur"
                                >
                                    {{ enrollLoading ? 'Enrôlement…' : 'Confirmer l’enrôlement' }}
                                </button>
                            </div>
                        </template>

                        <p v-if="flashMessage" class="text-sm" :class="flashMessage.type === 'ok' ? 'text-[#3B6D11]' : 'text-red-600'">
                            {{ flashMessage.text }}
                        </p>

                        <template v-if="profilVue">
                            <div class="rounded-lg border border-[#e2e0d8] bg-[#FAFAF8] p-4">
                                <h3 class="text-[11px] font-bold uppercase text-[#888780]">Informations affichées automatiquement</h3>
                                <dl class="mt-3 grid gap-2 text-sm text-[#0C447C] sm:grid-cols-2">
                                    <div><dt class="text-[#888780]">Matricule</dt> <dd class="font-mono">{{ profilVue.matricule ?? '—' }}</dd></div>
                                    <div><dt class="text-[#888780]">Nom et prénom</dt> <dd class="font-semibold">{{ profilVue.prenom }} {{ profilVue.nom }}</dd></div>
                                    <div><dt class="text-[#888780]">Fonction</dt> <dd>{{ profilVue.fonction ?? '—' }}</dd></div>
                                    <div><dt class="text-[#888780]">Département</dt> <dd>{{ profilVue.departement ?? '—' }}</dd></div>
                                    <div><dt class="text-[#888780]">Service</dt> <dd>{{ profilVue.service ?? '—' }}</dd></div>
                                    <div><dt class="text-[#888780]">E-mail</dt> <dd>{{ profilVue.email ?? '—' }}</dd></div>
                                    <div><dt class="text-[#888780]">Téléphone</dt> <dd>{{ profilVue.telephone ?? '—' }}</dd></div>
                                    <div><dt class="text-[#888780]">Statut collaborateur</dt> <dd>{{ profilStatutLabel(profilVue.statut) }}</dd></div>
                                </dl>
                            </div>

                            <p v-if="modaleLectureSeule" class="rounded-md border border-[#C5D9EA] bg-[#F5FAFF] px-3 py-2 text-sm text-[#0C447C]">
                                Mode consultation : vous visualisez uniquement les données d’enrôlement au pointage.
                            </p>
                            <p v-else-if="alreadyEnrolled" class="rounded-md border border-[#C0DD97] bg-[#EAF3DE] px-3 py-2 text-sm text-[#27500A]">
                                Collaborateur déjà enrôlé au pointage — vous pouvez modifier son affectation ci-dessous.
                            </p>
                            <p v-if="!userVue && alreadyEnrolled && !enrollmentModalReadOnly" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                Aucun compte utilisateur avec cet e-mail : les agences autorisées nécessitent un compte de connexion au même e-mail.
                            </p>

                            <div class="rounded-lg border border-[#e2e0d8] bg-white p-4">
                                <h3 class="text-[11px] font-bold uppercase text-[#888780]">Informations complémentaires à renseigner</h3>
                                <div v-if="modaleLectureSeule" class="mt-3 grid gap-2 text-sm text-[#0C447C] sm:grid-cols-2">
                                    <div><span class="text-[#888780]">Type de pointage</span> — {{ libelleOption(type_pointage_options, paramForm.type_pointage) }}</div>
                                    <div><span class="text-[#888780]">Mode de validation</span> — {{ libelleOption(mode_validation_options, paramForm.mode_validation) }}</div>
                                    <div><span class="text-[#888780]">Date d’affectation</span> — {{ formaterDateCourt(paramForm.date_affectation) }}</div>
                                    <div><span class="text-[#888780]">Date de fin d’affectation</span> — {{ formaterDateCourt(paramForm.date_fin_affectation) }}</div>
                                    <div><span class="text-[#888780]">Statut d’activation</span> — {{ paramForm.statut_activation ? 'Actif' : 'Inactif' }}</div>
                                </div>
                                <div v-else class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Type de pointage</label>
                                        <select v-model="paramForm.type_pointage" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                            <option v-for="o in type_pointage_options" :key="o.value" :value="o.value">{{ o.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Mode de validation</label>
                                        <select v-model="paramForm.mode_validation" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                            <option v-for="o in mode_validation_options" :key="o.value" :value="o.value">{{ o.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Date d’affectation</label>
                                        <input v-model="paramForm.date_affectation" type="date" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Date de fin d’affectation</label>
                                        <input v-model="paramForm.date_fin_affectation" type="date" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Statut d’activation</label>
                                        <select
                                            class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                                            :value="paramForm.statut_activation ? 'actif' : 'inactif'"
                                            @change="onStatutActivationChange"
                                        >
                                            <option value="actif">Actif</option>
                                            <option value="inactif">Inactif</option>
                                        </select>
                                    </div>
                                </div>
                                <button
                                    v-if="alreadyEnrolled && !enrollmentModalReadOnly"
                                    type="button"
                                    class="mt-4 rounded-md bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#144a84] disabled:opacity-50"
                                    :disabled="actionLoading"
                                    @click="saveParametrage"
                                >
                                    Enregistrer les paramètres d’affectation
                                </button>
                            </div>

                            <div class="rounded-lg border border-[#e2e0d8] bg-white p-4">
                                <h3 class="text-[11px] font-bold uppercase text-[#888780]">Affectation des agences autorisées</h3>

                                <div
                                    v-if="!modaleLectureSeule"
                                    class="mt-4 grid gap-3 border-t border-[#e2e0d8] pt-4 sm:grid-cols-2 lg:grid-cols-6"
                                >
                                    <div class="lg:col-span-2">
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Agence</label>
                                        <select v-model="addAgenceForm.agence_id" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                            <option value="">— Choisir —</option>
                                            <option v-for="ag in agencesDisponiblesPourAjout" :key="'add-' + ag.id" :value="ag.id">
                                                {{ ag.code_agent || '—' }} — {{ ag.nom }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Début autorisation</label>
                                        <input v-model="addAgenceForm.date_debut_autorisation" type="date" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Fin autorisation</label>
                                        <input v-model="addAgenceForm.date_fin_autorisation" type="date" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Statut</label>
                                        <select v-model="addAgenceForm.statut_agence" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                            <option value="actif">Actif</option>
                                            <option value="inactif">Inactif</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold uppercase text-[#888780]">Droits d’accès</label>
                                        <select v-model="addAgenceForm.niveau_acces" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm">
                                            <option v-for="o in niveau_acces_options" :key="'n-' + o.value" :value="o.value">{{ o.label }}</option>
                                        </select>
                                    </div>
                                    <div class="flex items-end gap-2 lg:col-span-6">
                                        <label class="flex cursor-pointer items-center gap-2 text-sm text-[#0C447C]">
                                            <input v-model="addAgenceForm.is_default" type="checkbox" class="rounded border-[#e2e0d8]" />
                                            Définir comme agence principale
                                        </label>
                                        <button
                                            type="button"
                                            class="ml-auto rounded-md border border-[#185FA5] px-4 py-2 text-sm font-semibold text-[#185FA5] hover:bg-[#F5FAFF] disabled:opacity-50"
                                            :disabled="actionLoading || modaleLectureSeule"
                                            @click="ajouterAgence"
                                        >
                                            Ajouter une agence autorisée
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-4 overflow-x-auto">
                                    <table class="w-full min-w-[900px] text-sm">
                                        <thead class="border-b border-[#e2e0d8] bg-[#FAFAF8] text-left text-[10px] font-bold uppercase text-[#888780]">
                                            <tr>
                                                <th class="px-3 py-2">Code agence</th>
                                                <th class="px-3 py-2">Nom agence</th>
                                                <th class="px-3 py-2">Début autorisation</th>
                                                <th class="px-3 py-2">Fin autorisation</th>
                                                <th class="px-3 py-2">Statut</th>
                                                <th class="px-3 py-2">Droits</th>
                                                <th v-if="!modaleLectureSeule" class="px-3 py-2 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="a in agencesAffichees" :key="'row-' + a.id" class="border-b border-[#F1EFE8]">
                                                <td class="px-3 py-2 font-mono text-xs">{{ a.code_agent ?? '—' }}</td>
                                                <td class="px-3 py-2 font-medium text-[#0C447C]">
                                                    {{ a.nom }}
                                                    <span v-if="a.is_default" class="ml-2 rounded bg-[#E6F1FB] px-2 py-0.5 text-[10px] font-semibold text-[#185FA5]">Principale</span>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <template v-if="modaleLectureSeule">{{ formaterDateCourt(a.date_debut_autorisation ?? undefined) }}</template>
                                                    <input
                                                        v-else
                                                        v-model="rowDraft(a).date_debut_autorisation"
                                                        type="date"
                                                        class="w-full min-w-[8rem] rounded border border-[#e2e0d8] px-2 py-1 text-xs"
                                                    />
                                                </td>
                                                <td class="px-3 py-2">
                                                    <template v-if="modaleLectureSeule">{{ formaterDateCourt(a.date_fin_autorisation ?? undefined) }}</template>
                                                    <input
                                                        v-else
                                                        v-model="rowDraft(a).date_fin_autorisation"
                                                        type="date"
                                                        class="w-full min-w-[8rem] rounded border border-[#e2e0d8] px-2 py-1 text-xs"
                                                    />
                                                </td>
                                                <td class="px-3 py-2">
                                                    <template v-if="modaleLectureSeule">{{ a.statut_agence === 'inactif' ? 'Inactif' : 'Actif' }}</template>
                                                    <select v-else v-model="rowDraft(a).statut_agence" class="w-full rounded border border-[#e2e0d8] px-2 py-1 text-xs">
                                                        <option value="actif">Actif</option>
                                                        <option value="inactif">Inactif</option>
                                                    </select>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <template v-if="modaleLectureSeule">{{ libelleOption(niveau_acces_options, a.niveau_acces) }}</template>
                                                    <select v-else v-model="rowDraft(a).niveau_acces" class="w-full min-w-[9rem] rounded border border-[#e2e0d8] px-2 py-1 text-xs">
                                                        <option v-for="o in niveau_acces_options" :key="'e-' + a.id + o.value" :value="o.value">{{ o.label }}</option>
                                                    </select>
                                                </td>
                                                <td v-if="!modaleLectureSeule" class="px-3 py-2 text-right">
                                                    <div class="flex flex-wrap justify-end gap-1">
                                                        <button type="button" class="text-xs font-medium text-[#185FA5] underline" @click="enregistrerLigneAgence(a)">
                                                            Enregistrer
                                                        </button>
                                                        <button type="button" class="text-xs font-medium text-[#854F0B] underline" @click="definirPrincipale(a)">
                                                            Principale
                                                        </button>
                                                        <button type="button" class="text-xs font-medium text-red-600 underline" @click="supprimerAgence(a)">
                                                            Supprimer
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr v-if="!agencesAffichees.length">
                                                <td :colspan="modaleLectureSeule ? 6 : 7" class="px-3 py-6 text-center text-[#888780]">
                                                    Aucune agence autorisée pour le moment.
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>
                    </div>

                    <DialogFooter>
                        <button type="button" class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm" @click="enrollOpen = false">Fermer</button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog v-model:open="congeOpen">
                <DialogContent class="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Déclarer une demande</DialogTitle>
                        <DialogDescription>
                            {{ congeRow ? `${congeRow.prenom} ${congeRow.nom}` : '' }}
                            {{ congeDialogHint }}
                        </DialogDescription>
                    </DialogHeader>
                    <form class="space-y-3 py-2" @submit.prevent="submitConge">
                        <div>
                            <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-type">Type</label>
                            <select
                                id="conge-type"
                                v-model="congeForm.type"
                                class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm text-[#0C447C]"
                                @change="
                                    congeForm.motif = congeTypeLabel(congeForm.type);
                                    if (congeForm.type === 'allaitement') {
                                        congeForm.sens = congeForm.sens || 'entree';
                                        onCongeAllaitementSens();
                                    }
                                "
                            >
                                <option v-for="t in congeTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-debut">Date de début</label>
                                <input id="conge-debut" v-model="congeForm.date_concernee" type="date" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-fin">Date de fin</label>
                                <input id="conge-fin" v-model="congeForm.date_fin" type="date" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div v-if="congeNeedsHeures" class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-hdebut">Heure de début</label>
                                <input id="conge-hdebut" v-model="congeForm.heure_debut" type="time" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-hfin">Heure de fin</label>
                                <input id="conge-hfin" v-model="congeForm.heure_fin" type="time" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div v-if="congeNeedsAllaitement" class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-sens">Sens horaire</label>
                                <select
                                    id="conge-sens"
                                    v-model="congeForm.sens"
                                    required
                                    class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm"
                                    @change="onCongeAllaitementSens"
                                >
                                    <option value="entree">Matin</option>
                                    <option value="sortie">Après-midi</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-allait-heure">
                                    {{ congeForm.sens === 'sortie' ? 'Heure (après-midi)' : 'Heure (matin)' }}
                                </label>
                                <input id="conge-allait-heure" v-model="congeForm.heure_debut" type="time" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div v-if="congeNeedsLieu">
                            <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-lieu">Lieu</label>
                            <input id="conge-lieu" v-model="congeForm.lieu" type="text" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-motif">Motif</label>
                            <input id="conge-motif" v-model="congeForm.motif" type="text" required class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-[10px] font-bold uppercase text-[#888780]" for="conge-comment">Commentaire</label>
                            <textarea id="conge-comment" v-model="congeForm.commentaire" rows="2" class="mt-1 w-full rounded-md border border-[#e2e0d8] px-3 py-2 text-sm" />
                        </div>
                        <DialogFooter>
                            <button type="button" class="rounded-md border border-[#e2e0d8] px-4 py-2 text-sm" @click="congeOpen = false">Annuler</button>
                            <button
                                type="submit"
                                class="rounded-md bg-[#185FA5] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="congeLoading"
                            >
                                {{ congeLoading ? 'Enregistrement…' : 'Enregistrer' }}
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <p class="text-sm font-medium text-[#0C447C]">
                    <span class="tabular-nums">{{ total_enroles }}</span> enrôlé{{ total_enroles > 1 ? 's' : '' }}
                    — <span class="tabular-nums">{{ total_actifs }}</span> actif{{ total_actifs > 1 ? 's' : '' }}
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="sr-only" for="filtre-filiale">Filiale</label>
                    <select
                        id="filtre-filiale"
                        class="rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        :value="filters.filiale ?? 'tous'"
                        @change="applyFilters({ filiale: ($event.target as HTMLSelectElement).value, agence: 'tous' })"
                    >
                        <option value="tous">Toutes les filiales</option>
                        <option v-for="f in profil_form.filiales" :key="'filiale-' + f.id" :value="String(f.id)">{{ f.nom }}</option>
                    </select>
                    <label class="sr-only" for="filtre-statut">Statut affectation</label>
                    <select
                        id="filtre-statut"
                        class="rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        :value="filters.statut"
                        @change="applyFilters({ statut: ($event.target as HTMLSelectElement).value })"
                    >
                        <option value="tous">Tous statuts</option>
                        <option value="actif">Actifs</option>
                        <option value="inactif">Inactifs</option>
                    </select>
                    <label class="sr-only" for="filtre-agence">Agence</label>
                    <select
                        id="filtre-agence"
                        class="rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        :value="filters.agence"
                        @change="applyFilters({ agence: ($event.target as HTMLSelectElement).value })"
                    >
                        <option value="tous">Toutes les agences</option>
                        <option v-for="s in agencesListeFiltrees" :key="'agence-' + s" :value="s">{{ s }}</option>
                    </select>
                    <label class="sr-only" for="filtre-service">Service</label>
                    <select
                        id="filtre-service"
                        class="rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        :value="filters.service"
                        @change="applyFilters({ service: ($event.target as HTMLSelectElement).value })"
                    >
                        <option value="tous">Tous services</option>
                        <option v-for="svc in services" :key="'svc-' + svc" :value="svc">{{ svc }}</option>
                    </select>
                    <label class="sr-only" for="filtre-search">Recherche</label>
                    <input
                        id="filtre-search"
                        v-model="searchInput"
                        type="search"
                        placeholder="Nom, matricule, e-mail…"
                        class="w-48 rounded-md border border-[#e2e0d8] bg-white px-3 py-2 text-sm text-[#0C447C]"
                        @keyup.enter="applyFilters({ search: searchInput })"
                    />
                    <button
                        type="button"
                        class="text-xs font-medium text-[#185FA5] underline"
                        title="Réaffiche tous les enrôlements si des filtres les masquent"
                        @click="searchInput = ''; applyFilters({ agence: 'tous', service: 'tous', statut: 'tous', filiale: 'tous', search: '' })"
                    >
                        Tout afficher
                    </button>
                </div>
            </div>

            <Deferred data="affectations">
                <template #fallback>
                    <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white p-8 text-center text-sm text-[#888780] shadow-sm">
                        Chargement des affectations…
                    </div>
                </template>
                <div class="overflow-hidden rounded-[10px] border border-[#e2e0d8] bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead class="border-b border-[#e2e0d8] bg-[#FAFAF8] text-left text-[10px] font-bold uppercase tracking-wide text-[#888780]">
                            <tr>
                                <th class="px-4 py-3">Employé</th>
                                <th class="px-4 py-3">Matricule</th>
                                <th class="px-4 py-3">Service</th>
                                <th class="px-4 py-3" title="Agence pointage principale, ou agence du profil">Agence</th>
                                <th class="px-4 py-3">Horaire</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Demande</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                            <tr v-for="a in (affectations?.data ?? [])" :key="a.id" class="border-b border-[#F1EFE8] last:border-0 hover:bg-[#FAFAF8]/80">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-[#0C447C]">{{ a.prenom }} {{ a.nom }}</div>
                                    <div class="mt-0.5 text-xs text-[#888780]">{{ a.email ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-[#0C447C]">{{ a.matricule ?? '—' }}</td>
                                <td class="px-4 py-3 text-[#0C447C]">{{ a.departement ?? '—' }}</td>
                                <td class="px-4 py-3 text-[#0C447C]">{{ a.agence ?? '—' }}</td>
                                <td class="px-4 py-3 tabular-nums text-[#888780]">{{ horaire_display }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statutBadgeClass(a.statut_activation)">
                                        {{ statutLabel(a.statut_activation) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="a.note_label"
                                        class="inline-flex max-w-[11rem] truncate rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                        :class="noteBadgeClass(a.note_type)"
                                        :title="a.note_label"
                                    >
                                        {{ a.note_label }}
                                    </span>
                                    <span v-else class="text-xs text-[#C0BCB0]">—</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center justify-end gap-0.5">
                                        <ActionIconButton
                                            title="Voir"
                                            @click="openAffectation(a.id, true)"
                                        >
                                            <Eye class="h-4 w-4" />
                                        </ActionIconButton>
                                        <ActionIconButton
                                            title="Éditer"
                                            @click="openAffectation(a.id, false)"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </ActionIconButton>
                                        <ActionIconButton
                                            :title="a.statut_activation ? 'Désactiver' : 'Activer'"
                                            :variant="a.statut_activation ? 'danger' : 'success'"
                                            @click="toggleStatutAffectation(a)"
                                        >
                                            <PauseCircle
                                                v-if="a.statut_activation"
                                                class="h-4 w-4"
                                            />
                                            <PlayCircle v-else class="h-4 w-4" />
                                        </ActionIconButton>
                                        <ActionIconButton
                                            :title="a.note_type ? `Retirer « ${a.note_label ?? 'demande'} »` : 'Déclarer une demande'"
                                            :variant="a.note_type ? 'danger' : 'default'"
                                            :disabled="!a.has_user_account"
                                            @click="openConge(a)"
                                        >
                                            <Umbrella class="h-4 w-4" />
                                        </ActionIconButton>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!(affectations?.data?.length)">
                                <td colspan="8" class="px-4 py-12 text-center text-[#888780]">Aucune affectation enrôlée. Cliquez sur « Enrôler ».</td>
                        </tr>
                    </tbody>
                </table>
            </div>

                <div v-if="(affectations?.last_page ?? 1) > 1 && affectations?.links?.length" class="flex flex-wrap justify-center gap-1 border-t border-[#e2e0d8] px-4 py-3">
                    <template v-for="(link, i) in affectations?.links ?? []" :key="i">
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
            </Deferred>

            <p class="text-center text-xs text-[#888780]">
                Fiches collaborateur :
                <Link href="/profils" class="font-medium text-[#185FA5] underline">module Profils</Link>
            </p>
        </div>
    </PointageLayout>
</template>