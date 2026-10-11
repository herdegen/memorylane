<template>
  <div v-if="visible" class="relative flex items-center gap-4 rounded-2xl border border-brand-200 dark:border-brand-500/30 bg-linear-to-r from-brand-50 to-white dark:from-brand-500/10 dark:to-transparent px-4 sm:px-5 py-4">
    <img src="/icons/icon-192.png" alt="" class="w-12 h-12 rounded-xl shadow-warm-sm shrink-0" />

    <div v-if="justInstalled" class="flex-1 min-w-0">
      <p class="font-semibold text-surface-900">MemoryLane est installée !</p>
      <p class="text-sm text-surface-600">Retrouvez-la sur votre écran d'accueil et ouvrez-la toujours par son icône.</p>
    </div>

    <template v-else>
      <div class="flex-1 min-w-0">
        <p class="font-semibold text-surface-900">Installez MemoryLane sur votre téléphone</p>
        <p class="text-sm text-surface-600">
          Une icône sur l'écran d'accueil, comme une vraie application<span v-if="device === 'ios'"> — et les notifications de la famille</span>.
        </p>
        <div class="mt-2.5 flex flex-wrap items-center gap-2">
          <button v-if="canPromptNative" type="button" class="btn-primary !py-1.5" @click="install">Installer</button>
          <button v-else type="button" class="btn-primary !py-1.5" @click="showTutorial = true">Voir comment</button>
          <button type="button" class="btn-ghost !py-1.5 text-sm" @click="dismiss">Plus tard</button>
        </div>
      </div>
    </template>

    <button
      type="button"
      class="absolute top-2 right-2 p-1 text-surface-400 hover:text-surface-600"
      aria-label="Masquer"
      @click="dismiss"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12" /></svg>
    </button>

    <InstallAppTutorial v-if="showTutorial" :device="device" @close="showTutorial = false" />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useInstallApp } from '@/Composables/useInstallApp';
import InstallAppTutorial from '@/Components/InstallAppTutorial.vue';

// Bannière de l'accueil : sur téléphone, tant que l'app n'est pas ouverte
// depuis son icône. « Plus tard » la cache 30 jours sur cet appareil.
const DISMISS_KEY = 'ml_install_dismissed_at';
const DISMISS_DAYS = 30;

const { device, isMobile, standalone, canPromptNative, promptInstall, justInstalled } = useInstallApp();
const showTutorial = ref(false);

const readDismissed = () => {
  try {
    const at = Number(localStorage.getItem(DISMISS_KEY) || 0);
    return Date.now() - at < DISMISS_DAYS * 86400 * 1000;
  } catch (_) {
    return false;
  }
};
const dismissed = ref(readDismissed());

const visible = computed(() => isMobile && !standalone && (!dismissed.value || justInstalled.value));

const dismiss = () => {
  try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (_) { /* navigation privée */ }
  dismissed.value = true;
  justInstalled.value = false;
};

const install = () => promptInstall();
</script>
