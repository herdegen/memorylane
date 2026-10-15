<template>
  <Head title="Import Google Drive" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-6">
          <h1 class="text-display text-4xl text-surface-900">Importer depuis Google Drive</h1>
          <p class="mt-2 text-surface-600">
            Choisissez vos photos et vidéos directement dans votre Drive : MemoryLane les récupère
            en pleine qualité, sans passer par votre ordinateur.
          </p>
        </div>

        <FormError
          v-if="errorMessage"
          type="error"
          :message="errorMessage"
          dismissible
          @dismiss="errorMessage = null"
        />

        <div v-if="!isConfigured" class="card card--padded mb-6 text-sm text-surface-600">
          L'import Google Drive n'est pas encore activé sur ce site (clé d'API Google manquante).
        </div>

        <!-- Étape 1 : connexion Google -->
        <div class="card card--padded mb-6" :class="{ 'opacity-50': !isConfigured }">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h2 class="card-title mb-1">1. Connecter Google Drive</h2>
              <p class="text-sm text-surface-500">
                {{ isConnected ? 'Compte Google connecté.' : 'MemoryLane ne verra que les fichiers que vous choisirez, rien d\'autre de votre Drive.' }}
              </p>
            </div>
            <span v-if="isConnected" class="badge-teal shrink-0">Connecté</span>
            <a v-else-if="isConfigured" href="/google-drive/connect" class="btn-primary shrink-0">Se connecter</a>
          </div>
        </div>

        <!-- Étape 2 : sélection -->
        <div class="card card--padded mb-6" :class="{ 'opacity-50': !isConnected }">
          <h2 class="card-title mb-1">2. Choisir les photos</h2>
          <p class="text-sm text-surface-500 mb-4">
            Ouvrez un dossier puis sélectionnez les photos (Maj + clic pour en prendre une série,
            Ctrl + A pour tout le dossier). Vous pouvez recommencer pour piocher dans plusieurs dossiers.
          </p>

          <div class="flex flex-wrap items-center gap-3">
            <button
              type="button"
              class="btn-primary"
              :disabled="!isConnected || !isConfigured || openingPicker || importing"
              @click="openPicker"
            >
              {{ openingPicker ? 'Ouverture…' : (selected.length ? 'Ajouter d\'autres photos' : 'Ouvrir Google Drive') }}
            </button>
            <button
              v-if="selected.length && !importing"
              type="button"
              class="btn-ghost btn-sm"
              @click="selected = []"
            >
              Vider la sélection
            </button>
          </div>

          <div v-if="selected.length" class="mt-4">
            <p class="flex items-center gap-2 text-sm font-medium text-teal-700 dark:text-teal-300">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
              </svg>
              {{ selected.length }} fichier{{ selected.length > 1 ? 's' : '' }} sélectionné{{ selected.length > 1 ? 's' : '' }}
              <span v-if="totalSize" class="font-normal text-surface-500">({{ formatFileSize(totalSize) }})</span>
            </p>
            <ul class="mt-2 flex flex-wrap gap-1.5">
              <li
                v-for="doc in selected.slice(0, 12)"
                :key="doc.id"
                class="max-w-[14rem] truncate rounded-full bg-surface-100 px-2.5 py-1 text-xs text-surface-700"
                :title="doc.name"
              >
                {{ doc.name }}
              </li>
              <li v-if="selected.length > 12" class="px-2.5 py-1 text-xs text-surface-500">
                et {{ selected.length - 12 }} autre{{ selected.length - 12 > 1 ? 's' : '' }}
              </li>
            </ul>
          </div>
        </div>

        <!-- Étape 3 : rattachement + import -->
        <div class="card card--padded" :class="{ 'opacity-50': !selected.length && !importing }">
          <h2 class="card-title mb-1">3. Importer</h2>
          <p class="text-sm text-surface-500 mb-4">
            Optionnel : les photos importées seront directement rattachées à une personne
            et/ou un album.
          </p>

          <form v-if="!importing" @submit.prevent="startImport" class="space-y-4">
            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="form-label" for="person">Personne</label>
                <select id="person" v-model="importForm.person_id" class="form-select" :disabled="!selected.length">
                  <option :value="null">— Aucune —</option>
                  <option v-for="person in people" :key="person.id" :value="person.id">
                    {{ person.name }}
                  </option>
                </select>
              </div>
              <div>
                <label class="form-label" for="album">Album</label>
                <select id="album" v-model="importForm.album_id" class="form-select" :disabled="!selected.length">
                  <option :value="null">— Aucun —</option>
                  <option v-for="album in albums" :key="album.id" :value="album.id">
                    {{ album.name }}
                  </option>
                  <option value="__new__">➕ Créer un nouvel album…</option>
                </select>
                <input
                  v-if="importForm.album_id === '__new__'"
                  v-model="importForm.new_album_name"
                  type="text"
                  class="form-input mt-2"
                  placeholder="Nom du nouvel album"
                  :disabled="!selected.length"
                />
              </div>
            </div>

            <button type="submit" :disabled="!selected.length || launching" class="btn-primary">
              {{ launching ? 'Lancement…' : (selected.length ? `Importer ${selected.length} fichier${selected.length > 1 ? 's' : ''}` : 'Importer ma sélection') }}
            </button>
          </form>

          <!-- Progression : les photos arrivent au fur et à mesure -->
          <ImportProgress v-else status-url="/google-drive/imported" :started-at="importStartedAt" />
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormError from '@/Components/Forms/FormError.vue';
import ImportProgress from '@/Components/ImportProgress.vue';
import { formatFileSize } from '@/utils/format';

const props = defineProps({
  isConfigured: Boolean,
  isConnected: Boolean,
  picker: { type: Object, required: true },
  people: { type: Array, default: () => [] },
  albums: { type: Array, default: () => [] },
  errors: { type: Object, default: () => ({}) },
});

// Formats acceptés par MemoryLane (cf. MediaService::EXTENSIONS) ; le dossier
// est listé pour pouvoir naviguer dans l'arborescence du Drive.
const MIME_TYPES = [
  'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
  'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/avi', 'video/x-matroska', 'video/webm',
  // Caméscopes, DVD, vieux téléphones : optimisés à l'import (VideoOptimizer).
  'video/mpeg', 'video/mp2t', 'video/vnd.dlna.mpeg-tts', 'video/3gpp', 'video/x-m4v', 'video/x-ms-wmv', 'video/x-flv', 'video/dv',
];
const FOLDER_MIME = 'application/vnd.google-apps.folder';

// Échec de connexion renvoyé par le callback OAuth.
const errorMessage = ref(props.errors?.google || null);
const isConnected = ref(props.isConnected);
const selected = ref([]);
const openingPicker = ref(false);

const totalSize = computed(() => selected.value.reduce((sum, d) => sum + (Number(d.sizeBytes) || 0), 0));

const importForm = useForm({
  person_id: null,
  album_id: null,
  new_album_name: '',
});

// Le sélecteur Google (Picker) se charge à la demande depuis apis.google.com.
let pickerApiPromise = null;
const loadPickerApi = () => {
  if (window.google?.picker) return Promise.resolve();
  pickerApiPromise ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://apis.google.com/js/api.js';
    script.async = true;
    script.onload = () => window.gapi.load('picker', { callback: resolve, onerror: reject });
    script.onerror = reject;
    document.head.appendChild(script);
  }).catch((e) => {
    pickerApiPromise = null;
    throw e;
  });
  return pickerApiPromise;
};

const sessionExpired = () => {
  isConnected.value = false;
  errorMessage.value = 'La connexion Google a expiré. Reconnectez-vous (étape 1).';
};

const onPicked = (data) => {
  const { picker } = window.google;
  if (data[picker.Response.ACTION] !== picker.Action.PICKED) return;

  const known = new Set(selected.value.map((d) => d.id));
  const added = (data[picker.Response.DOCUMENTS] || [])
    .filter((doc) => doc.mimeType !== FOLDER_MIME && !known.has(doc.id))
    .map((doc) => ({ id: doc.id, name: doc.name, mimeType: doc.mimeType, sizeBytes: doc.sizeBytes }));
  selected.value = [...selected.value, ...added];
};

const openPicker = async () => {
  errorMessage.value = null;
  openingPicker.value = true;
  try {
    const [{ data }] = await Promise.all([axios.post('/google-drive/token'), loadPickerApi()]);
    const { picker } = window.google;

    const docsView = (configure) => {
      const view = new picker.DocsView(picker.ViewId.DOCS)
        .setIncludeFolders(true)
        .setSelectFolderEnabled(false)
        .setMimeTypes([...MIME_TYPES, FOLDER_MIME].join(','))
        .setMode(picker.DocsViewMode.GRID);
      return configure(view);
    };
    const myDrive = docsView((v) => v.setParent('root'));
    myDrive.setLabel?.('Mon Drive');
    const sharedWithMe = docsView((v) => v.setOwnedByMe(false));
    sharedWithMe.setLabel?.('Partagés avec moi');
    const allMedia = new picker.DocsView(picker.ViewId.DOCS_IMAGES_AND_VIDEOS)
      .setMimeTypes(MIME_TYPES.join(','))
      .setMode(picker.DocsViewMode.GRID);
    allMedia.setLabel?.('Toutes mes photos');

    new picker.PickerBuilder()
      .setDeveloperKey(props.picker.apiKey)
      .setAppId(props.picker.appId)
      .setOAuthToken(data.access_token)
      .setOrigin(window.location.origin)
      .setLocale('fr')
      .setTitle('Choisissez vos photos')
      .addView(myDrive)
      .addView(sharedWithMe)
      .addView(allMedia)
      .enableFeature(picker.Feature.MULTISELECT_ENABLED)
      .setCallback(onPicked)
      .build()
      .setVisible(true);
  } catch (error) {
    if (error.response?.status === 401) {
      sessionExpired();
    } else {
      errorMessage.value = 'Impossible d\'ouvrir Google Drive. Vérifiez votre connexion et réessayez.';
    }
  } finally {
    openingPicker.value = false;
  }
};

const launching = ref(false);
const importing = ref(false);
const importStartedAt = ref(null);

const startImport = async () => {
  errorMessage.value = null;
  launching.value = true;
  const isNew = importForm.album_id === '__new__';
  try {
    const { data } = await axios.post('/google-drive/import', {
      file_ids: selected.value.map((d) => d.id),
      person_id: importForm.person_id,
      album_id: isNew ? null : importForm.album_id,
      new_album_name: isNew ? importForm.new_album_name : null,
    });
    importStartedAt.value = data.started_at;
    importing.value = true;
  } catch (error) {
    if (error.response?.status === 401) {
      sessionExpired();
    } else {
      errorMessage.value = 'Le lancement de l\'import a échoué. Réessayez.';
    }
  } finally {
    launching.value = false;
  }
};
</script>
