<template>
  <!-- Carte d'un proche : visage en grand, nom, lien de parenté ou dates. -->
  <Link :href="`/tv/ecran/proches/${person.id}`" data-tv class="tv-person shrink-0 w-[13rem] flex flex-col items-center gap-3 text-center rounded-[1.5rem] p-3">
    <img v-if="person.avatar_url" :src="person.avatar_url" alt="" loading="lazy" class="w-[10.5rem] h-[10.5rem] rounded-full object-cover bg-[#3a2f1e]" />
    <span v-else class="w-[10.5rem] h-[10.5rem] rounded-full bg-[#3a2f1e] text-[#fcd34d] text-[4rem] font-bold flex items-center justify-center">{{ person.name.slice(0, 1) }}</span>
    <span class="text-[1.625rem] font-bold leading-tight line-clamp-2">{{ person.name }}</span>
    <span v-if="person.sub" class="-mt-1 text-[1.25rem] text-[#b9ad9c] line-clamp-1">{{ person.sub }}</span>
  </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({ person: { type: Object, required: true } });
</script>

<style scoped>
.tv-person:focus { background: #2c261e; transform: scale(1.05); }
</style>
