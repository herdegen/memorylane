<template>
  <!-- Mode amis : « Vos souvenirs ensemble », à la place de la frise de vie -->
  <div class="pt-9 pb-2">
    <div class="flex items-end justify-between gap-4 mb-4 px-4 sm:px-8">
      <div>
        <h2 class="font-display text-2xl font-semibold text-surface-900">
          {{ data?.together ? $t('Vos souvenirs ensemble') : $t('Ses souvenirs') }}
        </h2>
        <p v-if="data?.total" class="text-sm text-surface-500 mt-0.5">
          {{ $tc(':count photo|:count photos', data.total) }}
          <template v-if="data.first?.date && data.last?.date">
            · {{ $t('de :from à :to', { from: yearOf(data.first.date), to: yearOf(data.last.date) }) }}
          </template>
        </p>
      </div>
    </div>

    <div v-if="loading" class="text-sm text-surface-500 px-4 sm:px-8">{{ $t('Chargement…') }}</div>

    <div v-else-if="!data?.total" class="text-sm text-surface-500 px-4 sm:px-8">
      {{ data?.together
        ? $t('Pas encore de photo de vous deux avec :name dans ce cercle.', { name: personName })
        : $t('Pas encore de photo de :name dans ce cercle.', { name: personName }) }}
    </div>

    <div v-else class="flex gap-4 overflow-x-auto px-4 sm:px-8 pb-4 snap-x">
      <!-- Première photo -->
      <Link
        :href="`/media/${data.first.id}`"
        class="snap-start shrink-0 w-56 rounded-2xl overflow-hidden bg-white border border-surface-200 hover:shadow-warm-md transition"
      >
        <img :src="data.first.url" alt="" class="w-full h-36 object-cover" loading="lazy" />
        <div class="p-3">
          <div class="text-xs font-semibold uppercase tracking-wider text-brand-700">{{ $t('Première photo') }}</div>
          <div class="text-sm font-semibold text-surface-900 mt-0.5">{{ longDate(data.first.date) }}</div>
        </div>
      </Link>

      <!-- Chapitres : albums, puis années -->
      <component
        :is="c.album_id ? Link : 'div'"
        v-for="c in data.chapters"
        :key="c.title + c.year"
        :href="c.album_id ? `/albums/${c.album_id}` : undefined"
        class="snap-start shrink-0 w-64 rounded-2xl overflow-hidden bg-white border border-surface-200"
        :class="c.album_id ? 'hover:shadow-warm-md transition' : ''"
      >
        <div class="grid grid-cols-2 gap-0.5 h-36 bg-surface-100">
          <img
            v-for="t in c.thumbs"
            :key="t.id"
            :src="t.url"
            alt=""
            loading="lazy"
            class="w-full h-full object-cover"
            :class="c.thumbs.length === 1 ? 'col-span-2 row-span-2' : (c.thumbs.length === 3 && t === c.thumbs[0] ? 'row-span-2' : '')"
          />
        </div>
        <div class="p-3">
          <div class="text-sm font-semibold text-surface-900 truncate">{{ c.title }}</div>
          <div class="text-xs text-surface-500">
            <span v-if="c.year && c.album_id">{{ c.year }} · </span>{{ $tc(':count photo|:count photos', c.count) }}
          </div>
        </div>
      </component>

      <!-- Dernière photo -->
      <Link
        v-if="data.last.id !== data.first.id"
        :href="`/media/${data.last.id}`"
        class="snap-start shrink-0 w-56 rounded-2xl overflow-hidden bg-white border border-surface-200 hover:shadow-warm-md transition"
      >
        <img :src="data.last.url" alt="" class="w-full h-36 object-cover" loading="lazy" />
        <div class="p-3">
          <div class="text-xs font-semibold uppercase tracking-wider text-brand-700">{{ $t('Dernière photo') }}</div>
          <div class="text-sm font-semibold text-surface-900 mt-0.5">{{ longDate(data.last.date) }}</div>
        </div>
      </Link>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { formatLongDate } from '@/utils/format';
import { t } from '@/i18n';

const props = defineProps({
  personId: { type: String, required: true },
  personName: { type: String, default: '' },
});

const loading = ref(true);
const data = ref(null);

const yearOf = (d) => (d ? d.substring(0, 4) : '');
const longDate = (d) => (d ? formatLongDate(d) : t('Sans date'));

async function load() {
  loading.value = true;
  try {
    ({ data: data.value } = await axios.get(`/people/${props.personId}/together`));
  } catch {
    data.value = null;
  } finally {
    loading.value = false;
  }
}

watch(() => props.personId, load, { immediate: true });
</script>
