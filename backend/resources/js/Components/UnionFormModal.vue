<template>
  <!-- Carte « Mariage » de la frise : date, lieu et photo de l'union. Modifie
       l'union elle-même (fiche, arbre), journalisée sur les deux fiches. -->
  <BaseModal :title="$t('Modifier le mariage')" @close="$emit('close')">
    <div class="p-6">
      <form class="space-y-4" @submit.prevent="save">
        <p class="text-sm font-medium text-surface-700">{{ item.title }}</p>

        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Date') }}</label>
          <input v-model="form.start_date" type="date" class="form-input" />
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Lieu') }}</label>
          <PlaceInput v-model="form.start_place" input-class="form-input" :placeholder="$t('Ville, pays...')" />
        </div>

        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="block text-sm font-medium text-surface-700">{{ $t('Photo de la carte') }}</label>
            <button type="button" class="text-sm font-semibold text-brand-700 dark:text-brand-400 hover:underline" @click="pickerOpen = true">
              {{ photo ? $t('Changer') : $t('Choisir une photo') }}
            </button>
          </div>
          <div v-if="photo" class="flex items-center gap-3">
            <img :src="photo.thumbnail_url" alt="" class="w-16 h-16 rounded-lg object-cover" loading="lazy" decoding="async" />
            <button type="button" class="text-sm text-surface-500 hover:text-red-600" @click="photo = null">{{ $t('Retirer la photo') }}</button>
          </div>
          <p v-else class="text-xs text-surface-500">{{ $t('Sans photo choisie, la carte montre le portrait du conjoint.') }}</p>
        </div>

        <p class="text-xs text-surface-500">{{ $t('La date et le lieu changent aussi dans les fiches et dans l’arbre.') }}</p>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="$emit('close')">{{ $t('Annuler') }}</button>
          <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? $t('Enregistrement…') : $t('Enregistrer') }}</button>
        </div>
      </form>
    </div>

    <PhotoPicker
      :open="pickerOpen"
      :person-id="personId"
      :person-name="personName"
      :around="form.start_date"
      :initial="photo ? [photo] : []"
      :max="1"
      @close="pickerOpen = false"
      @done="onPicked"
    />
  </BaseModal>
</template>

<script setup>
import { ref, reactive } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import PhotoPicker from '@/Components/PhotoPicker.vue';
import PlaceInput from '@/Components/PlaceInput.vue';
import { useToast } from '@/Composables/useToast';
import { t } from '@/i18n';

const props = defineProps({
  personId: { type: String, required: true },
  personName: { type: String, default: '' },
  // Item « marriage » de la frise (avec `union` et éventuellement `media`).
  item: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);
const toast = useToast();

const form = reactive({
  start_date: props.item.union?.start_date || '',
  start_place: props.item.union?.start_place || '',
});
const photo = ref(props.item.media?.id ? { id: props.item.media.id, thumbnail_url: props.item.media.thumbnail_url } : null);
const pickerOpen = ref(false);
const saving = ref(false);

const onPicked = (list) => {
  photo.value = list[0] ?? null;
  pickerOpen.value = false;
};

const save = async () => {
  saving.value = true;
  try {
    await axios.put(`/people/${props.personId}/spouse`, {
      spouse_id: props.item.union.spouse_id,
      start_date: form.start_date || null,
      start_place: form.start_place || null,
      media_id: photo.value?.id ?? null,
    });
    toast.success(t('Mariage mis à jour.'));
    emit('saved');
  } catch (e) {
    toast.error(e.response?.data?.message || t('Impossible d’enregistrer ce mariage.'));
  } finally {
    saving.value = false;
  }
};
</script>
