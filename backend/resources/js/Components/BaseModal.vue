<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 overflow-y-auto"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="labelledby"
    >
      <!-- Backdrop -->
      <div class="fixed inset-0 bg-surface-900/50 transition-opacity" @click="$emit('close')"></div>

      <!-- Panneau -->
      <div class="flex min-h-full items-center justify-center p-4">
        <div
          ref="panel"
          class="relative w-full transform overflow-hidden rounded-modal bg-white text-left shadow-warm-lg"
          :class="[maxWidthClass, panelClass]"
          @click.stop
        >
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script>
// Pile des modales ouvertes — portée MODULE (une seule pile pour toute
// l'app, pas une par instance) : seule la modale du dessus réagit à Échap,
// et le verrou de scroll n'est rendu qu'à la fermeture de la dernière.
const openModals = [];
</script>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

// Enveloppe commune de toutes les modales : Teleport vers <body>, backdrop,
// fermeture à Échap et au clic hors panneau, focus initial + restitution du
// focus à la fermeture, cycle de tabulation confiné au panneau, verrou du
// scroll de la page. Le parent monte/démonte la modale en v-if et écoute @close.
const props = defineProps({
  // Largeur maximale du panneau (classes max-w-* de Tailwind).
  maxWidth: {
    type: String,
    default: 'lg', // md | lg | 2xl | 4xl
  },
  // Classes additionnelles du panneau (ex. 'max-h-[85vh] flex flex-col').
  panelClass: {
    type: [String, Array, Object],
    default: '',
  },
  // Id de l'élément qui sert de titre (aria-labelledby).
  labelledby: {
    type: String,
    default: undefined,
  },
});

const emit = defineEmits(['close']);

const maxWidthClass = computed(() => ({
  md: 'max-w-md',
  lg: 'max-w-lg',
  '2xl': 'max-w-2xl',
  '4xl': 'max-w-4xl',
}[props.maxWidth] || 'max-w-lg'));

const panel = ref(null);
let previouslyFocused = null;

const focusables = () =>
  Array.from(
    panel.value?.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    ) || []
  ).filter((el) => !el.disabled && el.offsetParent !== null);

const handleKeydown = (event) => {
  if (event.key === 'Escape') {
    if (openModals[openModals.length - 1] === handleKeydown) {
      emit('close');
    }
    return;
  }
  // Cycle de tabulation confiné au panneau.
  if (event.key === 'Tab') {
    const items = focusables();
    if (items.length === 0) return;
    const first = items[0];
    const last = items[items.length - 1];
    // Focus parti hors du panneau (clic sur une zone non focusable) :
    // on le ramène dedans au lieu de laisser Tab s'échapper vers la page.
    if (!panel.value?.contains(document.activeElement)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
    } else if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }
};

onMounted(() => {
  previouslyFocused = document.activeElement;
  openModals.push(handleKeydown);
  document.addEventListener('keydown', handleKeydown);
  document.body.style.overflow = 'hidden';
  nextTick(() => {
    // Priorité à un éventuel [autofocus], sinon premier élément focusable.
    const target = panel.value?.querySelector('[autofocus]') || focusables()[0];
    target?.focus();
  });
});

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleKeydown);
  const index = openModals.indexOf(handleKeydown);
  if (index !== -1) openModals.splice(index, 1);
  if (openModals.length === 0) {
    document.body.style.overflow = '';
  }
  previouslyFocused?.focus?.();
});
</script>
