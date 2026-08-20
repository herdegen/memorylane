<template>
  <BaseModal labelledby="person-form-title" @close="$emit('close')">
    <!-- Header -->
    <div class="bg-white px-6 py-4 border-b border-surface-200">
      <h3 id="person-form-title" class="text-lg font-semibold text-surface-900">
        {{ person ? 'Modifier la personne' : 'Nouvelle personne' }}
      </h3>
    </div>

    <!-- Form -->
    <form @submit.prevent="submit" class="px-6 py-4 space-y-4">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label for="first_name" class="block text-sm font-medium text-surface-700 mb-1">
            Prénom(s) <span class="text-red-500 dark:text-red-400">*</span>
          </label>
          <input
            id="first_name"
            v-model="form.first_name"
            type="text"
            required
            class="w-full px-4 py-2 border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
            placeholder="Jean-Marie"
          />
          <p v-if="errors.first_name" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ errors.first_name }}</p>
        </div>
        <div>
          <label for="last_name" class="block text-sm font-medium text-surface-700 mb-1">
            Nom de famille
          </label>
          <input
            id="last_name"
            v-model="form.last_name"
            type="text"
            class="w-full px-4 py-2 border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
            placeholder="Dupont"
          />
          <p v-if="errors.last_name" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ errors.last_name }}</p>
        </div>
      </div>

      <div>
        <label for="maiden_name" class="block text-sm font-medium text-surface-700 mb-1">
          Nom de naissance
        </label>
        <input
          id="maiden_name"
          v-model="form.maiden_name"
          type="text"
          class="w-full px-4 py-2 border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
          placeholder="Nom de jeune fille (si différent)"
        />
        <p v-if="errors.maiden_name" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ errors.maiden_name }}</p>
      </div>

      <div>
        <label for="gender" class="block text-sm font-medium text-surface-700 mb-1">
          Genre
        </label>
        <select
          id="gender"
          v-model="form.gender"
          class="w-full px-4 py-2 border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
        >
          <option value="U">Non spécifié</option>
          <option value="M">Masculin</option>
          <option value="F">Féminin</option>
        </select>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label for="birth_date" class="block text-sm font-medium text-surface-700 mb-1">
            Date de naissance
          </label>
          <input
            id="birth_date"
            v-model="form.birth_date"
            type="date"
            class="w-full px-4 py-2 border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
          />
        </div>

        <div>
          <label for="death_date" class="block text-sm font-medium text-surface-700 mb-1">
            Date de décès
          </label>
          <input
            id="death_date"
            v-model="form.death_date"
            type="date"
            class="w-full px-4 py-2 border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
          />
        </div>
      </div>

      <div>
        <label for="notes" class="block text-sm font-medium text-surface-700 mb-1">
          Notes
        </label>
        <textarea
          id="notes"
          v-model="form.notes"
          rows="3"
          class="w-full px-4 py-2 border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
          placeholder="Notes sur cette personne..."
        ></textarea>
      </div>
    </form>

    <!-- Footer -->
    <div class="bg-surface-50 px-6 py-4 flex justify-end gap-3">
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
        @click="$emit('close')"
      >
        Annuler
      </button>
      <button
        type="submit"
        :disabled="submitting"
        class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 disabled:opacity-50"
        @click="submit"
      >
        {{ submitting ? 'Enregistrement...' : (person ? 'Enregistrer' : 'Créer') }}
      </button>
    </div>
  </BaseModal>
</template>

<script setup>
import { ref, reactive } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
  person: {
    type: Object,
    default: null,
  },
  initialName: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['close', 'created', 'updated']);

const toast = useToast();

const submitting = ref(false);
const errors = ref({});

// initialName (nom complet tapé ailleurs) : dernier mot = nom de famille
const splitInitialName = (full) => {
  const parts = (full || '').trim().split(/\s+/).filter(Boolean);
  if (parts.length > 1) {
    return { first: parts.slice(0, -1).join(' '), last: parts[parts.length - 1] };
  }
  return { first: parts[0] || '', last: '' };
};

const initial = splitInitialName(props.initialName);

const form = reactive({
  first_name: props.person?.first_name || initial.first,
  last_name: props.person?.last_name || initial.last,
  maiden_name: props.person?.maiden_name || '',
  gender: props.person?.gender || 'U',
  birth_date: props.person?.birth_date || '',
  death_date: props.person?.death_date || '',
  notes: props.person?.notes || '',
});

const submit = async () => {
  submitting.value = true;
  errors.value = {};

  try {
    if (props.person) {
      const response = await axios.put(`/people/${props.person.id}`, form);
      emit('updated', response.data.person);
    } else {
      const response = await axios.post('/people', form);
      emit('created', response.data.person);
    }
    emit('close');
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors || {};
    } else {
      toast.error(error.response?.data?.message || 'Erreur lors de l\'enregistrement.');
    }
  } finally {
    submitting.value = false;
  }
};
</script>
