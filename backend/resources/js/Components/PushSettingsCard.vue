<template>
  <div class="bg-white overflow-hidden shadow-xs sm:rounded-lg">
    <div class="p-6">
      <h2 class="text-lg font-medium text-surface-900 mb-4">{{ $t('Notifications') }}</h2>
      <p class="mt-1 text-sm text-surface-600 mb-6">
        {{ $t('Rares et discrètes : les nouvelles de vos cercles, les anniversaires de vos proches et le résumé du dimanche. Jamais la nuit, désactivables à tout moment.') }}
      </p>

      <!-- État de cet appareil -->
      <div class="rounded-lg border border-surface-200 bg-surface-50 px-4 py-3 mb-6">
        <p v-if="!supported" class="text-sm text-surface-600">
          {{ $t('Votre navigateur ne prend pas en charge les notifications.') }}
        </p>
        <p v-else-if="needsIosInstall" class="text-sm text-surface-600">
          <Trans text="Sur iPhone et iPad, les notifications ne fonctionnent que depuis l'app installée : ajoutez MemoryLane à l'écran d'accueil (:path), puis revenez ici.">
            <template #path><span class="font-medium">{{ $t("Partager → Sur l'écran d'accueil") }}</span></template>
          </Trans>
        </p>
        <p v-else-if="permission === 'denied'" class="text-sm text-surface-600">
          {{ $t('Les notifications sont bloquées pour ce site dans les réglages du navigateur. Autorisez-les pour pouvoir les activer ici.') }}
        </p>
        <div v-else-if="currentEndpoint" class="flex flex-wrap items-center justify-between gap-3">
          <span class="inline-flex items-center gap-2 text-sm font-medium text-emerald-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            {{ $t('Activées sur cet appareil') }}
          </span>
          <div class="flex items-center gap-2">
            <button type="button" class="btn-secondary btn-sm" :disabled="busy" @click="sendTest">
              {{ $t('Envoyer une notification de test') }}
            </button>
            <button type="button" class="btn-ghost btn-sm" :disabled="busy" @click="disableHere">
              {{ $t('Désactiver sur cet appareil') }}
            </button>
          </div>
        </div>
        <div v-else class="flex flex-wrap items-center justify-between gap-3">
          <span class="text-sm text-surface-600">{{ $t('Cet appareil ne reçoit pas de notifications.') }}</span>
          <button type="button" class="btn-primary btn-sm" :disabled="busy" @click="enableHere">
            {{ busy ? $t('Activation…') : $t('Activer sur cet appareil') }}
          </button>
        </div>
      </div>

      <!-- Catégories (dès qu'un appareil est abonné) -->
      <div v-if="subscriptions.length > 0" class="space-y-5">
        <ToggleSwitch
          v-model="prefs.social"
          :label="$t('Vie des cercles')"
          :help="$t('Ajout à un cercle, photos partagées avec vos cercles, accès à un album. Rare par nature, et jamais entre 21h et 8h.')"
          :disabled="savingPrefs"
          @update:modelValue="savePrefs"
        />
        <ToggleSwitch
          v-model="prefs.birthdays"
          :label="$t('Anniversaires')"
          :help="$t('Le matin, les anniversaires du jour de vos proches, et le souvenir de ceux qui nous ont quittés.')"
          :disabled="savingPrefs"
          @update:modelValue="savePrefs"
        />
        <ToggleSwitch
          v-model="prefs.digest"
          :label="$t('Résumé de la semaine')"
          :help="$t('Le dimanche soir, uniquement s\'il y a eu de nouvelles photos.')"
          :disabled="savingPrefs"
          @update:modelValue="savePrefs"
        />

        <!-- Appareils -->
        <div class="pt-4 border-t border-surface-100">
          <h3 class="text-sm font-medium text-surface-700 mb-2">{{ $t('Appareils abonnés') }}</h3>
          <ul class="divide-y divide-surface-100">
            <li
              v-for="sub in subscriptions"
              :key="sub.id"
              class="flex items-center justify-between gap-3 py-2 text-sm"
            >
              <span class="text-surface-700">
                {{ sub.label }}
                <span v-if="sub.endpoint === currentEndpoint" class="text-xs text-brand-700 ml-1">{{ $t('(cet appareil)') }}</span>
                <span class="block text-xs text-surface-400">{{ $t('ajouté le :date', { date: formatLongDate(sub.created_at) }) }}</span>
              </span>
              <button type="button" class="text-xs font-medium text-surface-500 hover:text-red-600" :disabled="busy" @click="remove(sub)">
                {{ $t('Retirer') }}
              </button>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import ToggleSwitch from '@/Components/Forms/ToggleSwitch.vue';
import { usePush } from '@/Composables/usePush';
import { useToast } from '@/Composables/useToast';
import { formatLongDate } from '@/utils/format';
import { t } from '@/i18n';
import Trans from '@/Components/Trans.vue';

const props = defineProps({
  // Liste initiale des appareils (ProfileController::show).
  initialSubscriptions: { type: Array, default: () => [] },
});

const page = usePage();
const toast = useToast();
const { supported, permission, currentEndpoint, needsIosInstall, refresh, subscribe, unsubscribeThisDevice } = usePush();

const subscriptions = ref([...props.initialSubscriptions]);
const prefs = reactive({ ...(page.props.push?.preferences || { social: false, birthdays: false, digest: false }) });
const busy = ref(false);
const savingPrefs = ref(false);

onMounted(refresh);

const enableHere = async () => {
  busy.value = true;
  try {
    const data = await subscribe();
    if (!data) {
      toast.info(t('Vous avez refusé les notifications : rien ne sera envoyé à cet appareil.'));
      return;
    }
    subscriptions.value = data.subscriptions;
    Object.assign(prefs, data.preferences);
    toast.success(t('Notifications activées sur cet appareil.'));
  } catch (error) {
    toast.error(t("Impossible d'activer les notifications sur cet appareil."));
  } finally {
    busy.value = false;
  }
};

const disableHere = async () => {
  busy.value = true;
  try {
    const data = await unsubscribeThisDevice(subscriptions.value);
    if (data) subscriptions.value = data.subscriptions;
    toast.success(t('Notifications désactivées sur cet appareil.'));
  } catch {
    toast.error(t('La désactivation a échoué.'));
  } finally {
    busy.value = false;
  }
};

const remove = async (sub) => {
  if (sub.endpoint === currentEndpoint.value) return disableHere();
  busy.value = true;
  try {
    const { data } = await axios.delete(`/push/subscriptions/${sub.id}`);
    subscriptions.value = data.subscriptions;
    toast.success(t('Appareil retiré.'));
  } catch {
    toast.error(t("Impossible de retirer cet appareil."));
  } finally {
    busy.value = false;
  }
};

const savePrefs = async () => {
  savingPrefs.value = true;
  try {
    const { data } = await axios.put('/push/preferences', {
      social: prefs.social,
      birthdays: prefs.birthdays,
      digest: prefs.digest,
    });
    Object.assign(prefs, data.preferences);
  } catch {
    toast.error(t("Impossible d'enregistrer vos préférences."));
    Object.assign(prefs, page.props.push?.preferences || {});
  } finally {
    savingPrefs.value = false;
  }
};

const sendTest = async () => {
  busy.value = true;
  try {
    await axios.post('/push/test');
    toast.success(t('Notification de test envoyée : elle arrive dans quelques secondes.'));
  } catch (error) {
    toast.error(error.response?.data?.message || t("L'envoi du test a échoué."));
  } finally {
    busy.value = false;
  }
};
</script>
