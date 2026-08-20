<template>
  <Head title="Détection des visages" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow-xs p-6">
          <h1 class="text-xl font-semibold text-surface-900 mb-2">Détection des visages en lot</h1>
          <p class="text-sm text-surface-500 mb-6">
            <template v-if="selectionMode">
              Analyse les photos sélectionnées dans l'admin, directement dans votre
              navigateur. Laissez cet onglet ouvert pendant le traitement.
            </template>
            <template v-else>
              Analyse toutes les photos pas encore traitées, directement dans votre
              navigateur. Laissez cet onglet ouvert pendant le traitement.
            </template>
          </p>

          <!-- Chargement de la liste -->
          <div v-if="loadingList" class="text-sm text-surface-500">Chargement des photos…</div>

          <!-- Rien à faire -->
          <div v-else-if="pendingIds.length === 0 && !done" class="text-sm text-surface-600">
            Toutes les photos ont déjà été analysées. 🎉
          </div>

          <template v-else>
            <!-- Progression -->
            <div class="mb-4">
              <div class="flex justify-between text-sm text-surface-600 mb-1">
                <span>{{ processed }} / {{ total }} photos</span>
                <span v-if="errors > 0" class="text-red-600 dark:text-red-400">{{ errors }} erreur{{ errors > 1 ? 's' : '' }}</span>
              </div>
              <div class="w-full h-2 bg-surface-100 rounded-full overflow-hidden">
                <div
                  class="h-full bg-brand-600 transition-all duration-300"
                  :style="{ width: total ? `${(processed / total) * 100}%` : '0%' }"
                ></div>
              </div>
              <p v-if="running" class="mt-2 text-xs text-surface-500">{{ statusMessage }}</p>
            </div>

            <!-- Contrôles -->
            <div class="flex gap-2">
              <button
                v-if="!running && !done"
                @click="startBatch"
                class="px-4 py-2 bg-brand-600 text-white rounded-lg text-sm font-medium hover:bg-brand-700"
              >
                Lancer la détection ({{ total }})
              </button>
              <button
                v-if="running"
                @click="cancel"
                class="px-4 py-2 bg-surface-100 text-surface-700 rounded-lg text-sm font-medium hover:bg-surface-200"
              >
                Arrêter
              </button>
              <div v-if="done" class="text-sm text-green-700 dark:text-green-300 font-medium py-2">
                Terminé : {{ processed - errors }} photo(s) analysée(s){{ errors ? `, ${errors} en échec` : '' }}.
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFaceDetection } from '@/composables/useFaceDetection';
import axios from 'axios';

const { detectFaces, ensureModelsLoaded } = useFaceDetection();

const pendingIds = ref([]);
const loadingList = ref(true);
const running = ref(false);
const done = ref(false);
const processed = ref(0);
const total = ref(0);
const errors = ref(0);
const statusMessage = ref('');
const selectionMode = ref(false);
let cancelled = false;

const loadPending = async () => {
  loadingList.value = true;
  try {
    // Mode sélection : ?ids=a,b,c passés depuis l'admin -> on n'analyse que ceux-là.
    const idsParam = new URLSearchParams(window.location.search).get('ids');
    if (idsParam) {
      selectionMode.value = true;
      pendingIds.value = idsParam.split(',').filter(Boolean);
    } else {
      const { data } = await axios.get('/vision/pending');
      pendingIds.value = data.media_ids || [];
    }
    total.value = pendingIds.value.length;
  } catch (e) {
    console.error('Failed to load pending media:', e);
  } finally {
    loadingList.value = false;
  }
};

const startBatch = async () => {
  running.value = true;
  done.value = false;
  cancelled = false;
  processed.value = 0;
  errors.value = 0;

  statusMessage.value = 'Chargement des modèles…';
  try {
    await ensureModelsLoaded();
  } catch (e) {
    statusMessage.value = 'Échec du chargement des modèles.';
    running.value = false;
    return;
  }

  // Traitement séquentiel : un seul contexte WebGL à la fois.
  for (const id of pendingIds.value) {
    if (cancelled) break;

    statusMessage.value = `Analyse ${processed.value + 1} / ${total.value}…`;
    try {
      const faces = await detectFaces(`/vision/media/${id}/image?conversion=medium`);
      await axios.post(`/vision/media/${id}/faces`, { faces });
    } catch (e) {
      console.error(`Détection échouée pour ${id}:`, e);
      errors.value++;
    }
    processed.value++;
  }

  running.value = false;
  if (!cancelled) {
    done.value = true;
  }
};

const cancel = () => {
  cancelled = true;
  statusMessage.value = 'Arrêt en cours…';
};

onMounted(loadPending);
</script>
