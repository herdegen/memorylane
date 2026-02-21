<template>
  <div>
    <label class="block text-sm font-medium text-surface-700 mb-1">{{ label }}</label>

    <!-- Current selection -->
    <div v-if="currentPerson" class="flex items-center justify-between bg-surface-50 rounded-lg px-3 py-2">
      <Link :href="`/people/${currentPerson.id}`" class="text-sm text-brand-600 hover:text-brand-800 font-medium">
        {{ currentPerson.name }}
      </Link>
      <button @click="$emit('remove')" class="text-surface-400 hover:text-red-500 ml-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Search input -->
    <div v-else class="relative">
      <input
        v-model="query"
        @input="search"
        @focus="showResults = true"
        type="text"
        :placeholder="placeholder"
        class="w-full px-3 py-2 border border-surface-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
      />

      <!-- Results dropdown -->
      <div
        v-if="showResults && results.length > 0"
        class="absolute z-10 mt-1 w-full bg-white border rounded-lg shadow-lg max-h-40 overflow-auto"
      >
        <button
          v-for="person in results"
          :key="person.id"
          @click="selectPerson(person)"
          class="w-full text-left px-3 py-2 hover:bg-surface-50 text-sm"
        >
          {{ person.name }}
          <span v-if="person.birth_date" class="text-surface-400 ml-1">
            ({{ person.birth_date.substring(0, 4) }})
          </span>
        </button>
      </div>

      <p v-if="showResults && query.length >= 2 && results.length === 0" class="text-xs text-surface-400 mt-1">
        Aucune personne trouvee
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
  label: { type: String, required: true },
  currentPerson: { type: Object, default: null },
  excludeIds: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Rechercher une personne...' },
});

const emit = defineEmits(['select', 'remove']);

const query = ref('');
const results = ref([]);
const showResults = ref(false);

async function search() {
  if (query.value.length < 2) {
    results.value = [];
    return;
  }

  try {
    const response = await axios.get('/people', {
      headers: { Accept: 'application/json' },
    });

    const q = query.value.toLowerCase();
    results.value = response.data
      .filter(p =>
        p.name.toLowerCase().includes(q) &&
        !props.excludeIds.includes(p.id)
      )
      .slice(0, 8);
  } catch (error) {
    console.error('Erreur recherche:', error);
  }
}

function selectPerson(person) {
  emit('select', person);
  query.value = '';
  results.value = [];
  showResults.value = false;
}
</script>
