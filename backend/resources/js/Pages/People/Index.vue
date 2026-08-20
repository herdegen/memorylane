<template>
  <Head title="Personnes" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
          <div>
            <h1 class="text-display text-4xl text-surface-900">Personnes</h1>
            <p class="mt-1 text-sm text-surface-500">Gérez les personnes présentes sur vos médias</p>
          </div>
          <button
            @click="showCreateModal = true"
            class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Ajouter une personne
          </button>
        </div>

        <!-- Sort bar -->
        <div v-if="people.length > 0" class="flex flex-wrap items-center gap-3 mb-6">
          <label class="text-sm font-medium text-surface-600">Trier par</label>
          <div class="inline-flex rounded-lg border border-surface-200 bg-white p-0.5">
            <button
              v-for="opt in sortOptions"
              :key="opt.value"
              @click="sortBy = opt.value"
              class="px-3 py-1.5 text-sm rounded-md transition-colors"
              :class="sortBy === opt.value
                ? 'bg-brand-600 text-white'
                : 'text-surface-600 hover:bg-surface-100'"
            >
              {{ opt.label }}
            </button>
          </div>
          <button
            @click="toggleDir"
            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg border border-surface-200 bg-white text-surface-600 hover:bg-surface-100 transition-colors"
            :title="sortDir === 'asc' ? 'Ordre croissant' : 'Ordre décroissant'"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-if="sortDir === 'asc'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9M3 12h5m4 4l4 4m0 0l4-4m-4 4V4" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9M3 12h5m4 8l4-4m0 0l-4-4m4 4V4" />
            </svg>
            {{ sortDir === 'asc' ? 'Croissant' : 'Décroissant' }}
          </button>
          <p
            v-if="sortBy === 'proximity' && !selfPersonId"
            class="text-xs text-surface-500"
          >
            Astuce : ouvrez une fiche et cliquez « C'est moi » pour trier par proximité familiale.
          </p>
        </div>

        <!-- People Grid -->
        <div
          v-if="people.length > 0"
          class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
        >
          <div
            v-for="person in sortedPeople"
            :key="person.id"
            class="bg-white rounded-card border border-surface-200 shadow-warm-sm overflow-hidden cursor-pointer hover:shadow-warm-md hover:border-brand-200 hover:scale-[1.01] transition-all duration-200"
            @click="goToPerson(person)"
          >
            <!-- Avatar -->
            <div class="aspect-square bg-brand-100 flex items-center justify-center">
              <img
                v-if="person.avatar_url"
                :src="person.avatar_url"
                :alt="person.name"
                class="w-full h-full object-cover"
              />
              <span
                v-else
                class="text-6xl font-bold text-brand-700"
              >
                {{ person.name.charAt(0).toUpperCase() }}
              </span>
            </div>

            <!-- Info -->
            <div class="p-4">
              <h3 class="text-lg font-semibold text-surface-900 truncate">
                <span v-if="person.gender === 'M'" class="text-surface-400">&#9794;</span>
                <span v-else-if="person.gender === 'F'" class="text-surface-400">&#9792;</span>
                {{ personLabel(person) }}
              </h3>
              <p class="text-sm text-surface-500 mt-1">
                {{ person.media_count }} {{ person.media_count === 1 ? 'média' : 'médias' }}
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
          <h3 class="mt-4 text-lg font-medium text-surface-900">Aucune personne</h3>
          <p class="mt-2 text-surface-500">Ajoutez des personnes pour les tagger sur vos photos.</p>
          <button
            @click="showCreateModal = true"
            class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 rounded-lg hover:bg-brand-100"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Ajouter une personne
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
import { personLabel } from '@/utils/personName';
import { formatLongDate } from '@/utils/format';

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

const sortOptions = [
  { value: 'proximity', label: 'Proximité' },
  { value: 'last_name', label: 'Nom' },
  { value: 'first_name', label: 'Prénom' },
  { value: 'birth_year', label: 'Année de naissance' },
];
const sortBy = ref('proximity');
const sortDir = ref('asc'); // 'asc' | 'desc'

const collator = new Intl.Collator('fr', { sensitivity: 'base', numeric: true });
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

const goToPerson = (person) => {
  router.visit(`/people/${person.id}`);
};

const handlePersonCreated = () => {
  router.reload();
};
</script>
