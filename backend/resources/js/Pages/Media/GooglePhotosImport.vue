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
            <span v-if="isConnected" class="badge-teal flex-shrink-0">Connecté</span>
            <a v-else href="/google-photos/connect" class="btn-primary flex-shrink-0">Se connecter</a>
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
            v-if="!selectionDone"
            @click="openPicker"
            :disabled="!isConnected || waitingForSelection"
            class="btn-primary"
          >
            {{ waitingForSelection ? 'En attente de votre sélection…' : 'Ouvrir Google Photos' }}
          </button>

          <p v-if="waitingForSelection" class="mt-3 text-sm text-surface-500">
            Choisissez vos photos dans l'onglet Google Photos, validez, puis revenez ici —
            la page détectera votre sélection automatiquement.
          </p>

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

          <form @submit.prevent="startImport" class="space-y-4">
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
                </select>
              </div>
            </div>

            <button
              type="submit"
              :disabled="!selectionDone || importForm.processing"
              class="btn-primary"
            >
              {{ importForm.processing ? 'Lancement…' : 'Importer ma sélection' }}
            </button>
          </form>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { useForm } from '@inertiajs/vue3';
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

let pollTimer = null;

const importForm = useForm({
  person_id: null,
  album_id: null,
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
    if (error.response?.status === 401) {
      stopPolling();
      waitingForSelection.value = false;
      errorMessage.value = 'La connexion Google a expiré. Reconnectez-vous (étape 1).';
    }
  }
};

const startPolling = () => {
  stopPolling();
  pollTimer = setInterval(pollStatus, 3000);
};

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
};

const openPicker = async () => {
  errorMessage.value = null;
  try {
    const { data } = await axios.post('/google-photos/session');
    window.open(data.pickerUri, '_blank', 'noopener');
    waitingForSelection.value = true;
    startPolling();
  } catch (error) {
    if (error.response?.status === 401) {
      errorMessage.value = 'La connexion Google a expiré. Reconnectez-vous (étape 1).';
    } else {
      errorMessage.value = 'Impossible d\'ouvrir la sélection Google Photos. Réessayez.';
    }
  }
};

const startImport = () => {
  importForm.post('/google-photos/import');
};

// Une session de sélection déjà terminée peut exister (retour sur la page)
if (props.pickerSession) {
  waitingForSelection.value = true;
  startPolling();
  pollStatus();
}

onBeforeUnmount(stopPolling);
</script>
