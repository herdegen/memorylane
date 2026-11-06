<template>
  <!-- Partager une branche avec une autre famille : les fiches choisies
       deviennent communes aux deux familles (aperçu avant de confirmer). -->
  <BaseModal :title="$t('Partager avec une autre famille')" max-width="md" @close="emit('close')">
    <div class="px-6 py-5 space-y-5">
      <p class="text-sm text-surface-600">
        {{ $t('Les fiches choisies deviennent communes aux deux familles. Chacune ne voit autour que ce qui est à elle : ses propres enfants, conjoints et photos.') }}
      </p>

      <!-- Sans fiche de départ (« Pour bien démarrer ») : on la cherche. -->
      <div v-if="!personId" class="space-y-2">
        <label for="branch-root" class="block text-sm font-semibold text-surface-900">{{ $t('À partir de qui ?') }}</label>
        <div v-if="chosen" class="flex items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-3 py-2 text-sm">
          <span class="font-semibold text-surface-900">{{ chosen.name }} <span v-if="chosen.years" class="font-normal text-surface-500">{{ chosen.years }}</span></span>
          <button type="button" class="text-brand-700 hover:underline" @click="chosen = null; query = ''">{{ $t('Changer') }}</button>
        </div>
        <template v-else>
          <input id="branch-root" v-model="query" type="search" class="form-input" :placeholder="$t('Nom d’une personne de vos autres familles…')" @input="search" />
          <ul v-if="results.length" class="divide-y divide-surface-100 rounded-xl border border-surface-200">
            <li v-for="r in results" :key="r.id">
              <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-50" @click="chosen = r">
                {{ r.name }} <span v-if="r.years" class="text-surface-500">{{ r.years }}</span>
              </button>
            </li>
          </ul>
        </template>
      </div>

      <fieldset v-if="families.length > 1" class="space-y-2">
        <legend class="text-sm font-semibold text-surface-900 mb-1">{{ $t('Avec quelle famille ?') }}</legend>
        <label v-for="f in families" :key="f.id" class="flex items-center gap-2 text-sm text-surface-800">
          <input v-model="familyId" type="radio" :value="f.id" class="text-brand-600 focus:ring-brand-500" />
          {{ f.name }}
        </label>
      </fieldset>

      <fieldset class="space-y-2">
        <legend class="text-sm font-semibold text-surface-900 mb-1">{{ $t('Quelles fiches ?') }}</legend>
        <label v-for="s in scopes" :key="s.value" class="flex items-start gap-2 text-sm text-surface-800">
          <input v-model="scope" type="radio" :value="s.value" class="mt-0.5 text-brand-600 focus:ring-brand-500" />
          <span>{{ s.label }}</span>
        </label>
      </fieldset>

      <div class="space-y-2">
        <label class="flex items-start gap-2 text-sm text-surface-800">
          <input v-model="living" type="checkbox" class="mt-0.5 rounded border-surface-300 text-brand-600 focus:ring-brand-500" />
          <span>{{ $t('Inclure les personnes vivantes (avec leur accord)') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-surface-800">
          <input v-model="photos" type="checkbox" class="mt-0.5 rounded border-surface-300 text-brand-600 focus:ring-brand-500" />
          <span>{{ preview ? tc('Partager aussi mes photos où elles figurent (:count)|Partager aussi mes photos où elles figurent (:count)', preview.photos, { count: preview.photos }) : $t('Partager aussi mes photos où elles figurent') }}</span>
        </label>
      </div>

      <div class="rounded-xl bg-surface-50 border border-surface-200 px-4 py-3 text-sm text-surface-700 min-h-[3.5rem]" aria-live="polite">
        <p v-if="loading" class="text-surface-500">{{ $t('Calcul…') }}</p>
        <template v-else-if="preview">
          <p class="font-semibold text-surface-900">
            {{ tc(':count fiche sera partagée avec « :family ».|:count fiches seront partagées avec « :family ».', preview.to_share, { count: preview.to_share, family: preview.family }) }}
          </p>
          <p v-if="preview.living && !preview.living_included">{{ tc(':count personne vivante (ou peut-être) reste à l’écart.|:count personnes vivantes (ou peut-être) restent à l’écart.', preview.living, { count: preview.living }) }}</p>
          <p v-if="preview.already">{{ tc(':count y est déjà.|:count y sont déjà.', preview.already, { count: preview.already }) }}</p>
        </template>
      </div>

      <div class="flex justify-end gap-3">
        <button type="button" class="btn-secondary" @click="emit('close')">{{ $t('Annuler') }}</button>
        <button type="button" class="btn-primary" :disabled="!canShare || saving" @click="share">
          {{ saving ? $t('Partage…') : $t('Partager') }}
        </button>
      </div>
    </div>
  </BaseModal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import { t, tc } from '@/i18n';
import { useToast } from '@/Composables/useToast';
import { apiError } from '@/utils/apiError';

const props = defineProps({
  // Fiche de départ ; absente : on la cherche dans ses autres familles.
  personId: { type: String, default: null },
  personName: { type: String, default: '' },
  // Ses autres familles : [{ id, name }].
  families: { type: Array, required: true },
});
const emit = defineEmits(['close', 'shared']);
const toast = useToast();

const familyId = ref(props.families[0]?.id ?? null);
const chosen = ref(props.personId ? { id: props.personId, name: props.personName } : null);
const query = ref('');
const results = ref([]);
let searchTimer = 0;
function search() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    if (query.value.trim().length < 2) { results.value = []; return; }
    const { data } = await axios.get(`/households/${familyId.value}/branch-candidates`, { params: { q: query.value.trim() } });
    results.value = data;
  }, 250);
}
const scope = ref('descendants');
const living = ref(false);
const photos = ref(false);
const preview = ref(null);
const loading = ref(false);
const saving = ref(false);

const scopes = computed(() => [
  { value: 'person', label: t('Seulement la fiche de :name', { name: chosen.value?.name ?? '' }) },
  { value: 'descendants', label: t('Ses descendants : enfants, petits-enfants…') },
  { value: 'ancestors', label: t('Ses ancêtres : parents, grands-parents…') },
  { value: 'both', label: t('Ses ancêtres et ses descendants') },
]);
const params = () => ({ household_id: familyId.value, scope: scope.value, living: living.value ? 1 : 0, photos: photos.value ? 1 : 0 });
const canShare = computed(() => preview.value && (preview.value.to_share > 0 || (photos.value && preview.value.photos > 0)));

let request = 0;
async function refresh() {
  if (!familyId.value || !chosen.value) { preview.value = null; return; }
  const mine = ++request;
  loading.value = true;
  try {
    const { data } = await axios.get(`/people/${chosen.value.id}/share-branch`, { params: params() });
    if (mine === request) preview.value = data;
  } catch (e) {
    if (mine === request) toast.error(apiError(e, t('Impossible de préparer le partage.')));
  } finally {
    if (mine === request) loading.value = false;
  }
}
watch([familyId, scope, living, chosen], refresh, { immediate: true });

async function share() {
  saving.value = true;
  try {
    const { data } = await axios.post(`/people/${chosen.value.id}/share-branch`, params());
    toast.success(tc(':count fiche partagée avec « :family ».|:count fiches partagées avec « :family ».', data.shared, { count: data.shared, family: data.family }));
    emit('shared');
    emit('close');
  } catch (e) {
    toast.error(apiError(e, t('Impossible de partager.')));
  } finally {
    saving.value = false;
  }
}
</script>
