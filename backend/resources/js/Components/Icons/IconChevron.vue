<template>
  <svg :class="iconClass" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
  </svg>
</template>

<script setup>
defineProps({
  iconClass: {
    type: String,
    default: 'icon-sm'
  }
});
</script>
