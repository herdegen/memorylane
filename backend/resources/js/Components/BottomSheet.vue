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
        <!-- Voile en noir fixe : surface-900 devient clair en mode sombre. -->
        <div class="absolute inset-0 bg-black/50" @click="$emit('close')"></div>
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
import { pushOverlay, popOverlay, isTopOverlay } from '@/utils/modalStack';

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
  // Seulement depuis le haut de la feuille (poignée/titre) ou quand la zone
  // qui défile SOUS LE DOIGT est en haut (une grille, une liste imbriquée…) :
  // sinon on laisse défiler.
  const scroller = e.target.closest?.('.overflow-y-auto, .overflow-auto');
  if (scroller && panel.value?.contains(scroller) && scroller.scrollTop > 0) return;
  const sheetScroller = panel.value?.querySelector('.overflow-y-auto');
  if (sheetScroller && sheetScroller.scrollTop > 0) return;
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

// Échap et verrou de défilement : pile partagée avec BaseModal (une feuille
// ouverte sur une modale ne libère pas son verrou, Échap ne ferme qu'elle).
const onKey = (e) => {
  if (e.key === 'Escape' && props.open && isTopOverlay(onKey)) emit('close');
};
let stacked = false;
const setOpen = (v) => {
  if (v && !stacked) {
    stacked = true;
    pushOverlay(onKey);
    document.addEventListener('keydown', onKey);
  } else if (!v && stacked) {
    stacked = false;
    document.removeEventListener('keydown', onKey);
    popOverlay(onKey);
  }
};
watch(() => props.open, setOpen, { immediate: true });
onBeforeUnmount(() => setOpen(false));
</script>

<style scoped>
.sheet-enter-active, .sheet-leave-active { transition: opacity .2s ease; }
.sheet-enter-active .sheet-panel, .sheet-leave-active .sheet-panel { transition: transform .25s cubic-bezier(.2,.8,.2,1); }
.sheet-enter-from, .sheet-leave-to { opacity: 0; }
.sheet-enter-from .sheet-panel, .sheet-leave-to .sheet-panel { transform: translateY(100%); }
/* Desktop : le centrage passe par la propriété CSS `translate` (Tailwind v4,
   sm:-translate-x-1/2) ; l'animation ne joue que sur `transform`, en Y. */
</style>
