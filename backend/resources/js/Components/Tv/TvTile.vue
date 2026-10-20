<template>
  <!-- Tuile d'une collection (année, vidéos, souvenirs racontés…) : grand titre sur l'image. -->
  <Link :href="tile.href" data-tv class="tv-card shrink-0 relative rounded-[1.25rem] overflow-hidden bg-[#2c261e]" :style="{ width: width + 'rem', height: width * 0.62 + 'rem' }">
    <img v-if="tile.cover" :src="tile.cover" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover" />
    <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(0,0,0,.8), rgba(0,0,0,.1) 50%, rgba(0,0,0,0))"></div>
    <div class="absolute left-6 right-6 bottom-5 flex flex-col">
      <span class="font-display text-[2.75rem] font-bold leading-tight truncate">{{ tile.title }}</span>
      <span class="text-[1.375rem] text-[#e7e0d6]">{{ tile.meta }}</span>
    </div>
  </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
  tile: { type: Object, required: true },
  width: { type: Number, default: 21 },
});
</script>
