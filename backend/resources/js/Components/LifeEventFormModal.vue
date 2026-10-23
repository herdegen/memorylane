<template>
  <BaseModal :title="event ? $t('Modifier le moment') : $t('Ajouter un moment')" @close="$emit('close')">
    <!-- Corps (le padding vit ici : l'en-tête standard reste bord à bord) -->
    <div class="p-6">
      <form @submit.prevent="save" class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Type') }}</label>
          <select v-model="form.type" class="form-input">
            <optgroup v-for="group in typeGroups" :key="group.label" :label="group.label">
              <option v-for="t in group.types" :key="t.value" :value="t.value">{{ t.label }}</option>
            </optgroup>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Titre *') }}</label>
          <input v-model="form.title" type="text" class="form-input" :placeholder="$t('ex. Baptême de Camille')" />
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Date *') }}</label>
            <input v-model="form.event_date" type="date" class="form-input" />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Fin (optionnel)') }}</label>
            <input v-model="form.end_date" type="date" class="form-input" />
          </div>
        </div>

        <!-- Souvenir connu seulement à l'année / au mois : on choisit une date
             dans la période, la précision dit ce qui sera affiché. -->
        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Précision de la date') }}</label>
          <select v-model="form.date_precision" class="form-input">
            <option value="day">{{ $t('Jour exact') }}</option>
            <option value="month">{{ $t('Mois seulement') }}</option>
            <option value="year">{{ $t('Année seulement') }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Lieu') }}</label>
          <div class="flex gap-2">
            <input v-model="form.place" type="text" class="form-input" :placeholder="$t('Ville, pays...')" />
            <button
              type="button"
              class="btn-secondary shrink-0"
              :title="hasCoordinates ? $t('Modifier la position sur la carte') : $t('Situer sur la carte')"
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
            <span>📍 {{ $t('Position enregistrée (:lat, :lng)', { lat: form.latitude.toFixed(4), lng: form.longitude.toFixed(4) }) }}</span>
            <button type="button" class="underline text-surface-500 hover:text-surface-700" @click="clearCoordinates">
              {{ $t('retirer') }}
            </button>
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Description') }}</label>
          <textarea v-model="form.description" rows="3" class="form-input"></textarea>
        </div>

        <!-- Album lié (galerie du moment : baptême, mariage…) -->
        <div v-if="albums.length > 0">
          <label class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Album lié (optionnel)') }}</label>
          <select v-model="form.album_id" class="form-input">
            <option :value="null">— {{ $t('Aucun') }} —</option>
            <option v-for="album in albums" :key="album.id" :value="album.id">{{ album.name }}</option>
          </select>
        </div>

        <!-- Photos du moment : choisies dans tout ce qu'on voit (récentes,
             autour de la date, avec la personne, un album). La première (ou
             celle à l'étoile) illustre le moment ; l'ordre est celui de la
             rafale du récit de vie. -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="block text-sm font-medium text-surface-700">
              {{ $t('Photos (optionnel)') }}
              <span v-if="selectedPhotos.length" class="font-normal text-surface-500">— {{ selectedPhotos.length }}</span>
            </label>
            <button type="button" class="text-sm font-semibold text-brand-700 dark:text-brand-400 hover:underline" @click="pickerOpen = true">
              {{ selectedPhotos.length ? $t('Modifier') : $t('Choisir des photos') }}
            </button>
          </div>
          <div v-if="selectedPhotos.length" class="flex gap-2 overflow-x-auto pb-1">
            <div v-for="(p, i) in selectedPhotos" :key="p.id" class="relative w-16 h-16 shrink-0 rounded-lg overflow-hidden border-2" :class="illustrationId === p.id ? 'border-amber-400' : 'border-transparent'">
              <img :src="p.thumbnail_url" alt="" class="w-full h-full object-cover" loading="lazy" decoding="async" />
              <span class="absolute bottom-0.5 left-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-black/60 text-snow text-[11px] font-semibold flex items-center justify-center">{{ i + 1 }}</span>
              <button
                type="button"
                class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full flex items-center justify-center text-[12px]"
                :class="illustrationId === p.id ? 'bg-amber-400 text-snow' : 'bg-black/45 text-snow/80'"
                :title="illustrationId === p.id ? $t('Photo d\'illustration') : $t('En faire la photo d\'illustration')"
                @click="form.media_id = p.id; photosTouched = true"
              >★</button>
              <button
                type="button"
                class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-black/55 text-snow text-xs flex items-center justify-center"
                :title="$t('Retirer')"
                @click="removePhoto(p.id)"
              >×</button>
            </div>
          </div>
          <button
            v-else
            type="button"
            class="w-full h-16 rounded-lg border-2 border-dashed border-surface-300 text-sm text-surface-500 hover:border-brand-300 hover:text-brand-700"
            @click="pickerOpen = true"
          >
            + {{ $t("Ajouter des photos (récentes, de la date, d'un album…)") }}
          </button>
        </div>

        <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>

        <!-- Boutons laissés dans le <form> : le bouton type="submit" dépend du
             submit natif du formulaire (déplacé en slot footer, il ne le
             déclencherait plus). -->
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="$emit('close')" class="btn-secondary">{{ $t('Annuler') }}</button>
          <button type="submit" :disabled="saving || !form.title.trim() || !form.event_date" class="btn-primary disabled:opacity-50">
            {{ saving ? $t('Enregistrement…') : $t('Enregistrer') }}
          </button>
        </div>
      </form>
    </div>

    <!-- Choix de la position (modale imbriquée : la pile de BaseModal gère
         Échap et le verrou de scroll) -->
    <GeolocatePickerModal
      v-if="showMapPicker"
      :title="$t('Situer le moment')"
      :description="$t('Cliquez sur la carte ou cherchez une adresse : la position permettra d\'animer la carte dans le diaporama.')"
      :apply-label="$t('Utiliser cette position')"
      @close="showMapPicker = false"
      @apply="applyCoordinates"
    />

    <PhotoPicker
      :open="pickerOpen"
      :person-id="personId"
      :person-name="personName"
      :around="form.event_date"
      :initial="selectedPhotos"
      @close="pickerOpen = false"
      @done="onPicked"
    />
  </BaseModal>
</template>

<script setup>
import { computed, onMounted, ref, reactive, defineAsyncComponent } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import PhotoPicker from '@/Components/PhotoPicker.vue';
import { t } from '@/i18n';

// Chargé à l'ouverture seulement : la carte embarque Leaflet (~45 ko gzip).
const GeolocatePickerModal = defineAsyncComponent(() => import('@/Components/GeolocatePickerModal.vue'));

const props = defineProps({
  personId: { type: String, required: true },
  event: { type: Object, default: null }, // item de frise (life_event) pour l'édition
  personName: { type: String, default: '' },
  // Nouveau moment pré-rempli (« En faire un moment » depuis des photos) :
  // { title, event_date, latitude, longitude, photos: [{ id, thumbnail_url }] }
  prefill: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

// « Sous-moments » définis : liste fermée, groupée — miroir de
// LifeEventController::TYPES (le diaporama s'appuiera sur ces types).
const typeGroups = [
  {
    label: t('Moments de vie'),
    types: [
      { value: 'moment', label: t('Moment') },
      { value: 'job', label: t('Emploi') },
      { value: 'education', label: t('Études') },
      { value: 'residence', label: t('Résidence') },
      { value: 'voyage', label: t('Voyage') },
    ],
  },
  {
    label: t('Parcours & distinctions'),
    types: [
      { value: 'military', label: t('Parcours militaire') },
      { value: 'distinction', label: t('Décoration / distinction') },
    ],
  },
  {
    label: t('Fêtes religieuses'),
    types: [
      { value: 'bapteme', label: t('Baptême') },
      { value: 'communion', label: t('Communion') },
      { value: 'confirmation', label: t('Confirmation') },
      { value: 'mariage_religieux', label: t('Mariage religieux') },
    ],
  },
  {
    label: t('Fêtes & célébrations'),
    types: [
      { value: 'mariage', label: t('Mariage') },
      { value: 'fiancailles', label: t('Fiançailles') },
      { value: 'anniversaire', label: t('Anniversaire') },
      { value: 'diplome', label: t('Remise de diplôme') },
      { value: 'fete', label: t('Fête') },
    ],
  },
  {
    label: t('Autre'),
    types: [{ value: 'custom', label: t('Autre') }],
  },
];

const allTypes = typeGroups.flatMap((g) => g.types);

const form = reactive({
  type: props.event?.kind && allTypes.some(t => t.value === props.event.kind) ? props.event.kind : 'moment',
  title: props.event?.title || props.prefill?.title || '',
  event_date: props.event?.date || props.prefill?.event_date || '',
  end_date: props.event?.end_date || '',
  date_precision: props.event?.date_precision || 'day',
  place: props.event?.place || '',
  latitude: props.event?.latitude ?? props.prefill?.latitude ?? null,
  longitude: props.event?.longitude ?? props.prefill?.longitude ?? null,
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

// Photos du moment, dans l'ordre : l'illustration historique (si elle n'est
// pas dans la galerie) en tête, puis la galerie.
const thumbOf = (m) => m?.thumbnail_url || m?.url || '';
const selectedPhotos = ref((() => {
  if (props.prefill?.photos) return props.prefill.photos.map((p) => ({ id: p.id, thumbnail_url: thumbOf(p) }));
  const list = (props.event?.photos || []).map((p) => ({ id: p.id, thumbnail_url: thumbOf(p) }));
  const ill = props.event?.media;
  if (ill && !list.some((p) => p.id === ill.id)) list.unshift({ id: ill.id, thumbnail_url: thumbOf(ill) });
  return list;
})());
// Illustration : l'étoile, sinon la première photo.
const illustrationId = computed(() =>
  (form.media_id && selectedPhotos.value.some((p) => p.id === form.media_id)) ? form.media_id : (selectedPhotos.value[0]?.id || null));

// Photos envoyées seulement si on y a touché : sinon le moment garde sa
// galerie telle quelle (y compris ce que l'éditeur ne voit pas).
const photosTouched = ref(!!props.prefill?.photos);
const pickerOpen = ref(false);
const onPicked = (list) => {
  selectedPhotos.value = list;
  photosTouched.value = true;
  pickerOpen.value = false;
};
const removePhoto = (id) => {
  selectedPhotos.value = selectedPhotos.value.filter((p) => p.id !== id);
  if (form.media_id === id) form.media_id = null;
  photosTouched.value = true;
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
    date_precision: form.date_precision,
    place: form.place || null,
    latitude: form.latitude,
    longitude: form.longitude,
    description: form.description || null,
    ...(photosTouched.value || !props.event ? {
      media_id: illustrationId.value,
      photos: selectedPhotos.value.slice(0, 24).map((p) => p.id),
    } : {}),
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
    error.value = e.response?.data?.message || t('Erreur lors de l\'enregistrement.');
  } finally {
    saving.value = false;
  }
};
</script>
