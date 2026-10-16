<template>
  <!-- Feuille du bas (mobile) : poignée, fond assombri, fermeture par
       glissement vers le bas, toucher du fond ou Échap. Sur desktop elle
       reste utilisable (centrée en bas) mais les pages y préfèrent BaseModal. -->
  <Teleport to="body">
    <Transition name="sheet">
      <div
        v-if="open"
        class="fixed inset-0 z-[70]"
        role="dialog"
        aria-modal="true"
        :aria-label="title || undefined"
      >
        <div class="absolute inset-0 bg-surface-900/50" @click="$emit('close')"></div>
        <div
          ref="panel"
          class="sheet-panel absolute inset-x-0 bottom-0 sm:left-1/2 sm:right-auto sm:w-[420px] sm:-translate-x-1/2
                 bg-page rounded-t-3xl shadow-warm-lg max-h-[88vh] flex flex-col"
          :style="{ transform: dragY ? `translateY(${dragY}px)` : undefined, transition: dragging ? 'none' : undefined }"
          @touchstart.passive="onTouchStart"
          @touchmove.passive="onTouchMove"
          @touchend="onTouchEnd"
        >
          <div class="shrink-0 pt-2.5 pb-1 flex justify-center" aria-hidden="true">
            <span class="w-10 h-1 rounded-full bg-surface-300"></span>
          </div>
          <div v-if="title || $slots.header" class="shrink-0 px-4 pb-2 flex items-center justify-between gap-3">
            <slot name="header">
              <h2 class="text-display text-xl text-surface-900 truncate">{{ title }}</h2>
            </slot>
            <button
              type="button"
              class="w-9 h-9 rounded-full bg-surface-200 text-surface-700 flex items-center justify-center shrink-0"
              aria-label="Fermer"
              @click="$emit('close')"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <div class="overflow-y-auto px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
            <slot />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
});
const emit = defineEmits(['close']);

// Glissement vers le bas : on suit le doigt, on ferme au-delà de 90 px.
const panel = ref(null);
const dragY = ref(0);
const dragging = ref(false);
let startY = 0;

const onTouchStart = (e) => {
  // Seulement depuis le haut de la feuille (poignée/titre) ou quand le
  // contenu est en haut : sinon on laisse défiler.
  const scroller = panel.value?.querySelector('.overflow-y-auto');
  if (scroller && scroller.scrollTop > 0) return;
  startY = e.touches[0].clientY;
  dragging.value = true;
};
const onTouchMove = (e) => {
  if (!dragging.value) return;
  dragY.value = Math.max(0, e.touches[0].clientY - startY);
};
const onTouchEnd = () => {
  if (!dragging.value) return;
  const shouldClose = dragY.value > 90;
  dragging.value = false;
  dragY.value = 0;
  if (shouldClose) emit('close');
};

const onKey = (e) => {
  if (e.key === 'Escape' && props.open) emit('close');
};
watch(() => props.open, (v) => {
  if (v) document.addEventListener('keydown', onKey);
  else document.removeEventListener('keydown', onKey);
  document.body.style.overflow = v ? 'hidden' : '';
}, { immediate: true });
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey);
  document.body.style.overflow = '';
});
</script>

<style scoped>
.sheet-enter-active, .sheet-leave-active { transition: opacity .2s ease; }
.sheet-enter-active .sheet-panel, .sheet-leave-active .sheet-panel { transition: transform .25s cubic-bezier(.2,.8,.2,1); }
.sheet-enter-from, .sheet-leave-to { opacity: 0; }
.sheet-enter-from .sheet-panel, .sheet-leave-to .sheet-panel { transform: translateY(100%); }
@media (min-width: 640px) {
  .sheet-enter-from .sheet-panel, .sheet-leave-to .sheet-panel { transform: translate(-50%, 100%); }
}
</style>
