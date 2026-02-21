<template>
  <Link
    :href="href"
    :class="[
      active
        ? 'bg-brand-50 border-brand-500 text-brand-700'
        : 'border-transparent text-surface-500 hover:bg-surface-50 hover:border-surface-300 hover:text-surface-800',
      'block pl-3 pr-4 py-2 border-l-4 text-base font-medium'
    ]"
  >
    <slot />
  </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
  href: String,
  active: {
    type: Boolean,
    default: false,
  },
});
</script>
