<template>
  <!-- Un élément daté du récit de vie (école, métier, lieu de vie…) -->
  <div v-if="row.editable" class="rounded-xl border border-surface-200 bg-white p-3">
    <div class="flex items-start gap-2">
      <div class="flex-1 grid gap-2" :class="def.place_only ? '' : 'sm:grid-cols-2'">
        <input
          v-if="!def.place_only && def.title_label"
          v-model="row.title"
          type="text"
          maxlength="255"
          :class="FIELD"
          :placeholder="def.title_label"
          :aria-label="def.title_label"
        />
        <p v-else-if="!def.place_only" class="self-center font-semibold text-surface-800">{{ def.title }}</p>
        <input v-model="row.place" type="text" maxlength="255" :class="FIELD" :placeholder="def.place_label" :aria-label="def.place_label" />
      </div>
      <button type="button" class="w-9 h-9 shrink-0 rounded-full text-surface-400 hover:text-surface-700 hover:bg-surface-100" :aria-label="$t('Retirer')" @click="$emit('remove')">×</button>
    </div>
    <div class="mt-2 flex items-center gap-2 text-sm text-surface-600">
      <span>{{ def.range ? $t('De') : $t('En') }}</span>
      <input v-model.number="row.from" type="number" inputmode="numeric" min="1000" max="2100" :class="[BASE, 'w-24 tabular-nums']" :placeholder="years ? String(years[0]) : $t('année')" :aria-label="def.range ? $t('De') : $t('En')" />
      <template v-if="def.range">
        <span>{{ $t('à') }}</span>
        <input v-model.number="row.to" type="number" inputmode="numeric" min="1000" max="2100" :class="[BASE, 'w-24 tabular-nums']" :placeholder="years && years[1] ? String(years[1]) : $t('année')" :aria-label="$t('à')" />
      </template>
    </div>
  </div>
  <!-- Saisi par une autre famille (fiche commune) : on le voit, sans le modifier -->
  <div v-else class="rounded-xl bg-surface-100 px-3 py-2 text-sm text-surface-700">
    {{ [row.title, row.place].filter(Boolean).join(' · ') }} — {{ row.to ? `${row.from}–${row.to}` : row.from }}
  </div>
</template>

<script setup>
defineProps({
  row: { type: Object, required: true },
  def: { type: Object, required: true },
  years: { type: Array, default: null },
});
defineEmits(['remove']);

const BASE = 'h-11 px-3 text-[15px] border border-surface-300 rounded-lg bg-white focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500';
const FIELD = `w-full ${BASE}`;
</script>

