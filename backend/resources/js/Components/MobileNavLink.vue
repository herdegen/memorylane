<template>
  <Link
    :href="href"
    :class="[
      active
        ? 'bg-indigo-50 border-indigo-500 text-indigo-700'
        : 'border-transparent text-gray-500 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-700',
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
