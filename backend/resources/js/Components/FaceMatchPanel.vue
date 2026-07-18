<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-xs p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-surface-900">Identifier le visage</h2>
      <button @click="$emit('close')" class="text-surface-400 hover:text-surface-600">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Aperçu recadré du visage -->
    <div class="mb-4 flex items-center gap-3">
      <canvas
        ref="cropCanvas"
        class="w-20 h-20 rounded-lg bg-surface-100 object-cover shrink-0 border border-surface-200"
      ></canvas>
      <div class="text-sm text-surface-500">
        <span v-if="face.person" class="block font-medium text-surface-900">
          Actuellement : {{ face.person.name }}
        </span>
        Confiance : {{ Math.round((face.confidence || 0) * 100) }}%
      </div>
    </div>

    <!-- Suggestion de reconnaissance -->
    <div
      v-if="topSuggestion"
      class="mb-4 p-3 bg-brand-50 border border-brand-100 rounded-lg"
    >
      <p class="text-sm text-surface-700 mb-2">
        Suggestion :
        <span class="font-semibold text-surface-900">{{ topSuggestion.person.name }}</span>
        <span class="text-surface-500">({{ Math.round(topSuggestion.score * 100) }}%)</span>
      </p>
      <button
        @click="matchToPerson(topSuggestion.person)"
        :disabled="matching"
        class="w-full px-3 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 disabled:opacity-50"
      >
        Confirmer {{ topSuggestion.person.name }}
      </button>
    </div>

    <!-- Search existing people -->
    <div class="mb-4">
      <label class="block text-sm font-medium text-surface-700 mb-1">
        {{ face.person ? 'Changer de personne' : 'Selectionner une personne' }}
      </label>
      <div class="relative">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Rechercher..."
          class="w-full px-3 py-2 border border-surface-300 rounded-lg text-sm focus:ring-brand-500 focus:border-brand-500"
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
          <span
            v-if="person.score != null"
            class="ml-auto text-xs font-medium text-brand-600"
          >{{ Math.round(person.score * 100) }}%</span>
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
      <!-- Retirer l'association si le visage est déjà matché, sinon Ignorer -->
      <button
        v-if="face.person"
        @click="resetFace"
        :disabled="matching"
        class="flex-1 px-3 py-2 text-sm font-medium text-surface-600 bg-surface-100 rounded-lg hover:bg-surface-200"
      >
        Retirer
      </button>
      <button
        v-else
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
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import { matchesPerson } from '@/utils/personSearch';

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

const emit = defineEmits(['matched', 'dismissed', 'reset', 'close']);

const searchQuery = ref('');
const people = ref([]);
const suggestions = ref([]); // candidats classés par proximité (embedding)
const loadingPeople = ref(false);
const matching = ref(false);
const showCreatePerson = ref(false);
const newPersonName = ref('');
const topSuggestion = ref(null);
const cropCanvas = ref(null);

const loadPeople = async () => {
  loadingPeople.value = true;
  try {
    const response = await axios.get('/people', {
      headers: { Accept: 'application/json' },
    });
    people.value = Array.isArray(response.data) ? response.data : (response.data.data || []);
  } catch (error) {
    console.error('Failed to load people:', error);
  } finally {
    loadingPeople.value = false;
  }
};

// Liste ordonnée : les personnes reconnues (par distance d'embedding) d'abord,
// avec leur score, puis les autres par ordre alphabétique. Filtrée par recherche.
const filteredPeople = computed(() => {
  const ranked = suggestions.value.map((s) => ({
    id: s.person.id,
    name: s.person.name,
    score: s.score,
  }));
  const rankedIds = new Set(ranked.map((p) => p.id));

  const others = people.value
    .filter((p) => !rankedIds.has(p.id))
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name));

  let list = [...ranked, ...others];

  // Filtre insensible aux accents + tolérant aux fautes (helper partagé), en
  // conservant l'ordre (suggestions par proximité d'embedding d'abord).
  if (searchQuery.value.trim()) {
    list = list.filter((p) => matchesPerson(searchQuery.value, p.name));
  }
  return list;
});

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

const resetFace = async () => {
  matching.value = true;
  try {
    await axios.post(`/vision/faces/${props.face.id}/reset`);
    emit('reset', props.face);
  } catch (error) {
    console.error('Failed to reset face:', error);
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

// Suggestion de reconnaissance (plus proche voisin sur les visages labellisés).
const loadSuggestion = async () => {
  topSuggestion.value = null;
  suggestions.value = [];
  try {
    const { data } = await axios.get(`/vision/faces/${props.face.id}/suggest`);
    suggestions.value = data.suggestions || [];
    // Bannière de suggestion : seulement si le visage n'est pas déjà nommé.
    topSuggestion.value = props.face.person ? null : (suggestions.value[0] || null);
  } catch (error) {
    console.error('Failed to load suggestion:', error);
  }
};

// Aperçu recadré : on dessine la région bounding_box (en %) de l'image proxy
// même-origine sur le canvas.
const drawCrop = () => {
  const canvas = cropCanvas.value;
  const box = props.face.bounding_box;
  if (!canvas || !box) return;

  const img = new Image();
  img.crossOrigin = 'anonymous';
  img.onload = () => {
    const sx = (box.x / 100) * img.naturalWidth;
    const sy = (box.y / 100) * img.naturalHeight;
    const sw = (box.width / 100) * img.naturalWidth;
    const sh = (box.height / 100) * img.naturalHeight;

    const size = 160; // canvas carré haute-def
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, size, size);
    // Recadrage centré façon "cover" pour rester carré.
    const side = Math.max(sw, sh);
    const cx = sx + sw / 2;
    const cy = sy + sh / 2;
    ctx.drawImage(
      img,
      Math.max(0, cx - side / 2), Math.max(0, cy - side / 2), side, side,
      0, 0, size, size
    );
  };
  img.src = `/vision/media/${props.mediaId}/image?conversion=medium`;
};

watch(() => props.face?.id, () => {
  loadSuggestion();
  drawCrop();
});

onMounted(() => {
  loadPeople();
  loadSuggestion();
  drawCrop();
});
</script>
