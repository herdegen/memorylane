<template>
  <!-- Site de démonstration : rappel discret en haut de chaque page. -->
  <div
    v-if="$page.props.demo"
    ref="root"
    class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-1.5 text-xs sm:text-sm bg-teal-700 text-snow"
  >
    <span>{{ $t('Démonstration : une famille imaginaire pour découvrir MemoryLane. Tout revient à l’état de départ chaque nuit.') }}</span>
    <button type="button" class="underline underline-offset-2 font-semibold" @click="replay">
      {{ $t('Refaire la visite') }}
    </button>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { startTour } from '@/Composables/useTour';
import { useTopBanner } from '@/Composables/useTopBanner';

const page = usePage();
const root = ref(null);
useTopBanner('demo', root);
const replay = () => startTour(page);
</script>
