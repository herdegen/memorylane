<template>
  <!-- Téléphone : segmenté en tête de l'onglet « Albums » — Albums · Histoires
       (les histoires partent souvent d'un album). Pas d'histoires en mode amis. -->
  <div v-if="!isFriends" class="sm:hidden h-13 px-4 py-2 bg-page">
    <div class="grid grid-cols-2 h-9 p-0.5 rounded-full bg-surface-200/80" role="tablist">
      <Link
        v-for="s in segments"
        :key="s.href"
        :href="s.href"
        role="tab"
        :aria-selected="s.active"
        class="flex items-center justify-center rounded-full text-[13px] font-bold transition"
        :class="s.active ? 'bg-white text-surface-900 shadow-warm-sm' : 'text-surface-600'"
      >
        {{ s.label }}
      </Link>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useCircle } from '@/Composables/useCircle';
import { t } from '@/i18n';

const page = usePage();
const { isFriends } = useCircle();

const segments = computed(() => [
  { href: '/albums', label: t('Albums'), active: page.component.startsWith('Albums/') },
  { href: '/stories', label: t('Histoires'), active: page.component.startsWith('Stories/') },
]);
</script>
