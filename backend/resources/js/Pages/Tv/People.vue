<template>
  <Head :title="$t('Proches — Télévision')" />
  <TvLayout :tab="tab" :circle="circle" :circles="circles" :account="account" back="/tv/ecran/accueil">
    <div class="px-24 pt-10 pb-4 flex flex-col gap-9">
      <section v-for="(row, r) in rows" :key="row.key" class="flex flex-col gap-3">
        <h2 class="font-display text-[2.25rem] font-bold">{{ row.title }}</h2>
        <div class="flex gap-4 overflow-x-auto -mx-24 px-[5.25rem] py-5 -my-3" data-tv-row>
          <TvPersonCard v-for="(p, i) in row.people" :key="p.id" :person="p" :data-tv-autofocus="r === 0 && i === 0 ? '' : null" />
        </div>
      </section>
      <p v-if="!rows.length" class="text-[2rem] text-[#c9bfb1]">{{ $t('Personne n\'est encore identifié sur les photos de ce cercle.') }}</p>
    </div>
  </TvLayout>
</template>

<script setup>
import { Head } from '@inertiajs/vue3';
import TvLayout from '@/Layouts/TvLayout.vue';
import TvPersonCard from '@/Components/Tv/TvPersonCard.vue';

defineProps({ tab: String, circle: Object, circles: Array, account: Object, rows: Array });
</script>
