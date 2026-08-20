<template>
  <BaseModal labelledby="album-form-title" @close="$emit('close')">
    <!-- Header -->
    <div class="bg-white px-6 py-4 border-b border-surface-200">
      <h3 id="album-form-title" class="text-lg font-semibold text-surface-900">
        {{ album ? 'Modifier l\'album' : 'Créer un album' }}
      </h3>
    </div>

    <!-- Form -->
    <form @submit.prevent="submit" class="px-6 py-4 space-y-4">
      <FormField
        v-model="form.name"
        id="album-name"
        label="Nom de l'album"
        placeholder="Ex: Vacances été 2024"
        :error="form.errors.name"
        required
      />

      <div>
        <label class="block text-sm font-medium text-surface-700 mb-2">
          Description
        </label>
        <textarea
          v-model="form.description"
          rows="3"
          class="w-full px-4 py-2.5 border border-surface-300 rounded-lg shadow-xs focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
          placeholder="Décrivez cet album..."
        ></textarea>
        <p v-if="form.errors.description" class="mt-1 text-sm text-red-600 dark:text-red-400">
          {{ form.errors.description }}
        </p>
      </div>

      <div class="flex items-center">
        <input
          id="is-public"
          v-model="form.is_public"
          type="checkbox"
          class="h-4 w-4 text-brand-600 focus:ring-brand-500 border-surface-300 rounded-sm"
        />
        <label for="is-public" class="ml-2 block text-sm text-surface-700">
          Album public (visible par tous les utilisateurs)
        </label>
      </div>

      <!-- Album intelligent -->
      <div class="border-t border-surface-200 pt-4">
        <div class="flex items-center">
          <input
            id="is-smart"
            v-model="form.is_smart"
            type="checkbox"
            class="h-4 w-4 text-brand-600 focus:ring-brand-500 border-surface-300 rounded-sm"
          />
          <label for="is-smart" class="ml-2 block text-sm text-surface-700">
            Album intelligent (se remplit tout seul selon des critères)
          </label>
        </div>

        <div v-if="form.is_smart" class="mt-4 space-y-3 bg-surface-50 rounded-lg p-4">
          <p class="text-xs text-surface-500">
            L'album contiendra automatiquement tous les médias correspondant à
            l'ensemble des critères choisis — y compris les futurs ajouts.
          </p>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="form-label" for="smart-person">Personne</label>
              <select id="smart-person" v-model="form.smart_rules.person_id" class="form-select">
                <option :value="null">— Toutes —</option>
                <option v-for="person in people" :key="person.id" :value="person.id">
                  {{ person.name }}
                </option>
              </select>
            </div>
            <div>
              <label class="form-label" for="smart-tag">Tag</label>
              <select id="smart-tag" v-model="form.smart_rules.tag_id" class="form-select">
                <option :value="null">— Tous —</option>
                <option v-for="tag in tags" :key="tag.id" :value="tag.id">
                  {{ tag.name }}
                </option>
              </select>
            </div>
            <div>
              <label class="form-label" for="smart-year">Année</label>
              <input
                id="smart-year"
                v-model.number="form.smart_rules.year"
                type="number"
                min="1800"
                max="2200"
                placeholder="Ex : 2025"
                class="form-input"
              />
            </div>
            <div>
              <label class="form-label" for="smart-type">Type</label>
              <select id="smart-type" v-model="form.smart_rules.type" class="form-select">
                <option :value="null">— Tous —</option>
                <option value="photo">Photos</option>
                <option value="video">Vidéos</option>
                <option value="document">Documents</option>
              </select>
            </div>
          </div>

          <p v-if="form.errors.smart_rules" class="text-sm text-red-600 dark:text-red-400">
            Choisissez au moins un critère.
          </p>
        </div>
      </div>
    </form>

    <!-- Footer -->
    <div class="bg-surface-50 px-6 py-4 flex justify-end gap-3">
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-brand-500"
        @click="$emit('close')"
      >
        Annuler
      </button>
      <FormButton
        type="submit"
        :text="album ? 'Enregistrer' : 'Créer'"
        :loading-text="album ? 'Enregistrement...' : 'Création...'"
        :loading="form.processing"
        @click="submit"
      />
    </div>
  </BaseModal>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import FormField from '@/Components/Forms/FormField.vue';
import FormButton from '@/Components/Forms/FormButton.vue';

const props = defineProps({
  album: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'saved']);

const people = ref([]);
const tags = ref([]);

const form = useForm({
  name: props.album?.name || '',
  description: props.album?.description || '',
  is_public: props.album?.is_public || false,
  cover_media_id: props.album?.cover_media_id || null,
  is_smart: props.album?.is_smart || false,
  smart_rules: {
    person_id: props.album?.smart_rules?.person_id || null,
    tag_id: props.album?.smart_rules?.tag_id || null,
    year: props.album?.smart_rules?.year || null,
    type: props.album?.smart_rules?.type || null,
  },
});

// Les listes ne sont chargées que si la section intelligente est utilisée
let listsLoaded = false;
const loadLists = async () => {
  if (listsLoaded) return;
  listsLoaded = true;
  try {
    const [peopleRes, tagsRes] = await Promise.all([
      axios.get('/people', { headers: { Accept: 'application/json' } }),
      axios.get('/tags', { headers: { Accept: 'application/json' } }),
    ]);
    people.value = peopleRes.data.data || peopleRes.data;
    tags.value = tagsRes.data;
  } catch (error) {
    console.error('Impossible de charger personnes/tags :', error);
  }
};

watch(() => form.is_smart, (isSmart) => {
  if (isSmart) loadLists();
});

onMounted(() => {
  if (form.is_smart) loadLists();
});

const submit = () => {
  // Année vide → null (l'input number renvoie '' quand on efface)
  if (!form.smart_rules.year) form.smart_rules.year = null;

  const options = {
    onSuccess: () => {
      emit('saved');
      emit('close');
    },
  };

  if (props.album) {
    form.put(`/albums/${props.album.id}`, options);
  } else {
    form.post('/albums', options);
  }
};
</script>
