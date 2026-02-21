<template>
  <Link
    :href="href"
    :class="classes"
    class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none"
  >
    <slot />
  </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
  href: {
    type: String,
    required: true,
  },
  active: {
    type: Boolean,
    default: false,
  },
});

const classes = computed(() => {
  return props.active
    ? 'border-brand-500 text-surface-900 focus:border-brand-700'
    : 'border-transparent text-surface-500 hover:text-surface-800 hover:border-surface-300 focus:text-surface-800 focus:border-surface-300';
});
</script>
