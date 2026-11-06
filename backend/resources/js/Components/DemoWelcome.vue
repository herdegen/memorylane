<template>
  <!-- Démo : première arrivée sur l'accueil DANS CE NAVIGATEUR (le compte est
       partagé entre tous les visiteurs) → l'histoire de la famille, quelques
       choses à essayer tout de suite, et la visite guidée. -->
  <BaseModal v-if="open" max-width="lg" @close="later">
    <div class="px-5 sm:px-7 pt-7 pb-6 space-y-5">
      <div class="text-center space-y-3">
        <div class="mx-auto w-14 h-14 rounded-2xl bg-brand-100 text-brand-800 flex items-center justify-center text-2xl" aria-hidden="true">✦</div>
        <h2 class="font-display text-2xl font-bold text-surface-900">{{ $t('Bienvenue dans MemoryLane') }}</h2>
        <p class="text-surface-600 leading-relaxed">
          {{ welcome?.story ?? $t('Vous visitez « :family », une famille imaginaire, avec son arbre, ses photos et ses souvenirs.', { family: $page.props.circle?.current?.name ?? '' }) }}
        </p>
      </div>

      <div v-if="welcome?.try?.length" class="space-y-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-surface-500">{{ $t('À essayer d’abord') }}</p>
        <Link
          v-for="item in welcome.try"
          :key="item.link"
          :href="item.link"
          class="flex items-start gap-3 rounded-xl border border-surface-200 bg-white px-3.5 py-3 text-left transition hover:border-brand-300 hover:bg-brand-50"
          @click="later"
        >
          <span class="text-2xl leading-none mt-0.5" aria-hidden="true">{{ item.icon }}</span>
          <span class="min-w-0">
            <span class="block font-semibold text-surface-900">{{ item.title }}</span>
            <span class="block text-sm text-surface-600">{{ item.text }}</span>
          </span>
        </Link>
      </div>

      <div class="flex flex-col sm:flex-row gap-3 justify-center">
        <button type="button" class="btn-primary" @click="tour">{{ $t('Faire la visite guidée') }}</button>
        <button type="button" class="btn-secondary" @click="later">{{ $t('Explorer seul') }}</button>
      </div>
      <p class="text-xs text-center text-surface-400">{{ $t('La visite reste disponible à tout moment, en haut de la page.') }}</p>
    </div>
  </BaseModal>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import BaseModal from '@/Components/BaseModal.vue';
import { startTour, tourInProgress } from '@/Composables/useTour';

const page = usePage();
const open = ref(false);
const welcome = computed(() => page.props.demoWelcome);
// Une fois par famille et par navigateur (on peut visiter l'une puis l'autre).
const key = computed(() => `ml:demo:welcomed:${welcome.value?.family ?? 'default'}`);

const remember = () => {
  try { localStorage.setItem(key.value, new Date().toISOString()); } catch { /* navigation privée */ }
};

onMounted(() => {
  let seen = false;
  try { seen = !!localStorage.getItem(key.value); } catch { /* navigation privée */ }
  open.value = !!page.props.demo && page.component === 'Dashboard' && !seen && !tourInProgress();
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
