<template>
  <AppLayout title="Import GEDCOM">
    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <Link
          href="/family-tree"
          class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-6"
        >
          &larr; Retour a l'arbre
        </Link>

        <h1 class="text-2xl font-bold text-gray-900 mb-6">Importer un fichier GEDCOM</h1>

        <!-- Step 1: Upload -->
        <div v-if="step === 'upload'" class="bg-white rounded-lg shadow-md p-6">
          <p class="text-gray-600 mb-4">
            Selectionnez un fichier GEDCOM (.ged) exporte depuis Geneanet ou un autre logiciel de genealogie.
          </p>

          <div
            class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center"
            :class="{ 'border-indigo-400 bg-indigo-50': isDragging }"
            @dragover.prevent="isDragging = true"
            @dragleave="isDragging = false"
            @drop.prevent="handleDrop"
          >
            <input
              ref="fileInput"
              type="file"
              accept=".ged,.gedcom"
              class="hidden"
              @change="handleFileSelect"
            />
            <svg class="mx-auto h-12 w-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            <button
              @click="$refs.fileInput.click()"
              class="px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
              :disabled="uploading"
            >
              {{ uploading ? 'Analyse en cours...' : 'Choisir un fichier' }}
            </button>
            <p class="mt-2 text-sm text-gray-500">ou glissez-deposez un fichier .ged ici</p>
          </div>

          <p v-if="uploadError" class="mt-4 text-red-600 text-sm">{{ uploadError }}</p>

          <!-- Previous imports -->
          <div v-if="imports.length > 0" class="mt-8">
            <h3 class="font-semibold text-gray-900 mb-3">Imports precedents</h3>
            <div v-for="imp in imports" :key="imp.id" class="flex items-center justify-between py-2 border-b">
              <div>
                <span class="font-medium">{{ imp.filename }}</span>
                <span class="text-sm text-gray-500 ml-2">{{ imp.individuals_count }} individus</span>
              </div>
              <span
                :class="{
                  'text-green-600': imp.status === 'completed',
                  'text-yellow-600': imp.status === 'matching',
                  'text-red-600': imp.status === 'failed',
                  'text-gray-500': imp.status === 'pending',
                }"
                class="text-sm font-medium"
              >
                {{ statusLabel(imp.status) }}
              </span>
            </div>
          </div>
        </div>

        <!-- Step 2: Match review -->
        <div v-if="step === 'matching'" class="bg-white rounded-lg shadow-md p-6">
          <div class="flex justify-between items-center mb-6">
            <div>
              <h2 class="text-lg font-semibold">Verification des correspondances</h2>
              <p class="text-sm text-gray-500">
                {{ suggestions.length }} individus trouves dans le fichier.
                Choisissez de creer, associer ou ignorer chaque personne.
              </p>
            </div>
            <div class="flex gap-2">
              <button
                @click="setAllDecisions('create')"
                class="px-3 py-1 text-sm bg-green-100 text-green-700 rounded hover:bg-green-200"
              >
                Tout creer
              </button>
              <button
                @click="setAllDecisions('skip')"
                class="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200"
              >
                Tout ignorer
              </button>
            </div>
          </div>

          <div class="space-y-3 max-h-[60vh] overflow-y-auto">
            <div
              v-for="suggestion in suggestions"
              :key="suggestion.gedcom_id"
              class="border rounded-lg p-4"
              :class="{
                'border-green-300 bg-green-50': decisions[suggestion.gedcom_id] === 'create',
                'border-blue-300 bg-blue-50': decisions[suggestion.gedcom_id]?.startsWith('match_'),
                'border-gray-200 bg-gray-50': decisions[suggestion.gedcom_id] === 'skip',
              }"
            >
              <div class="flex items-center justify-between">
                <div>
                  <span class="font-medium">{{ suggestion.name }}</span>
                  <span v-if="suggestion.birth_date" class="text-sm text-gray-500 ml-2">
                    {{ suggestion.birth_date }}
                  </span>
                  <span v-if="suggestion.death_date" class="text-sm text-gray-500">
                    - {{ suggestion.death_date }}
                  </span>
                  <span class="text-xs text-gray-400 ml-2">
                    {{ suggestion.sex === 'M' ? 'Homme' : suggestion.sex === 'F' ? 'Femme' : '' }}
                  </span>
                </div>

                <div class="flex gap-2">
                  <button
                    @click="decisions[suggestion.gedcom_id] = 'create'"
                    :class="decisions[suggestion.gedcom_id] === 'create' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700'"
                    class="px-3 py-1 text-sm rounded"
                  >
                    Creer
                  </button>
                  <button
                    @click="decisions[suggestion.gedcom_id] = 'skip'"
                    :class="decisions[suggestion.gedcom_id] === 'skip' ? 'bg-gray-600 text-white' : 'bg-gray-100 text-gray-700'"
                    class="px-3 py-1 text-sm rounded"
                  >
                    Ignorer
                  </button>
                </div>
              </div>

              <div v-if="suggestion.matches.length > 0" class="mt-3">
                <p class="text-xs text-gray-500 mb-1">Correspondances possibles :</p>
                <div class="flex flex-wrap gap-2">
                  <button
                    v-for="match in suggestion.matches"
                    :key="match.person_id"
                    @click="decisions[suggestion.gedcom_id] = 'match_' + match.person_id"
                    :class="decisions[suggestion.gedcom_id] === 'match_' + match.person_id ? 'bg-blue-600 text-white' : 'bg-blue-50 text-blue-700'"
                    class="px-3 py-1 text-sm rounded border border-blue-200"
                  >
                    {{ match.person_name }}
                    <span class="text-xs opacity-75">({{ match.score }}%)</span>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-6 flex justify-end gap-3">
            <button
              @click="step = 'upload'"
              class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
            >
              Annuler
            </button>
            <button
              @click="confirmImport"
              :disabled="importing"
              class="px-6 py-2 text-sm text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50"
            >
              {{ importing ? 'Import en cours...' : 'Confirmer l\'import' }}
            </button>
          </div>
        </div>

        <!-- Step 3: Results -->
        <div v-if="step === 'results'" class="bg-white rounded-lg shadow-md p-6 text-center">
          <svg class="mx-auto h-16 w-16 text-green-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          <h2 class="text-xl font-semibold text-gray-900 mb-2">Import termine</h2>
          <div class="text-gray-600 space-y-1">
            <p>{{ importStats.created }} personne(s) creee(s)</p>
            <p>{{ importStats.matched }} personne(s) associee(s)</p>
            <p>{{ importStats.skipped }} personne(s) ignoree(s)</p>
          </div>
          <Link
            href="/family-tree"
            class="mt-6 inline-flex items-center px-6 py-2 text-sm text-white bg-indigo-600 rounded-lg hover:bg-indigo-700"
          >
            Voir l'arbre genealogique
          </Link>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const props = defineProps({
  imports: { type: Array, default: () => [] },
});

const step = ref('upload');
const uploading = ref(false);
const importing = ref(false);
const uploadError = ref('');
const isDragging = ref(false);
const importId = ref(null);
const suggestions = ref([]);
const decisions = reactive({});
const importStats = ref({ created: 0, matched: 0, skipped: 0 });

function handleFileSelect(e) {
  const file = e.target.files[0];
  if (file) uploadFile(file);
}

function handleDrop(e) {
  isDragging.value = false;
  const file = e.dataTransfer.files[0];
  if (file) uploadFile(file);
}

async function uploadFile(file) {
  uploading.value = true;
  uploadError.value = '';

  const formData = new FormData();
  formData.append('file', file);

  try {
    const response = await axios.post('/family-tree/import/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    importId.value = response.data.import_id;
    suggestions.value = response.data.suggestions;

    // Default decisions: auto-match high-confidence, otherwise create
    suggestions.value.forEach(s => {
      if (s.matches.length > 0 && s.matches[0].score >= 80) {
        decisions[s.gedcom_id] = 'match_' + s.matches[0].person_id;
      } else {
        decisions[s.gedcom_id] = 'create';
      }
    });

    step.value = 'matching';
  } catch (error) {
    uploadError.value = error.response?.data?.message || 'Erreur lors de l\'analyse du fichier';
  } finally {
    uploading.value = false;
  }
}

async function confirmImport() {
  importing.value = true;
  try {
    const response = await axios.post(`/family-tree/import/${importId.value}/confirm`, {
      decisions: { ...decisions },
    });
    importStats.value = response.data.stats;
    step.value = 'results';
  } catch (error) {
    alert('Erreur: ' + (error.response?.data?.message || error.message));
  } finally {
    importing.value = false;
  }
}

function setAllDecisions(action) {
  suggestions.value.forEach(s => {
    decisions[s.gedcom_id] = action;
  });
}

function statusLabel(status) {
  const labels = {
    pending: 'En attente',
    matching: 'En cours de verification',
    importing: 'Import en cours',
    completed: 'Termine',
    failed: 'Echoue',
  };
  return labels[status] || status;
}
</script>
