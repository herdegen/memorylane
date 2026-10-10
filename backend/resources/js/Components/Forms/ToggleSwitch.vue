<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :disabled="disabled"
    @click="$emit('update:modelValue', !modelValue)"
    class="w-full flex items-start justify-between gap-4 text-left disabled:opacity-50"
  >
    <span>
      <span class="block text-sm font-medium text-surface-700">{{ label }}</span>
      <span v-if="help" class="block mt-1 text-xs text-surface-500">{{ help }}</span>
    </span>
    <span
      :class="[
        'relative inline-flex h-5 w-9 shrink-0 mt-0.5 rounded-full transition-colors',
        modelValue ? 'bg-brand-600' : 'bg-surface-300'
      ]"
    >
      <span
        :class="[
          'absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform',
          modelValue ? 'translate-x-4.5' : 'translate-x-0.5'
        ]"
      ></span>
    </span>
  </button>
</template>

<script setup>
// Interrupteur maison (libellé + aide à gauche, bouton à droite), utilisé
// dans le profil pour les préférences booléennes.
defineProps({
  modelValue: { type: Boolean, default: false },
  label: { type: String, required: true },
  help: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);
</script>
