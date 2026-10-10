<template>
  <Head title="Ma famille" />
  <div class="min-h-screen bg-page text-surface-900 px-5 py-6 sm:px-10 sm:py-8 flex flex-col gap-7">

    <header class="flex items-center gap-6 flex-wrap">
      <Link href="/simple" class="min-h-[72px] px-7 rounded-full bg-white border-2 border-surface-200 flex items-center gap-3 text-[24px] font-semibold">
        <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7" /><path d="M5 10v10h14V10" /></svg>
        Accueil
      </Link>
      <h1 class="font-display text-5xl font-bold">Ma famille</h1>
    </header>

    <div v-if="!members.length" class="flex-1 flex items-center justify-center text-center text-2xl text-surface-600 px-8">
      Votre famille apparaîtra ici dès que votre fiche sera reliée à l'arbre.
    </div>

    <div v-else class="grid gap-5 grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
      <Link
        v-for="m in members"
        :key="m.id"
        :href="`/simple/photos?person=${m.id}`"
        class="rounded-[26px] overflow-hidden bg-white border-2 border-surface-200 shadow-warm-md flex flex-col"
      >
        <div class="relative aspect-[4/3] overflow-hidden bg-surface-200 flex items-center justify-center text-5xl font-bold text-surface-500">
          <img
            v-if="m.avatar_url"
            :src="m.avatar_url"
            alt=""
            class="absolute inset-0 w-full h-full object-cover"
            :class="{ grayscale: m.deceased }"
            loading="lazy"
          />
          <span v-else>{{ m.name.charAt(0) }}</span>
        </div>
        <div class="px-5 py-4 flex flex-col gap-0.5">
          <div class="text-[28px] font-bold leading-tight">{{ m.name }}</div>
          <div class="text-[21px] text-surface-600">{{ m.relation }}</div>
        </div>
      </Link>
    </div>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
  members: { type: Array, default: () => [] },
});
</script>
