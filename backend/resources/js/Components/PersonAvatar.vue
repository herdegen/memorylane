<template>
  <!-- Racine <span> en display:flex : valide dans un <button> ou un lien, et
       de niveau bloc (pas d'espace de ligne de base sous l'avatar). -->
  <span
    class="flex items-center justify-center overflow-hidden shrink-0"
    :class="[shapeClass, sizeClass, url ? photoClass : fallbackClass]"
  >
    <img
      v-if="url"
      :src="url"
      :alt="alt"
      class="w-full h-full object-cover"
      :class="imgClass"
      :style="facePosition ? { objectPosition: facePosition } : undefined"
      :loading="eager ? undefined : 'lazy'"
      decoding="async"
    />
    <template v-else>{{ initial }}</template>
  </span>
</template>

<script setup>
import { computed } from 'vue';

// Avatar d'une personne : sa photo (avatar_url), cadrée sur le visage quand
// le serveur fournit avatar_position (avatar « photo entière »), sinon
// l'initiale de son nom sur fond coloré.
//
// Dimensions : `size` (préréglages des petites pastilles rondes) ou des
// classes w-/h-/aspect- passées en `class`. Les classes de `class` valent
// dans les deux cas ; `photoClass` seulement avec une photo, `fallbackClass`
// seulement pour l'initiale (fond, couleur, taille du texte).
//
// Ne sert pas aux cartes de l'arbre (family-chart génère leur HTML hors Vue).
const props = defineProps({
  // Objet personne : name, avatar_url, avatar_position.
  person: { type: Object, default: null },
  // Surcharges explicites (ou à la place de `person`).
  name: { type: String, default: undefined },
  src: { type: String, default: undefined },
  position: { type: String, default: undefined },
  // Préréglages : 5, 6, 7, 8, 9, 10 ou 12 (w-N h-N + taille de l'initiale).
  size: { type: [Number, String], default: null },
  // circle (rounded-full) | rounded (rounded-xl) | none (le parent arrondit).
  shape: { type: String, default: 'circle' },
  alt: { type: String, default: '' },
  photoClass: { type: [String, Array, Object], default: '' },
  fallbackClass: { type: [String, Array, Object], default: 'bg-brand-100 text-brand-700 font-bold' },
  imgClass: { type: [String, Array, Object], default: '' },
  // Avatar en tête de page : chargé tout de suite plutôt qu'en différé.
  eager: { type: Boolean, default: false },
});

const SIZES = {
  5: 'w-5 h-5 text-xs',
  6: 'w-6 h-6 text-xs',
  7: 'w-7 h-7 text-xs',
  8: 'w-8 h-8 text-xs',
  9: 'w-9 h-9 text-sm',
  10: 'w-10 h-10 text-sm',
  12: 'w-12 h-12 text-base',
};

const SHAPES = {
  circle: 'rounded-full',
  rounded: 'rounded-xl',
  none: '',
};

// Petites pastilles (préréglages de taille) : vignette carrée légère du
// portrait stocké plutôt que le portrait 480×640 des cartes.
const url = computed(() => {
  const base = props.src ?? props.person?.avatar_url ?? null;
  return base && props.size && base.includes('/portrait?') ? `${base}&s=sm` : base;
});
const facePosition = computed(() => props.position ?? props.person?.avatar_position ?? null);
const initial = computed(() =>
  (props.name ?? props.person?.name ?? '?').charAt(0).toUpperCase() || '?'
);
const sizeClass = computed(() => SIZES[props.size] || '');
const shapeClass = computed(() => SHAPES[props.shape] ?? SHAPES.circle);
</script>
