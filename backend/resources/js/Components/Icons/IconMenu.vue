<template>
  <svg :class="iconClass" fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path v-if="!open" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
    <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
  </svg>
</template>

<script setup>
defineProps({
  iconClass: {
    type: String,
    default: 'h-6 w-6'
  },
  open: {
    type: Boolean,
    default: false
  }
});
</script>
