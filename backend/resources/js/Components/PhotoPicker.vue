<template>
  <!-- Sélecteur de photos (moments de la frise) : feuille du bas sur
       téléphone, fenêtre sur ordinateur. Onglets Récentes / Autour de la
       date / Avec {prénom} / Album, recherche, sélection ordonnée. -->
  <component
    :is="mobile ? BottomSheet : BaseModal"
    v-if="mobile || open"
    v-bind="mobile ? { open, title: 'Choisir des photos' } : { title: 'Choisir des photos', maxWidth: '4xl', panelClass: 'max-h-[85vh] flex flex-col' }"
    @close="$emit('close')"
  >
    <div class="flex flex-col gap-3" :class="mobile ? '' : 'px-6 pt-4 pb-2 flex-1 min-h-0'">
      <!-- Onglets -->
      <div class="flex flex-nowrap gap-2 overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 [&>*]:shrink-0">
        <button
          v-for="t in tabs"
          :key="t.value"
          type="button"
          class="px-3.5 py-1.5 rounded-full text-[13px] font-medium whitespace-nowrap border transition"
          :class="tab === t.value ? 'bg-brand-600 border-brand-600 text-white font-semibold' : 'bg-white border-surface-300 text-surface-600 hover:bg-surface-50'"
          @click="setTab(t.value)"
        >
          {{ t.label }}
        </button>
      </div>

      <!-- Album (onglet Album) + recherche -->
      <div class="flex flex-col sm:flex-row gap-2">
        <select v-if="tab === 'album'" v-model="albumId" class="form-input sm:max-w-xs" @change="reload">
          <option :value="null" disabled>Choisir un album…</option>
          <option v-for="a in albums" :key="a.id" :value="a.id">{{ a.name }}</option>
        </select>
        <input
          v-model="q"
          type="search"
          class="form-input flex-1"
          placeholder="Rechercher (titre, album, personne)…"
          @input="debouncedReload"
        />
      </div>

      <!-- Grille -->
      <div class="overflow-y-auto -mx-4 sm:mx-0" :class="mobile ? 'max-h-[52vh]' : 'flex-1 min-h-[300px]'">
        <p v-if="hint" class="px-4 sm:px-0 py-6 text-sm text-surface-500 text-center">{{ hint }}</p>
        <div v-else class="grid grid-cols-3 sm:grid-cols-6 gap-0.5 sm:gap-1.5">
          <button
            v-for="m in items"
            :key="m.id"
            type="button"
            class="relative aspect-square overflow-hidden bg-surface-100 sm:rounded-lg"
            :class="indexOf(m.id) >= 0 ? 'ring-3 ring-inset ring-brand-500' : ''"
            :title="m.title || m.taken_at || ''"
            @click="toggle(m)"
          >
            <img :src="m.thumbnail_url" alt="" class="w-full h-full object-cover" loading="lazy" decoding="async" />
            <span
              v-if="indexOf(m.id) >= 0"
              class="absolute top-1 left-1 min-w-6 h-6 px-1.5 rounded-full bg-brand-600 text-snow text-xs font-bold flex items-center justify-center shadow"
            >{{ indexOf(m.id) + 1 }}</span>
            <span v-if="m.taken_at" class="absolute bottom-0 inset-x-0 px-1.5 py-0.5 text-[10px] text-snow bg-linear-to-t from-black/60 to-transparent text-left">
              {{ shortDate(m.taken_at) }}
            </span>
          </button>
        </div>
        <div v-if="nextPage && !hint" class="py-3 text-center">
          <button type="button" class="btn-secondary" :disabled="loading" @click="loadMore">
            {{ loading ? 'Chargement…' : 'Afficher plus' }}
          </button>
        </div>
        <p v-if="loading && !items.length" class="py-6 text-sm text-surface-500 text-center">Chargement…</p>
      </div>
    </div>

    <!-- Pied : compteur + valider -->
    <div
      class="flex items-center gap-3"
      :class="mobile ? 'pt-3 sticky bottom-0 bg-page' : 'px-6 py-4 border-t border-surface-200'"
    >
      <span class="text-sm text-surface-600 flex-1">
        {{ selected.length ? `${selected.length} photo${selected.length > 1 ? 's' : ''}, dans l'ordre choisi` : 'Touchez les photos à ajouter' }}
        <span v-if="selected.length >= max" class="text-amber-700"> (maximum {{ max }})</span>
      </span>
      <button type="button" class="btn-primary" @click="done">Valider</button>
    </div>
  </component>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import BottomSheet from '@/Components/BottomSheet.vue';
import { formatDate } from '@/utils/format';

const props = defineProps({
  open: { type: Boolean, default: false },
  // Fiche du moment : onglet « Avec {prénom} »
  personId: { type: String, default: null },
  personName: { type: String, default: '' },
  // Date du moment : onglet « Autour de … »
  around: { type: String, default: '' },
  // Sélection de départ : [{ id, thumbnail_url }]
  initial: { type: Array, default: () => [] },
  max: { type: Number, default: 24 },
});
const emit = defineEmits(['close', 'done']);

const mobile = typeof window !== 'undefined' && window.innerWidth < 640;

const firstName = computed(() => (props.personName || '').split(/\s+/)[0]);
const tabs = computed(() => [
  { value: 'recent', label: 'Récentes' },
  ...(props.around ? [{ value: 'date', label: `Autour du ${shortDate(props.around)}` }] : []),
  ...(props.personId ? [{ value: 'person', label: `Avec ${firstName.value || 'cette personne'}` }] : []),
  { value: 'album', label: 'Un album' },
]);

const tab = ref('recent');
const q = ref('');
const albumId = ref(null);
const albums = ref([]);
const items = ref([]);
const nextPage = ref(null);
const loading = ref(false);
const selected = ref([]);

const shortDate = (d) => formatDate(d);
const indexOf = (id) => selected.value.findIndex((s) => s.id === id);

const hint = computed(() => {
  if (tab.value === 'album' && !albumId.value) return 'Choisissez un album.';
  if (!loading.value && !items.value.length) return q.value ? 'Aucune photo ne correspond.' : 'Aucune photo ici.';
  return '';
});

let reqId = 0;
const fetchPage = async (page) => {
  // Toute nouvelle demande invalide celles en cours (réponse d'un autre onglet).
  const id = ++reqId;
  if (tab.value === 'album' && !albumId.value) {
    items.value = [];
    nextPage.value = null;
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await axios.get('/media/pick', {
      params: {
        tab: tab.value,
        around: tab.value === 'date' ? props.around : undefined,
        person_id: tab.value === 'person' ? props.personId : undefined,
        album_id: tab.value === 'album' ? albumId.value : undefined,
        q: q.value.trim() || undefined,
        page,
      },
    });
    if (id !== reqId) return;
    // Pas de doublon d'une page à l'autre (même date de prise de vue).
    const known = new Set(page === 1 ? [] : items.value.map((m) => m.id));
    items.value = page === 1 ? data.data : [...items.value, ...data.data.filter((m) => !known.has(m.id))];
    nextPage.value = data.next_page;
  } finally {
    if (id === reqId) loading.value = false;
  }
};
const reload = () => fetchPage(1);
const loadMore = () => nextPage.value && fetchPage(nextPage.value);
let timer = null;
const debouncedReload = () => {
  clearTimeout(timer);
  timer = setTimeout(reload, 300);
};

const setTab = async (value) => {
  tab.value = value;
  items.value = [];
  if (value === 'album' && !albums.value.length) {
    try {
      const { data } = await axios.get('/albums', { headers: { Accept: 'application/json' } });
      albums.value = data;
    } catch {
      albums.value = [];
    }
  }
  reload();
};

const toggle = (m) => {
  const i = indexOf(m.id);
  if (i >= 0) selected.value.splice(i, 1);
  else if (selected.value.length < props.max) selected.value.push({ id: m.id, thumbnail_url: m.thumbnail_url });
};

const done = () => emit('done', [...selected.value]);

// À chaque ouverture : sélection de départ, onglet le plus utile.
watch(() => props.open, (v) => {
  if (!v) return;
  selected.value = props.initial.map((p) => ({ id: p.id, thumbnail_url: p.thumbnail_url }));
  q.value = '';
  setTab(props.around ? 'date' : 'recent');
}, { immediate: true });
</script>
