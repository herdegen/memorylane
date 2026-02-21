<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-sm p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-surface-900">Identifier le visage</h2>
      <button @click="$emit('close')" class="text-surface-400 hover:text-surface-600">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Confidence -->
    <div class="mb-4 text-sm text-surface-500">
      Confiance : {{ Math.round((face.confidence || 0) * 100) }}%
    </div>

    <!-- Search existing people -->
    <div class="mb-4">
      <label class="block text-sm font-medium text-surface-700 mb-1">Selectionner une personne</label>
      <div class="relative">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Rechercher..."
          class="w-full px-3 py-2 border border-surface-300 rounded-lg text-sm focus:ring-brand-500 focus:border-brand-500"
          @input="searchPeople"
        />
      </div>

      <!-- People list -->
      <div v-if="filteredPeople.length > 0" class="mt-2 max-h-48 overflow-y-auto border border-surface-200 rounded-lg">
        <button
          v-for="person in filteredPeople"
          :key="person.id"
          @click="matchToPerson(person)"
          :disabled="matching"
          class="w-full px-3 py-2 text-left text-sm hover:bg-brand-50 flex items-center gap-2 border-b border-surface-100 last:border-b-0"
        >
          <div class="w-8 h-8 bg-surface-200 rounded-full flex items-center justify-center text-xs font-medium text-surface-600">
            {{ person.name.charAt(0).toUpperCase() }}
          </div>
          <span>{{ person.name }}</span>
        </button>
      </div>

      <p v-else-if="searchQuery && !loadingPeople" class="mt-2 text-sm text-surface-500">
        Aucune personne trouvee
      </p>
    </div>

    <!-- Actions -->
    <div class="flex items-center gap-2">
      <button
        @click="showCreatePerson = true"
        class="flex-1 px-3 py-2 text-sm font-medium text-brand-600 bg-brand-50 rounded-lg hover:bg-brand-100"
      >
        Nouvelle personne
      </button>
      <button
        @click="dismissFace"
        :disabled="matching"
        class="flex-1 px-3 py-2 text-sm font-medium text-surface-600 bg-surface-100 rounded-lg hover:bg-surface-200"
      >
        Ignorer
      </button>
    </div>

    <!-- Create person inline form -->
    <div v-if="showCreatePerson" class="mt-4 p-4 bg-surface-50 rounded-lg">
      <h3 class="text-sm font-medium text-surface-900 mb-3">Nouvelle personne</h3>
      <input
        v-model="newPersonName"
        type="text"
        placeholder="Nom"
        class="w-full px-3 py-2 border border-surface-300 rounded-lg text-sm focus:ring-brand-500 focus:border-brand-500 mb-3"
        @keyup.enter="createAndMatch"
      />
      <div class="flex gap-2">
        <button
          @click="createAndMatch"
          :disabled="!newPersonName.trim() || matching"
          class="flex-1 px-3 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 disabled:opacity-50"
        >
          Creer et associer
        </button>
        <button
          @click="showCreatePerson = false"
          class="px-3 py-2 text-sm font-medium text-surface-600 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
        >
          Annuler
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
  face: {
    type: Object,
    required: true,
  },
  mediaId: {
    type: String,
    required: true,
  },
});

const emit = defineEmits(['matched', 'dismissed', 'close']);

const searchQuery = ref('');
const people = ref([]);
const filteredPeople = ref([]);
const loadingPeople = ref(false);
const matching = ref(false);
const showCreatePerson = ref(false);
const newPersonName = ref('');

const loadPeople = async () => {
  loadingPeople.value = true;
  try {
    const response = await axios.get('/people', {
      headers: { Accept: 'application/json' },
    });
    people.value = Array.isArray(response.data) ? response.data : (response.data.data || []);
    filteredPeople.value = people.value;
  } catch (error) {
    console.error('Failed to load people:', error);
  } finally {
    loadingPeople.value = false;
  }
};

const searchPeople = () => {
  const query = searchQuery.value.toLowerCase().trim();
  if (!query) {
    filteredPeople.value = people.value;
    return;
  }
  filteredPeople.value = people.value.filter(p =>
    p.name.toLowerCase().includes(query)
  );
};

const matchToPerson = async (person) => {
  matching.value = true;
  try {
    await axios.post(`/vision/faces/${props.face.id}/match`, {
      person_id: person.id,
    });
    emit('matched', { face: props.face, person });
  } catch (error) {
    console.error('Failed to match face:', error);
  } finally {
    matching.value = false;
  }
};

const dismissFace = async () => {
  matching.value = true;
  try {
    await axios.post(`/vision/faces/${props.face.id}/dismiss`);
    emit('dismissed', props.face);
  } catch (error) {
    console.error('Failed to dismiss face:', error);
  } finally {
    matching.value = false;
  }
};

const createAndMatch = async () => {
  if (!newPersonName.value.trim()) return;

  matching.value = true;
  try {
    // Create person
    const createResponse = await axios.post('/people', {
      name: newPersonName.value.trim(),
    });
    const person = createResponse.data.person || createResponse.data;

    // Match face to new person
    await axios.post(`/vision/faces/${props.face.id}/match`, {
      person_id: person.id,
    });

    emit('matched', { face: props.face, person });
  } catch (error) {
    console.error('Failed to create and match:', error);
  } finally {
    matching.value = false;
  }
};

onMounted(() => {
  loadPeople();
});
</script>
