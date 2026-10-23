<template>
  <div ref="rootRef" class="space-y-3">
    <!-- Sur mobile, les panneaux s'ancrent à toute la barre (pleine largeur),
         sur écran large sous leur bouton. -->
    <div class="relative flex flex-wrap items-center gap-2">
      <!-- Tri -->
      <label class="sr-only" for="media-sort">{{ $t('Trier') }}</label>
      <select id="media-sort" v-model="sortModel" class="form-select !w-auto !py-1.5 text-sm">
        <option v-for="opt in SORTS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>

      <!-- Date -->
      <div class="sm:relative">
        <button type="button" :class="pillClass(dateActive)" @click="toggle('date')">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
          {{ $t('Date') }}
          <span v-if="dateActive ? 1 : 0" class="min-w-5 h-5 px-1 inline-flex items-center justify-center rounded-full bg-brand-600 text-white text-xs">{{ dateActive ? 1 : 0 }}</span>
          <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
        </button>
        <div v-if="open === 'date'" :class="[panelClass, 'sm:w-80']">
          <p class="text-xs font-semibold uppercase tracking-wide text-surface-500 mb-2">{{ $t('Période de prise de vue') }}</p>
          <div class="flex items-center gap-2">
            <select v-model.number="yearFrom" class="form-select !py-1.5 text-sm" :aria-label="$t('De l\'année')">
              <option :value="null">{{ $t('Début') }}</option>
              <option v-for="y in yearsAsc" :key="y" :value="y">{{ y }}</option>
            </select>
            <span class="text-surface-400 text-sm shrink-0">{{ $t('à') }}</span>
            <select v-model.number="yearTo" class="form-select !py-1.5 text-sm" :aria-label="$t('À l\'année')">
              <option :value="null">{{ $t('Aujourd\'hui') }}</option>
              <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
          </div>
          <button type="button" class="btn-primary btn-sm mt-3 w-full justify-center" @click="applyPeriod">
            {{ $t('Afficher cette période') }}
          </button>
          <div class="dropdown-divider !my-3" />
          <button type="button" :class="optionClass(state.undated)" @click="pick({ undated: !state.undated, yearFrom: null, yearTo: null })">
            <span>{{ $t('Sans date') }}</span>
            <span class="text-xs text-surface-400">{{ counts.undated }}</span>
          </button>
        </div>
      </div>

      <!-- Personnes -->
      <div class="sm:relative">
        <button type="button" :class="pillClass(peopleActive)" @click="toggle('people')">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          {{ $t('Personnes') }}
          <span v-if="peopleCount" class="min-w-5 h-5 px-1 inline-flex items-center justify-center rounded-full bg-brand-600 text-white text-xs">{{ peopleCount }}</span>
          <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
        </button>
        <div v-if="open === 'people'" :class="[panelClass, 'sm:w-80']">
          <button type="button" :class="optionClass(state.peopleMode === 'unidentified')" @click="pick({ peopleMode: state.peopleMode === 'unidentified' ? null : 'unidentified', people: [] })">
            <span>{{ $t('Visages à identifier') }}</span>
            <span class="text-xs text-surface-400">{{ counts.unidentified }}</span>
          </button>
          <button type="button" :class="optionClass(state.peopleMode === 'none')" @click="pick({ peopleMode: state.peopleMode === 'none' ? null : 'none', people: [] })">
            <span>{{ $t('Aucune personne identifiée') }}</span>
            <span class="text-xs text-surface-400">{{ counts.no_people }}</span>
          </button>
          <div class="dropdown-divider !my-3" />
          <p class="text-xs font-semibold uppercase tracking-wide text-surface-500 mb-2">
            {{ $t('Avec…') }}
            <span class="normal-case font-normal tracking-normal">{{ $t('(plusieurs = ensemble sur la photo)') }}</span>
          </p>
          <input
            ref="peopleSearchRef"
            v-model="peopleQuery"
            type="search"
            :placeholder="$t('Chercher une personne')"
            class="form-input !py-1.5 text-sm mb-2"
          />
          <p v-if="peopleLoading" class="text-sm text-surface-500 py-2">{{ $t('Chargement…') }}</p>
          <p v-else-if="peopleError" class="text-sm text-red-600 dark:text-red-400 py-2">{{ peopleError }}</p>
          <ul v-else class="max-h-64 overflow-y-auto -mx-1">
            <li v-for="p in filteredPeople" :key="p.id">
              <label class="flex items-center gap-2.5 px-2 py-1.5 rounded-lg text-sm text-surface-700 hover:bg-surface-50 cursor-pointer">
                <input
                  type="checkbox"
                  class="rounded border-surface-300 text-brand-600 focus:ring-brand-500"
                  :checked="state.people.includes(p.id)"
                  @change="togglePerson(p)"
                />
                <span class="flex-1 truncate">{{ personLabel(p) }}<span v-if="p.birth_date" class="text-surface-400"> · {{ p.birth_date.slice(0, 4) }}</span></span>
                <span class="text-xs text-surface-400">{{ p.media_count }}</span>
              </label>
            </li>
            <li v-if="!filteredPeople.length" class="px-2 py-2 text-sm text-surface-500">{{ $t('Aucune personne trouvée.') }}</li>
          </ul>
        </div>
      </div>

      <!-- Tags -->
      <div v-if="tags.length" class="sm:relative">
        <button type="button" :class="pillClass(state.tags.length > 0)" @click="toggle('tags')">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
          {{ $t('Tags') }}
          <span v-if="state.tags.length" class="min-w-5 h-5 px-1 inline-flex items-center justify-center rounded-full bg-brand-600 text-white text-xs">{{ state.tags.length }}</span>
          <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
        </button>
        <div v-if="open === 'tags'" :class="[panelClass, 'sm:w-80']">
          <div class="flex flex-wrap gap-2">
            <button
              v-for="tag in tags"
              :key="tag.id"
              type="button"
              :class="[
                'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium transition',
                state.tags.includes(tag.id) ? 'text-white' : 'bg-surface-100 text-surface-700 hover:bg-surface-200'
              ]"
              :style="state.tags.includes(tag.id) ? { backgroundColor: tag.color || '#0D9488' } : {}"
              @click="toggleTag(tag.id)"
            >
              {{ tag.name }}
              <span class="ml-1.5 text-xs opacity-75">({{ tag.media_count }})</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Filtres actifs -->
    <div v-if="chips.length" class="flex flex-wrap items-center gap-2">
      <span
        v-for="chip in chips"
        :key="chip.key"
        class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 dark:bg-brand-500/15 border border-brand-200 dark:border-brand-500/30 pl-3 pr-1.5 py-1 text-sm text-brand-800 dark:text-brand-200"
      >
        {{ chip.label }}
        <button
          type="button"
          class="w-5 h-5 inline-flex items-center justify-center rounded-full hover:bg-brand-100 dark:hover:bg-brand-500/25"
          :aria-label="$t('Retirer le filtre :label', { label: chip.label })"
          @click="emit('change', chip.remove)"
        >
          <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </span>
      <button
        v-if="chips.length > 1"
        type="button"
        class="text-sm font-medium text-surface-500 hover:text-surface-800"
        @click="emit('change', CLEARED)"
      >
        {{ $t('Tout effacer') }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { personLabel } from '@/utils/personName';
import { searchPeople } from '@/utils/personSearch';
import { apiError } from '@/utils/apiError';
import { t } from '@/i18n';

// Barre de tri/filtres de la galerie « Mes photos ». Ne navigue pas
// elle-même : émet `change` avec les champs modifiés, la page recharge.
const props = defineProps({
  // { sort, yearFrom, yearTo, undated, people: [ids], peopleMode, tags: [ids] }
  state: { type: Object, required: true },
  years: { type: Array, default: () => [] },
  counts: { type: Object, default: () => ({}) },
  tags: { type: Array, default: () => [] },
  // Noms connus des personnes filtrées (pour les pastilles avant chargement de la liste)
  knownPeople: { type: Array, default: () => [] },
});

const emit = defineEmits(['change']);

const SORTS = [
  { value: 'recent', label: t('Plus récentes d\'abord') },
  { value: 'oldest', label: t('Plus anciennes d\'abord') },
  { value: 'added', label: t('Dernières ajoutées') },
];
const sortModel = computed({
  get: () => props.state.sort,
  set: (sort) => emit('change', { sort }),
});
const CLEARED = { yearFrom: null, yearTo: null, undated: false, people: [], peopleMode: null, tags: [] };

const open = ref(null);
const rootRef = ref(null);
const toggle = (name) => {
  open.value = open.value === name ? null : name;
};
const onClickOutside = (e) => {
  if (rootRef.value && !rootRef.value.contains(e.target)) open.value = null;
};
onMounted(() => document.addEventListener('click', onClickOutside));
onUnmounted(() => document.removeEventListener('click', onClickOutside));

const pick = (patch) => {
  open.value = null;
  emit('change', patch);
};

// --- Date -------------------------------------------------------------------
const yearFrom = ref(props.state.yearFrom);
const yearTo = ref(props.state.yearTo);
watch(() => [props.state.yearFrom, props.state.yearTo], ([from, to]) => {
  yearFrom.value = from;
  yearTo.value = to;
});
const yearsAsc = computed(() => [...props.years].reverse());

const applyPeriod = () => {
  let [from, to] = [yearFrom.value || null, yearTo.value || null];
  if (from && to && from > to) [from, to] = [to, from];
  pick({ yearFrom: from, yearTo: to, undated: false });
};

const dateActive = computed(() => !!(props.state.undated || props.state.yearFrom || props.state.yearTo));
const dateLabel = computed(() => {
  const { undated, yearFrom: from, yearTo: to } = props.state;
  if (undated) return t('Sans date');
  if (from && to) return from === to ? t('En :year', { year: from }) : `${from} – ${to}`;
  if (from) return t('Depuis :year', { year: from });
  if (to) return t('Jusqu\'en :year', { year: to });
  return '';
});

// --- Personnes ----------------------------------------------------------------
const people = ref(null); // chargées à la première ouverture
const peopleLoading = ref(false);
const peopleError = ref(null);
const peopleQuery = ref('');
const peopleSearchRef = ref(null);

const loadPeople = async () => {
  if (people.value || peopleLoading.value) return;
  peopleLoading.value = true;
  peopleError.value = null;
  try {
    const { data } = await axios.get('/media/people');
    people.value = data.people;
  } catch (e) {
    peopleError.value = apiError(e, t('Impossible de charger la liste des personnes.'));
  } finally {
    peopleLoading.value = false;
  }
};

watch(open, async (name) => {
  if (name !== 'people') return;
  loadPeople();
  await nextTick();
  peopleSearchRef.value?.focus();
});

const filteredPeople = computed(() => searchPeople(peopleQuery.value, people.value || [], personLabel));

const nameOf = (id) =>
  (people.value || []).find((p) => p.id === id)?.name
  || props.knownPeople.find((p) => p.id === id)?.name
  || t('Personne');

// Cocher une personne ne ferme pas le panneau : on peut en cocher plusieurs.
const togglePerson = (p) => {
  const ids = props.state.people.includes(p.id)
    ? props.state.people.filter((id) => id !== p.id)
    : [...props.state.people, p.id];
  emit('change', { people: ids, peopleMode: null });
};

const peopleCount = computed(() => props.state.people.length + (props.state.peopleMode ? 1 : 0));
const peopleActive = computed(() => peopleCount.value > 0);
const MODE_LABELS = { unidentified: t('Visages à identifier'), none: t('Aucune personne identifiée') };

// --- Tags ---------------------------------------------------------------------
const toggleTag = (tagId) => {
  const ids = props.state.tags.includes(tagId)
    ? props.state.tags.filter((id) => id !== tagId)
    : [...props.state.tags, tagId];
  emit('change', { tags: ids });
};

// --- Pastilles des filtres actifs --------------------------------------------
const chips = computed(() => {
  const list = [];
  if (dateActive.value) {
    list.push({ key: 'date', label: dateLabel.value, remove: { yearFrom: null, yearTo: null, undated: false } });
  }
  if (props.state.peopleMode) {
    list.push({ key: 'mode', label: MODE_LABELS[props.state.peopleMode], remove: { peopleMode: null } });
  }
  props.state.people.forEach((id) => {
    list.push({
      key: `p-${id}`,
      label: t('Avec :name', { name: nameOf(id) }),
      remove: { people: props.state.people.filter((x) => x !== id) },
    });
  });
  props.state.tags.forEach((id) => {
    const tag = props.tags.find((tg) => tg.id === id);
    list.push({
      key: `t-${id}`,
      label: tag ? `#${tag.name}` : t('Tag'),
      remove: { tags: props.state.tags.filter((x) => x !== id) },
    });
  });
  return list;
});

// --- Styles -------------------------------------------------------------------
const pillClass = (active) => [
  'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition max-w-[16rem]',
  active
    ? 'bg-brand-50 dark:bg-brand-500/15 border-brand-300 dark:border-brand-500/40 text-brand-800 dark:text-brand-200'
    : 'bg-white border-surface-300 text-surface-700 hover:border-surface-400',
];
const panelClass = 'absolute left-0 right-0 sm:right-auto top-full mt-2 z-30 sm:w-72 rounded-xl bg-white p-3 shadow-warm-lg ring-1 ring-surface-200';
const optionClass = (active) => [
  'w-full flex items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-left text-sm transition',
  active ? 'bg-brand-50 dark:bg-brand-500/15 text-brand-800 dark:text-brand-200 font-medium' : 'text-surface-700 hover:bg-surface-50',
];
</script>
