<template>
  <BaseModal :title="$t('Partager avec un cercle')" @close="$emit('close')">
    <template #header-extra>
      <span class="text-sm text-surface-500">
        {{ $tc(':count média|:count médias', mediaIds.length) }}
      </span>
    </template>

    <div class="px-6 py-4">
      <!-- Chargement des foyers -->
      <div v-if="loading" class="py-8 text-center text-sm text-surface-500">
        {{ $t('Chargement des cercles…') }}
      </div>

      <!-- Aucun foyer -->
      <div v-else-if="households.length === 0" class="py-6 text-center">
        <p class="text-sm text-surface-600">{{ $t('Vous n\'appartenez à aucun cercle.') }}</p>
        <Link href="/households" class="mt-2 inline-block text-sm font-medium text-brand-600 dark:text-brand-400 hover:underline">
          {{ $t('Voir les cercles') }}
        </Link>
      </div>

      <!-- Choix du foyer -->
      <div v-else class="space-y-2">
        <p class="text-sm text-surface-500 mb-3">
          {{ $t('Les membres du cercle choisi pourront voir ces médias. Seuls les médias qui vous appartiennent seront partagés.') }}
        </p>
        <label
          v-for="household in households"
          :key="household.id"
          class="flex items-center gap-3 rounded-lg border px-4 py-3 cursor-pointer transition"
          :class="selectedHouseholdId === household.id
            ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10'
            : 'border-surface-200 hover:border-brand-300'"
        >
          <input
            type="radio"
            name="household"
            :value="household.id"
            v-model="selectedHouseholdId"
            class="h-4 w-4 text-brand-600 focus:ring-brand-500 border-surface-300"
          />
          <span class="flex-1">
            <span class="block text-sm font-medium text-surface-900">{{ household.name }}</span>
            <span class="block text-xs text-surface-500">
              {{ $tc(':count membre|:count membres', household.members_count) }}
            </span>
          </span>
        </label>
      </div>

      <p v-if="errorMessage" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
    </div>

    <template #footer>
      <button
        type="button"
        class="mr-auto btn-ghost text-sm"
        :disabled="!selectedHouseholdId || saving"
        @click="submit('remove')"
      >
        {{ $t('Retirer du cercle') }}
      </button>
      <button type="button" class="btn-secondary" @click="$emit('close')">{{ $t('Annuler') }}</button>
      <button
        type="button"
        class="btn-primary"
        :disabled="!selectedHouseholdId || saving"
        @click="submit('share')"
      >
        {{ saving ? $t('Partage…') : $t('Partager') }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import { t } from '@/i18n';

const props = defineProps({
  // Sélection à partager (IDs de médias).
  mediaIds: {
    type: Array,
    required: true,
  },
});

const emit = defineEmits(['close', 'done']);

const loading = ref(true);
const households = ref([]);
const selectedHouseholdId = ref(null);
const saving = ref(false);
const errorMessage = ref(null);

onMounted(async () => {
  try {
    const { data } = await axios.get('/households', { headers: { Accept: 'application/json' } });
    households.value = data;
    // Un seul foyer : présélectionné.
    if (data.length === 1) {
      selectedHouseholdId.value = data[0].id;
    }
  } catch (e) {
    errorMessage.value = t('Impossible de charger vos cercles.');
  } finally {
    loading.value = false;
  }
});

// action : 'share' (ajout au périmètre du foyer) ou 'remove' (retrait).
const submit = async (action) => {
  saving.value = true;
  errorMessage.value = null;
  try {
    const url = action === 'share' ? '/media/bulk/household' : '/media/bulk/household/remove';
    const { data } = await axios.post(url, {
      media_ids: props.mediaIds,
      household_id: selectedHouseholdId.value,
    });
    emit('done', data);
    emit('close');
  } catch (e) {
    errorMessage.value = e.response?.data?.message || t('Erreur lors du partage.');
  } finally {
    saving.value = false;
  }
};
</script>
