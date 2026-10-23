<template>
  <div
    v-if="visible"
    class="relative flex flex-col sm:flex-row sm:items-center gap-5 bg-white border border-surface-200 rounded-2xl px-6 sm:px-7 py-5"
  >
    <button
      @click="dismiss"
      class="absolute top-3 right-3 text-surface-400 hover:text-surface-600 transition"
      :title="$t('Ne plus proposer')"
      :aria-label="$t('Ne plus proposer')"
    >
      <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
    </button>
    <div class="w-13 h-13 rounded-xl bg-brand-100 flex items-center justify-center shrink-0">
      <svg class="w-6.5 h-6.5 text-brand-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
      </svg>
    </div>
    <div class="flex-1 min-w-0">
      <h2 class="text-base font-semibold text-surface-900">{{ isFriends ? $t('Recevoir les nouvelles du groupe') : $t('Recevoir les nouvelles de la famille') }}</h2>
      <p v-if="needsIosInstall" class="text-sm text-surface-500 mt-0.5">
        {{ $t("Sur iPhone, ajoutez MemoryLane à l'écran d'accueil (Partager → Sur l'écran d'accueil) pour pouvoir recevoir les notifications.") }}
      </p>
      <p v-else class="text-sm text-surface-500 mt-0.5">
        {{ $t('Anniversaires du jour, photos partagées dans vos cercles, résumé du dimanche. Rare et discret, désactivable à tout moment.') }}
      </p>
    </div>
    <div v-if="!needsIosInstall" class="flex items-center gap-2 shrink-0">
      <button type="button" class="btn-primary" :disabled="busy" @click="enable">
        {{ busy ? $t('Activation…') : $t('Activer sur cet appareil') }}
      </button>
      <button type="button" class="btn-ghost" @click="dismiss">{{ $t('Plus tard') }}</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { usePush } from '@/Composables/usePush';
import { useToast } from '@/Composables/useToast';
import { useCircle } from '@/Composables/useCircle';
import { t } from '@/i18n';

const { isFriends } = useCircle();

// Carte d'invitation du tableau de bord : jamais de demande automatique, un
// seul clic explicite. Masquée dès que cet appareil est abonné ou après
// « Plus tard » (persisté par compte).
const page = usePage();
const toast = useToast();
const { supported, permission, currentEndpoint, needsIosInstall, refresh, subscribe } = usePush();

const visible = ref(false);
const busy = ref(false);

onMounted(async () => {
  if (!supported || page.props.push?.prompt_dismissed) return;
  await refresh();
  visible.value = permission.value !== 'denied' && !currentEndpoint.value;
});

const enable = async () => {
  busy.value = true;
  try {
    const data = await subscribe();
    if (!data) {
      toast.info(t('Vous avez refusé les notifications pour ce site.'));
      visible.value = false;
      return;
    }
    toast.success(t('Notifications activées. Réglez-les à tout moment depuis votre profil.'));
    visible.value = false;
  } catch {
    toast.error(t("Impossible d'activer les notifications sur cet appareil."));
  } finally {
    busy.value = false;
  }
};

const dismiss = async () => {
  visible.value = false;
  try {
    await axios.post('/push/prompt-dismiss');
  } catch {
    // Sans gravité : la carte réapparaîtra à la prochaine visite.
  }
};
</script>
