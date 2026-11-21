<template>
  <!-- Identification refusée à cause de la date de la photo : souvent une
       vieille photo reprise en photo, datée du jour de la reprise. On propose
       de lui donner son époque (décennie ou année), puis on réessaie. -->
  <BottomSheet :open="!!refusal" :title="$t('La date de la photo ne colle pas')" @close="$emit('close')">
    <div v-if="refusal" class="space-y-3 pb-2">
      <p class="text-sm text-surface-800">{{ refusal.message }}</p>

      <template v-if="refusal.photo?.can_edit">
        <p class="text-sm text-surface-600">
          {{ $t("Une ancienne photo reprise en photo garde la date du jour de la reprise. Indiquez plutôt son époque :") }}
        </p>
        <div class="flex rounded-xl bg-surface-100 p-1 text-sm font-semibold" role="radiogroup">
          <button
            v-for="opt in precisions"
            :key="opt.value"
            type="button"
            role="radio"
            :aria-checked="precision === opt.value"
            class="flex-1 h-9 rounded-lg"
            :class="precision === opt.value ? 'bg-white text-surface-900 shadow-xs' : 'text-surface-600'"
            @click="precision = opt.value"
          >{{ opt.label }}</button>
        </div>
        <select v-if="precision === 'decade'" v-model.number="decade" class="form-input w-full h-12 text-base" :aria-label="$t('Décennie')">
          <option v-for="d in decades" :key="d" :value="d">{{ $t('Années :decade', { decade: d }) }}</option>
        </select>
        <input
          v-else
          v-model.number="year"
          type="number"
          inputmode="numeric"
          :min="1830"
          :max="thisYear"
          class="form-input w-full h-12 text-base"
          :aria-label="$t('Année')"
        />
        <p v-if="stillImpossible" class="text-sm text-amber-700 dark:text-amber-300">
          {{ $t('À cette époque non plus, :name ne pouvait pas être sur la photo.', { name: refusal.person?.name }) }}
        </p>
        <button
          type="button"
          class="w-full h-12 rounded-xl bg-brand-600 text-white font-bold disabled:opacity-50"
          :disabled="saving || !chosenYear || stillImpossible"
          @click="save"
        >
          {{ $t('Corriger la date et identifier :name', { name: firstName }) }}
        </button>
      </template>
      <p v-else class="text-sm text-surface-600">
        {{ $t("Demandez à la personne qui a envoyé la photo de corriger sa date.") }}
      </p>

      <button type="button" class="w-full h-11 rounded-xl border border-surface-300 text-sm font-semibold text-surface-700" @click="$emit('close')">
        {{ $t('Annuler') }}
      </button>
    </div>
  </BottomSheet>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import BottomSheet from '@/Components/BottomSheet.vue';
import { useToast } from '@/Composables/useToast';
import { apiError } from '@/utils/apiError';
import { t } from '@/i18n';

// refusal : réponse 422 « photo_date » du serveur
// { message, person: { name, birth_year, death_year }, photo: { id, year, precision, can_edit } }
const props = defineProps({ refusal: { type: Object, default: null } });
const emit = defineEmits(['close', 'fixed']);
const toast = useToast();

const thisYear = new Date().getFullYear();
const decades = [];
for (let d = Math.floor(thisYear / 10) * 10; d >= 1840; d -= 10) decades.push(d);
const precisions = [
  { value: 'decade', label: t('Décennie') },
  { value: 'year', label: t('Année') },
];

const precision = ref('decade');
const decade = ref(null);
const year = ref(null);
const saving = ref(false);

const firstName = computed(() => (props.refusal?.person?.name || '').split(' ')[0]);

// Époque proposée : la personne vers 30 ans, sans dépasser son décès.
watch(() => props.refusal, (r) => {
  if (!r) return;
  const b = r.person?.birth_year;
  const d = r.person?.death_year;
  let guess = b ? b + 30 : (d ? d - 10 : thisYear - 50);
  if (d) guess = Math.min(guess, d);
  guess = Math.min(guess, thisYear);
  precision.value = 'decade';
  decade.value = Math.floor(guess / 10) * 10;
  year.value = guess;
}, { immediate: true });

const chosenYear = computed(() => (precision.value === 'decade' ? decade.value : year.value));

// Même règle que le serveur (un an de marge) : inutile d'enregistrer une
// date qui serait encore refusée.
const stillImpossible = computed(() => {
  const y = chosenYear.value;
  if (!y || !props.refusal) return false;
  const from = y;
  const to = precision.value === 'decade' ? y + 9 : y;
  const b = props.refusal.person?.birth_year;
  const d = props.refusal.person?.death_year;
  return (b && b > to + 1) || (d && d < from - 1);
});

const save = async () => {
  if (!chosenYear.value || saving.value) return;
  saving.value = true;
  try {
    await axios.put(`/media/${props.refusal.photo.id}`, {
      taken_at: `${chosenYear.value}-01-01`,
      taken_at_precision: precision.value,
    }, { headers: { Accept: 'application/json' } });
    emit('fixed', { year: chosenYear.value, precision: precision.value });
  } catch (error) {
    toast.error(apiError(error, t('La date n’a pas pu être enregistrée.')));
  } finally {
    saving.value = false;
  }
};
</script>
