<script setup lang="ts">
import ActionIconButton from '@/components/pointage/ActionIconButton.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Link } from '@inertiajs/vue3';
import {
    Copy,
    MoreVertical,
    PauseCircle,
    Pencil,
    PlayCircle,
    QrCode,
    Smartphone,
} from 'lucide-vue-next';

export interface ActionsQrSite {
    id: number;
    nom: string;
    actif: boolean;
    pointage_qr_enabled?: boolean;
    is_virtual?: boolean;
    kiosk_url?: string | null;
    kiosk_serial_number?: string | null;
}

const props = defineProps<{
    site: ActionsQrSite;
}>();

const emit = defineEmits<{
    voirQr: [];
    editQr: [];
    copyLink: [];
    createVirtual: [];
    openKiosk: [];
    regenQr: [];
    togglePause: [];
    openKioskSerial: [];
}>();

const qrActif = () =>
    props.site.actif && props.site.pointage_qr_enabled !== false;
</script>

<template>
    <div class="inline-flex items-center justify-end gap-0.5" @click.stop>
        <ActionIconButton title="Voir QR" @click="emit('voirQr')">
            <QrCode class="h-4 w-4" />
        </ActionIconButton>
        <ActionIconButton title="Éditer QR" @click="emit('editQr')">
            <Pencil class="h-4 w-4" />
        </ActionIconButton>
        <ActionIconButton
            title="Copier lien"
            :disabled="!site.kiosk_url"
            @click="emit('copyLink')"
        >
            <Copy class="h-4 w-4" />
        </ActionIconButton>

        <DropdownMenu>
            <DropdownMenuTrigger :as-child="true">
                <ActionIconButton title="Plus d’actions">
                    <MoreVertical class="h-4 w-4" />
                </ActionIconButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                side="bottom"
                :side-offset="6"
                class="min-w-[12.5rem] border-[#e2e0d8] bg-white text-sm text-[#0C447C] shadow-lg"
            >
                <DropdownMenuItem :as-child="true">
                    <Link
                        :href="`/pointage/sites/${site.id}/edit`"
                        class="cursor-pointer"
                        title="Modifier le site (GPS, rayon…)"
                    >
                        Site
                    </Link>
                </DropdownMenuItem>

                <DropdownMenuItem
                    v-if="!site.is_virtual"
                    class="cursor-pointer"
                    @select="emit('createVirtual')"
                >
                    Ajouter agence virtuelle
                </DropdownMenuItem>

                <DropdownMenuItem
                    v-if="site.is_virtual"
                    class="cursor-pointer"
                    @select="emit('openKioskSerial')"
                >
                    Téléphone borne
                </DropdownMenuItem>

                <DropdownMenuItem
                    class="cursor-pointer"
                    :disabled="!site.kiosk_url"
                    @select="emit('openKiosk')"
                >
                    <Smartphone class="h-4 w-4 opacity-70" />
                    Tablette
                </DropdownMenuItem>

                <DropdownMenuItem class="cursor-pointer" @select="emit('regenQr')">
                    Régénérer QR
                </DropdownMenuItem>

                <DropdownMenuSeparator class="bg-[#e2e0d8]" />

                <DropdownMenuItem
                    class="cursor-pointer font-medium"
                    :class="
                        qrActif()
                            ? 'text-[#A32D2D] focus:bg-[#FCEBEB] focus:text-[#A32D2D]'
                            : 'text-[#3B6D11] focus:bg-[#EAF3DE] focus:text-[#3B6D11]'
                    "
                    :title="
                        qrActif()
                            ? 'Désactiver temporairement le QR Code'
                            : 'Réactiver le QR Code'
                    "
                    @select="emit('togglePause')"
                >
                    <PauseCircle v-if="qrActif()" class="h-4 w-4" />
                    <PlayCircle v-else class="h-4 w-4" />
                    {{ qrActif() ? 'Pause QR' : 'Activer QR' }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
