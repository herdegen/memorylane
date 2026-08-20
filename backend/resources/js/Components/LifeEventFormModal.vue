<template>
  <BaseModal panel-class="p-6" labelledby="life-event-form-title" @close="$emit('close')">
    <h3 id="life-event-form-title" class="text-lg font-semibold text-surface-900 mb-4">
      {{ event ? 'Modifier le moment' : 'Ajouter un moment' }}
    </h3>

    <form @submit.prevent="save" class="space-y-3">
      <div>
        <label class="block text-sm font-medium text-surface-700 mb-1">Type</label>
        <select v-model="form.type" class="form-input">
          <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-surface-700 mb-1">Titre *</label>
        <input v-model="form.title" type="text" class="form-input" placeholder="ex. Boulanger chez ..." />
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">Date *</label>
          <input v-model="form.event_date" type="date" class="form-input" />
        </div>
        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">Fin (optionnel)</label>
          <input v-model="form.end_date" type="date" class="form-input" />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-surface-700 mb-1">Lieu</label>
        <input v-model="form.place" type="text" class="form-input" placeholder="Ville, pays..." />
      </div>

      <div>
        <label class="block text-sm font-medium text-surface-700 mb-1">Description</label>
        <textarea v-model="form.description" rows="3" class="form-input"></textarea>
      </div>

      <!-- Photo d'illustration (parmi les photos de la personne) -->
      <div v-if="photoOptions.length > 0">
        <label class="block text-sm font-medium text-surface-700 mb-1">Photo (optionnel)</label>
        <div class="flex flex-wrap gap-2 max-h-32 overflow-y-auto">
          <button
            v-for="opt in photoOptions"
            :key="opt.media.id"
            type="button"
            @click="form.media_id = form.media_id === opt.media.id ? null : opt.media.id"
            class="w-14 h-14 rounded-lg overflow-hidden border-2 transition"
            :class="form.media_id === opt.media.id ? 'border-brand-500' : 'border-transparent hover:border-surface-300'"
          >
            <img :src="opt.media.thumbnail_url || opt.media.url" class="w-full h-full object-cover" />
          </button>
        </div>
      </div>

      <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button" @click="$emit('close')" class="btn-secondary">Annuler</button>
        <button type="submit" :disabled="saving || !form.title.trim() || !form.event_date" class="btn-primary disabled:opacity-50">
          {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>

<script setup>
import { ref, reactive } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';

const props = defineProps({
  personId: { type: String, required: true },
  event: { type: Object, default: null }, // item de frise (life_event) pour l'édition
  photoOptions: { type: Array, default: () => [] }, // items kind==='photo'
});

const emit = defineEmits(['close', 'saved']);

const types = [
  { value: 'moment', label: 'Moment' },
  { value: 'job', label: 'Emploi' },
  { value: 'education', label: 'Études' },
  { value: 'residence', label: 'Résidence' },
  { value: 'custom', label: 'Autre' },
];

const form = reactive({
  type: props.event?.kind && types.some(t => t.value === props.event.kind) ? props.event.kind : 'moment',
  title: props.event?.title || '',
  event_date: props.event?.date || '',
  end_date: props.event?.end_date || '',
  place: props.event?.place || '',
  description: props.event?.description || '',
  media_id: props.event?.media?.id || null,
});

const saving = ref(false);
const error = ref('');

const save = async () => {
  saving.value = true;
  error.value = '';
  const payload = {
    type: form.type,
    title: form.title.trim(),
    event_date: form.event_date,
    end_date: form.end_date || null,
    place: form.place || null,
    description: form.description || null,
    media_id: form.media_id || null,
  };
  try {
    if (props.event?.life_event_id) {
      await axios.put(`/life-events/${props.event.life_event_id}`, payload);
    } else {
      await axios.post(`/people/${props.personId}/events`, payload);
    }
    emit('saved');
  } catch (e) {
    error.value = e.response?.data?.message || 'Erreur lors de l\'enregistrement.';
  } finally {
    saving.value = false;
  }
};
</script>
