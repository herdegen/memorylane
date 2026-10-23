<template>
  <!-- Barre d'onglets (téléphone) : Accueil · Photos · ⊕ · Albums · Proches.
       Arbre, Réseau et Carte vivent dans « Proches » (segmenté) ; en mode
       amis l'onglet s'appelle « Amis ». -->
  <nav
    class="sm:hidden fixed inset-x-0 bottom-0 z-40 bg-white border-t border-surface-200
           grid grid-cols-5 pb-[env(safe-area-inset-bottom)]"
    :aria-label="$t('Navigation principale')"
    data-tour="tabbar"
  >
    <Link href="/dashboard" :class="tabClass('home')" :aria-current="isActive('home') ? 'page' : undefined">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-7 9 7v9a1 1 0 01-1 1h-5v-6h-6v6H4a1 1 0 01-1-1z" /></svg>
      <span>{{ $t('Accueil') }}</span>
    </Link>
    <Link href="/media" :class="tabClass('photos')" :aria-current="isActive('photos') ? 'page' : undefined">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3" /><path d="M3 16l5-5 4 4 3-3 6 6" /></svg>
      <span>{{ $t('Photos') }}</span>
    </Link>
    <button type="button" class="flex items-center justify-center" :aria-label="$t('Ajouter des photos')" data-tour="add" @click="$emit('add')">
      <span class="w-14 h-14 -mt-6 rounded-full bg-brand-600 text-white flex items-center justify-center shadow-[0_10px_24px_-8px_rgba(217,119,6,.7)] dark:shadow-none">
        <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
      </span>
    </button>
    <Link href="/albums" :class="tabClass('albums')" :aria-current="isActive('albums') ? 'page' : undefined">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="14" rx="2" /><path d="M7 3h10" /></svg>
      <span>{{ $t('Albums') }}</span>
    </Link>
    <Link href="/people" :class="tabClass('people')" :aria-current="isActive('people') ? 'page' : undefined">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5" /><circle cx="17" cy="10" r="2.5" /><path d="M3 19a6 6 0 0112 0M15 19a4 4 0 016 0" /></svg>
      <span>{{ isFriends ? $t('Amis') : $t('Proches') }}</span>
    </Link>
  </nav>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { useCircle } from '@/Composables/useCircle';

defineEmits(['add']);

const page = usePage();
const { isFriends } = useCircle();

// Onglet actif d'après le composant de page : « Proches » couvre aussi
// l'arbre, le réseau et la carte.
const TAB = 'flex flex-col items-center justify-center gap-0.5 h-16 text-[11px]';
const tabClass = (tab) => [TAB, isActive(tab) ? 'text-brand-700 font-bold' : 'text-surface-500 font-semibold'];

const isActive = (tab) => {
  const c = page.component;
  switch (tab) {
    case 'home': return c === 'Dashboard';
    case 'photos': return c.startsWith('Media/');
    case 'albums': return c.startsWith('Albums/');
    case 'people': return c.startsWith('People/') || c.startsWith('FamilyTree/') || c.startsWith('Network/') || c.startsWith('Map/');
    default: return false;
  }
};
</script>
