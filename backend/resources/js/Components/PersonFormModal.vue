<template>
  <div
    class="fixed inset-0 z-50 overflow-y-auto"
    aria-labelledby="modal-title"
    role="dialog"
    aria-modal="true"
  >
    <!-- Backdrop -->
    <div
      class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
      @click="$emit('close')"
    ></div>

    <!-- Modal -->
    <div class="flex min-h-full items-center justify-center p-4">
      <div
        class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all w-full max-w-lg"
        @click.stop
      >
        <!-- Header -->
        <div class="bg-white px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">
            {{ person ? 'Modifier la personne' : 'Nouvelle personne' }}
          </h3>
        </div>

        <!-- Form -->
        <form @submit.prevent="submit" class="px-6 py-4 space-y-4">
          <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
              Nom <span class="text-red-500">*</span>
            </label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              required
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              placeholder="Prenom Nom"
            />
            <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name }}</p>
          </div>

          <div>
            <label for="gender" class="block text-sm font-medium text-gray-700 mb-1">
              Genre
            </label>
            <select
              id="gender"
              v-model="form.gender"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            >
              <option value="U">Non specifie</option>
              <option value="M">Masculin</option>
              <option value="F">Feminin</option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label for="birth_date" class="block text-sm font-medium text-gray-700 mb-1">
                Date de naissance
              </label>
              <input
                id="birth_date"
                v-model="form.birth_date"
                type="date"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>

            <div>
              <label for="death_date" class="block text-sm font-medium text-gray-700 mb-1">
                Date de deces
              </label>
              <input
                id="death_date"
                v-model="form.death_date"
                type="date"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
          </div>

          <div>
            <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">
              Notes
            </label>
            <textarea
              id="notes"
              v-model="form.notes"
              rows="3"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              placeholder="Notes sur cette personne..."
            ></textarea>
          </div>
        </form>

        <!-- Footer -->
        <div class="bg-gray-50 px-6 py-4 flex justify-end gap-3">
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
            @click="$emit('close')"
          >
            Annuler
          </button>
          <button
            type="submit"
            :disabled="submitting"
            class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50"
            @click="submit"
          >
            {{ submitting ? 'Enregistrement...' : (person ? 'Enregistrer' : 'Creer') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import axios from 'axios';

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

const submitting = ref(false);
const errors = ref({});

const form = reactive({
  name: props.person?.name || props.initialName || '',
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
      alert('Erreur: ' + (error.response?.data?.message || error.message));
    }
  } finally {
    submitting.value = false;
  }
};
</script>
