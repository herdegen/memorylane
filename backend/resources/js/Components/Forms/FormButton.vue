<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :class="buttonClasses"
    class="inline-flex items-center justify-center px-6 py-2.5 border rounded-lg font-medium transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
  >
    <!-- Loading spinner -->
    <svg
      v-if="loading"
      class="animate-spin -ml-1 mr-3 h-5 w-5"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
    >
      <circle
        class="opacity-25"
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        stroke-width="4"
      />
      <path
        class="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
      />
    </svg>

    <!-- Icon (slot or prop) -->
    <slot name="icon">
      <component v-if="icon" :is="icon" class="h-5 w-5 mr-2" />
    </slot>

    <!-- Label -->
    <span>{{ loading ? loadingText : text }}</span>

    <!-- Trailing icon -->
    <slot name="trailing-icon" />
  </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  type: {
    type: String,
    default: 'submit',
  },
  text: {
    type: String,
    required: true,
  },
  loadingText: {
    type: String,
    default: 'Chargement...',
  },
  loading: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  variant: {
    type: String,
    default: 'primary',
    validator: (value) => ['primary', 'secondary', 'danger', 'success', 'outline'].includes(value),
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value),
  },
  icon: {
    type: Object,
    default: null,
  },
  fullWidth: {
    type: Boolean,
    default: false,
  },
});

const buttonClasses = computed(() => {
  const baseClasses = [];

  // Variant classes
  const variantClasses = {
    primary: 'bg-brand-600 hover:bg-brand-700 text-white border-transparent focus:ring-brand-500 shadow-sm active:bg-brand-800',
    secondary: 'bg-white hover:bg-surface-50 text-surface-700 border-surface-300 focus:ring-brand-500',
    danger: 'bg-red-600 hover:bg-red-700 text-white border-transparent focus:ring-red-500 shadow-sm',
    success: 'bg-teal-600 hover:bg-teal-700 text-white border-transparent focus:ring-teal-500 shadow-sm',
    outline: 'bg-white hover:bg-surface-50 text-surface-700 border-surface-300 focus:ring-brand-500',
  };

  // Size classes
  const sizeClasses = {
    sm: 'text-sm px-4 py-2',
    md: 'text-base px-6 py-2.5',
    lg: 'text-lg px-8 py-3',
  };

  baseClasses.push(variantClasses[props.variant] || variantClasses.primary);
  baseClasses.push(sizeClasses[props.size] || sizeClasses.md);

  if (props.fullWidth) {
    baseClasses.push('w-full');
  }

  return baseClasses.join(' ');
});
</script>
