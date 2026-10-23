<template>
  <Head :title="$t('Ajouter des photos')" />
  <AppLayout>
    <div class="py-8 sm:py-12">
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-start justify-between gap-4 mb-6">
          <div>
            <h1 class="text-display text-3xl sm:text-4xl text-surface-900">{{ $t('Ajouter des photos') }}</h1>
            <p class="mt-2 text-surface-600">
              {{ isFriends ? $t('Depuis votre téléphone ou votre ordinateur : elles rejoignent vos souvenirs communs.') : $t('Depuis votre téléphone ou votre ordinateur : elles rejoignent la mémoire de la famille.') }}
            </p>
          </div>
          <button
            type="button"
            class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 rounded-full bg-white border border-surface-200 text-sm font-medium text-surface-700 hover:bg-surface-50 shadow-xs"
            @click="showTutorial = true"
          >
            <span class="w-5 h-5 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">?</span>
            <span class="hidden sm:inline">{{ $t('Comment faire') }}</span>
          </button>
        </div>

        <!-- Démo : la page reste visible, mais on explique au lieu d'envoyer. -->
        <div v-if="$page.props.demo" class="card p-6 sm:p-8 space-y-4">
          <div class="flex items-start gap-4">
            <span class="w-12 h-12 shrink-0 rounded-2xl bg-brand-100 text-brand-800 flex items-center justify-center" aria-hidden="true">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2" /></svg>
            </span>
            <div class="space-y-2">
              <h2 class="text-lg font-semibold text-surface-900">{{ $t('L’envoi de photos n’est pas disponible dans la démo') }}</h2>
              <p class="text-surface-600">{{ $t('Dans votre propre espace, c’est ici que la famille dépose ses photos et vidéos : depuis l’ordinateur par glisser-déposer, depuis le téléphone en quelques gestes, ou directement depuis Google Photos et Google Drive.') }}</p>
              <p class="text-surface-600">{{ $t('Les visages sont ensuite reconnus, les photos datées et placées sur la carte, et chacun peut les raconter.') }}</p>
            </div>
          </div>
          <Link href="/media" class="btn-secondary inline-flex">{{ $t('Voir les photos de la famille Lambert') }}</Link>
        </div>
        <div v-else class="card p-5 sm:p-8">
          <MediaUploader @organize="openAlbumModal" />
        </div>

        <!-- Astuce selon l'appareil -->
        <p v-if="device === 'ios'" class="mt-5 text-sm text-surface-500 px-1">
          <Trans text=":tip ajoutez MemoryLane à votre écran d'accueil (dans Safari, bouton Partager › « Sur l'écran d'accueil ») pour l'ouvrir comme une application.">
            <template #tip><strong class="text-surface-700">{{ $t('Astuce iPhone :') }}</strong></template>
          </Trans>
        </p>
        <p v-else-if="device === 'android'" class="mt-5 text-sm text-surface-500 px-1">
          <Trans text=":tip installez MemoryLane (menu ⋮ › « Installer l'application »), puis depuis votre Galerie : Partager › MemoryLane.">
            <template #tip><strong class="text-surface-700">{{ $t('Astuce Android :') }}</strong></template>
          </Trans>
        </p>

        <!-- Autres façons d'importer -->
        <details class="mt-6 group">
          <summary class="cursor-pointer list-none flex items-center gap-2 text-sm font-medium text-surface-600 hover:text-surface-900 px-1">
            <svg class="w-4 h-4 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
            {{ $t("Autres façons d'importer (Google Photos, Google Drive, archive complète)") }}
          </summary>

          <Link href="/google-photos" class="mt-3 card card--padded flex items-center gap-4 group/item">
            <span class="w-11 h-11 rounded-xl bg-brand-100 flex items-center justify-center shrink-0">
              <svg class="w-6 h-6 text-brand-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
              </svg>
            </span>
            <span class="flex-1">
              <span class="font-semibold text-surface-900 block">{{ $t('Depuis Google Photos') }}</span>
              <span class="text-sm text-surface-500">{{ $t('Si vos photos sont déjà chez Google : choisissez-les par personne, lieu ou date.') }}</span>
            </span>
          </Link>

          <Link href="/google-drive" class="mt-3 card card--padded flex items-center gap-4">
            <span class="w-11 h-11 rounded-xl bg-brand-100 flex items-center justify-center shrink-0">
              <svg class="w-6 h-6 text-brand-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
              </svg>
            </span>
            <span class="flex-1">
              <span class="font-semibold text-surface-900 block">{{ $t('Depuis Google Drive') }}</span>
              <span class="text-sm text-surface-500">{{ $t('Choisissez des photos rangées dans votre Drive : elles arrivent en pleine qualité, sans téléchargement.') }}</span>
            </span>
          </Link>

          <Link href="/takeout" class="mt-3 card card--padded flex items-center gap-4">
            <span class="w-11 h-11 rounded-xl bg-teal-100 dark:bg-teal-500/15 flex items-center justify-center shrink-0">
              <svg class="w-6 h-6 text-teal-700 dark:text-teal-300" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
              </svg>
            </span>
            <span class="flex-1">
              <span class="font-semibold text-surface-900 block">{{ $t('Archive Google Takeout') }}</span>
              <span class="text-sm text-surface-500">{{ $t("Tout l'historique Google Photos d'un coup, avec les lieux (pour les gros volumes, sur ordinateur).") }}</span>
            </span>
          </Link>
        </details>
      </div>
    </div>

    <UploadTutorial v-if="showTutorial" :device="device" @close="closeTutorial" />

    <AddToAlbumModal
      v-if="showAlbumModal"
      :media-ids="organizeIds"
      @close="showAlbumModal = false"
      @done="handleAlbumDone"
    />
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Trans from '@/Components/Trans.vue';
import { tc } from '@/i18n';
import MediaUploader from '@/Components/MediaUploader.vue';
import UploadTutorial from '@/Components/UploadTutorial.vue';
import AddToAlbumModal from '@/Components/AddToAlbumModal.vue';
import { detectDevice } from '@/utils/device';
import { useCircle } from '@/Composables/useCircle';

const { isFriends } = useCircle();
import { useToast } from '@/Composables/useToast';
import { tourInProgress } from '@/Composables/useTour';

const page = usePage();
const toast = useToast();
const device = detectDevice();

// Mini-tuto : ouvert d'office à la première visite, puis via « Comment faire ».
const showTutorial = ref(false);
// Pas pendant la visite guidée : sa bulle tomberait au milieu du tuto.
onMounted(() => {
  if (!page.props.demo && !page.props.auth?.user?.onboarding?.upload_tutorial && !tourInProgress()) showTutorial.value = true;
});
const closeTutorial = () => {
  showTutorial.value = false;
  if (!page.props.auth?.user?.onboarding?.upload_tutorial) {
    axios.post('/onboarding/upload_tutorial').catch(() => {});
  }
};

// Rangement en album après coup (depuis le bilan d'envoi).
const showAlbumModal = ref(false);
const organizeIds = ref([]);
const openAlbumModal = (ids) => {
  organizeIds.value = ids;
  showAlbumModal.value = true;
};
const handleAlbumDone = ({ albumName, count, isNew }) => {
  showAlbumModal.value = false;
  toast.success(isNew
    ? tc('Album « :name » créé avec :count élément.|Album « :name » créé avec :count éléments.', count, { name: albumName })
    : tc(':count élément ajouté à « :name ».|:count éléments ajoutés à « :name ».', count, { name: albumName }));
};
</script>
