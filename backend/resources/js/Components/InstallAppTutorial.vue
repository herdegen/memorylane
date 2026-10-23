<template>
  <BaseModal :title="$t('Installer MemoryLane sur votre téléphone')" max-width="2xl" @close="$emit('close')">
    <div class="px-5 sm:px-6 pt-4 pb-6">
      <div class="flex rounded-lg bg-surface-100 p-1 text-sm font-medium mb-5">
        <button
          v-for="tab in tabs"
          :key="tab.value"
          type="button"
          class="flex-1 rounded-md px-2 py-1.5 transition"
          :class="current === tab.value ? 'bg-white text-surface-900 shadow-xs' : 'text-surface-500 hover:text-surface-700'"
          @click="current = tab.value"
        >
          {{ tab.label }}
        </button>
      </div>

      <p v-if="current === 'ios' && browser === 'in-app'" class="mb-4 rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
        <Trans text="Vous êtes dans une autre application (Gmail, Facebook…). Ouvrez d'abord cette page dans :safari (menu :dots ou icône :compass › « Ouvrir dans Safari »), puis suivez ces étapes.">
          <template #safari><strong>Safari</strong></template>
          <template #dots><strong>⋯</strong></template>
          <template #compass><strong>{{ $t('boussole') }}</strong></template>
        </Trans>
      </p>

      <ol class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <li v-for="(step, i) in steps" :key="current + i" class="rounded-2xl border border-surface-200 bg-surface-50 p-4 flex sm:flex-col gap-4">
          <div class="shrink-0 w-28 sm:w-full h-28 rounded-xl bg-white border border-surface-200 flex items-center justify-center overflow-hidden">
            <!-- Bouton Partager de Safari -->
            <div v-if="step.art === 'share'" class="w-[86%]">
              <div class="flex items-center justify-around rounded-lg bg-surface-100 py-2 text-surface-400">
                <span class="text-base leading-none">‹</span>
                <span class="text-base leading-none">›</span>
                <span class="w-7 h-7 rounded-full ring-2 ring-brand-500 bg-brand-50 flex items-center justify-center text-blue-600">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25H7.5a2.25 2.25 0 00-2.25 2.25v9a2.25 2.25 0 002.25 2.25h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25H15M12 15V2.25m0 0l-3 3m3-3l3 3" /></svg>
                </span>
                <span class="text-base leading-none">⋯</span>
              </div>
            </div>
            <!-- Menu ⋮ de Chrome -->
            <div v-else-if="step.art === 'dots'" class="w-[86%]">
              <div class="flex items-center gap-2 rounded-lg bg-surface-100 px-2 py-2">
                <span class="flex-1 h-4 rounded-full bg-white text-[8px] text-surface-400 px-2 leading-4 truncate">memory-lane.fr</span>
                <span class="w-6 h-6 rounded-full ring-2 ring-brand-500 bg-brand-50 flex items-center justify-center text-surface-700 font-bold leading-none">⋮</span>
              </div>
            </div>
            <!-- Menu avec l'entrée à toucher -->
            <div v-else-if="step.art === 'menu'" class="w-[86%] space-y-1">
              <div class="h-4 rounded bg-surface-100"></div>
              <div class="rounded-md ring-2 ring-brand-500 bg-brand-50 px-1.5 py-1 flex items-center gap-1 text-[9px] font-semibold text-surface-800">
                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3" /><path stroke-linecap="round" d="M12 8v8M8 12h8" /></svg>
                {{ current === 'android' ? $t("Installer l'application") : $t("Sur l'écran d'accueil") }}
              </div>
              <div class="h-4 rounded bg-surface-100"></div>
            </div>
            <!-- Écran d'accueil avec l'icône -->
            <div v-else class="grid grid-cols-4 gap-1.5 p-2">
              <div v-for="n in 7" :key="n" class="w-5 h-5 rounded-md" :class="tileColors[n % tileColors.length]"></div>
              <img src="/icons/icon-192.png" alt="" class="w-5 h-5 rounded-md ring-2 ring-brand-500" />
            </div>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-700">{{ $t('Étape :n', { n: i + 1 }) }}</p>
            <p class="mt-1 text-sm text-surface-800 leading-relaxed" v-html="step.text"></p>
          </div>
        </li>
      </ol>

      <p class="mt-5 text-sm text-surface-500" v-html="footer"></p>
    </div>

    <template #footer>
      <button type="button" class="btn-primary" @click="$emit('close')">{{ $t("J'ai compris") }}</button>
    </template>
  </BaseModal>
</template>

<script setup>
import { computed, ref } from 'vue';
import BaseModal from '@/Components/BaseModal.vue';
import Trans from '@/Components/Trans.vue';
import { t } from '@/i18n';
import { iosBrowser } from '@/Composables/useInstallApp';

const props = defineProps({
  // 'ios' | 'android' | 'desktop' : onglet ouvert par défaut.
  device: { type: String, default: 'ios' },
});
defineEmits(['close']);

const tabs = [
  { value: 'ios', label: 'iPhone / iPad' },
  { value: 'android', label: 'Android' },
];
const current = ref(props.device === 'android' ? 'android' : 'ios');
const browser = typeof navigator !== 'undefined' ? iosBrowser() : 'safari';

const tileColors = ['bg-amber-200', 'bg-sky-200', 'bg-rose-200', 'bg-emerald-200', 'bg-violet-200'];

// Textes statiques (aucune donnée utilisateur) : v-html sert juste au gras.
const shareText = computed(() => (browser === 'chrome' || browser === 'edge'
  ? t('Touchez le bouton <strong>Partager</strong> (carré avec une flèche ↑), en haut à droite à côté de l\'adresse.')
  : t('Touchez le bouton <strong>Partager</strong> (carré avec une flèche ↑). Sur les iPhone récents, il est dans le menu <strong>« ⋯ »</strong> en bas à droite.')));

const steps = computed(() => (current.value === 'android'
  ? [
      { art: 'dots', text: t('Dans Chrome, touchez le menu <strong>⋮</strong> en haut à droite.') },
      { art: 'menu', text: t('Touchez <strong>« Installer l\'application »</strong> (ou « Ajouter à l\'écran d\'accueil »).') },
      { art: 'home', text: t('Confirmez avec <strong>« Installer »</strong> : l\'icône MemoryLane apparaît sur votre écran d\'accueil.') },
    ]
  : [
      { art: 'share', text: shareText.value },
      { art: 'menu', text: t('Faites défiler et touchez <strong>« Sur l\'écran d\'accueil »</strong>.') },
      { art: 'home', text: t('Touchez <strong>« Ajouter »</strong> : l\'icône MemoryLane apparaît sur votre écran d\'accueil.') },
    ]));

const footer = computed(() => (current.value === 'android'
  ? t('Ensuite, ouvrez MemoryLane depuis son icône : vous restez connecté. Sur Samsung Internet : menu ☰ › « Ajouter la page à » › « Écran d\'accueil ».')
  : t('Ensuite, ouvrez MemoryLane <strong>depuis son icône</strong> : vous restez connecté, et c\'est la seule façon de recevoir les notifications sur iPhone.')));
</script>
