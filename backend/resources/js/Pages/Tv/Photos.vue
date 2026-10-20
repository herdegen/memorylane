<template>
  <Head title="Photos — Télévision" />
  <TvLayout :tab="tab" :circle="circle" :circles="circles" :account="account" back="/tv/ecran/accueil">
    <div class="px-24 pt-10 pb-4 flex flex-col gap-9">
      <h1 class="font-display text-[3.5rem] font-bold">Les photos, année par année</h1>
      <section v-for="row in rows" :key="row.key" class="flex flex-col gap-4">
        <h2 class="font-display text-[2.25rem] font-bold">{{ row.title }}</h2>
        <div class="flex gap-7 overflow-x-auto -mx-24 px-24 py-6 -my-3" data-tv-row>
          <TvTile v-for="(t, i) in row.tiles" :key="t.key" :tile="t" :data-tv-autofocus="row === rows[0] && i === 0 ? '' : null" />
        </div>
      </section>
      <p v-if="!rows.length" class="text-[2rem] text-[#c9bfb1]">Pas encore de photos dans ce cercle.</p>
    </div>
  </TvLayout>
</template>

<script setup>
import { Head } from '@inertiajs/vue3';
import TvLayout from '@/Layouts/TvLayout.vue';
import TvTile from '@/Components/Tv/TvTile.vue';

defineProps({ tab: String, circle: Object, circles: Array, account: Object, rows: Array });
</script>
