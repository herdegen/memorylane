<template>
  <div class="tag-input">
    <!-- Current tags -->
    <div v-if="mediaTags.length > 0" class="flex flex-wrap gap-2 mb-3">
      <span
        v-for="tag in mediaTags"
        :key="tag.id"
        class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium text-white cursor-pointer hover:opacity-90 transition"
        :style="{ backgroundColor: tag.color || '#0D9488' }"
      >
        {{ tag.name }}
        <button
          @click="removeTag(tag)"
          class="ml-1 hover:text-red-200 transition"
          type="button"
          title="Retirer ce tag"
        >
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </span>
    </div>

    <!-- Add tag input -->
    <div class="relative">
      <input
        v-model="searchQuery"
        @focus="showSuggestions = true"
        @blur="handleBlur"
        @input="filterTags"
        @keydown.enter.prevent="selectFirstSuggestion"
        @keydown.escape="showSuggestions = false"
        type="text"
        placeholder="Ajouter un tag..."
        class="w-full px-4 py-2 pr-10 border border-surface-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-transparent text-sm"
      />
      <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
        <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
        </svg>
      </div>

      <!-- Suggestions dropdown -->
      <div
        v-if="showSuggestions && filteredTags.length > 0"
        class="absolute z-10 w-full mt-1 bg-white border border-surface-200 rounded-lg shadow-lg max-h-48 overflow-auto"
      >
        <button
          v-for="tag in filteredTags"
          :key="tag.id"
          @mousedown.prevent="addTag(tag)"
          type="button"
          class="w-full px-4 py-2 text-left hover:bg-surface-50 transition flex items-center gap-2"
        >
          <div
            class="w-3 h-3 rounded-full"
            :style="{ backgroundColor: tag.color || '#0D9488' }"
          ></div>
          <span class="text-sm text-surface-900">{{ tag.name }}</span>
          <span class="text-xs text-surface-500 ml-auto">{{ tag.media_count }} médias</span>
        </button>
      </div>

      <!-- No results -->
      <div
        v-if="showSuggestions && searchQuery && filteredTags.length === 0 && availableTags.length > 0"
        class="absolute z-10 w-full mt-1 bg-white border border-surface-200 rounded-lg shadow-lg p-4 text-center text-sm text-surface-500"
      >
        Aucun tag trouvé pour "{{ searchQuery }}"
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { matchesPerson } from '@/utils/personSearch';
import { useToast } from '@/Composables/useToast';

const toast = useToast();

const props = defineProps({
  mediaId: {
    type: String,
    required: true,
  },
  initialTags: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['tags-updated']);

const mediaTags = ref([...props.initialTags]);
const availableTags = ref([]);
const searchQuery = ref('');
const showSuggestions = ref(false);
const loading = ref(false);

// Filter tags based on search query and exclude already attached tags
const filteredTags = computed(() => {
  const attachedIds = mediaTags.value.map(t => t.id);
  let tags = availableTags.value.filter(tag => !attachedIds.includes(tag.id));

  if (searchQuery.value) {
    // Insensible aux accents + tolérant aux fautes (helper partagé).
    tags = tags.filter(tag => matchesPerson(searchQuery.value, tag.name));
  }

  return tags.slice(0, 10); // Limit to 10 suggestions
});

const loadAvailableTags = async () => {
  try {
    const response = await axios.get('/tags', {
      headers: { 'Accept': 'application/json' }
    });
    availableTags.value = response.data;
  } catch (error) {
    console.error('Error loading tags:', error);
  }
};

const addTag = async (tag) => {
  if (loading.value) return;

  loading.value = true;
  try {
    await axios.post('/tags/attach', {
      media_id: props.mediaId,
      tag_id: tag.id,
    });

    mediaTags.value.push(tag);
    searchQuery.value = '';
    showSuggestions.value = false;
    emit('tags-updated', mediaTags.value);
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible d\'ajouter le tag.');
  } finally {
    loading.value = false;
  }
};

const removeTag = async (tag) => {
  if (loading.value) return;

  loading.value = true;
  try {
    await axios.post('/tags/detach', {
      media_id: props.mediaId,
      tag_id: tag.id,
    });

    mediaTags.value = mediaTags.value.filter(t => t.id !== tag.id);
    emit('tags-updated', mediaTags.value);
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible de retirer le tag.');
  } finally {
    loading.value = false;
  }
};

const filterTags = () => {
  showSuggestions.value = true;
};

const handleBlur = () => {
  // Delay to allow click on suggestion
  setTimeout(() => {
    showSuggestions.value = false;
  }, 200);
};

const selectFirstSuggestion = () => {
  if (filteredTags.value.length > 0) {
    addTag(filteredTags.value[0]);
  }
};

onMounted(() => {
  loadAvailableTags();
});
</script>
