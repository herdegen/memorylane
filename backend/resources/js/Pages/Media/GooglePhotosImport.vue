<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
          <h1 class="text-display text-4xl text-surface-900">Importer depuis Google Photos</h1>
          <p class="mt-2 text-surface-600">
            Choisissez vos photos dans Google Photos — vous pouvez y chercher par personne,
            lieu ou date — puis rattachez-les à une personne ou un album MemoryLane.
          </p>
        </div>

        <FormError
          v-if="errorMessage"
          type="error"
          :message="errorMessage"
          dismissible
          @dismiss="errorMessage = null"
        />

        <!-- Étape 1 : connexion Google -->
        <div class="card card--padded mb-6">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h2 class="card-title mb-1">1. Connecter Google Photos</h2>
              <p class="text-sm text-surface-500">
                {{ isConnected ? 'Compte Google connecté.' : 'Autorisez MemoryLane à recevoir les photos que vous choisirez. Rien d\'autre n\'est partagé.' }}
              </p>
            </div>
            <span v-if="isConnected" class="badge-teal shrink-0">Connecté</span>
            <a v-else href="/google-photos/connect" class="btn-primary shrink-0">Se connecter</a>
          </div>
        </div>

        <!-- Étape 2 : sélection -->
        <div class="card card--padded mb-6" :class="{ 'opacity-50': !isConnected }">
          <h2 class="card-title mb-1">2. Choisir les photos</h2>
          <p class="text-sm text-surface-500 mb-4">
            La sélection s'ouvre dans Google Photos. Astuce : cherchez-y un visage pour
            retrouver toutes les photos d'une personne.
          </p>

          <button
            v-if="!selectionDone && !waitingForSelection"
            @click="openPicker"
            :disabled="!isConnected"
            class="btn-primary"
          >
            Ouvrir Google Photos
          </button>

          <div v-if="waitingForSelection">
            <div class="flex flex-wrap items-center gap-3">
              <span class="inline-flex items-center gap-2 text-sm text-surface-600">
                <svg class="w-4 h-4 animate-spin text-brand-600" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                En attente de votre sélection…
              </span>
              <a
                v-if="pickerUri"
                :href="pickerUri"
                target="_blank"
                rel="noopener"
                class="btn-secondary btn-sm"
              >
                Rouvrir Google Photos
              </a>
              <button @click="cancelSelection" type="button" class="btn-ghost btn-sm">
                Annuler
              </button>
            </div>
            <p class="mt-3 text-sm text-surface-500">
              Choisissez vos photos dans l'onglet Google Photos, validez, puis revenez ici —
              la page détectera votre sélection automatiquement. Vous pouvez annuler si vous
              avez fermé l'onglet ou changé d'avis.
            </p>
          </div>

          <div v-if="selectionDone" class="flex items-center gap-2 text-teal-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            <span class="text-sm font-medium">Sélection terminée — prête à importer.</span>
          </div>
        </div>

        <!-- Étape 3 : rattachement + import -->
        <div class="card card--padded" :class="{ 'opacity-50': !selectionDone }">
          <h2 class="card-title mb-1">3. Importer</h2>
          <p class="text-sm text-surface-500 mb-4">
            Optionnel : les photos importées seront directement rattachées à une personne
            et/ou un album.
          </p>

          <form v-if="!importing" @submit.prevent="startImport" class="space-y-4">
            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="form-label" for="person">Personne</label>
                <select id="person" v-model="importForm.person_id" class="form-select" :disabled="!selectionDone">
                  <option :value="null">— Aucune —</option>
                  <option v-for="person in people" :key="person.id" :value="person.id">
                    {{ person.name }}
                  </option>
                </select>
              </div>
              <div>
                <label class="form-label" for="album">Album</label>
                <select id="album" v-model="importForm.album_id" class="form-select" :disabled="!selectionDone">
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
                  :disabled="!selectionDone"
                />
              </div>
            </div>

            <button
              type="submit"
              :disabled="!selectionDone || launching"
              class="btn-primary"
            >
              {{ launching ? 'Lancement…' : 'Importer ma sélection' }}
            </button>
          </form>

          <!-- Progression : les photos arrivent au fur et à mesure -->
          <div v-else>
            <!-- En cours -->
            <div v-if="!importDone" class="flex items-center gap-2 mb-4">
              <svg class="w-5 h-5 animate-spin text-brand-600" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              <span class="text-sm text-surface-700">
                <span class="font-semibold text-surface-900">{{ importedCount }}</span>
                photo(s) importée(s)… <span class="text-surface-400">l'import continue en arrière-plan.</span>
              </span>
            </div>

            <!-- Terminé (ou en pause) : le compteur n'évolue plus -->
            <div v-else class="flex items-center gap-2 mb-4">
              <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span class="text-sm text-surface-700">
                <span class="font-semibold text-surface-900">{{ importedCount }}</span>
                photo(s) importée(s). Import terminé — <span class="text-surface-400">si des photos manquent, relancez l'import.</span>
              </span>
            </div>

            <div v-if="importedItems.length" class="grid grid-cols-4 sm:grid-cols-6 gap-2 mb-4">
              <img
                v-for="it in importedItems"
                :key="it.id"
                :src="it.url"
                :alt="it.name"
                loading="lazy"
                class="aspect-square object-cover rounded-lg border border-surface-200"
              />
            </div>

            <Link href="/media" class="btn-secondary btn-sm">Voir la galerie</Link>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormError from '@/Components/Forms/FormError.vue';

const props = defineProps({
  isConnected: Boolean,
  pickerSession: { type: Object, default: null },
  people: { type: Array, default: () => [] },
  albums: { type: Array, default: () => [] },
});

const errorMessage = ref(null);
const waitingForSelection = ref(false);
const selectionDone = ref(false);
const pickerUri = ref(props.pickerSession?.pickerUri || null);

let pollTimer = null;
let pollCount = 0;
const MAX_POLLS = 200; // ~10 min à 3s : évite de poller indéfiniment

const importForm = useForm({
  person_id: null,
  album_id: null,
  new_album_name: '',
});

const pollStatus = async () => {
  try {
    const { data } = await axios.get('/google-photos/session/status');
    if (data.mediaItemsSet) {
      selectionDone.value = true;
      waitingForSelection.value = false;
      stopPolling();
    }
  } catch (error) {
    if (error.response?.status === 401 || error.response?.data?.error === 'google_error') {
      stopPolling();
      waitingForSelection.value = false;
      errorMessage.value = googleErrorText(error);
    }
  }
};

const startPolling = () => {
  stopPolling();
  pollCount = 0;
  pollTimer = setInterval(() => {
    if (++pollCount > MAX_POLLS) {
      stopPolling();
      waitingForSelection.value = false;
      errorMessage.value = 'Aucune sélection détectée. Rouvrez Google Photos et validez votre choix, ou recommencez.';
      return;
    }
    pollStatus();
  }, 3000);
};

const cancelSelection = async () => {
  stopPolling();
  waitingForSelection.value = false;
  selectionDone.value = false;
  pickerUri.value = null;
  errorMessage.value = null;
  try {
    await axios.post('/google-photos/session/cancel');
  } catch (e) {
    // best effort
  }
};

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
};

const googleErrorText = (error) => {
  if (error.response?.status === 401) {
    return 'La connexion Google a expiré. Reconnectez-vous (étape 1).';
  }
  const data = error.response?.data;
  if (data?.error === 'google_error') {
    return `Google répond (${data.google_status}) : ${data.message}` +
      (data.google_status === 403
        ? ' — vérifiez que le « Google Photos Picker API » est activé dans la console Google Cloud du projet.'
        : '');
  }
  return 'Impossible d\'ouvrir la sélection Google Photos. Réessayez.';
};

const openPicker = async () => {
  errorMessage.value = null;
  try {
    const { data } = await axios.post('/google-photos/session');
    pickerUri.value = data.pickerUri;
    window.open(data.pickerUri, '_blank', 'noopener');
    waitingForSelection.value = true;
    startPolling();
  } catch (error) {
    errorMessage.value = googleErrorText(error);
  }
};

const launching = ref(false);
const importing = ref(false);
const importDone = ref(false);
const importStartedAt = ref(null);
const importedCount = ref(0);
const importedItems = ref([]);
let importPollTimer = null;
let importPollCount = 0;
let importIdlePolls = 0;
let importLastCount = -1;
const IMPORT_MAX_POLLS = 150; // ~10 min à 4s
// Sans nouvelle photo pendant ~1 min, on considère l'import terminé (ou en
// pause/échec) et on arrête le spinner — évite qu'il tourne indéfiniment.
const IMPORT_IDLE_LIMIT = 15;

const stopImportPolling = () => {
  if (importPollTimer) {
    clearInterval(importPollTimer);
    importPollTimer = null;
  }
};

// L'import n'expose pas d'état terminal côté serveur (cf. GitHub #11) : on le
// déduit de l'arrêt de la progression.
const finishImport = () => {
  stopImportPolling();
  importDone.value = true;
};

const pollImported = async () => {
  try {
    const { data } = await axios.get('/google-photos/imported', { params: { after: importStartedAt.value } });
    importedCount.value = data.count;
    importedItems.value = data.items;

    if (data.count === importLastCount) {
      if (++importIdlePolls >= IMPORT_IDLE_LIMIT) {
        finishImport();
      }
    } else {
      importIdlePolls = 0;
      importLastCount = data.count;
    }
  } catch (e) {
    // transient : on réessaiera au prochain tick
  }
};

const startImport = async () => {
  errorMessage.value = null;
  launching.value = true;
  const isNew = importForm.album_id === '__new__';
  try {
    const { data } = await axios.post('/google-photos/import', {
      person_id: importForm.person_id,
      album_id: isNew ? null : importForm.album_id,
      new_album_name: isNew ? importForm.new_album_name : null,
    });
    importStartedAt.value = data.started_at;
    importing.value = true;
    importDone.value = false;
    importedCount.value = 0;
    importedItems.value = [];
    importPollCount = 0;
    importIdlePolls = 0;
    importLastCount = -1;
    pollImported();
    importPollTimer = setInterval(() => {
      if (++importPollCount > IMPORT_MAX_POLLS) {
        finishImport();
        return;
      }
      pollImported();
    }, 4000);
  } catch (error) {
    errorMessage.value = 'Le lancement de l\'import a échoué. Réessayez.';
  } finally {
    launching.value = false;
  }
};

// Une session de sélection déjà terminée peut exister (retour sur la page)
if (props.pickerSession) {
  waitingForSelection.value = true;
  startPolling();
  pollStatus();
}

onBeforeUnmount(() => {
  stopPolling();
  stopImportPolling();
});
</script>
