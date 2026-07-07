<template>
  <svg :class="iconClass" fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <!-- Soleil : affiché en mode sombre (cliquer → repasser en clair) -->
    <g v-if="dark" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
    </g>
    <!-- Lune : affichée en mode clair (cliquer → passer en sombre) -->
    <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
  </svg>
</template>

<script setup>
defineProps({
  iconClass: {
    type: String,
    default: 'icon'
  },
  dark: {
    type: Boolean,
    default: false
  }
});
</script>
