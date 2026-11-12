<template>
  <!-- Date d'une photo à précision variable : jour exact, mois, année ou
       décennie (vieilles photos). v-model = { date, precision }, `date` au
       format AAAA-MM-JJ (ou AAAA-MM-JJTHH:mm avec `with-time` : heure vide =
       midi), ramenée au début de la période ; `precision` null pour une date
       exacte. -->
  <div class="space-y-2">
    <div class="flex flex-wrap gap-1.5" role="radiogroup" :aria-label="$t('Précision de la date')">
      <button
        v-for="option in options"
        :key="option.value"
        type="button"
        role="radio"
        :aria-checked="precision === option.value"
        class="px-3 py-1 rounded-full text-[13px] font-medium border transition"
        :class="precision === option.value ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white border-surface-300 text-surface-600 hover:bg-surface-50'"
        @click="setPrecision(option.value)"
      >
        {{ option.label }}
      </button>
    </div>

    <!-- Jour exact : la date, et l'heure si on la connaît (vide = midi). -->
    <div v-if="precision === 'day' && withTime" class="flex flex-wrap gap-2">
      <input :id="id" v-model="day" type="date" class="form-input flex-1 min-w-[10rem]" :max="today" />
      <input v-model="time" type="time" class="form-input w-[8.5rem]" :aria-label="$t('Heure (facultative)')" :title="$t('Heure facultative : midi si vide')" />
    </div>
    <input v-else-if="precision === 'day'" :id="id" v-model="day" type="date" class="form-input" :max="today" />
    <input v-else-if="precision === 'month'" :id="id" v-model="month" type="month" class="form-input" :max="today.slice(0, 7)" />
    <input
      v-else-if="precision === 'year'"
      :id="id"
      v-model.number="year"
      type="number"
      inputmode="numeric"
      min="1800"
      :max="currentYear"
      class="form-input max-w-[10rem]"
      :placeholder="$t('ex. 1972')"
    />
    <select v-else :id="id" v-model.number="decade" class="form-input max-w-[14rem]">
      <option :value="null" disabled>{{ $t('Choisir la décennie') }}</option>
      <option v-for="d in decades" :key="d" :value="d">{{ $t('années :decade', { decade: d }) }}</option>
    </select>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { t } from '@/i18n';

const props = defineProps({
  modelValue: { type: Object, default: () => ({ date: null, precision: null }) },
  withTime: { type: Boolean, default: false },
  id: { type: String, default: undefined },
});
const emit = defineEmits(['update:modelValue']);

const now = new Date();
const pad = (n) => String(n).padStart(2, '0');
const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
const currentYear = now.getFullYear();
const decades = Array.from({ length: Math.floor(currentYear / 10) - 184 }, (_, i) => Math.floor(currentYear / 10) * 10 - i * 10);

const options = [
  { value: 'day', label: t('Jour exact') },
  { value: 'month', label: t('Mois') },
  { value: 'year', label: t('Année') },
  { value: 'decade', label: t('Décennie') },
];

const initial = props.modelValue?.date ? String(props.modelValue.date) : '';
const precision = ref(props.modelValue?.precision || 'day');
const time = ref(initial.length > 10 ? initial.slice(11, 16) : '');
const day = ref(initial.slice(0, 10));
const month = ref(initial.slice(0, 7));
const year = ref(initial ? Number(initial.slice(0, 4)) : null);
const decade = ref(initial ? Math.floor(Number(initial.slice(0, 4)) / 10) * 10 : null);

const setPrecision = (value) => {
  precision.value = value;
};

const value = computed(() => {
  switch (precision.value) {
    case 'month':
      return month.value ? `${month.value}-01` : null;
    case 'year':
      return year.value && year.value >= 1800 && year.value <= currentYear ? `${year.value}-01-01` : null;
    case 'decade':
      return decade.value ? `${decade.value}-01-01` : null;
    default:
      if (!day.value) return null;
      // Heure inconnue : midi (pas minuit, qui glisse d'un jour selon le fuseau).
      return props.withTime ? `${day.value}T${time.value || '12:00'}` : day.value;
  }
});

watch([value, precision], () => {
  emit('update:modelValue', { date: value.value, precision: precision.value === 'day' ? null : precision.value });
});
</script>
