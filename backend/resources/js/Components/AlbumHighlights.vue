<template>
  <!-- Album à thème : ce que la page met en avant avant toutes les photos
       (sections calculées par AlbumHighlights côté serveur). -->
  <div class="px-4 sm:px-8 pt-5 sm:pt-8 space-y-8 sm:space-y-10">
    <section v-for="s in sections" :key="s.key">
      <!-- Personne(s) à l'honneur à désigner : simple proposition -->
      <button
        v-if="s.type === 'role' && s.compact && !s.people.length"
        type="button"
        class="inline-flex items-center gap-2 h-9 px-3.5 rounded-full border border-dashed border-surface-300 text-sm font-semibold text-surface-700 hover:border-brand-400 hover:text-brand-700"
        @click="$emit('edit-role', s)"
      >
        <span aria-hidden="true">+</span> {{ $t('Mettre quelqu’un à l’honneur') }}
      </button>

      <template v-else>
        <div class="flex items-baseline flex-wrap gap-x-3 gap-y-1 mb-3">
          <h2 class="font-display text-xl sm:text-2xl font-semibold text-surface-900">{{ s.title }}</h2>
          <span v-if="countOf(s)" class="text-[13px] text-surface-500">
            {{ s.subtitle || $tc(':count photo|:count photos', countOf(s)) }}
          </span>
          <span v-else-if="s.subtitle" class="text-[13px] text-surface-500">{{ s.subtitle }}</span>
          <button
            v-if="s.type === 'role' && s.people.length && canEdit"
            type="button"
            class="text-[13px] font-semibold text-brand-700 dark:text-brand-400 hover:underline"
            @click="$emit('edit-role', s)"
          >
            {{ $t('Modifier') }}
          </button>
          <button
            v-if="(s.ids || []).length > 5"
            type="button"
            class="ml-auto text-sm font-semibold text-brand-700 dark:text-brand-400 hover:underline"
            @click="$emit('filter', { label: s.title, ids: s.ids })"
          >
            {{ $t('Tout voir') }}
          </button>
        </div>
        <p v-if="s.why" class="-mt-1.5 mb-3 inline-flex text-xs text-surface-500 bg-surface-100 rounded-full px-2.5 py-0.5">{{ s.why }}</p>

        <!-- Personnes du rôle -->
        <div v-if="s.type === 'role' && s.people.length" class="flex flex-wrap gap-2 mb-3">
          <span v-for="p in s.people" :key="p.id" class="inline-flex items-center gap-2 pl-1 pr-3.5 py-1 rounded-full bg-white shadow-warm-sm text-sm font-semibold text-surface-800">
            <PersonAvatar :person="p" :size="8" />
            {{ p.first_name }}
          </span>
        </div>

        <!-- Rôle à remplir -->
        <div
          v-if="s.type === 'role' && !s.people.length"
          class="flex flex-wrap sm:flex-nowrap items-center gap-4 p-4 rounded-2xl border-[1.5px] border-dashed border-surface-300 bg-white/60"
        >
          <span class="flex shrink-0" aria-hidden="true">
            <span v-for="k in 3" :key="k" class="w-10 h-10 -mr-2 rounded-full border-[1.5px] border-dashed border-surface-300 bg-white grid place-items-center text-surface-400 text-lg">+</span>
          </span>
          <p class="flex-1 min-w-[12rem] text-sm text-surface-600">{{ s.empty }}</p>
          <button type="button" class="btn-primary w-full sm:w-auto justify-center" @click="$emit('edit-role', s)">
            {{ $t('Choisir') }}
          </button>
        </div>

        <!-- Photos : une grande, puis les autres -->
        <div v-if="(s.type === 'role' || s.type === 'tiles') && tilesOf(s).length" class="grid grid-cols-3 sm:grid-cols-6 gap-1 rounded-xl overflow-hidden">
          <button
            v-for="(m, i) in tilesOf(s)"
            :key="m.id"
            type="button"
            class="relative aspect-square overflow-hidden bg-surface-100"
            :class="[i === 0 && s.type === 'role' ? 'col-span-2 row-span-2' : '', i >= mobileCount(s) ? 'hidden sm:block' : '']"
            @click="$emit('open', m)"
          >
            <MediaCard :media="m" fill class="pointer-events-none" />
          </button>
          <button
            v-if="s.ids.length > mobileCount(s)"
            type="button"
            class="sm:hidden aspect-square grid place-items-center bg-surface-900 text-snow font-bold"
            @click="$emit('filter', { label: s.title, ids: s.ids })"
          >
            +{{ s.ids.length - mobileCount(s) }}
          </button>
          <button
            v-if="s.ids.length > desktopCount(s)"
            type="button"
            class="hidden sm:grid aspect-square place-items-center bg-surface-900 text-snow font-bold text-lg"
            @click="$emit('filter', { label: s.title, ids: s.ids })"
          >
            +{{ s.ids.length - desktopCount(s) }}
          </button>
        </div>

        <!-- Personnes de l'album : un nom = ses photos -->
        <div v-if="s.type === 'people'" class="flex gap-2 overflow-x-auto sm:flex-wrap -mx-4 px-4 sm:mx-0 sm:px-0 pb-1 [scrollbar-width:none]">
          <button
            v-for="p in s.people"
            :key="p.id"
            type="button"
            class="shrink-0 inline-flex items-center gap-2.5 pl-1 pr-4 py-1 rounded-full bg-white shadow-warm-sm hover:shadow-warm-md text-left transition"
            @click="$emit('filter', { label: p.name, ids: p.ids })"
          >
            <PersonAvatar :person="p" :size="9" />
            <span>
              <span class="block text-sm font-semibold text-surface-900 leading-tight">{{ s.key === 'people' ? p.name : p.first_name }}</span>
              <span class="block text-xs text-surface-500">{{ [p.years, $tc(':count photo|:count photos', p.ids.length)].filter(Boolean).join(' · ') }}</span>
            </span>
          </button>
        </div>

        <!-- Lignes : une décennie (archives), un jour (voyage)… -->
        <div v-if="s.type === 'rows'">
          <div v-for="r in s.rows" :key="r.key" class="grid sm:grid-cols-[11rem_1fr] gap-2 sm:gap-5 py-3 border-t border-surface-200">
            <button type="button" class="text-left self-start" @click="$emit('filter', { label: rowLabel(r), ids: r.ids })">
              <h3 class="font-display text-lg sm:text-xl font-semibold text-surface-900 leading-tight">{{ r.title }}</h3>
              <span v-if="r.subtitle" class="block text-[13px] text-surface-600 mt-0.5 first-letter:uppercase">{{ r.subtitle }}</span>
              <span class="block text-xs font-medium text-surface-500 mt-0.5">{{ $tc(':count photo|:count photos', r.ids.length) }}</span>
            </button>
            <div class="grid grid-cols-4 sm:grid-cols-6 gap-1 rounded-lg overflow-hidden self-start">
              <button
                v-for="(m, i) in mediaOf(r.ids).slice(0, r.ids.length > 6 ? 5 : 6)"
                :key="m.id"
                type="button"
                class="relative aspect-square overflow-hidden bg-surface-100"
                :class="i >= (r.ids.length > 4 ? 3 : 4) ? 'hidden sm:block' : ''"
                @click="$emit('open', m)"
              >
                <MediaCard :media="m" fill class="pointer-events-none" />
              </button>
              <button
                v-if="r.ids.length > 4"
                type="button"
                class="sm:hidden aspect-square grid place-items-center bg-surface-900 text-snow font-bold"
                @click="$emit('filter', { label: rowLabel(r), ids: r.ids })"
              >+{{ r.ids.length - 3 }}</button>
              <button
                v-if="r.ids.length > 6"
                type="button"
                class="hidden sm:grid aspect-square place-items-center bg-surface-900 text-snow font-bold"
                @click="$emit('filter', { label: rowLabel(r), ids: r.ids })"
              >+{{ r.ids.length - 5 }}</button>
            </div>
          </div>
        </div>

        <!-- Archives : photos sans date -->
        <div v-if="s.type === 'undated'" class="-mt-1 flex flex-wrap sm:flex-nowrap items-center gap-4 p-4 sm:p-5 rounded-2xl bg-brand-50 dark:bg-brand-500/10 border border-brand-100 dark:border-brand-500/20">
          <p class="flex-1 min-w-[12rem] text-sm text-surface-700">{{ s.text }}</p>
          <button type="button" class="btn-primary w-full sm:w-auto justify-center" @click="$emit('filter', { label: s.title, ids: s.ids })">
            {{ $t('Voir les photos à dater') }}
          </button>
        </div>
      </template>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import MediaCard from '@/Components/MediaCard.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';

const props = defineProps({
  highlights: { type: Object, required: true },
  // Médias de l'album (déjà chargés par la page), par id.
  media: { type: Array, default: () => [] },
  canEdit: { type: Boolean, default: false },
});
defineEmits(['open', 'filter', 'edit-role']);

const byId = computed(() => new Map(props.media.map((m) => [m.id, m])));
const sections = computed(() => props.highlights?.sections || []);
const mediaOf = (ids) => (ids || []).map((id) => byId.value.get(id)).filter(Boolean);

// Une grande photo (rôle) puis les autres, en lignes pleines avec la case
// « +N » : 5 photos sur téléphone (3 colonnes), 8 sur ordinateur (6 colonnes).
const mobileCount = () => 5;
const desktopCount = (s) => (s.type === 'role' ? 8 : 5);
const tilesOf = (s) => mediaOf(s.ids).slice(0, Math.max(mobileCount(s), desktopCount(s)));
const countOf = (s) => (s.ids || []).length;

// « Jour 2 · dimanche 8 août · Bruges » (bandeau du filtre)
const rowLabel = (r) => [r.title, r.subtitle].filter(Boolean).join(' · ');
</script>
