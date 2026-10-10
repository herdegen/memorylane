<template>
  <!-- « Comment on s'est connus » : une version par compte, la mienne éditable -->
  <div class="space-y-2">
    <div class="flex items-center justify-between">
      <div class="text-xs font-semibold text-surface-600">Comment vous vous êtes connus</div>
      <button
        v-if="!editing && mine"
        type="button"
        class="text-xs font-semibold text-brand-700 hover:underline"
        @click="startEdit"
      >Modifier</button>
    </div>

    <div v-if="loading" class="text-xs text-surface-500">Chargement…</div>

    <template v-else-if="!editing">
      <div
        v-for="v in versions"
        :key="v.id"
        class="rounded-xl bg-brand-50 dark:bg-brand-500/10 px-3.5 py-2.5"
      >
        <div class="text-sm text-surface-900 leading-snug">
          <span class="font-semibold">{{ headline(v) }}</span>
          <span v-if="v.story"> — {{ v.story }}</span>
        </div>
        <div class="text-xs text-surface-500 mt-0.5">
          {{ v.mine ? 'Votre version' : `Raconté par ${v.author}` }}<span v-if="v.year"> · {{ v.year }}</span>
        </div>
      </div>
      <button
        v-if="!mine"
        type="button"
        class="w-full h-11 rounded-xl border border-dashed border-brand-300 text-sm font-semibold text-brand-700 hover:bg-brand-50 dark:hover:bg-brand-500/10"
        @click="startEdit"
      >
        {{ versions.length ? 'Ajouter ma version' : 'Raconter votre rencontre' }}
      </button>
    </template>

    <form v-else class="space-y-2.5" @submit.prevent="save">
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="c in contexts"
          :key="c.key"
          type="button"
          class="h-8 px-3 rounded-full text-xs font-semibold border transition-colors"
          :class="form.context === c.key
            ? 'border-brand-500 bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200'
            : 'border-surface-200 bg-white text-surface-700 hover:border-surface-300'"
          @click="form.context = c.key"
        >{{ c.label }}</button>
      </div>
      <input v-model="form.story" type="text" maxlength="500" class="form-input" placeholder="En une phrase (facultatif)" aria-label="Récit (facultatif)" />
      <input v-model="form.year" type="number" min="1900" :max="currentYear" class="form-input w-32" placeholder="Année ?" aria-label="Année (facultatif)" />
      <div class="flex gap-2">
        <button type="submit" class="btn-primary" :disabled="!form.context || saving">Enregistrer</button>
        <button type="button" class="btn-secondary" @click="editing = false">Annuler</button>
        <button v-if="mine" type="button" class="ml-auto text-xs font-semibold text-red-600 dark:text-red-400" @click="remove">Supprimer</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
  personId: { type: String, required: true },
  // Autre personne de la paire ; par défaut la fiche « moi » du visiteur.
  withId: { type: String, default: null },
});

const toast = useToast();
const currentYear = new Date().getFullYear();

const loading = ref(true);
const saving = ref(false);
const editing = ref(false);
const versions = ref([]);
const contexts = ref([]);
const form = ref({});

const mine = computed(() => versions.value.find((v) => v.mine) || null);

const params = () => (props.withId ? { with: props.withId } : {});

function apply(data) {
  versions.value = data.versions;
  if (data.contexts) contexts.value = data.contexts;
}

async function load() {
  loading.value = true;
  editing.value = false;
  try {
    const { data } = await axios.get(`/people/${props.personId}/links`, { params: params() });
    apply(data);
  } catch {
    versions.value = [];
  } finally {
    loading.value = false;
  }
}

function headline(v) {
  if (v.context === 'friend' && v.via) return `Par ${v.via.name}`;
  return v.context_label || 'Rencontre';
}

function startEdit() {
  form.value = mine.value
    ? { context: mine.value.context, story: mine.value.story || '', year: mine.value.year || '', via_person_id: mine.value.via?.id || null }
    : { context: null, story: '', year: '', via_person_id: null };
  editing.value = true;
}

async function save() {
  saving.value = true;
  try {
    const { data } = await axios.put(`/people/${props.personId}/links`, {
      ...params(),
      context: form.value.context,
      // « Par un ami » garde le « via » d'origine seulement si on n'a pas changé de contexte.
      via_person_id: form.value.context === 'friend' ? form.value.via_person_id : null,
      story: form.value.story || null,
      year: form.value.year || null,
    });
    apply(data);
    editing.value = false;
  } catch (e) {
    toast.error(e.response?.data?.message || "Impossible d'enregistrer.");
  } finally {
    saving.value = false;
  }
}

async function remove() {
  try {
    const { data } = await axios.delete(`/people/${props.personId}/links`, { data: params() });
    apply(data);
    editing.value = false;
  } catch {
    toast.error('Impossible de supprimer.');
  }
}

watch(() => [props.personId, props.withId], load, { immediate: true });
</script>
