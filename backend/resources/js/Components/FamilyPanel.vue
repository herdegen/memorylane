<template>
  <div class="px-4 sm:px-8 pt-9 pb-2">
    <div class="flex items-center justify-between mb-2">
      <h2 class="font-display text-2xl font-semibold text-surface-900">Famille</h2>
      <Link href="/family-tree" class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline">
        Voir dans l'arbre
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
      </Link>
    </div>

    <!-- Mini-arbre : parents / génération de la personne / enfants -->
    <div class="flex flex-col items-center py-4">

      <!-- Parents -->
      <div class="flex flex-wrap justify-center gap-6">
        <template v-for="slot in ['father', 'mother']" :key="slot">
          <div v-if="slot === 'father' ? father : mother" class="relative group">
            <Link
              :href="`/people/${(slot === 'father' ? father : mother).id}`"
              class="relative block w-36 aspect-[3/4] rounded-xl overflow-hidden bg-brand-100 shadow-warm-sm hover:shadow-warm-md hover:scale-[1.02] transition"
            >
              <img
                v-if="(slot === 'father' ? father : mother).avatar_url"
                :src="(slot === 'father' ? father : mother).avatar_url"
                class="absolute inset-0 w-full h-full object-cover"
              />
              <div v-else class="absolute inset-0 flex items-center justify-center text-4xl font-bold text-brand-700">
                {{ (slot === 'father' ? father : mother).name.charAt(0).toUpperCase() }}
              </div>
              <div class="absolute inset-x-1.5 bottom-1.5 rounded-lg bg-white/80 backdrop-blur-sm px-2 py-1.5 text-center">
                <p class="text-[13px] font-semibold text-surface-900 leading-tight line-clamp-2">{{ personLabel(slot === 'father' ? father : mother) }}</p>
                <p class="text-[11px] text-surface-600">{{ lifeYears(slot === 'father' ? father : mother) || (slot === 'father' ? 'père' : 'mère') }}</p>
              </div>
            </Link>
            <button
              v-if="canManage"
              type="button"
              @click="removeParent(slot)"
              class="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 text-surface-400 hover:text-red-500 dark:hover:text-red-400 bg-white/90 rounded-full p-0.5 transition"
              title="Retirer"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <button
            v-else-if="canManage"
            type="button"
            @click="toggleAdding(slot)"
            class="flex flex-col items-center justify-center gap-2 w-36 aspect-[3/4] border-2 border-dashed rounded-xl transition"
            :class="adding === slot ? 'border-brand-400 text-brand-600 bg-brand-50/50' : 'border-surface-300 text-surface-400 hover:border-brand-300 hover:text-brand-600'"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
            <span class="text-xs font-medium">{{ slot === 'father' ? 'Définir le père' : 'Définir la mère' }}</span>
          </button>
        </template>
      </div>

      <!-- Picker parent -->
      <div v-if="adding === 'father' || adding === 'mother'" class="mt-3 w-72">
        <RelationshipPicker
          label=""
          :current-person="null"
          :exclude-ids="[person.id]"
          :placeholder="adding === 'father' ? 'Rechercher le père…' : 'Rechercher la mère…'"
          @select="setParent($event, adding)"
        />
        <button @click="adding = null" class="text-xs text-surface-400 hover:text-surface-600 mt-1">Annuler</button>
      </div>

      <div v-if="father || mother || canManage" class="w-0.5 h-7 bg-surface-200"></div>

      <!-- Génération de la personne : fratrie · personne · conjoint(s) -->
      <div class="flex flex-wrap justify-center items-stretch gap-4">
        <Link
          v-for="sibling in siblings"
          :key="sibling.id"
          :href="`/people/${sibling.id}`"
          class="relative block w-32 aspect-[3/4] rounded-xl overflow-hidden bg-surface-100 shadow-warm-sm opacity-85 hover:opacity-100 hover:shadow-warm-md hover:scale-[1.02] transition self-center"
        >
          <img v-if="sibling.avatar_url" :src="sibling.avatar_url" class="absolute inset-0 w-full h-full object-cover" />
          <div v-else class="absolute inset-0 flex items-center justify-center text-3xl font-bold text-surface-400">
            {{ sibling.name.charAt(0).toUpperCase() }}
          </div>
          <div class="absolute inset-x-1.5 bottom-1.5 rounded-lg bg-white/80 backdrop-blur-sm px-2 py-1.5 text-center">
            <p class="text-[13px] font-medium text-surface-900 leading-tight line-clamp-2">{{ personLabel(sibling) }}</p>
            <p class="text-[11px] text-surface-600">{{ siblingSub(sibling) }}</p>
          </div>
        </Link>

        <!-- La personne, mise en avant -->
        <div class="relative w-40 aspect-[3/4] rounded-xl overflow-hidden bg-brand-100 ring-2 ring-brand-500 shadow-warm-md">
          <img v-if="person.avatar_url" :src="person.avatar_url" class="absolute inset-0 w-full h-full object-cover" />
          <div v-else class="absolute inset-0 flex items-center justify-center text-5xl font-bold text-brand-700">
            {{ person.name.charAt(0).toUpperCase() }}
          </div>
          <div class="absolute inset-x-1.5 bottom-1.5 rounded-lg bg-white/85 backdrop-blur-sm px-2 py-1.5 text-center">
            <p class="text-sm font-bold text-surface-900 leading-tight line-clamp-2">{{ personLabel(person) }}</p>
            <p class="text-[11px] font-medium text-brand-700">{{ lifeYears(person) }}</p>
          </div>
        </div>

        <div v-if="spouses.length > 0 || canManage" class="self-center text-surface-300 text-xl px-1">⸺</div>

        <div v-for="spouse in spouses" :key="spouse.id" class="relative group">
          <Link
            :href="`/people/${spouse.id}`"
            class="relative block w-36 aspect-[3/4] rounded-xl overflow-hidden bg-brand-100 shadow-warm-sm hover:shadow-warm-md hover:scale-[1.02] transition"
          >
            <img v-if="spouse.avatar_url" :src="spouse.avatar_url" class="absolute inset-0 w-full h-full object-cover" />
            <div v-else class="absolute inset-0 flex items-center justify-center text-4xl font-bold text-brand-700">
              {{ spouse.name.charAt(0).toUpperCase() }}
            </div>
            <div class="absolute inset-x-1.5 bottom-1.5 rounded-lg bg-white/80 backdrop-blur-sm px-2 py-1.5 text-center">
              <p class="text-[13px] font-semibold text-surface-900 leading-tight line-clamp-2">{{ personLabel(spouse) }}</p>
              <p class="text-[11px] text-surface-600">{{ spouseLabel(spouse) }}</p>
            </div>
          </Link>
          <button
            v-if="canManage"
            type="button"
            @click="removeSpouse(spouse)"
            class="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 text-surface-400 hover:text-red-500 dark:hover:text-red-400 bg-white/90 rounded-full p-0.5 transition"
            title="Retirer"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <button
          v-if="canManage && spouses.length === 0"
          type="button"
          @click="toggleAdding('spouse')"
          class="flex flex-col items-center justify-center gap-2 w-36 aspect-[3/4] border-2 border-dashed rounded-xl transition"
          :class="adding === 'spouse' ? 'border-brand-400 text-brand-600 bg-brand-50/50' : 'border-surface-300 text-surface-400 hover:border-brand-300 hover:text-brand-600'"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
          <span class="text-xs font-medium">Ajouter un conjoint</span>
        </button>
        <button
          v-else-if="canManage"
          type="button"
          @click="toggleAdding('spouse')"
          class="self-center inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-dashed text-xs font-medium transition"
          :class="adding === 'spouse' ? 'border-brand-400 text-brand-600' : 'border-surface-300 text-surface-400 hover:border-brand-300 hover:text-brand-600'"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
          Conjoint
        </button>
      </div>

      <!-- Picker conjoint -->
      <div v-if="adding === 'spouse'" class="mt-3 w-72">
        <RelationshipPicker
          label=""
          :current-person="null"
          :exclude-ids="excludeSpouseIds"
          placeholder="Rechercher un conjoint…"
          @select="addSpouse"
        />
        <button @click="adding = null" class="text-xs text-surface-400 hover:text-surface-600 mt-1">Annuler</button>
      </div>

      <div class="w-0.5 h-7 bg-surface-200"></div>

      <!-- Enfants -->
      <div class="flex flex-wrap justify-center gap-4">
        <Link
          v-for="child in children"
          :key="child.id"
          :href="`/people/${child.id}`"
          class="relative block w-36 aspect-[3/4] rounded-xl overflow-hidden bg-brand-100 shadow-warm-sm hover:shadow-warm-md hover:scale-[1.02] transition"
        >
          <img v-if="child.avatar_url" :src="child.avatar_url" class="absolute inset-0 w-full h-full object-cover" />
          <div v-else class="absolute inset-0 flex items-center justify-center text-4xl font-bold text-brand-700">
            {{ child.name.charAt(0).toUpperCase() }}
          </div>
          <div class="absolute inset-x-1.5 bottom-1.5 rounded-lg bg-white/80 backdrop-blur-sm px-2 py-1.5 text-center">
            <p class="text-[13px] font-semibold text-surface-900 leading-tight line-clamp-2">{{ personLabel(child) }}</p>
            <p class="text-[11px] text-surface-600">{{ lifeYears(child) }}</p>
          </div>
        </Link>
        <button
          v-if="canManage"
          type="button"
          @click="toggleAdding('child')"
          class="flex flex-col items-center justify-center gap-2 w-36 aspect-[3/4] border-2 border-dashed rounded-xl transition"
          :class="adding === 'child' ? 'border-brand-400 text-brand-600 bg-brand-50/50' : 'border-surface-300 text-surface-400 hover:border-brand-300 hover:text-brand-600'"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
          <span class="text-xs font-medium">Ajouter un enfant</span>
        </button>
      </div>

      <!-- Formulaire enfant -->
      <div v-if="adding === 'child'" class="mt-3 w-80 space-y-2">
        <div v-if="spouses.length > 1">
          <label class="block text-xs font-medium text-surface-600 mb-1">Autre parent</label>
          <select v-model="coParentId" class="form-input">
            <option value="">Inconnu</option>
            <option v-for="s in spouses" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <p v-else-if="spouses.length === 1" class="text-xs text-surface-500">
          Autre parent : {{ spouses[0].name }}
        </p>

        <RelationshipPicker
          label=""
          :current-person="null"
          :exclude-ids="excludeChildIds"
          placeholder="Rechercher l'enfant…"
          @select="addChild"
        />

        <div class="flex gap-2">
          <input
            v-model="newChildName"
            type="text"
            placeholder="ou créer : nom de l'enfant"
            class="form-input flex-1"
            @keyup.enter="createAndAddChild"
          />
          <button
            @click="createAndAddChild"
            :disabled="!newChildName.trim() || busy"
            class="btn-primary disabled:opacity-50"
          >
            Créer
          </button>
        </div>

        <button @click="adding = null" class="text-xs text-surface-400 hover:text-surface-600">Annuler</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import RelationshipPicker from '@/Components/RelationshipPicker.vue';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';
import { personLabel } from '@/utils/personName';

const toast = useToast();

const props = defineProps({
  person: { type: Object, required: true },
  father: { type: Object, default: null },
  mother: { type: Object, default: null },
  spouses: { type: Array, default: () => [] },
  children: { type: Array, default: () => [] },
  siblings: { type: Array, default: () => [] },
  // Affordances d'édition (le serveur vérifie de toute façon).
  canManage: { type: Boolean, default: false },
});

// Un seul formulaire d'ajout ouvert à la fois : 'father'|'mother'|'spouse'|'child'|null.
const adding = ref(null);
const coParentId = ref('');
const newChildName = ref('');
const busy = ref(false);

const toggleAdding = (what) => {
  if (adding.value === what) {
    adding.value = null;
    return;
  }
  adding.value = what;
  if (what === 'child') {
    newChildName.value = '';
    // Si un seul conjoint, il est automatiquement l'autre parent.
    coParentId.value = props.spouses.length === 1 ? props.spouses[0].id : '';
  }
};

// « 1954 – 1998 » / « 1988 » — années de vie pour le sous-titre des cartes.
const lifeYears = (p) => {
  if (!p?.birth_date) return '';
  const birth = p.birth_date.slice(0, 4);
  return p.death_date ? `${birth} – ${p.death_date.slice(0, 4)}` : birth;
};

const spouseLabel = (spouse) => lifeYears(spouse) || 'conjoint(e)';

// « 1994 · frère » — années + lien de fratrie selon le genre.
const siblingSub = (s) => {
  const relation = s.gender === 'F' ? 'sœur' : s.gender === 'M' ? 'frère' : 'fratrie';
  const years = lifeYears(s);
  return years ? `${years} · ${relation}` : relation;
};

const excludeSpouseIds = computed(() => {
  return [props.person.id, ...props.spouses.map(s => s.id)];
});

// On exclut soi-même, les enfants déjà rattachés et les parents (évite les boucles).
const excludeChildIds = computed(() => {
  const ids = [props.person.id, ...props.children.map(c => c.id)];
  if (props.father) ids.push(props.father.id);
  if (props.mother) ids.push(props.mother.id);
  return ids;
});

async function addChild(child) {
  if (busy.value) return;
  busy.value = true;
  try {
    await axios.post(`/people/${props.person.id}/child`, {
      child_id: child.id,
      other_parent_id: coParentId.value || null,
    });
    adding.value = null;
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible d\'ajouter cet enfant.');
  } finally {
    busy.value = false;
  }
}

async function createAndAddChild() {
  const name = newChildName.value.trim();
  if (!name || busy.value) return;
  busy.value = true;
  try {
    const { data } = await axios.post('/people', { name });
    const created = data.person || data;
    await axios.post(`/people/${props.person.id}/child`, {
      child_id: created.id,
      other_parent_id: coParentId.value || null,
    });
    adding.value = null;
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible de créer cet enfant.');
  } finally {
    busy.value = false;
  }
}

async function setParent(parent, type) {
  try {
    await axios.post(`/people/${props.person.id}/parent`, {
      parent_id: parent.id,
      parent_type: type,
    });
    adding.value = null;
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible de définir ce parent.');
  }
}

async function removeParent(type) {
  try {
    await axios.delete(`/people/${props.person.id}/parent`, {
      data: { parent_type: type },
    });
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible de retirer ce parent.');
  }
}

async function addSpouse(spouse) {
  try {
    await axios.post(`/people/${props.person.id}/spouse`, {
      spouse_id: spouse.id,
    });
    adding.value = null;
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible d\'ajouter ce conjoint.');
  }
}

async function removeSpouse(spouse) {
  try {
    await axios.delete(`/people/${props.person.id}/spouse`, {
      data: { spouse_id: spouse.id },
    });
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible de retirer ce conjoint.');
  }
}
</script>
