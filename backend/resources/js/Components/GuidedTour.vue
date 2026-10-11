<template>
  <span hidden></span>
</template>

<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { attachTour, detachTour } from '@/Composables/useTour';

// Monté par AppLayout sur chaque page : reprend la visite guidée en cours
// (ou la propose à la première arrivée sur l'accueil).
const page = usePage();
onMounted(() => attachTour(page));
onBeforeUnmount(detachTour);
</script>

<style>
/* Bulles de la visite guidée (driver.js), aux couleurs de MemoryLane. */
.driver-popover.ml-tour {
  border-radius: 18px;
  padding: 20px 20px 16px;
  max-width: min(360px, calc(100vw - 32px));
  background: #fff;
  color: #44403c;
  box-shadow: 0 18px 50px -12px rgba(41, 37, 36, 0.35);
  font-family: inherit;
}
.driver-popover.ml-tour .driver-popover-title {
  font-family: var(--font-display, Georgia, serif);
  font-size: 1.3rem;
  font-weight: 700;
  color: #1c1917;
  line-height: 1.25;
}
.driver-popover.ml-tour .driver-popover-description {
  margin-top: 6px;
  font-size: 0.95rem;
  line-height: 1.55;
}
.driver-popover.ml-tour .ml-tour-progress {
  display: block;
  margin-top: 10px;
  font-size: 0.75rem;
  color: #a8a29e;
}
.driver-popover.ml-tour .driver-popover-footer button {
  border-radius: 10px;
  padding: 8px 14px;
  font-size: 0.9rem;
  font-weight: 600;
  text-shadow: none;
  border: 1px solid #e7e5e4;
  background: #fff;
  color: #57534e;
}
.driver-popover.ml-tour .driver-popover-footer .driver-popover-next-btn {
  background: var(--color-brand-600, #d97706);
  border-color: var(--color-brand-600, #d97706);
  color: #fff;
}
.driver-popover.ml-tour .driver-popover-close-btn {
  color: #a8a29e;
}
[data-theme='dark'] .driver-popover.ml-tour {
  background: #292524;
  color: #d6d3d1;
}
[data-theme='dark'] .driver-popover.ml-tour .driver-popover-title {
  color: #fafaf9;
}
[data-theme='dark'] .driver-popover.ml-tour .driver-popover-footer button {
  background: #1c1917;
  border-color: #44403c;
  color: #d6d3d1;
}
[data-theme='dark'] .driver-popover.ml-tour .driver-popover-footer .driver-popover-next-btn {
  background: var(--color-brand-600, #d97706);
  color: #fff;
}
[data-theme='dark'] .driver-popover.ml-tour .driver-popover-arrow-side-bottom { border-bottom-color: #292524; }
[data-theme='dark'] .driver-popover.ml-tour .driver-popover-arrow-side-top { border-top-color: #292524; }
[data-theme='dark'] .driver-popover.ml-tour .driver-popover-arrow-side-left { border-left-color: #292524; }
[data-theme='dark'] .driver-popover.ml-tour .driver-popover-arrow-side-right { border-right-color: #292524; }
</style>
