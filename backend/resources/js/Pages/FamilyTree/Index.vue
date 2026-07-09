<template>
  <AppLayout title="Arbre genealogique">
    <div class="h-[calc(100vh-4rem)] flex">
      <!-- Sidebar -->
      <div class="w-80 bg-surface-100 border-r border-surface-200 p-4 overflow-y-auto shrink-0">
        <h2 class="text-xl font-semibold mb-4 text-surface-900">Arbre généalogique</h2>

        <!-- Person search -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-surface-700 mb-1">
            Centrer sur une personne
          </label>
          <div class="relative">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Rechercher..."
              class="form-input"
            />
            <div
              v-if="searchQuery && filteredPeople.length > 0"
              class="absolute z-10 mt-1 w-full bg-white border border-surface-200 rounded-lg shadow-warm-lg max-h-48 overflow-auto"
            >
              <button
                v-for="person in filteredPeople"
                :key="person.id"
                @click="centerOnPerson(person)"
                class="w-full text-left px-4 py-2 hover:bg-surface-50 text-sm text-surface-700"
              >
                {{ personLabel(person.data) }}
                <span v-if="person.data.birth_date" class="text-surface-400 ml-1">
                  ({{ person.data.birth_date.substring(0, 4) }})
                </span>
              </button>
            </div>
          </div>
        </div>

        <!-- Selected person detail -->
        <div v-if="selectedPerson" class="mt-4 p-4 bg-surface-50 rounded-lg border border-surface-100">
          <div class="flex items-center gap-3 mb-2">
            <img
              v-if="selectedPerson.data.avatar_url"
              :src="selectedPerson.data.avatar_url"
              :alt="personLabel(selectedPerson.data)"
              class="w-14 h-14 rounded-full object-cover border border-surface-200 shrink-0"
            />
            <div
              v-else
              class="w-14 h-14 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-lg font-semibold shrink-0"
            >
              {{ (selectedPerson.data.name || '?').charAt(0).toUpperCase() }}
            </div>
            <h3 class="font-semibold text-surface-900 leading-tight">{{ personLabel(selectedPerson.data) }}</h3>
          </div>
          <p v-if="selectedPerson.data.birth_date" class="text-sm text-surface-500 mt-1">
            Naissance : {{ formatDate(selectedPerson.data.birth_date) }}
            <span v-if="selectedPerson.data.birth_place"> — {{ selectedPerson.data.birth_place }}</span>
          </p>
          <p v-if="selectedPerson.data.death_date" class="text-sm text-surface-500">
            Décès : {{ formatDate(selectedPerson.data.death_date) }}
          </p>
          <Link
            :href="`/people/${selectedPerson.id}`"
            class="mt-3 inline-flex items-center text-sm text-brand-600 hover:text-brand-800"
          >
            Voir la fiche complète &rarr;
          </Link>
        </div>

        <!-- Stats -->
        <div class="mt-4 p-3 bg-brand-50 rounded-lg dark:bg-brand-500/10">
          <div class="text-sm text-surface-600">
            {{ treeNodes.length }} personne(s) dans l'arbre
          </div>
        </div>

        <p class="mt-4 text-xs text-surface-400 leading-relaxed">
          Molette pour zoomer, glisser pour déplacer. Cliquez sur une carte
          pour recentrer l'arbre sur cette personne.
        </p>
      </div>

      <!-- Tree container -->
      <div class="flex-1 relative bg-surface-50 overflow-hidden">
        <!-- Empty state -->
        <div v-if="!loading && treeNodes.length === 0" class="absolute inset-0 flex items-center justify-center">
          <div class="text-center">
            <svg class="mx-auto h-16 w-16 text-surface-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <p class="text-surface-500 mb-4">Aucune personne avec des liens familiaux.</p>
            <Link href="/people" class="btn-primary">Gérer les personnes</Link>
          </div>
        </div>

        <!-- family-chart mount point -->
        <div
          v-show="!loading && treeNodes.length > 0"
          id="ml-family-chart"
          ref="chartRef"
          class="f3 ml-tree w-full h-full"
        ></div>

        <!-- Loading -->
        <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-white/75 dark:bg-surface-50/75">
          <svg class="animate-spin h-8 w-8 text-brand-600" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';
import * as f3 from 'family-chart';
import 'family-chart/styles/family-chart.css';
import { personLabel } from '@/utils/personName';

const chartRef = ref(null);
const treeNodes = ref([]);      // données brutes (format backend) pour la sidebar
const loading = ref(true);
const searchQuery = ref('');
const selectedPerson = ref(null);

let chart = null;
let rawById = {};

const filteredPeople = computed(() => {
  if (!searchQuery.value) return [];
  const q = searchQuery.value.toLowerCase();
  return treeNodes.value
    .filter(n => n.data.name.toLowerCase().includes(q))
    .slice(0, 10);
});

// Année de naissance – décès, pour la 2e ligne de carte
function yearSpan(birth, death) {
  const b = birth ? birth.substring(0, 4) : '';
  const d = death ? death.substring(0, 4) : '';
  if (b && d) return `${b} – ${d}`;
  if (b) return b;
  if (d) return `† ${d}`;
  return '';
}

// Transforme le format backend (rels.father/mother) → format family-chart (rels.parents[])
// en filtrant toute référence vers une personne absente du jeu de données.
function toChartData(raw) {
  const known = new Set(raw.map(n => n.id));
  return raw.map(n => ({
    id: n.id,
    data: {
      // Nom composé (Prénom Nom (nom de naissance)) pour des cartes lisibles.
      name: personLabel(n.data),
      gender: n.data.gender,
      birth_date: n.data.birth_date,
      years: yearSpan(n.data.birth_date, n.data.death_date),
      // Bug corrigé : l'avatar n'était pas transmis -> les photos ne
      // s'affichaient jamais sur les cartes.
      avatar_url: n.data.avatar_url || null,
    },
    rels: {
      parents: [n.rels.father, n.rels.mother].filter(id => id && known.has(id)),
      spouses: (n.rels.spouses || []).filter(id => id && known.has(id)),
      children: (n.rels.children || []).filter(id => id && known.has(id)),
    },
  }));
}

// Choisit une personne « centrale » par défaut (max de liens directs).
function pickMainId(raw) {
  let best = raw[0];
  let bestScore = -1;
  for (const n of raw) {
    const score =
      (n.rels.father ? 1 : 0) +
      (n.rels.mother ? 1 : 0) +
      (n.rels.spouses?.length || 0) +
      (n.rels.children?.length || 0);
    if (score > bestScore) { bestScore = score; best = n; }
  }
  return best?.id;
}

function renderChart() {
  const raw = treeNodes.value;
  if (!raw.length || !chartRef.value) return;

  const data = toChartData(raw);

  chart = f3.createChart('#ml-family-chart', data)
    .setTransitionTime(700)
    .setCardXSpacing(300)
    .setCardYSpacing(190)
    .setOrientationVertical()
    .updateMainId(pickMainId(raw));

  chart.setCardHtml()
    .setCardDisplay([['name'], ['years']])
    .setCardImageField('avatar_url')
    // imageRect : carte rectangulaire avec photo À GAUCHE et texte à droite
    // (nom + années), pour TOUS — silhouette si pas de photo. Cartes agrandies.
    .setStyle('imageRect')
    .setCardDim({ w: 280, h: 96, img_w: 80, img_h: 80, img_x: 0, img_y: 0 })
    .setOnCardClick((e, d) => {
      const id = d?.data?.id || d?.id;
      if (id && rawById[id]) selectedPerson.value = rawById[id];
      if (id) {
        chart.updateMainId(id);
        chart.updateTree({ tree_position: 'main_to_middle' });
      }
    });

  chart.updateTree({ initial: true });
}

function centerOnPerson(person) {
  selectedPerson.value = rawById[person.id] || person;
  searchQuery.value = '';
  if (chart) {
    chart.updateMainId(person.id);
    chart.updateTree();
  }
}

function formatDate(dateString) {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
}

onMounted(async () => {
  try {
    const response = await axios.get('/family-tree/data');
    treeNodes.value = response.data;
    rawById = Object.fromEntries(treeNodes.value.map(n => [n.id, n]));
  } catch (error) {
    console.error('Erreur lors du chargement de l\'arbre:', error);
  } finally {
    loading.value = false;
  }
  // Attendre que v-show révèle le conteneur avant de monter le graphe
  await new Promise(r => requestAnimationFrame(r));
  renderChart();
});

onBeforeUnmount(() => {
  chart = null;
});
</script>

<style>
/* =============================================================================
   Thème family-chart — aligné sur le design « album de famille » (clair + sombre).
   family-chart expose des variables CSS sur .f3 (thème sombre par défaut) : on les
   surcharge. Double sélecteur (.ml-tree.f3 / .ml-tree .f3) pour couvrir les deux
   structures DOM possibles et gagner en spécificité sur les styles de la lib.
   ============================================================================= */
.ml-tree.f3,
.ml-tree .f3 {
  --male-color: #dbe6ef;
  --female-color: #f3dde3;
  --genderless-color: #e7e5e4;
  --background-color: #ffffff;
  --text-color: #1c1917;
  color: #1c1917;
  font-family: var(--font-sans);
}

/* Liens de filiation / mariage */
.ml-tree .link { stroke: #d6d3d1; }
/* Contour de la personne centrée → doré (accent brand) */
.ml-tree .card-main-outline { stroke: var(--color-brand-500, #f59e0b); stroke-width: 2px; }
/* Texte des cartes en pierre foncée quel que soit le genre */
.ml-tree .card-inner .card-label,
.ml-tree svg.main_svg text { fill: #292524; }

/* Lisibilité des cartes : coins arrondis, ombre douce, photo + texte alignés */
.ml-tree .card-inner { border-radius: 10px; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.14); }
.ml-tree .card-image-rect {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 10px;
}
/* Photo carrée arrondie, jamais déformée */
.ml-tree .card-image-rect img,
.ml-tree .card-image-rect .person-icon {
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
}
/* Bloc texte : nom en gras + années plus discrètes */
.ml-tree .card-image-rect .card-label { line-height: 1.25; }
.ml-tree .card-image-rect .card-label > div:first-child { font-weight: 600; font-size: 14px; }
.ml-tree .card-image-rect .card-label > div:last-child { font-size: 12px; opacity: 0.8; }

/* --- Dark mode --- */
:root[data-theme='dark'] .ml-tree.f3,
:root[data-theme='dark'] .ml-tree .f3 {
  --male-color: #2b3a44;
  --female-color: #432b33;
  --genderless-color: #3a332a;
  --background-color: #211d17;
  --text-color: #f7f3ec;
  color: #f7f3ec;
}
:root[data-theme='dark'] .ml-tree .link { stroke: #4d453a; }
:root[data-theme='dark'] .ml-tree .card-inner .card-label,
:root[data-theme='dark'] .ml-tree svg.main_svg text { fill: #f0ebe2; }
</style>
