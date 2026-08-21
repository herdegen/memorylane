<template>
  <div class="pt-9 pb-2">
    <div class="flex items-center justify-between mb-4 px-4 sm:px-8">
      <h2 class="font-display text-2xl font-semibold text-surface-900">Sa vie</h2>
      <button
        v-if="canManage"
        @click="openCreate"
        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-white border border-surface-300 text-sm font-medium text-surface-700 hover:bg-surface-50 transition"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
        Ajouter un moment
      </button>
    </div>

    <div v-if="loading" class="text-sm text-surface-500 px-4 sm:px-8">Chargement de la frise…</div>

    <div v-else-if="eventItems.length === 0" class="text-sm text-surface-500 px-4 sm:px-8">
      Aucun événement daté pour l'instant.
      <span v-if="canManage">Ajoutez un moment pour commencer la frise.</span>
    </div>

    <!-- Frise HORIZONTALE : rail + cartes photo, défilement latéral -->
    <div v-else class="overflow-x-auto pb-4">
      <div class="relative min-w-max px-4 sm:px-8 pt-2">
        <!-- Rail (positionné au niveau des points, sous les cartes photo) -->
        <div class="absolute left-0 right-0 top-[174px] h-0.5 bg-surface-200"></div>

        <ol class="flex items-start gap-5">
          <li v-for="item in eventItems" :key="item._key" class="relative w-[230px] shrink-0 flex flex-col items-center">
            <!-- Carte photo (ou carte icône pour un moment sans média) -->
            <div class="relative group w-[230px] h-[150px] rounded-xl overflow-hidden shadow-warm-md">
              <img
                v-if="thumb(item)"
                :src="thumb(item)"
                class="w-full h-full object-cover"
                :class="{ 'cursor-pointer': item.media }"
                @click="playSlideshow"
              />
              <div v-else class="w-full h-full flex items-center justify-center bg-brand-50 dark:bg-brand-500/10 text-4xl">
                {{ kindIcon(item.kind) }}
              </div>

              <!-- Actions gestionnaire sur les moments (au survol) -->
              <div v-if="canManage && item.life_event_id" class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                <button @click.stop="openEdit(item)" class="p-1.5 rounded-full bg-white/90 text-surface-600 hover:text-brand-600 shadow-sm" title="Modifier">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                </button>
                <button @click.stop="removeEvent(item)" class="p-1.5 rounded-full bg-white/90 text-surface-600 hover:text-red-500 shadow-sm" title="Supprimer">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M4 7h16" /></svg>
                </button>
              </div>
            </div>

            <!-- Point sur le rail -->
            <div class="w-3.5 h-3.5 rounded-full bg-white border-[3px] border-brand-500 mt-[10px] mb-2.5" :title="kindLabel(item.kind)"></div>

            <!-- Légende -->
            <div class="flex flex-col items-center gap-0.5 text-center px-1">
              <p class="text-xs font-semibold text-brand-600 dark:text-brand-400">
                {{ formatEventDate(item.date) }}<span v-if="item.end_date"> → {{ formatEventDate(item.end_date) }}</span>
              </p>
              <p class="text-sm font-semibold text-surface-900 leading-snug">
                <Link v-if="item.related" :href="`/people/${item.related.id}`" class="hover:text-brand-700">{{ kindIcon(item.kind) }} {{ item.title }}</Link>
                <span v-else>{{ kindIcon(item.kind) }} {{ item.title }}</span>
              </p>
              <p v-if="item.place" class="text-xs text-surface-500">
                {{ item.place }}<span v-if="item.latitude" title="Lieu géolocalisé"> 📍</span>
              </p>
              <p v-if="item.description" class="text-xs text-surface-500 line-clamp-2">{{ item.description }}</p>
              <Link
                v-if="item.album"
                :href="`/albums/${item.album.id}`"
                class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 dark:text-brand-400 hover:underline"
              >
                📁 {{ item.album.name }}
              </Link>
            </div>
          </li>
        </ol>
      </div>
    </div>

    <LifeEventFormModal
      v-if="showModal"
      :person-id="personId"
      :event="editing"
      :photo-options="photoItems"
      @close="showModal = false"
      @saved="onSaved"
    />

    <LifeStoryPlayer ref="storyPlayer" :events="storyEvents" :person-name="personName" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import LifeEventFormModal from '@/Components/LifeEventFormModal.vue';
import LifeStoryPlayer from '@/Components/LifeStoryPlayer.vue';
import { useToast } from '@/Composables/useToast';
import { formatLongDate } from '@/utils/format';

const toast = useToast();

const props = defineProps({
  personId: { type: String, required: true },
  personName: { type: String, default: '' },
  canManage: { type: Boolean, default: false },
});

const items = ref([]);
const loading = ref(true);
const showModal = ref(false);
const editing = ref(null);
const storyPlayer = ref(null);

const photoItems = computed(() => items.value.filter(i => i.kind === 'photo' && i.media));
const hasPlayable = computed(() => items.value.length > 0);

// La frise horizontale ne montre que les ÉVÉNEMENTS datés (auto + moments) :
// les photos, elles, vivent dans les galeries de la fiche. Les `items` bruts
// (photos comprises) restent utilisés par le diaporama et le sélecteur de
// photo d'un moment.
const eventItems = computed(() =>
  items.value
    .filter(i => i.kind !== 'photo')
    .map((e) => ({
      ...e,
      // Clé stable pour le v-for (la liste est retriée à chaque rechargement).
      _key: `event-${e.life_event_id ?? `${e.kind}-${e.date}`}`,
    }))
    .sort((a, b) => ((a.date || '') < (b.date || '') ? -1 : (a.date || '') > (b.date || '') ? 1 : 0))
);

const load = async () => {
  loading.value = true;
  try {
    const { data } = await axios.get(`/people/${props.personId}/timeline`);
    items.value = data || [];
  } catch (e) {
    console.error('Erreur chargement frise:', e);
  } finally {
    loading.value = false;
  }
};

const openCreate = () => { editing.value = null; showModal.value = true; };
const openEdit = (item) => { editing.value = item; showModal.value = true; };
const onSaved = () => { showModal.value = false; load(); };

const removeEvent = async (item) => {
  if (!confirm('Supprimer ce moment ?')) return;
  try {
    await axios.delete(`/life-events/${item.life_event_id}`);
    load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'Impossible de supprimer ce moment.');
  }
};

const playSlideshow = () => {
  if (storyEvents.value.length) storyPlayer.value?.start();
};

// Le bouton « Diaporama de sa vie » vit dans l'en-tête de la fiche personne.
defineExpose({ play: playSlideshow, hasPlayable });

// Chapitres du récit de vie : les événements datés, enrichis de leur icône,
// de leur date lisible et de leur RAFALE de photos (l'illustration du moment
// + les photos de la personne prises dans la fourchette de l'événement).
const storyEvents = computed(() => eventItems.value.map((ev) => {
  const from = ev.date || '';
  const to = ev.end_date || ev.date || '';
  const burst = [];
  if (ev.media) burst.push(ev.media.medium_url || ev.media.url);
  for (const p of photoItems.value) {
    if (burst.length >= 6) break;
    if (p.date && p.date >= from && p.date <= to) {
      const src = p.media.medium_url || p.media.url;
      if (src && !burst.includes(src)) burst.push(src);
    }
  }
  return {
    ...ev,
    icon: kindIcon(ev.kind),
    dateLabel: formatEventDate(ev.date),
    // Une seule photo n'est pas une rafale : la carte-chapitre suffit.
    burst: burst.length > 1 ? burst : [],
  };
}));

const thumb = (item) => item.media?.medium_url || item.media?.thumbnail_url || item.related?.avatar_url || null;

const ICONS = {
  birth: '🎂', death: '🕯️', marriage: '💍', child: '👶',
  job: '💼', education: '🎓', residence: '🏠', photo: '📷',
  // Fêtes (« sous-moments » définis, cf. LifeEventFormModal)
  bapteme: '🕊️', communion: '🕊️', confirmation: '🕊️', mariage_religieux: '⛪',
  mariage: '💍', fiancailles: '💍', anniversaire: '🎈', diplome: '🎓', fete: '🎉',
  moment: '★', custom: '★',
};
const LABELS = {
  birth: 'Naissance', death: 'Décès', marriage: 'Mariage', child: 'Enfant',
  job: 'Emploi', education: 'Études', residence: 'Résidence', photo: 'Photo',
  bapteme: 'Baptême', communion: 'Communion', confirmation: 'Confirmation', mariage_religieux: 'Mariage religieux',
  mariage: 'Mariage', fiancailles: 'Fiançailles', anniversaire: 'Anniversaire', diplome: 'Remise de diplôme', fete: 'Fête',
  moment: 'Moment', custom: 'Moment',
};
const kindIcon = (k) => ICONS[k] || '★';
const kindLabel = (k) => LABELS[k] || 'Moment';

// Wrapper autour du formatteur partagé : le formatteur renvoie '' pour une
// date invalide, ici on conserve l'ancien comportement (afficher la string
// brute, ex. « vers 1950 »).
const formatEventDate = (d) => formatLongDate(d) || d || '';

onMounted(load);
</script>
