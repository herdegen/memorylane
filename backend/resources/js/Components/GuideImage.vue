<template>
  <!-- Vignette du guide : servie authentifiée (/guide/images/…, photos de
       famille), variante -dark selon le thème. Dégradation silencieuse : le
       bloc disparaît si la capture manque (jamais d'icône cassée).
       ⚠️ L'image doit garder une boîte dans le layout tant qu'elle n'est pas
       chargée (aspect fixe + opacity, PAS de display:none) : un <img lazy>
       sans boîte n'est jamais chargé par le navigateur. -->
  <div
    v-if="!failed"
    class="aspect-[16/10] rounded-xl overflow-hidden border border-surface-200 bg-surface-100 shadow-warm-sm"
  >
    <img
      :src="src"
      :alt="alt"
      loading="lazy"
      class="w-full h-full object-cover object-top transition-opacity duration-300"
      :class="loaded ? 'opacity-100' : 'opacity-0'"
      @load="loaded = true"
      @error="onError"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useTheme } from '@/Composables/useTheme';

const props = defineProps({
  // Nom de base de la vignette (cf. scripts/guide-captures.mjs).
  name: { type: String, required: true },
  alt: { type: String, default: '' },
});

const { isDark } = useTheme();

const loaded = ref(false);
const failed = ref(false);
// Si la variante -dark manque (régénération partielle), on retombe sur la light.
const forceLight = ref(false);

// URL sans extension (nginx intercepte les *.webp en statique).
const src = computed(
  () => `/guide/images/${props.name}${isDark.value && !forceLight.value ? '-dark' : ''}`
);

watch(src, () => {
  loaded.value = false;
});

const onError = () => {
  if (isDark.value && !forceLight.value) {
    forceLight.value = true;
    return;
  }
  failed.value = true;
};
</script>
