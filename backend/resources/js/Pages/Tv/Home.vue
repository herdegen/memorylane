<template>
  <Head :title="$t('Télévision')" />
  <TvLayout :tab="tab" :circle="circle" :circles="circles" :account="account">
    <div class="flex flex-col gap-12 px-24 pt-10">
      <div class="grid grid-cols-[1.75fr_1fr] gap-8 h-[25rem]">
        <!-- Ce jour-là (ou l'album à l'honneur) -->
        <Link v-if="hero" :href="hero.href" data-tv data-tv-autofocus class="tv-hero relative rounded-[1.75rem] overflow-hidden bg-[#2c261e]">
          <img v-if="hero.image" :src="hero.image" alt="" class="absolute inset-0 w-full h-full object-cover" />
          <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(0,0,0,.85), rgba(0,0,0,.2) 50%, rgba(0,0,0,0))"></div>
          <div class="absolute left-12 right-12 bottom-11 flex flex-col gap-3.5">
            <span class="self-start px-4.5 py-2 rounded-full bg-white/20 text-[1.375rem] font-extrabold tracking-[.08em] uppercase">{{ hero.kicker }}</span>
            <h1 class="font-display text-[4rem] leading-[1.05] font-bold line-clamp-2">{{ hero.title }}</h1>
            <p class="text-[1.75rem] text-[#e7e0d6]">{{ hero.sub }}</p>
            <span class="tv-btn tv-btn-primary self-start mt-2">
              <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
              {{ hero.action }}
            </span>
          </div>
        </Link>
        <section v-else class="rounded-[1.75rem] bg-[#211d17] p-12 flex flex-col justify-end gap-4">
          <h1 class="font-display text-[3.5rem] font-bold">{{ $t('Bienvenue') }}</h1>
          <p class="text-[1.875rem] text-[#c9bfb1]">{{ $t('Les albums de ce cercle apparaîtront ici dès qu\'ils auront des photos.') }}</p>
        </section>

        <div class="flex flex-col gap-8" data-tv-row>
          <Link v-if="person" :href="`/tv/ecran/proches/${person.id}`" data-tv class="tv-card flex-1 min-h-0 rounded-[1.75rem] bg-[#211d17] px-8 py-7 flex items-center gap-7">
            <img v-if="person.avatar_url" :src="person.avatar_url" alt="" class="w-[7.5rem] h-[7.5rem] shrink-0 rounded-full object-cover bg-[#3a2f1e]" />
            <span v-else class="w-[7.5rem] h-[7.5rem] shrink-0 rounded-full bg-[#3a2f1e] text-[#fcd34d] text-[3.5rem] font-bold flex items-center justify-center">{{ person.name.slice(0, 1) }}</span>
            <span class="flex flex-col gap-2 min-w-0">
              <span class="text-[1.375rem] font-extrabold tracking-[.08em] uppercase text-[#fcd34d]">{{ $t('Personne du jour') }}</span>
              <span class="font-display text-[2.5rem] font-bold leading-tight line-clamp-2">{{ person.name }}</span>
              <span class="text-[1.625rem] text-[#c9bfb1]">{{ person.sub }}</span>
            </span>
          </Link>
          <Link href="/tv/ecran/qui-est-ce" data-tv class="tv-card flex-1 min-h-0 rounded-[1.75rem] bg-[#211d17] px-8 py-7 flex items-center gap-7">
            <span class="w-[7.5rem] h-[7.5rem] shrink-0 rounded-3xl bg-[#3a2f1e] text-[#fcd34d] flex items-center justify-center">
              <svg class="w-[4.5rem] h-[4.5rem]" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="9" r="4" /><path d="M4 21a8 8 0 0116 0" /><path d="M18 3.5c1.2.4 2 1.4 2 2.6 0 1.6-1.5 2-1.5 3.4M18.5 12.2v.3" /></svg>
            </span>
            <span class="flex flex-col gap-2">
              <span class="text-[1.375rem] font-extrabold tracking-[.08em] uppercase text-[#fcd34d]">{{ $t('Jeu de salon') }}</span>
              <span class="font-display text-[2.5rem] font-bold">{{ $t('Qui est-ce ?') }}</span>
              <span class="text-[1.625rem] text-[#c9bfb1]">{{ gameLabel }}</span>
            </span>
          </Link>
        </div>
      </div>

      <section v-if="albums.length" class="flex flex-col gap-5">
        <h2 class="font-display text-[2.5rem] font-bold">{{ $t('Albums') }}</h2>
        <div class="flex gap-7 overflow-x-auto -mx-24 px-24 py-6 -my-3" data-tv-row>
          <TvAlbumCard v-for="a in albums" :key="a.id" :album="a" :width="19" />
        </div>
      </section>
    </div>
  </TvLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import TvLayout from '@/Layouts/TvLayout.vue';
import TvAlbumCard from '@/Components/Tv/TvAlbumCard.vue';
import { t, tc } from '@/i18n';

const props = defineProps({
  tab: String,
  circle: Object,
  circles: Array,
  account: Object,
  hero: Object,
  person: Object,
  facesToName: Number,
  albums: Array,
});

const gameLabel = computed(() => {
  const n = props.facesToName || 0;
  if (!n) return t('Tous les visages ont un nom');
  return tc(':count visage à nommer|:count visages à nommer', n, { count: n >= 200 ? '200+' : n });
});
</script>

<style scoped>
.tv-hero:focus .tv-btn { background: #f7f2ea; transform: scale(1.05); }
</style>
