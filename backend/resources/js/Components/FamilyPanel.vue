<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-xs p-6 mb-6">
    <h3 class="text-lg font-semibold text-surface-900 mb-4">Famille</h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Father -->
      <div>
        <label class="block text-sm font-medium text-surface-700 mb-1">Père</label>
        <RelativeCard v-if="father" :person="father" removable @remove="removeParent('father')" />
        <RelationshipPicker
          v-else
          label=""
          :current-person="null"
          :exclude-ids="[person.id]"
          placeholder="Définir le père..."
          @select="setParent($event, 'father')"
          @remove=""
        />
      </div>

      <!-- Mother -->
      <div>
        <label class="block text-sm font-medium text-surface-700 mb-1">Mère</label>
        <RelativeCard v-if="mother" :person="mother" removable @remove="removeParent('mother')" />
        <RelationshipPicker
          v-else
          label=""
          :current-person="null"
          :exclude-ids="[person.id]"
          placeholder="Définir la mère..."
          @select="setParent($event, 'mother')"
          @remove=""
        />
      </div>
    </div>

    <!-- Spouses -->
    <div class="mt-4">
      <label class="block text-sm font-medium text-surface-700 mb-2">Conjoint(s)</label>

      <div v-if="spouses.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2">
        <RelativeCard
          v-for="spouse in spouses"
          :key="spouse.id"
          :person="spouse"
          removable
          @remove="removeSpouse(spouse)"
        />
      </div>

      <div v-if="!addingSpouse">
        <button
          @click="addingSpouse = true"
          class="text-sm text-brand-600 hover:text-brand-800"
        >
          + Ajouter un conjoint
        </button>
      </div>

      <div v-else class="mt-2">
        <RelationshipPicker
          label=""
          :current-person="null"
          :exclude-ids="excludeSpouseIds"
          placeholder="Rechercher un conjoint..."
          @select="addSpouse"
          @remove=""
        />
        <button @click="addingSpouse = false" class="text-xs text-surface-400 hover:text-surface-600 mt-1">
          Annuler
        </button>
      </div>
    </div>

    <!-- Children -->
    <div class="mt-4">
      <label class="block text-sm font-medium text-surface-700 mb-2">Enfants</label>

      <div v-if="children.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2">
        <RelativeCard v-for="child in children" :key="child.id" :person="child" />
      </div>

      <div v-if="!addingChild">
        <button @click="startAddChild" class="text-sm text-brand-600 hover:text-brand-800">
          + Ajouter un enfant
        </button>
      </div>

      <div v-else class="mt-2 space-y-2">
        <!-- Choix de l'autre parent quand plusieurs conjoints -->
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

        <!-- Lier un enfant existant -->
        <RelationshipPicker
          label=""
          :current-person="null"
          :exclude-ids="excludeChildIds"
          placeholder="Rechercher l'enfant..."
          @select="addChild"
          @remove=""
        />

        <!-- Ou créer un nouvel enfant -->
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

        <button @click="addingChild = false" class="text-xs text-surface-400 hover:text-surface-600">
          Annuler
        </button>
      </div>
    </div>

    <!-- Frères et sœurs (lecture seule — dérivés des parents) -->
    <div v-if="siblings.length > 0" class="mt-4">
      <label class="block text-sm font-medium text-surface-700 mb-2">Frères et sœurs</label>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <RelativeCard v-for="sibling in siblings" :key="sibling.id" :person="sibling" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import RelationshipPicker from '@/Components/RelationshipPicker.vue';
import RelativeCard from '@/Components/RelativeCard.vue';
import axios from 'axios';

const props = defineProps({
  person: { type: Object, required: true },
  father: { type: Object, default: null },
  mother: { type: Object, default: null },
  spouses: { type: Array, default: () => [] },
  children: { type: Array, default: () => [] },
  siblings: { type: Array, default: () => [] },
});

const addingSpouse = ref(false);
const addingChild = ref(false);
const coParentId = ref('');
const newChildName = ref('');
const busy = ref(false);

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

function startAddChild() {
  addingChild.value = true;
  newChildName.value = '';
  // Si un seul conjoint, il est automatiquement l'autre parent.
  coParentId.value = props.spouses.length === 1 ? props.spouses[0].id : '';
}

async function addChild(child) {
  if (busy.value) return;
  busy.value = true;
  try {
    await axios.post(`/people/${props.person.id}/child`, {
      child_id: child.id,
      other_parent_id: coParentId.value || null,
    });
    addingChild.value = false;
    router.reload();
  } catch (error) {
    alert(error.response?.data?.message || 'Erreur');
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
    addingChild.value = false;
    router.reload();
  } catch (error) {
    alert(error.response?.data?.message || 'Erreur');
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
    router.reload();
  } catch (error) {
    alert(error.response?.data?.message || 'Erreur');
  }
}

async function removeParent(type) {
  try {
    await axios.delete(`/people/${props.person.id}/parent`, {
      data: { parent_type: type },
    });
    router.reload();
  } catch (error) {
    alert(error.response?.data?.message || 'Erreur');
  }
}

async function addSpouse(spouse) {
  try {
    await axios.post(`/people/${props.person.id}/spouse`, {
      spouse_id: spouse.id,
    });
    addingSpouse.value = false;
    router.reload();
  } catch (error) {
    alert(error.response?.data?.message || 'Erreur');
  }
}

async function removeSpouse(spouse) {
  try {
    await axios.delete(`/people/${props.person.id}/spouse`, {
      data: { spouse_id: spouse.id },
    });
    router.reload();
  } catch (error) {
    alert(error.response?.data?.message || 'Erreur');
  }
}
</script>
