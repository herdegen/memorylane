<template>
  <!-- Téléphone : segmenté en tête de l'onglet « Proches » (« Amis » en mode
       amis) — Personnes · Arbre (Réseau) · Carte. Les pages Arbre, Réseau et
       Carte sont pleine hauteur : voir `.app-viewport` dans app.css. -->
  <div class="sm:hidden h-13 px-4 py-2 bg-page">
    <div class="grid grid-cols-3 h-9 p-0.5 rounded-full bg-surface-200/80" role="tablist">
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

const page = usePage();
const { isFriends, peopleLabel } = useCircle();

const segments = computed(() => {
  const c = page.component;
  return [
    { href: '/people', label: peopleLabel.value, active: c.startsWith('People/') },
    isFriends.value
      ? { href: '/network', label: 'Réseau', active: c.startsWith('Network/') }
      : { href: '/family-tree', label: 'Arbre', active: c.startsWith('FamilyTree/') || c.startsWith('Network/') },
    { href: '/map', label: 'Carte', active: c.startsWith('Map/') },
  ];
});
</script>
