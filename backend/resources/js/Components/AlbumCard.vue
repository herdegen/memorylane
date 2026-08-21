<template>
  <div
    class="relative group aspect-[4/3] rounded-2xl overflow-hidden bg-surface-100 cursor-pointer shadow-warm-sm transition-all duration-200 hover:shadow-warm-md hover:scale-[1.01]"
    @click="$emit('click', album)"
  >
    <!-- Couverture plein cadre -->
    <img
      v-if="album.cover_url"
      :src="album.cover_url"
      :alt="album.name"
      class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
      loading="lazy"
    />
    <div
      v-else
      class="absolute inset-0 flex items-center justify-center bg-linear-to-br from-brand-50 to-surface-200"
    >
      <svg class="h-16 w-16 text-brand-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.5"
          d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
        />
      </svg>
    </div>

    <!-- Badges posés sur la photo -->
    <div class="absolute top-2.5 right-2.5 z-10 flex items-center gap-1.5">
      <span
        v-if="album.is_smart"
        class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-violet-100/95 text-violet-700 backdrop-blur-sm"
        title="Album intelligent : se remplit automatiquement"
      >
        ✦ Auto
      </span>
      <span
        :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold backdrop-blur-sm', visibility.class]"
      >
        {{ visibility.label }}
      </span>
    </div>

    <!-- Cartouche givré (même langage que les cartes de l'arbre familial) -->
    <div class="absolute inset-x-2 bottom-2 rounded-xl bg-white/85 backdrop-blur-sm px-3.5 py-2.5">
      <h3 class="font-display text-[17px] font-semibold text-surface-900 truncate leading-snug">
        {{ album.name }}
      </h3>
      <p class="text-xs text-surface-600 mt-0.5 truncate">{{ subLine }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  album: {
    type: Object,
    required: true,
  },
  // Nom du propriétaire, affiché quand l'album est partagé AVEC moi.
  ownerName: {
    type: String,
    default: null,
  },
});

defineEmits(['click']);

const visibility = computed(() => {
  if (props.album.is_public) {
    return { label: 'Public', class: 'bg-teal-100/95 text-teal-800' };
  }
  if ((props.album.accesses_count || 0) > 0 || props.album.share_token) {
    return { label: 'Partagé', class: 'bg-amber-100/95 text-amber-800' };
  }
  return { label: 'Privé', class: 'bg-white/85 text-surface-600' };
});

const subLine = computed(() => {
  const count = props.album.media_count || 0;
  return [
    `${count} ${count === 1 ? 'média' : 'médias'}`,
    props.ownerName ? `partagé par ${props.ownerName}` : null,
    props.album.description || null,
  ].filter(Boolean).join(' · ');
});
</script>
