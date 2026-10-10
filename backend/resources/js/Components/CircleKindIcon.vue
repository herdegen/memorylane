<template>
  <!-- Amis : petit réseau ; famille : arbre -->
  <svg v-if="kind === 'friends'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
    <circle cx="12" cy="5" r="2.5" /><circle cx="5" cy="17" r="2.5" /><circle cx="19" cy="15" r="2.5" />
    <path d="M10.8 7.2 6.2 14.8M14.2 6.3l3.9 6.6M7.5 17h9" />
  </svg>
  <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
    <rect x="9" y="2.5" width="6" height="4.5" rx="1" /><rect x="2.5" y="16.5" width="6" height="4.5" rx="1" />
    <rect x="15.5" y="16.5" width="6" height="4.5" rx="1" /><path d="M12 7v4.5M5.5 16.5v-5h13v5" />
  </svg>
</template>

<script setup>
defineProps({ kind: { type: String, default: 'family' } });
</script>
