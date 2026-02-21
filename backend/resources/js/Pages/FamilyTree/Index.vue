<template>
  <AppLayout title="Arbre genealogique">
    <div class="h-[calc(100vh-4rem)] flex">
      <!-- Sidebar -->
      <div class="w-80 bg-white shadow-lg p-4 overflow-y-auto flex-shrink-0">
        <h2 class="text-xl font-semibold mb-4">Arbre genealogique</h2>

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
              class="w-full px-4 py-2 border border-surface-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
            />
            <div
              v-if="searchQuery && filteredPeople.length > 0"
              class="absolute z-10 mt-1 w-full bg-white border rounded-lg shadow-lg max-h-48 overflow-auto"
            >
              <button
                v-for="person in filteredPeople"
                :key="person.id"
                @click="centerOnPerson(person)"
                class="w-full text-left px-4 py-2 hover:bg-surface-50 text-sm"
              >
                {{ person.data.name }}
                <span v-if="person.data.birth_date" class="text-surface-400 ml-1">
                  ({{ person.data.birth_date.substring(0, 4) }})
                </span>
              </button>
            </div>
          </div>
        </div>

        <!-- Import GEDCOM -->
        <div class="mb-4">
          <Link
            href="/family-tree/import"
            class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 w-full"
          >
            Importer un GEDCOM
          </Link>
        </div>

        <!-- Selected person detail -->
        <div v-if="selectedPerson" class="mt-4 p-4 bg-surface-50 rounded-lg">
          <h3 class="font-semibold text-surface-900">{{ selectedPerson.data.name }}</h3>
          <p v-if="selectedPerson.data.birth_date" class="text-sm text-surface-500 mt-1">
            Naissance : {{ formatDate(selectedPerson.data.birth_date) }}
            <span v-if="selectedPerson.data.birth_place"> - {{ selectedPerson.data.birth_place }}</span>
          </p>
          <p v-if="selectedPerson.data.death_date" class="text-sm text-surface-500">
            Deces : {{ formatDate(selectedPerson.data.death_date) }}
          </p>
          <Link
            :href="`/people/${selectedPerson.id}`"
            class="mt-3 inline-flex items-center text-sm text-brand-600 hover:text-brand-800"
          >
            Voir la fiche complete &rarr;
          </Link>
        </div>

        <!-- Stats -->
        <div class="mt-4 p-3 bg-brand-50 rounded-lg">
          <div class="text-sm text-surface-600">
            {{ treeNodes.length }} personne(s) dans l'arbre
          </div>
        </div>
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
            <Link
              href="/family-tree/import"
              class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700"
            >
              Importer un GEDCOM
            </Link>
            <span class="mx-2 text-surface-400">ou</span>
            <Link
              href="/people"
              class="px-4 py-2 text-sm font-medium text-brand-600 border border-brand-600 rounded-lg hover:bg-brand-50"
            >
              Gerer les personnes
            </Link>
          </div>
        </div>

        <!-- Tree SVG -->
        <svg
          v-if="treeNodes.length > 0"
          ref="svgRef"
          class="w-full h-full"
          @mousedown="startPan"
          @mousemove="onPan"
          @mouseup="endPan"
          @mouseleave="endPan"
          @wheel="onZoom"
        >
          <g :transform="`translate(${pan.x}, ${pan.y}) scale(${zoom})`">
            <!-- Links -->
            <line
              v-for="link in treeLinks"
              :key="link.id"
              :x1="link.x1"
              :y1="link.y1"
              :x2="link.x2"
              :y2="link.y2"
              stroke="#cbd5e1"
              stroke-width="2"
            />
            <!-- Spouse links -->
            <line
              v-for="link in spouseLinks"
              :key="'s-' + link.id"
              :x1="link.x1"
              :y1="link.y1"
              :x2="link.x2"
              :y2="link.y2"
              stroke="#f59e0b"
              stroke-width="2"
              stroke-dasharray="6,3"
            />
            <!-- Nodes -->
            <g
              v-for="node in positionedNodes"
              :key="node.id"
              :transform="`translate(${node.x}, ${node.y})`"
              @click="selectPerson(node)"
              class="cursor-pointer"
            >
              <rect
                :width="nodeWidth"
                :height="nodeHeight"
                :x="-nodeWidth / 2"
                :y="-nodeHeight / 2"
                :rx="8"
                :fill="selectedPerson?.id === node.id ? '#e0e7ff' : (node.data.gender === 'M' ? '#dbeafe' : node.data.gender === 'F' ? '#fce7f3' : '#f3f4f6')"
                :stroke="selectedPerson?.id === node.id ? '#4f46e5' : '#94a3b8'"
                stroke-width="1.5"
              />
              <text
                text-anchor="middle"
                :y="-4"
                class="text-sm font-medium fill-gray-900"
                style="font-size: 13px;"
              >
                <tspan v-if="node.data.gender === 'M'" fill="#3b82f6">&#9794; </tspan>
                <tspan v-else-if="node.data.gender === 'F'" fill="#ec4899">&#9792; </tspan>
                {{ truncateName(node.data.name) }}
              </text>
              <text
                v-if="node.data.birth_date"
                text-anchor="middle"
                :y="14"
                class="fill-gray-500"
                style="font-size: 11px;"
              >
                {{ node.data.birth_date.substring(0, 4) }}{{ node.data.death_date ? ' - ' + node.data.death_date.substring(0, 4) : '' }}
              </text>
            </g>
          </g>
        </svg>

        <!-- Loading -->
        <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-white bg-opacity-75">
          <svg class="animate-spin h-8 w-8 text-brand-600" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
        </div>

        <!-- Zoom controls -->
        <div v-if="treeNodes.length > 0" class="absolute bottom-4 right-4 flex flex-col gap-1">
          <button @click="zoom = Math.min(zoom * 1.2, 3)" class="w-8 h-8 bg-white rounded shadow flex items-center justify-center text-surface-600 hover:bg-surface-50">+</button>
          <button @click="zoom = Math.max(zoom / 1.2, 0.1)" class="w-8 h-8 bg-white rounded shadow flex items-center justify-center text-surface-600 hover:bg-surface-50">-</button>
          <button @click="resetView" class="w-8 h-8 bg-white rounded shadow flex items-center justify-center text-surface-600 hover:bg-surface-50 text-xs">R</button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const svgRef = ref(null);
const treeNodes = ref([]);
const loading = ref(true);
const searchQuery = ref('');
const selectedPerson = ref(null);

const nodeWidth = 160;
const nodeHeight = 50;
const hGap = 40;
const vGap = 80;

const zoom = ref(0.8);
const pan = ref({ x: 400, y: 100 });
let isPanning = false;
let panStart = { x: 0, y: 0 };

const filteredPeople = computed(() => {
  if (!searchQuery.value) return [];
  const q = searchQuery.value.toLowerCase();
  return treeNodes.value
    .filter(n => n.data.name.toLowerCase().includes(q))
    .slice(0, 10);
});

// Build a simple layered layout from the tree data
const positionedNodes = computed(() => {
  if (treeNodes.value.length === 0) return [];
  return layoutTree(treeNodes.value);
});

const treeLinks = computed(() => {
  const links = [];
  const nodeMap = {};
  positionedNodes.value.forEach(n => { nodeMap[n.id] = n; });

  positionedNodes.value.forEach(node => {
    if (node.rels.father && nodeMap[node.rels.father]) {
      const parent = nodeMap[node.rels.father];
      links.push({
        id: `${parent.id}-${node.id}-f`,
        x1: parent.x, y1: parent.y + nodeHeight / 2,
        x2: node.x, y2: node.y - nodeHeight / 2,
      });
    }
    if (node.rels.mother && nodeMap[node.rels.mother]) {
      const parent = nodeMap[node.rels.mother];
      links.push({
        id: `${parent.id}-${node.id}-m`,
        x1: parent.x, y1: parent.y + nodeHeight / 2,
        x2: node.x, y2: node.y - nodeHeight / 2,
      });
    }
  });

  return links;
});

const spouseLinks = computed(() => {
  const links = [];
  const nodeMap = {};
  positionedNodes.value.forEach(n => { nodeMap[n.id] = n; });

  const seen = new Set();
  positionedNodes.value.forEach(node => {
    if (node.rels.spouses) {
      node.rels.spouses.forEach(spouseId => {
        const key = [node.id, spouseId].sort().join('-');
        if (!seen.has(key) && nodeMap[spouseId]) {
          seen.add(key);
          const spouse = nodeMap[spouseId];
          links.push({
            id: key,
            x1: node.x + nodeWidth / 2, y1: node.y,
            x2: spouse.x - nodeWidth / 2, y2: spouse.y,
          });
        }
      });
    }
  });

  return links;
});

function layoutTree(nodes) {
  // Assign generations (BFS from roots)
  const nodeMap = {};
  nodes.forEach(n => {
    nodeMap[n.id] = { ...n, generation: null, x: 0, y: 0 };
  });

  // Find roots (no parents)
  const roots = Object.values(nodeMap).filter(
    n => !n.rels.father && !n.rels.mother
  );

  // If no roots, just use all
  const queue = roots.length > 0 ? [...roots] : [Object.values(nodeMap)[0]];
  queue.forEach(n => { n.generation = 0; });

  const visited = new Set(queue.map(n => n.id));

  while (queue.length > 0) {
    const current = queue.shift();

    // Children go one generation down
    if (current.rels.children) {
      current.rels.children.forEach(childId => {
        if (nodeMap[childId] && !visited.has(childId)) {
          nodeMap[childId].generation = current.generation + 1;
          visited.add(childId);
          queue.push(nodeMap[childId]);
        }
      });
    }

    // Spouses same generation
    if (current.rels.spouses) {
      current.rels.spouses.forEach(spouseId => {
        if (nodeMap[spouseId] && !visited.has(spouseId)) {
          nodeMap[spouseId].generation = current.generation;
          visited.add(spouseId);
          queue.push(nodeMap[spouseId]);
        }
      });
    }

    // Parents go one generation up
    ['father', 'mother'].forEach(parentType => {
      const parentId = current.rels[parentType];
      if (parentId && nodeMap[parentId] && !visited.has(parentId)) {
        nodeMap[parentId].generation = current.generation - 1;
        visited.add(parentId);
        queue.push(nodeMap[parentId]);
      }
    });
  }

  // Assign generation to any remaining unvisited nodes
  Object.values(nodeMap).forEach(n => {
    if (n.generation === null) n.generation = 0;
  });

  // Group by generation
  const generations = {};
  Object.values(nodeMap).forEach(n => {
    if (!generations[n.generation]) generations[n.generation] = [];
    generations[n.generation].push(n);
  });

  // Normalize generations (shift so minimum is 0)
  const minGen = Math.min(...Object.keys(generations).map(Number));

  // Position nodes
  const sortedGens = Object.keys(generations).map(Number).sort((a, b) => a - b);

  sortedGens.forEach(gen => {
    const row = generations[gen];
    const y = (gen - minGen) * (nodeHeight + vGap);
    const totalWidth = row.length * (nodeWidth + hGap) - hGap;
    const startX = -totalWidth / 2 + nodeWidth / 2;

    row.forEach((node, i) => {
      node.x = startX + i * (nodeWidth + hGap);
      node.y = y;
    });
  });

  return Object.values(nodeMap);
}

onMounted(async () => {
  try {
    const response = await axios.get('/family-tree/data');
    treeNodes.value = response.data;
  } catch (error) {
    console.error('Erreur lors du chargement de l\'arbre:', error);
  } finally {
    loading.value = false;
  }
});

function selectPerson(node) {
  selectedPerson.value = node;
}

function centerOnPerson(node) {
  selectedPerson.value = node;
  searchQuery.value = '';

  // Find positioned node to center on it
  const positioned = positionedNodes.value.find(n => n.id === node.id);
  if (positioned && svgRef.value) {
    const rect = svgRef.value.getBoundingClientRect();
    pan.value = {
      x: rect.width / 2 - positioned.x * zoom.value,
      y: rect.height / 2 - positioned.y * zoom.value,
    };
  }
}

function resetView() {
  zoom.value = 0.8;
  pan.value = { x: 400, y: 100 };
}

function startPan(e) {
  isPanning = true;
  panStart = { x: e.clientX - pan.value.x, y: e.clientY - pan.value.y };
}

function onPan(e) {
  if (!isPanning) return;
  pan.value = { x: e.clientX - panStart.x, y: e.clientY - panStart.y };
}

function endPan() {
  isPanning = false;
}

function onZoom(e) {
  e.preventDefault();
  const factor = e.deltaY > 0 ? 0.9 : 1.1;
  zoom.value = Math.max(0.1, Math.min(3, zoom.value * factor));
}

function truncateName(name) {
  return name.length > 18 ? name.substring(0, 16) + '...' : name;
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
</script>
