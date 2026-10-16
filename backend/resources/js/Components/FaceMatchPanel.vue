<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-xs p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-surface-900">Identifier le visage</h2>
      <button @click="$emit('close')" class="text-surface-400 hover:text-surface-600">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Aperçu recadré du visage -->
    <div class="mb-4 flex items-center gap-3">
      <canvas
        ref="cropCanvas"
        class="w-20 h-20 rounded-lg bg-surface-100 object-cover shrink-0 border border-surface-200"
      ></canvas>
      <div class="text-sm text-surface-500">
        <span v-if="face.person" class="block font-medium text-surface-900">
          Actuellement : {{ face.person.name }}
        </span>
        Confiance : {{ Math.round((face.confidence || 0) * 100) }}%
      </div>
    </div>

    <!-- Identifié·e dans un autre cercle : nom visible, correction fermée -->
    <p v-if="face.person_restricted" class="text-sm text-surface-600 bg-surface-100 rounded-lg px-3 py-2.5">
      Cette personne a été identifiée dans un autre cercle : seule une personne de ce cercle peut corriger l'identification.
    </p>

    <template v-else>
    <!-- Suggestion de reconnaissance -->
    <div
      v-if="topSuggestion"
      class="mb-4 p-3 bg-brand-50 border border-brand-100 rounded-lg"
    >
      <p class="text-sm text-surface-700 mb-2">
        Suggestion :
        <span class="font-semibold text-surface-900">{{ topSuggestion.person.name }}</span>
        <span class="text-surface-500">({{ Math.round(topSuggestion.score * 100) }}%)</span>
      </p>
      <!-- Garde-fous : raisons de douter (bébé, parenté lointaine) -->
      <p v-for="d in topSuggestion.doubts || []" :key="d.code" class="mb-2 inline-flex items-center gap-1 mr-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">
        ⚠ {{ d.label }}
      </p>
      <button
        @click="matchToPerson(topSuggestion.person)"
        :disabled="matching"
        class="w-full px-3 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 disabled:opacity-50"
      >
        Confirmer {{ topSuggestion.person.name }}
      </button>
    </div>

    <!-- Search existing people -->
    <div class="mb-4">
      <label class="block text-sm font-medium text-surface-700 mb-1">
        {{ face.person ? 'Changer de personne' : 'Sélectionner une personne' }}
      </label>
      <div class="relative">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Rechercher..."
          class="w-full px-3 py-2 border border-surface-300 rounded-lg text-sm focus:ring-brand-500 focus:border-brand-500"
        />
      </div>

      <!-- People list -->
      <div v-if="filteredPeople.length > 0" class="mt-2 max-h-48 overflow-y-auto border border-surface-200 rounded-lg">
        <button
          v-for="person in filteredPeople"
          :key="person.id"
          @click="matchToPerson(person)"
          :disabled="matching"
          class="w-full px-3 py-2 text-left text-sm hover:bg-brand-50 flex items-center gap-2 border-b border-surface-100 last:border-b-0"
        >
          <!-- Initiale seule (pas de photo) : rendu historique de cette liste. -->
          <PersonAvatar :name="person.name" :size="8" fallback-class="bg-surface-200 text-surface-600 font-medium" />
          <span>{{ person.name }}</span>
          <span v-if="person.onAlbum" class="text-xs text-surface-400">· sur l'album</span>
          <span v-else-if="person.elsewhere" class="text-xs text-surface-400">· {{ person.elsewhere_label }}</span>
          <span
            v-for="d in person.doubts || []"
            :key="d.code"
            :title="d.label"
            class="px-1.5 py-0.5 rounded-full text-[11px] font-medium bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
          >⚠ {{ d.label }}</span>
          <span
            v-if="person.score != null"
            class="ml-auto text-xs font-medium text-brand-600"
          >{{ Math.round(person.score * 100) }}%</span>
        </button>
      </div>

      <p v-else-if="searchQuery && !loadingPeople" class="mt-2 text-sm text-surface-500">
        Aucune personne trouvée
      </p>
    </div>

    <!-- Actions -->
    <div class="flex items-center gap-2">
      <button
        @click="showCreatePerson = true"
        class="flex-1 px-3 py-2 text-sm font-medium text-brand-600 bg-brand-50 rounded-lg hover:bg-brand-100"
      >
        Nouvelle personne
      </button>
      <!-- Retirer l'association si le visage est déjà matché, sinon Ignorer -->
      <button
        v-if="face.person"
        @click="resetFace"
        :disabled="matching"
        class="flex-1 px-3 py-2 text-sm font-medium text-surface-600 bg-surface-100 rounded-lg hover:bg-surface-200"
      >
        Retirer
      </button>
      <button
        v-else
        @click="dismissFace"
        :disabled="matching"
        class="flex-1 px-3 py-2 text-sm font-medium text-surface-600 bg-surface-100 rounded-lg hover:bg-surface-200"
      >
        Ignorer
      </button>
    </div>
    </template>

    <!-- Create person inline form -->
    <div v-if="showCreatePerson" class="mt-4 p-4 bg-surface-50 rounded-lg">
      <h3 class="text-sm font-medium text-surface-900 mb-3">Nouvelle personne</h3>
      <input
        v-model="newPersonName"
        type="text"
        placeholder="Nom"
        class="w-full px-3 py-2 border border-surface-300 rounded-lg text-sm focus:ring-brand-500 focus:border-brand-500 mb-3"
        @keyup.enter="createAndMatch"
      />
      <div class="flex gap-2">
        <button
          @click="createAndMatch"
          :disabled="!newPersonName.trim() || matching"
          class="flex-1 px-3 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 disabled:opacity-50"
        >
          Créer et associer
        </button>
        <button
          @click="showCreatePerson = false"
          class="px-3 py-2 text-sm font-medium text-surface-600 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
        >
          Annuler
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import { matchesPerson, relevanceTier } from '@/utils/personSearch';
import { useToast } from '@/Composables/useToast';
import { useConfirm } from '@/Composables/useConfirm';
import { apiError } from '@/utils/apiError';
import { usePeopleDirectory } from '@/Composables/usePeopleDirectory';
import { useBringIn } from '@/Composables/useBringIn';

const toast = useToast();
const { confirm } = useConfirm();
const peopleDirectory = usePeopleDirectory();
const { confirmBringIn } = useBringIn();

const props = defineProps({
  face: {
    type: Object,
    required: true,
  },
  mediaId: {
    type: String,
    required: true,
  },
});

const emit = defineEmits(['matched', 'dismissed', 'reset', 'close']);

const searchQuery = ref('');
const people = ref([]);
const suggestions = ref([]); // candidats classés par proximité (embedding)
const albumPeople = ref([]); // déjà identifiés dans un album de la photo
const loadingPeople = ref(false);
const matching = ref(false);
const showCreatePerson = ref(false);
const newPersonName = ref('');
const topSuggestion = ref(null);
const cropCanvas = ref(null);
// Fiche créée par « Créer et associer » dont l'association a échoué : un
// nouvel essai la réutilise au lieu de créer un doublon.
const createdPerson = ref(null);

// Annuaire partagé : ouvrir un autre visage ne relance pas la requête.
const loadPeople = async () => {
  loadingPeople.value = true;
  try {
    people.value = await peopleDirectory.load();
  } catch (error) {
    console.error('Failed to load people:', error);
    toast.error(apiError(error, 'Impossible de charger la liste des personnes.'));
  } finally {
    loadingPeople.value = false;
  }
};

// Tri des « autres » personnes : proximité de parenté d'abord (les proches du
// user connecté remontent, ex. sa grand-mère avant des homonymes lointains),
// puis date de naissance décroissante (familles récentes = plus de photos),
// puis nom. Les personnes sans lien de parenté connu passent en fin.
const byKinshipThenBirth = (a, b) => {
  const pa = a.proximity, pb = b.proximity;
  if (pa == null && pb != null) return 1;
  if (pa != null && pb == null) return -1;
  if (pa != null && pb != null && pa !== pb) return pa - pb;

  const da = a.birth_date, db = b.birth_date;
  if (da && db && da !== db) return da < db ? 1 : -1; // récent d'abord
  if (da && !db) return -1;
  if (!da && db) return 1;

  return a.name.localeCompare(b.name);
};

// Score d'embedding par personne (pour afficher le %), indexé par id.
const scoreByPersonId = computed(() => {
  const map = {};
  for (const s of suggestions.value) map[s.person.id] = s.score;
  return map;
});

// Liste ordonnée, selon le contexte :
//  - RECHERCHE ACTIVE : la pertinence textuelle PRIME (l'exact/commence-par
//    avant les matchs flous), la parenté ne fait que départager. Évite qu'un
//    proche matchant « loi » en flou (Antoine/Julien) passe devant « Loïc ».
//  - SANS RECHERCHE : suggestions par ressemblance d'embedding d'abord, puis
//    les autres par proximité de parenté.
const filteredPeople = computed(() => {
  const scores = scoreByPersonId.value;
  const q = searchQuery.value.trim();

  if (q) {
    return people.value
      .filter((p) => matchesPerson(q, p.name))
      .map((p) => ({ ...p, score: scores[p.id] }))
      .sort((a, b) => {
        const ta = relevanceTier(q, a.name);
        const tb = relevanceTier(q, b.name);
        if (ta !== tb) return ta - tb;
        return byKinshipThenBirth(a, b);
      });
  }

  const ranked = suggestions.value.map((s) => ({
    id: s.person.id,
    name: s.person.name,
    score: s.score,
    doubts: s.doubts || [],
    onAlbum: !!s.on_album,
  }));
  // Puis les personnes déjà identifiées dans l'album (même d'un autre cercle),
  // puis le reste de l'annuaire.
  const onAlbum = albumPeople.value
    .filter((p) => !ranked.some((r) => r.id === p.id))
    .map((p) => ({ ...p, onAlbum: true }));
  const shownIds = new Set([...ranked, ...onAlbum].map((p) => p.id));

  const others = people.value
    .filter((p) => !shownIds.has(p.id))
    .slice()
    .sort(byKinshipThenBirth);

  return [...ranked, ...onAlbum, ...others];
});

const matchToPerson = async (person, allowDuplicate = false) => {
  if (!allowDuplicate && (await confirmBringIn(person)) === 'cancel') return;
  matching.value = true;
  try {
    await axios.post(`/vision/faces/${props.face.id}/match`, {
      person_id: person.id,
      ...(allowDuplicate ? { allow_duplicate: true } : {}),
    });
    emit('matched', { face: props.face, person });
  } catch (error) {
    // Déjà identifiée sur un autre visage de la photo : seulement si c'est
    // voulu (miroir, montage) — sinon c'est presque toujours une erreur.
    const duplicate = error?.response?.status === 422 && error.response.data?.errors?.duplicate?.[0];
    if (duplicate && !allowDuplicate) {
      matching.value = false;
      const ok = await confirm({
        title: 'Déjà sur cette photo',
        message: `${duplicate} Est-ce une photo avec un miroir ou un montage ? Sinon, choisissez une autre personne.`,
        confirmLabel: 'Oui, identifier quand même',
        cancelLabel: 'Non',
      });
      if (ok) await matchToPerson(person, true);
      return;
    }
    console.error('Failed to match face:', error);
    toast.error(apiError(error, "L'identification du visage n'a pas pu être enregistrée. Réessayez."));
  } finally {
    matching.value = false;
  }
};

const dismissFace = async () => {
  matching.value = true;
  try {
    await axios.post(`/vision/faces/${props.face.id}/dismiss`);
    emit('dismissed', props.face);
  } catch (error) {
    console.error('Failed to dismiss face:', error);
    toast.error(apiError(error, "Impossible d'ignorer ce visage pour le moment. Réessayez."));
  } finally {
    matching.value = false;
  }
};

const resetFace = async () => {
  matching.value = true;
  try {
    await axios.post(`/vision/faces/${props.face.id}/reset`);
    emit('reset', props.face);
  } catch (error) {
    console.error('Failed to reset face:', error);
    toast.error(apiError(error, "Impossible de retirer l'identification pour le moment. Réessayez."));
  } finally {
    matching.value = false;
  }
};

const createAndMatch = async () => {
  const name = newPersonName.value.trim();
  if (!name) return;

  matching.value = true;
  let person = null;
  try {
    // Fiche déjà créée lors d'un essai précédent (même nom) : on la réutilise.
    if (createdPerson.value && createdPerson.value.name === name) {
      person = createdPerson.value;
    } else {
      const createResponse = await axios.post('/people', { name });
      person = createResponse.data.person || createResponse.data;
      createdPerson.value = { ...person, name: person.name || name };
      // Annuaire partagé caduc : le prochain visage ouvert verra la fiche.
      peopleDirectory.invalidate();
      // La nouvelle fiche devient sélectionnable dans la liste.
      if (!people.value.some((p) => p.id === person.id)) {
        people.value.push({ ...person, name: person.name || name });
      }
    }
  } catch (error) {
    console.error('Failed to create person:', error);
    toast.error(apiError(error, 'Impossible de créer la fiche. Réessayez.'));
    matching.value = false;
    return;
  }

  try {
    // Match face to new person
    await axios.post(`/vision/faces/${props.face.id}/match`, {
      person_id: person.id,
    });
    createdPerson.value = null;
    emit('matched', { face: props.face, person });
  } catch (error) {
    console.error('Failed to match new person:', error);
    toast.error(apiError(
      error,
      `La fiche « ${name} » a été créée, mais l'association au visage a échoué. Réessayez : la même fiche sera utilisée.`,
    ));
  } finally {
    matching.value = false;
  }
};

// Suggestion de reconnaissance (plus proche voisin sur les visages labellisés).
const loadSuggestion = async () => {
  topSuggestion.value = null;
  suggestions.value = [];
  albumPeople.value = [];
  try {
    const { data } = await axios.get(`/vision/faces/${props.face.id}/suggest`);
    suggestions.value = data.suggestions || [];
    albumPeople.value = data.album_people || [];
    // Bannière de suggestion : seulement si le visage n'est pas déjà nommé.
    topSuggestion.value = props.face.person ? null : (suggestions.value[0] || null);
  } catch (error) {
    console.error('Failed to load suggestion:', error);
    toast.error(apiError(error, 'Les suggestions de reconnaissance sont indisponibles pour le moment.'));
  }
};

// Aperçu recadré : on dessine la région bounding_box (en %) de l'image proxy
// même-origine sur le canvas.
const drawCrop = () => {
  const canvas = cropCanvas.value;
  const box = props.face.bounding_box;
  if (!canvas || !box) return;

  const img = new Image();
  img.crossOrigin = 'anonymous';
  img.onload = () => {
    const sx = (box.x / 100) * img.naturalWidth;
    const sy = (box.y / 100) * img.naturalHeight;
    const sw = (box.width / 100) * img.naturalWidth;
    const sh = (box.height / 100) * img.naturalHeight;

    const size = 160; // canvas carré haute-def
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, size, size);
    // Recadrage centré façon "cover" pour rester carré.
    const side = Math.max(sw, sh);
    const cx = sx + sw / 2;
    const cy = sy + sh / 2;
    ctx.drawImage(
      img,
      Math.max(0, cx - side / 2), Math.max(0, cy - side / 2), side, side,
      0, 0, size, size
    );
  };
  img.src = `/vision/media/${props.mediaId}/image?conversion=medium`;
};

watch(() => props.face?.id, () => {
  createdPerson.value = null;
  loadSuggestion();
  drawCrop();
});

onMounted(() => {
  loadPeople();
  loadSuggestion();
  drawCrop();
});
</script>
