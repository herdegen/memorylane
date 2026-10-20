<template>
  <!-- Mosaïque de photos et vidéos ; OK ouvre la visionneuse (data-index : on y revient en la fermant). -->
  <div class="grid grid-cols-5 gap-6">
    <button
      v-for="(m, i) in items"
      :key="m.id"
      type="button"
      data-tv
      :data-index="i"
      class="tv-card relative aspect-[4/3] rounded-[1.25rem] overflow-hidden bg-[#2c261e]"
      :aria-label="m.title || `Photo ${i + 1}`"
      @click="$emit('open', i)"
    >
      <img :src="m.thumb" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover" />
      <span v-if="m.type === 'video'" class="absolute right-3 bottom-3 w-12 h-12 rounded-full bg-black/60 flex items-center justify-center">
        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
      </span>
      <span v-if="m.voice_notes.length" class="absolute left-3 bottom-3 w-12 h-12 rounded-full bg-[#f59e0b] text-[#14110d] flex items-center justify-center" aria-label="Souvenir raconté">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0014 0M12 18v3" /></svg>
      </span>
    </button>
  </div>
</template>

<script setup>
defineProps({ items: { type: Array, required: true } });
defineEmits(['open']);
</script>
