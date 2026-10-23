<template>
  <Head :title="peopleLabel" />
  <AppLayout>
    <PeopleSegments />
    <div class="py-1 sm:py-12">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header (téléphone : titre compact + compteur, bouton « + » rond) -->
        <div class="flex justify-between items-center mb-3 sm:mb-8">
          <div class="min-w-0">
            <h1 class="text-display text-[26px] sm:text-4xl text-surface-900 truncate">
              {{ peopleLabel }}
              <span class="sm:hidden font-sans text-sm font-semibold text-surface-500 ml-1">{{ people.length }}</span>
            </h1>
            <p class="mt-1 text-sm text-surface-500 hidden sm:block">{{ $t('Gérez les personnes présentes sur vos médias') }}</p>
          </div>
          <button
            type="button"
            class="sm:hidden w-10 h-10 rounded-full bg-brand-600 text-white flex items-center justify-center shrink-0"
            :aria-label="$t('Ajouter une personne')"
            @click="showCreateModal = true"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
          </button>
          <button
            @click="showCreateModal = true"
            class="hidden sm:inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            {{ $t('Ajouter une personne') }}
          </button>
        </div>

        <!-- Tri (téléphone : puces sur une ligne qui défile + sens) -->
        <div v-if="people.length > 0" class="flex flex-nowrap sm:flex-wrap items-center gap-2 sm:gap-3 mb-3 sm:mb-6 overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 sm:overflow-visible [&>*]:shrink-0">
          <label class="text-sm font-medium text-surface-600 hidden sm:block">{{ $t('Trier par') }}</label>
          <div class="flex gap-2 sm:inline-flex sm:gap-0 sm:rounded-lg sm:border sm:border-surface-200 sm:bg-white sm:p-0.5">
            <button
              v-for="opt in sortOptions"
              :key="opt.value"
              @click="sortBy = opt.value"
              class="px-3.5 py-1.5 text-[13px] sm:text-sm rounded-full sm:rounded-md border sm:border-0 whitespace-nowrap transition-colors"
              :class="sortBy === opt.value
                ? 'bg-brand-600 border-brand-600 text-white font-semibold'
                : 'bg-white border-surface-300 sm:bg-transparent text-surface-600 hover:bg-surface-100'"
            >
              {{ opt.label }}
            </button>
          </div>
          <button
            @click="toggleDir"
            class="inline-flex items-center gap-1 px-3 py-1.5 text-[13px] sm:text-sm rounded-full sm:rounded-lg border border-surface-300 sm:border-surface-200 bg-white text-surface-600 hover:bg-surface-100 transition-colors"
            :title="sortDir === 'asc' ? $t('Ordre croissant') : $t('Ordre décroissant')"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-if="sortDir === 'asc'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9M3 12h5m4 4l4 4m0 0l4-4m-4 4V4" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9M3 12h5m4 8l4-4m0 0l-4-4m4 4V4" />
            </svg>
            <span class="hidden sm:inline">{{ sortDir === 'asc' ? $t('Croissant') : $t('Décroissant') }}</span>
          </button>
          <p
            v-if="sortBy === 'proximity' && !selfPersonId && !isFriends"
            class="text-xs text-surface-500 hidden sm:block"
          >
            {{ $t("Astuce : ouvrez une fiche et cliquez « C'est moi » pour trier par proximité familiale.") }}
          </p>
        </div>

        <!-- Téléphone : liste de lignes (avatar, nom, parenté · photos) -->
        <div v-if="people.length > 0" class="sm:hidden -mx-4 bg-white border-y border-surface-200 divide-y divide-surface-100">
          <button
            v-for="person in sortedPeople"
            :key="person.id"
            type="button"
            class="w-full flex items-center gap-3 px-4 h-[68px] text-left active:bg-surface-50"
            @click="goToPerson(person)"
          >
            <PersonAvatar :person="person" :size="12" class="shrink-0" />
            <span class="flex-1 min-w-0">
              <span class="block text-[15px] font-bold text-surface-900 truncate">{{ personLabel(person) }}</span>
              <span class="block text-xs text-surface-500 truncate">{{ personMeta(person) }}</span>
            </span>
            <span class="text-surface-400">›</span>
          </button>
        </div>

        <!-- People Grid (desktop) -->
        <div
          v-if="people.length > 0"
          class="hidden sm:grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
        >
          <div
            v-for="person in sortedPeople"
            :key="person.id"
            class="bg-white rounded-card border border-surface-200 shadow-warm-sm overflow-hidden cursor-pointer hover:shadow-warm-md hover:border-brand-200 hover:scale-[1.01] transition-all duration-200"
            @click="goToPerson(person)"
          >
            <!-- Avatar -->
            <PersonAvatar
              :person="person"
              :alt="person.name"
              shape="none"
              class="aspect-square bg-brand-100"
              fallback-class="text-6xl font-bold text-brand-700"
            />

            <!-- Info -->
            <div class="p-4">
              <h3 class="text-lg font-semibold text-surface-900 truncate">
                <span v-if="person.gender === 'M'" class="text-surface-400">&#9794;</span>
                <span v-else-if="person.gender === 'F'" class="text-surface-400">&#9792;</span>
                {{ personLabel(person) }}
              </h3>
              <p class="text-sm text-surface-500 mt-1">
                {{ $tc(':count média|:count médias', person.media_count) }}
              </p>
              <p v-if="person.birth_date" class="text-xs text-surface-400 mt-1">
                {{ formatLongDate(person.birth_date) }}
                {{ person.death_date ? ' - ' + formatLongDate(person.death_date) : '' }}
              </p>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div
          v-else
          class="text-center py-16 bg-white rounded-lg shadow-xs"
        >
          <svg
            class="mx-auto h-16 w-16 text-surface-300"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="1.5"
              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
            />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-surface-900">{{ $t('Aucune personne') }}</h3>
          <p class="mt-2 text-surface-500">{{ $t('Ajoutez des personnes pour les tagger sur vos photos.') }}</p>
          <button
            @click="showCreateModal = true"
            class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 rounded-lg hover:bg-brand-100"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            {{ $t('Ajouter une personne') }}
          </button>
        </div>

        <!-- Create Modal -->
        <PersonFormModal
          v-if="showCreateModal"
          @close="showCreateModal = false"
          @created="handlePersonCreated"
        />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PersonFormModal from '@/Components/PersonFormModal.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import PeopleSegments from '@/Components/PeopleSegments.vue';
import { personLabel } from '@/utils/personName';
import { formatLongDate } from '@/utils/format';
import { useCircle } from '@/Composables/useCircle';
import { t, tc, intlLocale } from '@/i18n';

const { peopleLabel, isFriends } = useCircle();

const props = defineProps({
  people: {
    type: Array,
    default: () => [],
  },
  selfPersonId: {
    type: [String, Number, null],
    default: null,
  },
});

const showCreateModal = ref(false);

// Amis : pas de parenté — on trie par photos (les plus présents d'abord).
const sortOptions = computed(() => [
  isFriends.value
    ? { value: 'photos', label: t('Les plus photographiés') }
    : { value: 'proximity', label: t('Proximité') },
  { value: 'last_name', label: t('Nom') },
  { value: 'first_name', label: t('Prénom') },
  { value: 'birth_year', label: t('Année de naissance') },
]);
const sortBy = ref(isFriends.value ? 'photos' : 'proximity');
const sortDir = ref('asc'); // 'asc' | 'desc'

const collator = new Intl.Collator(intlLocale(), { sensitivity: 'base', numeric: true });
const byName = (a, b) => collator.compare(a.name || '', b.name || '');

// nulls toujours en fin, quel que soit le sens
const nullsLast = (av, bv, cmp) => {
  const an = av === null || av === undefined || av === '';
  const bn = bv === null || bv === undefined || bv === '';
  if (an && bn) return 0;
  if (an) return 1;
  if (bn) return -1;
  return cmp(av, bv);
};

const toggleDir = () => {
  sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
};

const sortedPeople = computed(() => {
  const list = [...props.people];
  const dir = sortDir.value === 'desc' ? -1 : 1;

  // Comparaison primaire selon le critère (le sens `dir` ne s'applique qu'aux
  // valeurs présentes ; les valeurs manquantes restent toujours en fin).
  const primary = (a, b) => {
    switch (sortBy.value) {
      case 'last_name':
        return dir * collator.compare(a.last_name || '', b.last_name || '');
      case 'first_name':
        return dir * collator.compare(a.first_name || '', b.first_name || '');
      case 'birth_year':
        return nullsLast(a.birth_date, b.birth_date, (x, y) => dir * String(x).localeCompare(String(y)));
      case 'photos':
        return dir * ((b.media_count || 0) - (a.media_count || 0));
      case 'proximity':
      default:
        // Avec une fiche « moi » : distance de parenté (proches d'abord en asc).
        // Sans : les plus reliés (nb de proches directs) d'abord en asc.
        if (props.selfPersonId) {
          return nullsLast(a.proximity, b.proximity, (x, y) => dir * (x - y));
        }
        return dir * ((b.relatives_count || 0) - (a.relatives_count || 0));
    }
  };

  return list.sort((a, b) => primary(a, b) || byName(a, b));
});

// Sous-titre d'une ligne (téléphone) : « Moi » / parenté, années, photos.
const capitalize = (t) => t.charAt(0).toUpperCase() + t.slice(1);
const personMeta = (person) => {
  const parts = [];
  if (person.is_self) parts.push(t('Moi'));
  else if (person.relation_label) parts.push(capitalize(person.relation_label));
  if (person.birth_date) {
    const birth = String(person.birth_date).slice(0, 4);
    parts.push(person.death_date ? `${birth} – ${String(person.death_date).slice(0, 4)}` : birth);
  }
  const n = person.media_count || 0;
  if (n) parts.push(tc(':count photo|:count photos', n));
  return parts.join(' · ');
};

const goToPerson = (person) => {
  router.visit(`/people/${person.id}`);
};

const handlePersonCreated = () => {
  router.reload();
};
</script>
