<template>
  <svg :class="iconClass" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
  </svg>
</template>

<script setup>
defineProps({
  iconClass: {
    type: String,
    default: 'icon'
  }
});
</script>
