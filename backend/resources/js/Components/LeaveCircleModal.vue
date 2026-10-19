<template>
  <!-- Quitter un cercle : ce que ça concerne, le sort de MES photos et
       albums partagés, et le successeur si je suis le créateur du groupe. -->
  <BaseModal :title="member ? `Retirer ${member.name} de « ${household.name} »` : `Quitter « ${household.name} »`" max-width="md" @close="$emit('close')">
    <div class="px-6 py-5 space-y-4">
      <p v-if="loading" class="text-sm text-surface-500">Chargement…</p>
      <template v-else-if="summary">
        <p v-if="member" class="text-sm text-surface-700">
          {{ firstName }} ne verra plus les photos et les fiches de ce cercle, et recevra un mail pour le prévenir.
          <template v-if="household.kind === 'family'">Sa fiche reste dans l'arbre.</template>
        </p>
        <p v-else class="text-sm text-surface-700">
          <template v-if="household.kind === 'family'">
            Vous ne verrez plus les photos et les fiches de la famille. <strong>Votre fiche reste dans l'arbre</strong>
            (on ne réécrit pas l'histoire), sans votre adresse.
          </template>
          <template v-else>
            Vous ne verrez plus les photos du groupe, et votre fiche en sortira.
          </template>
        </p>

        <!-- Mes photos / albums partagés avec ce cercle -->
        <fieldset v-if="summary.shared_photos || summary.shared_albums" class="space-y-2">
          <legend class="text-sm font-semibold text-surface-900 mb-1">
            {{ member ? `Ce que ${firstName} partage avec ce cercle` : 'Ce que vous partagez avec ce cercle' }} : {{ sharedLabel }}
          </legend>
          <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer" :class="photos === 'keep' ? 'border-brand-400 bg-brand-50 dark:bg-brand-500/10' : 'border-surface-200'">
            <input v-model="photos" type="radio" value="keep" class="mt-1" />
            <span class="text-sm"><strong class="block text-surface-900">Les laisser au cercle</strong>
              <span class="text-surface-500">Les membres continuent de les voir. {{ member ? `Ils restent à ${firstName}.` : 'Ils restent à vous.' }}</span></span>
          </label>
          <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer" :class="photos === 'withdraw' ? 'border-brand-400 bg-brand-50 dark:bg-brand-500/10' : 'border-surface-200'">
            <input v-model="photos" type="radio" value="withdraw" class="mt-1" />
            <span class="text-sm"><strong class="block text-surface-900">Les retirer du cercle</strong>
              <span class="text-surface-500">Ils redeviennent privés. Rien n'est supprimé.</span></span>
          </label>
        </fieldset>

        <!-- Créateur d'un groupe d'amis : qui le reprend -->
        <div v-if="summary.needs_successor">
          <label class="block text-sm font-semibold text-surface-900 mb-1">Qui reprend ce groupe ?</label>
          <select v-model="successorId" class="form-input">
            <option :value="null" disabled>Choisir un membre…</option>
            <option v-for="c in summary.candidates" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <p class="mt-1 text-xs text-surface-500">Il pourra inviter, retirer des membres et régler le groupe.</p>
        </div>
        <p v-else-if="summary.last_member && household.kind === 'friends'" class="text-sm text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/10 rounded-lg px-3 py-2">
          {{ member ? `${firstName} est le dernier membre : le groupe sera fermé.` : 'Vous êtes le dernier membre : le groupe sera fermé.' }}
        </p>

        <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
      </template>
    </div>
    <template #footer>
      <button type="button" class="btn-secondary" @click="$emit('close')">Annuler</button>
      <button
        type="button"
        class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-50"
        :disabled="saving || loading || (summary?.needs_successor && !successorId)"
        @click="leave"
      >
        {{ saving ? 'Un instant…' : (member ? 'Retirer du cercle' : 'Quitter le cercle') }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';

const props = defineProps({
  household: { type: Object, required: true },
  // Retrait par le responsable : { user_id, name } (sinon : je quitte moi-même)
  member: { type: Object, default: null },
});
const firstName = computed(() => (props.member?.name || '').trim().split(' ')[0]);
const base = computed(() => props.member
  ? `/households/${props.household.id}/members/${props.member.user_id}`
  : `/households/${props.household.id}/leave`);
const emit = defineEmits(['close', 'left']);

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const summary = ref(null);
const photos = ref('keep');
const successorId = ref(null);

const sharedLabel = computed(() => {
  const s = summary.value || {};
  const parts = [];
  if (s.shared_photos) parts.push(`${s.shared_photos} photo${s.shared_photos > 1 ? 's' : ''}`);
  if (s.shared_albums) parts.push(`${s.shared_albums} album${s.shared_albums > 1 ? 's' : ''}`);
  return parts.join(' et ');
});

onMounted(async () => {
  try {
    const { data } = await axios.get(props.member ? `${base.value}/removal` : base.value);
    summary.value = data;
  } catch (e) {
    error.value = e.response?.data?.message || 'Impossible de préparer le départ.';
  } finally {
    loading.value = false;
  }
});

const leave = async () => {
  saving.value = true;
  error.value = '';
  try {
    const payload = { photos: photos.value, successor_id: successorId.value };
    if (props.member) await axios.delete(base.value, { data: payload });
    else await axios.post(base.value, payload);
    emit('left');
  } catch (e) {
    error.value = e.response?.data?.errors?.successor_id?.[0] || e.response?.data?.message || 'Impossible de quitter le cercle.';
  } finally {
    saving.value = false;
  }
};
</script>
