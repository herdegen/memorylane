<template>
  <Head :title="$t('Détection des visages')" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow-xs p-6">
          <h1 class="text-xl font-semibold text-surface-900 mb-2">{{ $t('Détection des visages en lot') }}</h1>
          <p class="text-sm text-surface-500 mb-6">
            <template v-if="serverMode">
              {{ $t('Les photos sont analysées sur le serveur, en arrière-plan : vous pouvez fermer cet onglet. Les visages déjà identifiés sont conservés.') }}
            </template>
            <template v-else-if="selectionMode">
              {{ $t('Analyse les photos sélectionnées dans l\'admin, directement dans votre navigateur. Laissez cet onglet ouvert pendant le traitement.') }}
            </template>
            <template v-else>
              {{ $t('Analyse toutes les photos pas encore traitées, directement dans votre navigateur. Laissez cet onglet ouvert pendant le traitement.') }}
            </template>
          </p>

          <!-- Chargement de la liste -->
          <div v-if="loadingList" class="text-sm text-surface-500">{{ $t('Chargement des photos…') }}</div>

          <!-- Rien à faire -->
          <div v-else-if="pendingIds.length === 0 && !done" class="text-sm text-surface-600">
            {{ $t('Toutes les photos ont déjà été analysées. 🎉') }}
          </div>

          <template v-else>
            <!-- Progression -->
            <div class="mb-4">
              <div class="flex justify-between text-sm text-surface-600 mb-1">
                <span>{{ $t(':processed / :total photos', { processed, total }) }}</span>
                <span v-if="errors > 0" class="text-red-600 dark:text-red-400">{{ $tc(':count erreur|:count erreurs', errors) }}</span>
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
                {{ $t('Lancer la détection (:total)', { total }) }}
              </button>
              <button
                v-if="running"
                @click="cancel"
                class="px-4 py-2 bg-surface-100 text-surface-700 rounded-lg text-sm font-medium hover:bg-surface-200"
              >
                {{ $t('Arrêter') }}
              </button>
              <div v-if="done && serverMode" class="text-sm text-green-700 dark:text-green-300 font-medium py-2">
                {{ $tc(':count photo envoyée à l\'analyse — quelques secondes chacune, en arrière-plan.|:count photos envoyées à l\'analyse — quelques secondes chacune, en arrière-plan.', queued) }}
              </div>
              <div v-else-if="done" class="text-sm text-green-700 dark:text-green-300 font-medium py-2">
                {{ errors
                  ? $tc('Terminé : :count photo analysée, :failed en échec.|Terminé : :count photos analysées, :failed en échec.', processed - errors, { failed: errors })
                  : $tc('Terminé : :count photo analysée.|Terminé : :count photos analysées.', processed - errors) }}
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFaceDetection } from '@/Composables/useFaceDetection';
import axios from 'axios';
import { t } from '@/i18n';

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
const serverMode = computed(() => usePage().props.faces_engine === 'insightface');
const queued = ref(0);
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

  // Serveur : mise en file par paquets, rien à garder ouvert.
  if (serverMode.value) {
    statusMessage.value = t('Envoi au serveur…');
    queued.value = 0;
    try {
      for (let i = 0; i < pendingIds.value.length; i += 500) {
        const { data } = await axios.post('/vision/batch/analyze', { ids: pendingIds.value.slice(i, i + 500) });
        queued.value += data.queued;
        processed.value = Math.min(total.value, i + 500);
      }
      done.value = true;
    } catch (e) {
      console.error('Envoi au serveur échoué :', e);
      errors.value = total.value - processed.value;
    }
    running.value = false;
    return;
  }

  statusMessage.value = t('Chargement des modèles…');
  try {
    await ensureModelsLoaded();
  } catch (e) {
    statusMessage.value = t('Échec du chargement des modèles.');
    running.value = false;
    return;
  }

  // Traitement séquentiel : un seul contexte WebGL à la fois.
  for (const id of pendingIds.value) {
    if (cancelled) break;

    statusMessage.value = t('Analyse :current / :total…', { current: processed.value + 1, total: total.value });
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
  statusMessage.value = t('Arrêt en cours…');
};

onMounted(loadPending);
</script>
