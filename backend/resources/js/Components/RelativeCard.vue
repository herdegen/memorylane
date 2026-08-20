<template>
  <div class="relative group">
    <Link
      :href="`/people/${person.id}`"
      class="flex items-center gap-3 p-2 rounded-lg border border-surface-200 hover:border-brand-300 hover:bg-surface-50 transition"
    >
      <img
        v-if="person.avatar_url"
        :src="person.avatar_url"
        :alt="personLabel(person)"
        class="w-12 h-12 rounded-full object-cover border border-surface-200 shrink-0"
      />
      <div
        v-else
        class="w-12 h-12 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center font-semibold shrink-0"
      >
        {{ initial }}
      </div>
      <div class="min-w-0">
        <p class="text-sm font-medium text-surface-900 truncate">{{ personLabel(person) }}</p>
        <p v-if="years" class="text-xs text-surface-500">{{ years }}</p>
      </div>
    </Link>

    <button
      v-if="removable"
      type="button"
      @click.prevent="$emit('remove')"
      class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 text-surface-400 hover:text-red-500 dark:hover:text-red-400 bg-white/90 rounded-full p-0.5 transition"
      title="Retirer"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { personLabel } from '@/utils/personName';

const props = defineProps({
  person: { type: Object, required: true },
  removable: { type: Boolean, default: false },
});

defineEmits(['remove']);

const initial = computed(() =>
  (props.person.name || props.person.first_name || '?').charAt(0).toUpperCase()
);

const years = computed(() => {
  const b = props.person.birth_date ? String(props.person.birth_date).substring(0, 4) : '';
  const d = props.person.death_date ? String(props.person.death_date).substring(0, 4) : '';
  if (b && d) return `${b} – ${d}`;
  if (b) return `n. ${b}`;
  if (d) return `† ${d}`;
  return '';
});
</script>
