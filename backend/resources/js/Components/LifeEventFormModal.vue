<template>
  <BaseModal :title="event ? 'Modifier le moment' : 'Ajouter un moment'" @close="$emit('close')">
    <!-- Corps (le padding vit ici : l'en-tête standard reste bord à bord) -->
    <div class="p-6">
      <form @submit.prevent="save" class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">Type</label>
          <select v-model="form.type" class="form-input">
            <optgroup v-for="group in typeGroups" :key="group.label" :label="group.label">
              <option v-for="t in group.types" :key="t.value" :value="t.value">{{ t.label }}</option>
            </optgroup>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">Titre *</label>
          <input v-model="form.title" type="text" class="form-input" placeholder="ex. Baptême de Camille" />
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
          <div class="flex gap-2">
            <input v-model="form.place" type="text" class="form-input" placeholder="Ville, pays..." />
            <button
              type="button"
              class="btn-secondary shrink-0"
              :title="hasCoordinates ? 'Modifier la position sur la carte' : 'Situer sur la carte'"
              @click="showMapPicker = true"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </button>
          </div>
          <!-- Position géolocalisée : servira à l'animation carte du diaporama -->
          <p v-if="hasCoordinates" class="mt-1 flex items-center gap-2 text-xs text-teal-700 dark:text-teal-300">
            <span>📍 Position enregistrée ({{ form.latitude.toFixed(4) }}, {{ form.longitude.toFixed(4) }})</span>
            <button type="button" class="underline text-surface-500 hover:text-surface-700" @click="clearCoordinates">
              retirer
            </button>
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">Description</label>
          <textarea v-model="form.description" rows="3" class="form-input"></textarea>
        </div>

        <!-- Album lié (galerie du moment : baptême, mariage…) -->
        <div v-if="albums.length > 0">
          <label class="block text-sm font-medium text-surface-700 mb-1">Album lié (optionnel)</label>
          <select v-model="form.album_id" class="form-input">
            <option :value="null">— Aucun —</option>
            <option v-for="album in albums" :key="album.id" :value="album.id">{{ album.name }}</option>
          </select>
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

        <!-- Boutons laissés dans le <form> : le bouton type="submit" dépend du
             submit natif du formulaire (déplacé en slot footer, il ne le
             déclencherait plus). -->
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="$emit('close')" class="btn-secondary">Annuler</button>
          <button type="submit" :disabled="saving || !form.title.trim() || !form.event_date" class="btn-primary disabled:opacity-50">
            {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
          </button>
        </div>
      </form>
    </div>

    <!-- Choix de la position (modale imbriquée : la pile de BaseModal gère
         Échap et le verrou de scroll) -->
    <GeolocatePickerModal
      v-if="showMapPicker"
      title="Situer le moment"
      description="Cliquez sur la carte ou cherchez une adresse : la position permettra d'animer la carte dans le diaporama."
      apply-label="Utiliser cette position"
      @close="showMapPicker = false"
      @apply="applyCoordinates"
    />
  </BaseModal>
</template>

<script setup>
import { computed, onMounted, ref, reactive } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import GeolocatePickerModal from '@/Components/GeolocatePickerModal.vue';

const props = defineProps({
  personId: { type: String, required: true },
  event: { type: Object, default: null }, // item de frise (life_event) pour l'édition
  photoOptions: { type: Array, default: () => [] }, // items kind==='photo'
});

const emit = defineEmits(['close', 'saved']);

// « Sous-moments » définis : liste fermée, groupée — miroir de
// LifeEventController::TYPES (le diaporama s'appuiera sur ces types).
const typeGroups = [
  {
    label: 'Moments de vie',
    types: [
      { value: 'moment', label: 'Moment' },
      { value: 'job', label: 'Emploi' },
      { value: 'education', label: 'Études' },
      { value: 'residence', label: 'Résidence' },
    ],
  },
  {
    label: 'Fêtes religieuses',
    types: [
      { value: 'bapteme', label: 'Baptême' },
      { value: 'communion', label: 'Communion' },
      { value: 'confirmation', label: 'Confirmation' },
      { value: 'mariage_religieux', label: 'Mariage religieux' },
    ],
  },
  {
    label: 'Fêtes & célébrations',
    types: [
      { value: 'mariage', label: 'Mariage' },
      { value: 'fiancailles', label: 'Fiançailles' },
      { value: 'anniversaire', label: 'Anniversaire' },
      { value: 'diplome', label: 'Remise de diplôme' },
      { value: 'fete', label: 'Fête' },
    ],
  },
  {
    label: 'Autre',
    types: [{ value: 'custom', label: 'Autre' }],
  },
];

const allTypes = typeGroups.flatMap((g) => g.types);

const form = reactive({
  type: props.event?.kind && allTypes.some(t => t.value === props.event.kind) ? props.event.kind : 'moment',
  title: props.event?.title || '',
  event_date: props.event?.date || '',
  end_date: props.event?.end_date || '',
  place: props.event?.place || '',
  latitude: props.event?.latitude ?? null,
  longitude: props.event?.longitude ?? null,
  description: props.event?.description || '',
  media_id: props.event?.media?.id || null,
  album_id: props.event?.album?.id || null,
});

// Albums accessibles, pour lier une galerie au moment.
const albums = ref([]);
onMounted(async () => {
  try {
    const { data } = await axios.get('/albums', { headers: { Accept: 'application/json' } });
    albums.value = data;
  } catch {
    albums.value = [];
  }
});

// Position géolocalisée du lieu.
const showMapPicker = ref(false);
const hasCoordinates = computed(() => form.latitude !== null && form.longitude !== null);

const applyCoordinates = ({ latitude, longitude }) => {
  form.latitude = latitude;
  form.longitude = longitude;
  showMapPicker.value = false;
};

const clearCoordinates = () => {
  form.latitude = null;
  form.longitude = null;
};

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
    latitude: form.latitude,
    longitude: form.longitude,
    description: form.description || null,
    media_id: form.media_id || null,
    album_id: form.album_id || null,
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
