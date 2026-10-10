<template>
  <div class="pt-9 pb-2">
    <div class="flex items-center justify-between mb-4 px-4 sm:px-8">
      <h2 class="font-display text-2xl font-semibold text-surface-900">Sa vie</h2>
      <div class="flex items-center gap-2">
      <button
        v-if="hasPlayable"
        @click="playSlideshow"
        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-brand-600 text-sm font-medium text-white hover:bg-brand-700 transition"
        title="Lire le récit de sa vie"
      >
        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
        Lire
      </button>
      <button
        v-if="canManage"
        @click="openCreate"
        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-white border border-surface-300 text-sm font-medium text-surface-700 hover:bg-surface-50 transition"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
        Ajouter un moment
      </button>
      </div>
    </div>

    <div v-if="loading" class="text-sm text-surface-500 px-4 sm:px-8">Chargement de la frise…</div>

    <div v-else-if="eventItems.length === 0" class="text-sm text-surface-500 px-4 sm:px-8">
      Aucun événement daté pour l'instant.
      <span v-if="canManage">Ajoutez un moment pour commencer la frise.</span>
    </div>

    <!-- Frise HORIZONTALE : rail + cartes photo, défilement latéral.
         Desktop : flèches de part et d'autre + cliquer-glisser à la souris
         (le glisser ne déclenche pas les clics sur les cartes). -->
    <div v-else class="relative group/rail">
      <button
        v-show="canScrollLeft"
        @click="scrollByCards(-1)"
        class="hidden sm:flex absolute left-2 top-[60px] z-10 w-10 h-10 rounded-full bg-white/90 dark:bg-surface-800/90 shadow-warm-md border border-surface-200 items-center justify-center text-surface-700 hover:bg-white dark:hover:bg-surface-700 transition"
        title="Reculer dans le temps"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      </button>
      <button
        v-show="canScrollRight"
        @click="scrollByCards(1)"
        class="hidden sm:flex absolute right-2 top-[60px] z-10 w-10 h-10 rounded-full bg-white/90 dark:bg-surface-800/90 shadow-warm-md border border-surface-200 items-center justify-center text-surface-700 hover:bg-white dark:hover:bg-surface-700 transition"
        title="Avancer dans le temps"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
      </button>

    <div
      ref="railEl"
      class="overflow-x-auto pb-4 select-none sm:cursor-grab"
      :class="{ 'sm:cursor-grabbing': dragging }"
      tabindex="0"
      @scroll.passive="updateScrollState"
      @mousedown="onDragStart"
      @click.capture="onRailClick"
      @keydown.left.prevent="scrollByCards(-1)"
      @keydown.right.prevent="scrollByCards(1)"
    >
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
                {{ formatEventDate(item.date, item.date_precision) }}<span v-if="item.end_date"> → {{ formatEventDate(item.end_date, item.date_precision) }}</span>
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
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import LifeEventFormModal from '@/Components/LifeEventFormModal.vue';
import LifeStoryPlayer from '@/Components/LifeStoryPlayer.vue';
import { useToast } from '@/Composables/useToast';
import { formatPartialDate } from '@/utils/format';

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

// Les lieux non encore résolus (géocodage, photo du lieu) le sont en
// arrière-plan : on recharge la frise sans clignoter, quelques fois.
let pendingReloads = 0;
let pendingTimer = null;
const load = async ({ silent = false } = {}) => {
  if (!silent) loading.value = true;
  clearTimeout(pendingTimer);
  try {
    const { data } = await axios.get(`/people/${props.personId}/timeline`);
    items.value = data || [];
    if (items.value.some((i) => i.place_pending) && pendingReloads < 4) {
      pendingReloads += 1;
      pendingTimer = setTimeout(() => load({ silent: true }), 3000 * pendingReloads);
    }
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
// de leur date lisible et de leur RAFALE de photos. La galerie du moment,
// ce sont ses photos rattachées (ou, à défaut, son album lié), illustration en tête ; à défaut, les photos de la
// personne prises dans la fourchette de l'événement, élargie à sa précision
// (un moment « en 1950 » rassemble les photos de 1950).
const BURST_MAX = 8;

const windowFor = (ev) => {
  const d = ev.date || '';
  const end = ev.end_date || d;
  const p = ev.date_precision || 'day';
  if (p === 'year') return [`${d.slice(0, 4)}-01-01`, `${end.slice(0, 4)}-12-31`];
  if (p === 'month') return [`${d.slice(0, 7)}-01`, `${end.slice(0, 7)}-31`];
  return [d, end];
};

const storyEvents = computed(() => {
  // Photos déjà « prises » par un moment (illustration ou album lié) : le
  // repli par fenêtre de dates ne les reprend pas, sinon un moment étalé sur
  // deux ans rejouerait les photos de tous ses voisins.
  const claimed = new Set();
  for (const ev of eventItems.value) {
    if (ev.media?.id) claimed.add(ev.media.id);
    for (const m of ev.photos || []) claimed.add(m.id);
    for (const m of ev.album?.media || []) claimed.add(m.id);
  }

  return eventItems.value.map((ev) => {
  const burst = [];
  const push = (m) => {
    const src = m?.medium_url || m?.url;
    if (src && burst.length < BURST_MAX && !burst.some((b) => b.src === src)) {
      burst.push({ src, full: m.url || src, title: m.title || '' });
    }
  };
  push(ev.media);
  const gallery = ev.photos?.length ? ev.photos : (ev.album?.media || []);
  if (gallery.length) {
    gallery.forEach(push);
  } else {
    const [from, to] = windowFor(ev);
    for (const p of photoItems.value) {
      if (p.date && p.date >= from && p.date <= to && !claimed.has(p.media?.id)) push(p.media);
    }
  }
  return {
    ...ev,
    icon: kindIcon(ev.kind),
    dateLabel: formatEventDate(ev.date, ev.date_precision),
    // Une seule photo n'est pas une rafale : la carte-chapitre suffit.
    burst: burst.length > 1 ? burst : [],
  };
  });
});

const thumb = (item) => item.media?.medium_url || item.media?.thumbnail_url || item.related?.avatar_url || null;

const ICONS = {
  birth: '🎂', death: '🕯️', marriage: '💍', child: '👶',
  job: '💼', education: '🎓', residence: '🏠', photo: '📷',
  voyage: '🧭', military: '🎖️', distinction: '🏅',
  // Fêtes (« sous-moments » définis, cf. LifeEventFormModal)
  bapteme: '🕊️', communion: '🕊️', confirmation: '🕊️', mariage_religieux: '⛪',
  mariage: '💍', fiancailles: '💍', anniversaire: '🎈', diplome: '🎓', fete: '🎉',
  moment: '★', custom: '★',
};
const LABELS = {
  birth: 'Naissance', death: 'Décès', marriage: 'Mariage', child: 'Enfant',
  job: 'Emploi', education: 'Études', residence: 'Résidence', photo: 'Photo',
  voyage: 'Voyage', military: 'Parcours militaire', distinction: 'Distinction',
  bapteme: 'Baptême', communion: 'Communion', confirmation: 'Confirmation', mariage_religieux: 'Mariage religieux',
  mariage: 'Mariage', fiancailles: 'Fiançailles', anniversaire: 'Anniversaire', diplome: 'Remise de diplôme', fete: 'Fête',
  moment: 'Moment', custom: 'Moment',
};
const kindIcon = (k) => ICONS[k] || '★';
const kindLabel = (k) => LABELS[k] || 'Moment';

// Wrapper autour du formatteur partagé : le formatteur renvoie '' pour une
// date invalide, ici on conserve l'ancien comportement (afficher la string
// brute, ex. « vers 1950 »).
const formatEventDate = (d, precision = 'day') => formatPartialDate(d, precision) || d || '';

// --- Navigation desktop du rail : flèches + cliquer-glisser ---------------
const railEl = ref(null);
const canScrollLeft = ref(false);
const canScrollRight = ref(false);
const dragging = ref(false);
const CARD_STEP = 235; // largeur d'une carte (230) + écart

const updateScrollState = () => {
  const el = railEl.value;
  if (!el) return;
  canScrollLeft.value = el.scrollLeft > 4;
  canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4;
};

const scrollByCards = (n) => {
  railEl.value?.scrollBy({ left: n * CARD_STEP * 2, behavior: 'smooth' });
};

// Glisser à la souris : on mémorise le point de départ, on déplace le
// scroll au mouvement, et on avale le clic qui suit un vrai déplacement.
let dragStartX = 0;
let dragStartScroll = 0;
let dragMoved = false;
const onDragStart = (e) => {
  if (e.button !== 0 || !railEl.value) return;
  dragging.value = true;
  dragMoved = false;
  dragStartX = e.pageX;
  dragStartScroll = railEl.value.scrollLeft;
  window.addEventListener('mousemove', onDragMove);
  window.addEventListener('mouseup', onDragEnd, { once: true });
};
const onDragMove = (e) => {
  const dx = e.pageX - dragStartX;
  if (Math.abs(dx) > 5) dragMoved = true;
  railEl.value.scrollLeft = dragStartScroll - dx;
};
const onDragEnd = () => {
  dragging.value = false;
  window.removeEventListener('mousemove', onDragMove);
};
const onRailClick = (e) => {
  if (dragMoved) {
    e.stopPropagation();
    e.preventDefault();
    dragMoved = false;
  }
};

// Après chargement, l'état des flèches dépend de la largeur réelle.
watch(eventItems, () => nextTick(updateScrollState));
onMounted(() => {
  load();
  window.addEventListener('resize', updateScrollState);
});
onBeforeUnmount(() => {
  clearTimeout(pendingTimer);
  window.removeEventListener('resize', updateScrollState);
  window.removeEventListener('mousemove', onDragMove);
});
</script>
