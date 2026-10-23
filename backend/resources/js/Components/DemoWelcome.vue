<template>
  <!-- Démo : première arrivée sur l'accueil DANS CE NAVIGATEUR (le compte est
       partagé entre tous les visiteurs) → proposer la visite guidée. -->
  <BaseModal v-if="open" max-width="md" @close="later">
    <div class="px-6 pt-7 pb-6 text-center space-y-4">
      <div class="mx-auto w-14 h-14 rounded-2xl bg-brand-100 text-brand-800 flex items-center justify-center text-2xl" aria-hidden="true">✦</div>
      <h2 class="font-display text-2xl font-bold text-surface-900">{{ $t('Bienvenue dans MemoryLane') }}</h2>
      <p class="text-surface-600">
        {{ $t('Vous visitez la famille Lambert, une famille imaginaire, avec son arbre, ses photos et ses souvenirs.') }}
        <strong class="text-surface-900">{{ $t('C’est votre première visite ?') }}</strong>
      </p>
      <div class="flex flex-col sm:flex-row gap-3 justify-center pt-1">
        <button type="button" class="btn-primary" @click="tour">{{ $t('Oui, faites-moi visiter') }}</button>
        <button type="button" class="btn-secondary" @click="later">{{ $t('Non, je regarde seul') }}</button>
      </div>
      <p class="text-xs text-surface-400">{{ $t('La visite reste disponible à tout moment, en haut de la page.') }}</p>
    </div>
  </BaseModal>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import BaseModal from '@/Components/BaseModal.vue';
import { startTour, tourInProgress } from '@/Composables/useTour';

const KEY = 'ml:demo:welcomed';
const page = usePage();
const open = ref(false);

const remember = () => {
  try { localStorage.setItem(KEY, new Date().toISOString()); } catch { /* navigation privée */ }
};

onMounted(() => {
  let seen = false;
  try { seen = !!localStorage.getItem(KEY); } catch { /* navigation privée */ }
  open.value = page.props.demo && page.component === 'Dashboard' && !seen && !tourInProgress();
});

function tour() {
  remember();
  open.value = false;
  startTour(page);
}

function later() {
  remember();
  open.value = false;
}
</script>
