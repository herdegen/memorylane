<template>
  <BaseModal :title="$t('Partager avec une branche de la famille')" max-width="md" @close="$emit('close')">
    <template #header-extra>
      <span class="text-sm text-surface-500">{{ $tc(':count média|:count médias', mediaIds.length) }}</span>
    </template>
    <div class="px-6 py-4 space-y-3">
      <p class="text-sm text-surface-500">
        {{ $t('Toute la branche pourra voir ces médias, même ceux qui n’étaient pas là. Seuls les médias qui vous appartiennent sont partagés.') }}
      </p>
      <BranchSharePicker v-model="choice" />
      <p v-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
    </div>
    <template #footer>
      <button type="button" class="mr-auto btn-ghost text-sm" :disabled="!choice.person || saving" @click="submit('remove')">
        {{ $t('Retirer ce partage') }}
      </button>
      <button type="button" class="btn-secondary" @click="$emit('close')">{{ $t('Annuler') }}</button>
      <button type="button" class="btn-primary" :disabled="!choice.person || saving" @click="submit('share')">
        {{ saving ? $t('Partage…') : $t('Partager') }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import BranchSharePicker from '@/Components/BranchSharePicker.vue';
import { useToast } from '@/Composables/useToast';
import { t } from '@/i18n';

const props = defineProps({ mediaIds: { type: Array, required: true } });
const emit = defineEmits(['close', 'done']);
const toast = useToast();
const choice = ref({ person: null, includeSpouses: true });
const saving = ref(false);
const errorMessage = ref(null);

const submit = async (action) => {
  saving.value = true;
  errorMessage.value = null;
  try {
    const url = action === 'share' ? '/media/bulk/branch' : '/media/bulk/branch/remove';
    const { data } = await axios.post(url, { media_ids: props.mediaIds, person_id: choice.value.person.id, include_spouses: choice.value.includeSpouses });
    toast.success(data.message);
    emit('done');
  } catch (e) {
    errorMessage.value = e.response?.data?.message || t('Le partage n’a pas pu être enregistré.');
  } finally {
    saving.value = false;
  }
};
</script>
