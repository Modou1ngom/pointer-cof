<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { Upload, FileSpreadsheet, AlertCircle, CheckCircle2 } from 'lucide-vue-next';
import { ref } from 'vue';

const form = useForm({
    file: null as File | null,
});

const fileInput = ref<HTMLInputElement | null>(null);
const isDragging = ref(false);

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        form.file = target.files[0];
    }
};

const handleDrop = (event: DragEvent) => {
    event.preventDefault();
    isDragging.value = false;
    
    if (event.dataTransfer?.files && event.dataTransfer.files[0]) {
        form.file = event.dataTransfer.files[0];
    }
};

const handleDragOver = (event: DragEvent) => {
    event.preventDefault();
    isDragging.value = true;
};

const handleDragLeave = () => {
    isDragging.value = false;
};

const submit = () => {
    if (!form.file) {
        form.setError('file', 'Veuillez sélectionner un fichier Excel.');
        return;
    }

    form.post('/profils/import', {
        forceFormData: true,
        onSuccess: () => {
            router.visit('/profils');
        },
    });
};

const downloadTemplate = () => {
    // Créer un fichier Excel exemple (CSV pour simplifier)
    const headers = [
        'Nom',
        'Prénom',
        'Email',
        'Téléphone',
        'Fonction',
        'Département',
        'Site',
        'Filiale',
        'Type Contrat',
        'Statut',
        'Back/Front Office',
    ];
    const csvContent =
        headers.join(',') +
        '\n' +
        'Dupont,Jean,jean.dupont@example.com,+221771234567,Directeur,IT,Dakar,Sénégal,CDI,actif,Back Office\n' +
        'Martin,Marie,marie.martin@example.com,+221771234568,Manager,Finance,Abidjan,Côte d\'Ivoire,CDI,actif,Front Office';

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'modele_import_profils.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};
</script>

<template>
    <Head title="Importer des profils" />

    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h1 class="text-3xl font-bold text-gray-900">Importer des profils</h1>
                    <FileSpreadsheet class="h-5 w-5 text-gray-500" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6">
            <!-- Instructions -->
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-6">
                <div class="mb-4 flex items-start gap-3">
                    <AlertCircle class="h-5 w-5 text-blue-600 mt-0.5" />
                    <div>
                        <h3 class="mb-2 text-lg font-semibold text-blue-900">Instructions d'import</h3>
                        <ul class="list-disc space-y-1 pl-5 text-sm text-blue-800">
                            <li>Le fichier doit être au format Excel (.xlsx ou .xls)</li>
                            <li>La première ligne doit contenir les en-têtes de colonnes</li>
                            <li>Les colonnes obligatoires sont : <strong>Nom</strong> et <strong>Prénom</strong></li>
                            <li>Les colonnes optionnelles sont : <strong>Matricule</strong>, Email, Téléphone, Fonction, Département, Site, <strong>Filiale</strong>, Type Contrat, Statut, Back/Front Office</li>
                            <li>Si <strong>Filiale</strong> est renseignée, le profil y est rattaché (créée si absente). Sinon : filiale « Sénégal » par défaut.</li>
                          <!--  <li>Si le matricule est fourni dans le fichier, il sera utilisé. Sinon, il sera généré automatiquement (M1, M2, M3...)</li>-->
                            <li>Les lignes avec des matricules ou emails déjà existants seront ignorées</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Formulaire d'upload -->
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Zone de dépôt de fichier -->
                    <div>
                        <Label for="file" class="mb-2 text-base font-medium text-gray-700">
                            Fichier Excel *
                        </Label>
                        <div
                            @drop="handleDrop"
                            @dragover="handleDragOver"
                            @dragleave="handleDragLeave"
                            :class="[
                                'mt-2 flex flex-col items-center justify-center rounded-lg border-2 border-dashed p-8 transition-colors',
                                isDragging ? 'border-blue-500 bg-blue-50' : 'border-gray-300 bg-gray-50 hover:border-gray-400',
                                form.errors.file ? 'border-red-500 bg-red-50' : ''
                            ]"
                        >
                            <Upload class="mb-4 h-12 w-12 text-gray-400" />
                            <p class="mb-2 text-sm text-gray-600">
                                Glissez-déposez votre fichier Excel ici, ou
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                @click="fileInput?.click()"
                                class="mb-2"
                            >
                                Parcourir les fichiers
                            </Button>
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".xlsx,.xls"
                                @change="handleFileSelect"
                                class="hidden"
                            />
                            <p class="mt-2 text-xs text-gray-500">
                                Formats acceptés : .xlsx, .xls (max 10MB)
                            </p>
                            <InputError :message="form.errors.file" />
                        </div>
                        
                        <!-- Fichier sélectionné -->
                        <div v-if="form.file" class="mt-4 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 p-3">
                            <CheckCircle2 class="h-5 w-5 text-green-600" />
                            <span class="text-sm font-medium text-green-800">{{ form.file.name }}</span>
                            <span class="text-xs text-green-600">({{ (form.file.size / 1024).toFixed(2) }} KB)</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="form.file = null"
                                class="ml-auto"
                            >
                                Supprimer
                            </Button>
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex items-center justify-between border-t border-gray-200 pt-6">
                        <Button
                            type="button"
                            variant="outline"
                            @click="downloadTemplate"
                            class="border-gray-300"
                        >
                            Télécharger un modèle
                        </Button>
                        <div class="flex gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                @click="router.visit('/profils')"
                                class="border-gray-300"
                            >
                                Annuler
                            </Button>
                            <Button
                                type="submit"
                                :disabled="form.processing || !form.file"
                                class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50"
                            >
                                <span v-if="form.processing">Import en cours...</span>
                                <span v-else>Importer</span>
                            </Button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Format attendu -->
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900">Format attendu</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Nom</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Prénom</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Matricule</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Email</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Téléphone</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Fonction</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Département</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Site</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Type Contrat</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Statut</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700">Back/Front Office</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900">Dupont</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900">Jean</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">M123</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">jean.dupont@example.com</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">+221771234567</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">Directeur</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">IT</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">Dakar</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">CDI</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">actif</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">Back Office</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-4 text-xs text-gray-500">
                    <strong>Note :</strong> Les colonnes peuvent être dans n'importe quel ordre et utiliser des noms similaires (ex: "Name" pour "Nom", "First Name" pour "Prénom").
                </p>
            </div>
        </div>
    </AppLayout>
</template>

