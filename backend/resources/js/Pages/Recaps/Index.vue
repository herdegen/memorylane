<template>
  <Head :title="$t('Récaps')" />
  <AppLayout>
    <div class="py-4 sm:py-9 px-4 sm:px-8 max-w-6xl mx-auto">
      <h1 class="font-display text-[26px] sm:text-4xl font-bold text-surface-900">{{ $t('Récaps') }}</h1>
      <p class="mt-1.5 text-sm text-surface-500 max-w-2xl">
        {{ $t('De courts diaporamas qui racontent le passé de la famille. Touchez-en un pour le regarder.') }}
      </p>

      <!-- À la une : anniversaires des derniers jours -->
      <div v-if="featured.length" class="mt-6 sm:mt-8">
        <RecapStrip :recaps="featured" />
      </div>

      <!-- Les années : ce qui est entré dans l'archive -->
      <section v-if="years.length" class="mt-8 sm:mt-10">
        <h2 class="font-display text-xl sm:text-2xl font-semibold text-surface-900">{{ $t('Les années') }}</h2>
        <p class="mt-1 mb-4 text-[13px] text-surface-500">{{ $t('Ce qui est entré dans la mémoire de la famille, année après année.') }}</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
          <button
            v-for="r in years"
            :key="r.key"
            type="button"
            class="group relative aspect-[4/3] rounded-2xl overflow-hidden bg-surface-200 text-left shadow-warm-sm hover:shadow-warm-md transition"
            @click="player?.play(r)"
          >
            <img v-if="r.avatar_url" :src="r.avatar_url" alt="" class="absolute inset-0 w-full h-full object-cover transition-transform duration-500 group-hover:scale-105" loading="lazy" />
            <span class="absolute inset-0 bg-linear-to-t from-black/75 via-black/20 to-transparent"></span>
            <span class="absolute left-3 right-3 bottom-3 text-snow">
              <span class="block font-display text-2xl sm:text-3xl font-bold leading-none">{{ r.year }}</span>
              <span class="block text-xs text-snow/85 mt-1">{{ r.subtitle }}</span>
            </span>
            <span class="absolute top-2.5 right-2.5 w-8 h-8 rounded-full bg-snow/90 text-ink grid place-items-center text-xs" aria-hidden="true">▶</span>
          </button>
        </div>
      </section>

      <!-- Au fil des ans : une photo par année -->
      <section class="mt-8 sm:mt-10">
        <h2 class="font-display text-xl sm:text-2xl font-semibold text-surface-900">{{ $t('Au fil des ans') }}</h2>
        <p class="mt-1 mb-4 text-[13px] text-surface-500">{{ $t('Une photo par année, de la plus ancienne à la plus récente.') }}</p>
        <div v-if="people.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2.5 sm:gap-4">
          <button
            v-for="r in people"
            :key="r.key"
            type="button"
            class="group flex items-center gap-3 p-2.5 sm:p-3 rounded-2xl bg-white shadow-warm-sm hover:shadow-warm-md text-left transition"
            @click="player?.play(r)"
          >
            <PersonAvatar :person="{ name: r.title, avatar_url: r.avatar_url, avatar_position: r.avatar_position }" :size="12" class="shrink-0" />
            <span class="min-w-0">
              <span class="block text-sm sm:text-[15px] font-semibold text-surface-900 leading-tight line-clamp-2">{{ r.title }}</span>
              <span class="block text-xs text-surface-500 mt-0.5">{{ $tc(':count année|:count années', r.years) }} · {{ r.subtitle }}</span>
            </span>
            <span class="ml-auto shrink-0 w-8 h-8 rounded-full bg-brand-50 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 grid place-items-center group-hover:bg-brand-600 group-hover:text-white transition" aria-hidden="true">▶</span>
          </button>
        </div>
        <p v-else class="text-sm text-surface-500 py-8">
          {{ $t('Pas encore de récap : il faut au moins cinq années de photos où la personne est reconnue.') }}
        </p>
      </section>

      <RecapPlayer ref="player" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import RecapPlayer from '@/Components/RecapPlayer.vue';
import RecapStrip from '@/Components/RecapStrip.vue';

defineProps({
  featured: { type: Array, default: () => [] },
  years: { type: Array, default: () => [] },
  people: { type: Array, default: () => [] },
});
const player = ref(null);
</script>
