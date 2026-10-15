<template>
  <div>
    <!-- En cours -->
    <div v-if="!done" class="flex items-center gap-2 mb-4">
      <svg class="w-5 h-5 animate-spin text-brand-600" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
      <span class="text-sm text-surface-700">
        <span class="font-semibold text-surface-900">{{ count }}</span>
        photo(s) importée(s)… <span class="text-surface-400">l'import continue en arrière-plan.</span>
      </span>
    </div>

    <!-- Échec : le job d'import s'est arrêté en erreur -->
    <div v-else-if="failed" class="flex items-center gap-2 mb-4">
      <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
      </svg>
      <span class="text-sm text-surface-700">
        Import interrompu — <span class="font-semibold text-surface-900">{{ count }}</span>
        photo(s) importée(s). <span class="text-surface-400">Relancez pour récupérer les manquantes.</span>
      </span>
    </div>

    <!-- Terminé -->
    <div v-else class="flex items-start gap-2 mb-4">
      <svg class="w-5 h-5 shrink-0 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
      </svg>
      <span class="text-sm text-surface-700">
        <span class="font-semibold text-surface-900">{{ count }}</span>
        photo(s) importée(s). Import terminé — <span class="text-surface-400">si des photos manquent, relancez l'import.</span>
        <span v-if="videos" class="block text-surface-500">
          {{ videos }} vidéo(s) en cours d’optimisation : elles apparaîtront dans « Mes photos » d’ici quelques minutes (environ 10 min par heure de vidéo).
        </span>
        <span v-if="skipped" class="block text-surface-500">
          {{ skipped }} fichier(s) ignoré(s) : format non pris en charge, trop lourd ou inaccessible.
        </span>
      </span>
    </div>

    <div v-if="items.length" class="grid grid-cols-4 sm:grid-cols-6 gap-2 mb-4">
      <img
        v-for="it in items"
        :key="it.id"
        :src="it.url"
        :alt="it.name"
        loading="lazy"
        class="aspect-square object-cover rounded-lg border border-surface-200"
      />
    </div>

    <Link href="/media" class="btn-secondary btn-sm">Voir mes photos</Link>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

// Suivi d'un import lancé en arrière-plan : les photos arrivent au fur et à
// mesure. `statusUrl` renvoie { count, items, finished, failed, skipped, videos }.
const props = defineProps({
  statusUrl: { type: String, required: true },
  startedAt: { type: String, required: true },
});

const done = ref(false);
const failed = ref(false);
const count = ref(0);
const skipped = ref(0);
const videos = ref(0);
const items = ref([]);

let timer = null;
let polls = 0;
let idlePolls = 0;
let lastCount = -1;
const MAX_POLLS = 150; // ~10 min à 4s
// Sans nouvelle photo pendant ~1 min, on considère l'import terminé (ou en
// pause/échec) et on arrête le spinner — évite qu'il tourne indéfiniment.
const IDLE_LIMIT = 15;

const stop = () => {
  if (timer) {
    clearInterval(timer);
    timer = null;
  }
};

const finish = (hasFailed = false) => {
  stop();
  failed.value = hasFailed;
  done.value = true;
};

const poll = async () => {
  try {
    const { data } = await axios.get(props.statusUrl, { params: { after: props.startedAt } });
    count.value = data.count;
    items.value = data.items;
    skipped.value = data.skipped || 0;
    videos.value = data.videos || 0;

    // Signal terminal côté serveur (fiable) : le job a fini ou échoué.
    if (data.finished) {
      finish(!!data.failed);
      return;
    }

    // Secours : si la progression n'évolue plus, on arrête quand même.
    if (data.count === lastCount) {
      if (++idlePolls >= IDLE_LIMIT) finish();
    } else {
      idlePolls = 0;
      lastCount = data.count;
    }
  } catch (e) {
    // transient : on réessaiera au prochain tick
  }
};

onMounted(() => {
  poll();
  timer = setInterval(() => {
    if (++polls > MAX_POLLS) {
      finish();
      return;
    }
    poll();
  }, 4000);
});

onBeforeUnmount(stop);
</script>
