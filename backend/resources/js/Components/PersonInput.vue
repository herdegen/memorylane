<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-sm p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-surface-900">Personnes</h2>
      <button
        @click="showCreateModal = true"
        class="text-sm text-brand-600 hover:text-brand-800"
      >
        + Nouvelle personne
      </button>
    </div>

    <!-- Current people -->
    <div v-if="mediaPeople.length > 0" class="flex flex-wrap gap-2 mb-3">
      <span
        v-for="person in mediaPeople"
        :key="person.id"
        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium bg-purple-100 text-purple-800"
      >
        <div
          v-if="person.avatar_url"
          class="w-5 h-5 rounded-full bg-cover bg-center"
          :style="{ backgroundImage: `url(${person.avatar_url})` }"
        ></div>
        <div
          v-else
          class="w-5 h-5 rounded-full bg-purple-300 flex items-center justify-center text-xs text-white"
        >
          {{ person.name.charAt(0).toUpperCase() }}
        </div>
        {{ person.name }}
        <button
          @click="removePerson(person)"
          class="ml-1 hover:text-red-600 transition"
          type="button"
          title="Retirer cette personne"
        >
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </span>
    </div>

    <div v-else class="text-sm text-surface-500 mb-3">
      Aucune personne identifiee
    </div>

    <!-- Add person input -->
    <div class="relative">
      <input
        v-model="searchQuery"
        @focus="showSuggestions = true"
        @blur="handleBlur"
        @input="filterPeople"
        @keydown.enter.prevent="selectFirstSuggestion"
        @keydown.escape="showSuggestions = false"
        type="text"
        placeholder="Ajouter une personne..."
        class="w-full px-4 py-2 pr-10 border border-surface-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-transparent text-sm"
      />
      <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
        <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
        </svg>
      </div>

      <!-- Suggestions dropdown -->
      <div
        v-if="showSuggestions && filteredPeople.length > 0"
        class="absolute z-10 w-full mt-1 bg-white border border-surface-200 rounded-lg shadow-lg max-h-48 overflow-auto"
      >
        <button
          v-for="person in filteredPeople"
          :key="person.id"
          @mousedown.prevent="addPerson(person)"
          type="button"
          class="w-full px-4 py-2 text-left hover:bg-surface-50 transition flex items-center gap-2"
        >
          <div
            v-if="person.avatar_url"
            class="w-6 h-6 rounded-full bg-cover bg-center"
            :style="{ backgroundImage: `url(${person.avatar_url})` }"
          ></div>
          <div
            v-else
            class="w-6 h-6 rounded-full bg-purple-300 flex items-center justify-center text-xs text-white"
          >
            {{ person.name.charAt(0).toUpperCase() }}
          </div>
          <span class="text-sm text-surface-900">{{ person.name }}</span>
          <span class="text-xs text-surface-500 ml-auto">{{ person.media_count }} medias</span>
        </button>
      </div>

      <!-- No results -->
      <div
        v-if="showSuggestions && searchQuery && filteredPeople.length === 0 && availablePeople.length > 0"
        class="absolute z-10 w-full mt-1 bg-white border border-surface-200 rounded-lg shadow-lg p-4 text-center text-sm text-surface-500"
      >
        <p>Aucune personne trouvee pour "{{ searchQuery }}"</p>
        <button
          @mousedown.prevent="showCreateModal = true"
          class="mt-2 text-brand-600 hover:text-brand-800"
        >
          Creer "{{ searchQuery }}"
        </button>
      </div>
    </div>

    <!-- Create Person Modal -->
    <PersonFormModal
      v-if="showCreateModal"
      :initial-name="searchQuery"
      @close="showCreateModal = false"
      @created="handlePersonCreated"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import PersonFormModal from '@/Components/PersonFormModal.vue';

const props = defineProps({
  mediaId: {
    type: String,
    required: true,
  },
  initialPeople: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['people-updated']);

const mediaPeople = ref([...props.initialPeople]);
const availablePeople = ref([]);
const searchQuery = ref('');
const showSuggestions = ref(false);
const showCreateModal = ref(false);
const loading = ref(false);

const filteredPeople = computed(() => {
  const attachedIds = mediaPeople.value.map(p => p.id);
  let people = availablePeople.value.filter(person => !attachedIds.includes(person.id));

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase();
    people = people.filter(person => person.name.toLowerCase().includes(query));
  }

  return people.slice(0, 10);
});

const loadAvailablePeople = async () => {
  try {
    const response = await axios.get('/people', {
      headers: { 'Accept': 'application/json' }
    });
    availablePeople.value = response.data;
  } catch (error) {
    console.error('Error loading people:', error);
  }
};

const addPerson = async (person) => {
  if (loading.value) return;

  loading.value = true;
  try {
    await axios.post('/people/attach', {
      media_id: props.mediaId,
      person_id: person.id,
    });

    mediaPeople.value.push(person);
    searchQuery.value = '';
    showSuggestions.value = false;
    emit('people-updated', mediaPeople.value);
  } catch (error) {
    alert('Erreur lors de l\'ajout: ' + (error.response?.data?.message || error.message));
  } finally {
    loading.value = false;
  }
};

const removePerson = async (person) => {
  if (loading.value) return;

  loading.value = true;
  try {
    await axios.post('/people/detach', {
      media_id: props.mediaId,
      person_id: person.id,
    });

    mediaPeople.value = mediaPeople.value.filter(p => p.id !== person.id);
    emit('people-updated', mediaPeople.value);
  } catch (error) {
    alert('Erreur lors du retrait: ' + (error.response?.data?.message || error.message));
  } finally {
    loading.value = false;
  }
};

const filterPeople = () => {
  showSuggestions.value = true;
};

const handleBlur = () => {
  setTimeout(() => {
    showSuggestions.value = false;
  }, 200);
};

const selectFirstSuggestion = () => {
  if (filteredPeople.value.length > 0) {
    addPerson(filteredPeople.value[0]);
  }
};

const handlePersonCreated = async (person) => {
  showCreateModal.value = false;
  searchQuery.value = '';
  await loadAvailablePeople();
  await addPerson(person);
};

onMounted(() => {
  loadAvailablePeople();
});
</script>
