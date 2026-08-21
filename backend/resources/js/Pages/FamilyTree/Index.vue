<template>
  <Head title="Arbre généalogique" />
  <AppLayout title="Arbre genealogique">
    <div class="h-[calc(100vh-4rem)] flex relative">
      <!-- Backdrop (mobile, tiroir ouvert) -->
      <div
        v-if="drawerOpen"
        @click="drawerOpen = false"
        class="md:hidden fixed inset-x-0 top-16 bottom-0 z-20 bg-black/40"
        aria-hidden="true"
      ></div>

      <!-- Sidebar — colonne fixe sur desktop, tiroir escamotable sur mobile -->
      <div
        class="w-80 bg-surface-100 border-r border-surface-200 p-4 overflow-y-auto shrink-0
               max-md:fixed max-md:top-16 max-md:bottom-0 max-md:left-0 max-md:z-30
               max-md:w-[85%] max-md:max-w-xs max-md:shadow-warm-lg
               max-md:transition-transform max-md:duration-300"
        :class="drawerOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full'"
      >
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xl font-semibold text-surface-900">Arbre généalogique</h2>
          <button
            @click="drawerOpen = false"
            class="md:hidden -mr-1 p-1 text-surface-500 hover:text-surface-800"
            aria-label="Fermer le panneau"
          >
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

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
            Naissance : {{ formatLongDate(selectedPerson.data.birth_date) }}
            <span v-if="selectedPerson.data.birth_place"> — {{ selectedPerson.data.birth_place }}</span>
          </p>
          <p v-if="selectedPerson.data.death_date" class="text-sm text-surface-500">
            Décès : {{ formatLongDate(selectedPerson.data.death_date) }}
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
          <span class="max-md:hidden">Molette pour zoomer, glisser pour déplacer.</span>
          <span class="md:hidden">Pincez pour zoomer, glissez à un doigt pour déplacer.</span>
          Touchez une carte pour recentrer l'arbre sur cette personne.
        </p>
      </div>

      <!-- Tree container -->
      <div class="flex-1 relative bg-surface-50 overflow-hidden">
        <!-- Bouton d'ouverture du panneau (mobile) -->
        <button
          @click="drawerOpen = true"
          class="md:hidden absolute top-3 left-3 z-10 inline-flex items-center gap-1.5
                 rounded-full bg-white/90 backdrop-blur px-3 py-2 text-sm font-medium
                 text-surface-700 shadow-warm border border-surface-200
                 dark:bg-surface-100/90"
          aria-label="Ouvrir le panneau (recherche et infos)"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
          Rechercher
        </button>

        <!-- Contrôles de vue : tout voir / recentrer -->
        <div
          v-show="!loading && treeNodes.length > 0"
          class="absolute bottom-4 right-4 z-10 flex flex-col gap-2"
        >
          <button
            @click="fitAll"
            class="inline-flex items-center gap-1.5 rounded-full bg-white/90 backdrop-blur
                   px-3 py-2 text-sm font-medium text-surface-700 shadow-warm
                   border border-surface-200 hover:bg-white dark:bg-surface-100/90"
            aria-label="Voir tout l'arbre"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V6a2 2 0 012-2h2M4 16v2a2 2 0 002 2h2m8-16h2a2 2 0 012 2v2m-4 12h2a2 2 0 002-2v-2" />
            </svg>
            <span class="max-md:hidden">Tout voir</span>
          </button>
          <button
            @click="recenter"
            class="inline-flex items-center gap-1.5 rounded-full bg-white/90 backdrop-blur
                   px-3 py-2 text-sm font-medium text-surface-700 shadow-warm
                   border border-surface-200 hover:bg-white dark:bg-surface-100/90"
            aria-label="Recentrer sur la personne sélectionnée"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v8m-4-4h8M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span class="max-md:hidden">Recentrer</span>
          </button>
        </div>

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

        <!-- Marche du lien de parenté (arrivée via « Lien de parenté » d'une fiche) -->
        <div
          v-if="kinship"
          class="absolute bottom-6 left-1/2 -translate-x-1/2 z-30 w-[min(92%,560px)] bg-white/95 backdrop-blur border border-surface-200 rounded-2xl shadow-warm-lg px-5 py-4"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold uppercase tracking-widest text-brand-700">Lien de parenté</p>
              <p class="text-sm font-semibold text-surface-900 mt-0.5">
                <template v-if="kinship.found">
                  {{ kinship.path[kinship.path.length - 1].name }} est
                  {{ kinship.relation_label || `relié·e à vous en ${kinship.steps} lien${kinship.steps > 1 ? 's' : ''}` }}
                </template>
                <template v-else>Aucun lien de parenté trouvé dans l'arbre.</template>
              </p>
            </div>
            <button @click="stopKinship" class="text-surface-400 hover:text-surface-600 shrink-0" aria-label="Fermer">
              <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>

          <template v-if="kinship.found">
            <!-- Fil du chemin : la personne courante est mise en avant -->
            <div class="mt-3 flex items-center gap-1 flex-wrap text-xs">
              <template v-for="(p, i) in kinship.path" :key="p.id">
                <span
                  class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full transition-all"
                  :class="i === kinshipStep
                    ? 'bg-brand-600 text-white font-semibold'
                    : i < kinshipStep ? 'bg-brand-100 dark:bg-brand-500/15 text-brand-800 dark:text-brand-300' : 'bg-surface-100 text-surface-500'"
                >
                  <img v-if="p.avatar_url" :src="p.avatar_url" class="w-4.5 h-4.5 rounded-full object-cover" />
                  {{ i === 0 ? 'Vous' : p.name.split(' ')[0] }}
                </span>
                <svg v-if="i < kinship.path.length - 1" class="w-3 h-3 text-surface-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" /></svg>
              </template>
            </div>
            <div class="mt-2 flex items-center justify-between gap-3">
              <p class="text-xs text-surface-500 min-h-4">
                <template v-if="kinshipApexView">
                  Vue depuis {{ kinship.path[kinship.apex_index].name.split(' ')[0] }}, votre ancêtre commun — les deux branches sont en surbrillance.
                </template>
                <template v-else-if="kinshipStep > 0">… {{ kinship.edge_labels[kinshipStep - 1] }}</template>
                <template v-else>Départ : votre fiche</template>
              </p>
              <div class="flex gap-2 shrink-0">
                <button
                  v-if="kinshipStep < kinship.path.length - 1"
                  @click="finishKinshipWalk"
                  class="text-xs font-medium text-surface-500 hover:text-surface-700"
                >Passer</button>
                <button
                  v-else
                  @click="startKinshipWalk"
                  class="text-xs font-medium text-brand-700 dark:text-brand-400 hover:underline"
                >Rejouer</button>
              </div>
            </div>
          </template>
        </div>

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
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';
import * as f3 from 'family-chart';
import 'family-chart/styles/family-chart.css';
import { personLabel } from '@/utils/personName';
import { searchPeople } from '@/utils/personSearch';
import { formatLongDate } from '@/utils/format';

const chartRef = ref(null);
const treeNodes = ref([]);      // données brutes (format backend) pour la sidebar
const loading = ref(true);
const searchQuery = ref('');
const selectedPerson = ref(null);
const drawerOpen = ref(false);   // tiroir sidebar (mobile)

let chart = null;
let rawById = {};

// Sous ce breakpoint (= md de Tailwind), on démarre l'arbre zoomé sur la
// personne principale plutôt que cadré en entier (sinon cartes illisibles).
const isMobile = () => window.matchMedia('(max-width: 767px)').matches;
const MOBILE_TREE_SCALE = 0.8;   // niveau de zoom initial mobile (cartes lisibles)
const DESKTOP_TREE_SCALE = 0.9;  // idem desktop : on démarre sur « moi », pas sur l'arbre entier

// L'animation d'ouverture (déploiement des cartes) ne joue qu'une fois par
// jour et par navigateur : ensuite l'arbre apparaît directement en place.
const TREE_INTRO_KEY = 'tree_intro_date';
const introPlayedToday = () => localStorage.getItem(TREE_INTRO_KEY) === new Date().toDateString();

const filteredPeople = computed(() => {
  if (!searchQuery.value) return [];
  // Recherche insensible aux accents + tolérante aux fautes (helper partagé).
  return searchPeople(searchQuery.value, treeNodes.value, n => n.data.name).slice(0, 10);
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

// Point de départ de l'arbre : la fiche de l'utilisateur connecté si elle est
// présente dans l'arbre, sinon la personne la plus centrale.
function initialMainId(raw) {
  const pid = usePage().props?.auth?.user?.person_id;
  if (pid && rawById[pid]) return pid;
  return pickMainId(raw);
}

function renderChart() {
  const raw = treeNodes.value;
  if (!raw.length || !chartRef.value) return;

  const data = toChartData(raw);
  const mainId = initialMainId(raw);
  if (rawById[mainId]) selectedPerson.value = rawById[mainId];

  const skipIntro = introPlayedToday();

  chart = f3.createChart('#ml-family-chart', data)
    .setTransitionTime(skipIntro ? 0 : 700)
    .setCardXSpacing(300)
    .setCardYSpacing(190)
    .setOrientationVertical()
    .updateMainId(mainId);

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

  // Démarrage zoomé sur la personne principale (la fiche de l'utilisateur
  // connecté) plutôt que sur l'arbre entier — « Vue d'ensemble » reste à un clic.
  chart.updateTree({
    initial: false,
    tree_position: 'main_to_middle',
    scale: isMobile() ? MOBILE_TREE_SCALE : DESKTOP_TREE_SCALE,
  });

  if (skipIntro) {
    // Rendu instantané aujourd'hui : on réarme les transitions juste après,
    // pour que les interactions (recentrage, marche du lien de parenté…)
    // restent animées.
    setTimeout(() => { if (chart) chart.setTransitionTime(700); }, 100);
  } else {
    localStorage.setItem(TREE_INTRO_KEY, new Date().toDateString());
  }
}

function centerOnPerson(person) {
  selectedPerson.value = rawById[person.id] || person;
  searchQuery.value = '';
  drawerOpen.value = false;   // referme le tiroir sur mobile après un choix
  if (chart) {
    chart.updateMainId(person.id);
    chart.updateTree({ tree_position: 'main_to_middle' });
  }
}

// Dézoome pour cadrer l'arbre entier (vue d'ensemble).
function fitAll() {
  if (chart) chart.updateTree({ tree_position: 'fit' });
}

// Recentre sur la personne sélectionnée (ou cadre tout si aucune).
function recenter() {
  if (!chart) return;
  if (selectedPerson.value) {
    chart.updateMainId(selectedPerson.value.id);
    chart.updateTree({ tree_position: 'main_to_middle', scale: isMobile() ? MOBILE_TREE_SCALE : undefined });
  } else {
    chart.updateTree({ tree_position: 'fit' });
  }
}

// ---- Marche du lien de parenté (?kinship=<personId>) ----------------------
// Arrivée depuis le bouton « Lien de parenté » d'une fiche : on charge le
// plus court chemin moi → personne, puis l'arbre « marche » de carte en
// carte (updateMainId + transition 700 ms) avec la légende de chaque pas.
const kinship = ref(null);
const kinshipStep = ref(0);
let kinshipTimer = null;

const KINSHIP_STEP_MS = 1900;

async function loadKinship(personId) {
  try {
    const { data } = await axios.get(`/people/${personId}/kinship`);
    kinship.value = data;
    if (data.found) startKinshipWalk();
  } catch (e) {
    console.error('Lien de parenté indisponible :', e);
  }
}

// Surbrillance du chemin parcouru : cartes visitées (halo doré) + traits
// entre personnes consécutives. Les liens family-chart portent en __data__
// un id « personne1, personne2[, enfant] » : un segment (a,b) du chemin
// matche le trait qui contient les deux ids. Réappliqué après la transition
// (d3 recrée les éléments à chaque updateTree).
function applyKinshipHighlight() {
  const k = kinship.value;
  if (!k?.found || !chartRef.value) return;
  const visited = k.path.slice(0, kinshipStep.value + 1).map((p) => p.id);
  const segments = visited.slice(1).map((id, i) => [visited[i], id]);

  chartRef.value.querySelectorAll('.card[data-id]').forEach((el) => {
    el.classList.toggle('kinship-glow', visited.includes(el.getAttribute('data-id')));
  });
  chartRef.value.querySelectorAll('path.link').forEach((el) => {
    const linkId = el.__data__?.id || '';
    el.classList.toggle('kinship-link', segments.some(([a, b]) => linkId.includes(a) && linkId.includes(b)));
  });
}

function clearKinshipHighlight() {
  if (!chartRef.value) return;
  chartRef.value.querySelectorAll('.kinship-glow').forEach((el) => el.classList.remove('kinship-glow'));
  chartRef.value.querySelectorAll('.kinship-link').forEach((el) => el.classList.remove('kinship-link'));
}

let kinshipHighlightTimers = [];

function scheduleKinshipHighlight() {
  kinshipHighlightTimers.forEach(clearTimeout);
  // Pendant et après la transition (700 ms) : les éléments sont recréés.
  kinshipHighlightTimers = [150, 800].map((ms) => setTimeout(applyKinshipHighlight, ms));
}

// Vue finale : recentrage sur l'ancêtre commun (apex du chemin) — l'arbre
// montre alors ses DEUX branches (vers moi et vers la personne), toutes
// deux en surbrillance. Quand l'apex est déjà l'extrémité (père direct,
// enfant…), la marche se termine simplement sur la personne.
const kinshipApexView = ref(false);

function finishOnApex() {
  const k = kinship.value;
  if (!k?.found || !chart) return;
  const apex = k.path[k.apex_index];
  if (k.apex_index === k.path.length - 1 || !apex) return;
  kinshipApexView.value = true;
  if (rawById[apex.id]) selectedPerson.value = rawById[apex.id];
  chart.updateMainId(apex.id);
  chart.updateTree({ tree_position: 'main_to_middle' });
  scheduleKinshipHighlight();
}

function focusKinshipStep() {
  const step = kinship.value?.path?.[kinshipStep.value];
  if (!step || !chart) return;
  if (rawById[step.id]) selectedPerson.value = rawById[step.id];
  chart.updateMainId(step.id);
  chart.updateTree({ tree_position: 'main_to_middle' });
  scheduleKinshipHighlight();
  if (kinshipStep.value < kinship.value.path.length - 1) {
    kinshipTimer = setTimeout(() => {
      kinshipStep.value += 1;
      focusKinshipStep();
    }, KINSHIP_STEP_MS);
  } else {
    kinshipTimer = setTimeout(finishOnApex, KINSHIP_STEP_MS);
  }
}

function startKinshipWalk() {
  clearTimeout(kinshipTimer);
  kinshipApexView.value = false;
  kinshipStep.value = 0;
  focusKinshipStep();
}

// « Passer » : va directement à la vue d'ensemble (ancêtre commun, tout
// le chemin en surbrillance) — ou à la personne si l'apex est l'extrémité.
function finishKinshipWalk() {
  clearTimeout(kinshipTimer);
  kinshipStep.value = kinship.value.path.length - 1;
  focusKinshipStep();
  clearTimeout(kinshipTimer);
  finishOnApex();
}

function stopKinship() {
  clearTimeout(kinshipTimer);
  kinshipHighlightTimers.forEach(clearTimeout);
  clearKinshipHighlight();
  kinshipApexView.value = false;
  kinship.value = null;
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

  const kinshipTarget = new URLSearchParams(window.location.search).get('kinship');
  if (kinshipTarget) loadKinship(kinshipTarget);
});

onBeforeUnmount(() => {
  clearTimeout(kinshipTimer);
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
/* Tactile : laisser d3-zoom capter le pan/pinch au lieu du scroll/zoom natif du
   navigateur. Sans touch-action:none, le geste part en défilement de page. */
.ml-tree,
.ml-tree #f3Canvas,
.ml-tree svg.main_svg {
  touch-action: none;
  -webkit-user-select: none;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
}

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

/* Marche du lien de parenté : halo doré sur les cartes visitées + traits du
   chemin en surbrillance (les liens ont un stroke inline → !important). */
.ml-tree .card.kinship-glow {
  box-shadow: 0 0 0 3px var(--color-brand-500, #f59e0b), 0 0 22px rgba(245, 158, 11, 0.55);
  border-radius: 10px;
}
.ml-tree path.link.kinship-link {
  stroke: var(--color-brand-500, #f59e0b) !important;
  stroke-width: 3.5px !important;
  filter: drop-shadow(0 0 3px rgba(245, 158, 11, 0.6));
}
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
