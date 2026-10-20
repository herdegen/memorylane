<template>
  <!-- Interface télévision : pensée pour 1920×1080 vue à 3 mètres. Toutes les
       tailles sont en rem et la racine vaut 1/120e de la largeur (16 px en
       1080p) : l'écran garde ses proportions quelle que soit la résolution
       annoncée par le téléviseur. Couleurs fixes (pas de mode clair/sombre). -->
  <div class="tv-root min-h-screen bg-[#12100c] text-[#f7f2ea] font-sans flex flex-col" :class="{ 'h-screen overflow-hidden': fill }">
    <nav v-if="!bare" aria-label="Navigation" class="shrink-0 flex items-center gap-12 h-[4.5rem] mx-24 mt-[3.375rem]" :class="navClass">
      <div class="font-display text-[2.5rem] font-bold">MemoryLane</div>
      <div class="flex gap-3 flex-1" data-tv-row>
        <Link v-for="t in TABS" :key="t.key" :href="t.href" data-tv class="tv-pill" :class="{ 'tv-pill-on': tab === t.key }">{{ t.label }}</Link>
      </div>
      <div class="flex items-center gap-4" data-tv-row>
        <button v-if="circles.length > 1" type="button" data-tv class="tv-chip" @click="menu = 'circle'">{{ circle?.name || 'Cercle' }}</button>
        <span v-else-if="circle" class="tv-chip">{{ circle.name }}</span>
        <button type="button" data-tv class="w-14 h-14 rounded-full bg-[#fcd34d] text-[#14110d] text-[1.625rem] font-extrabold flex items-center justify-center" :aria-label="`Compte de ${account?.name}`" @click="menu = 'account'">
          {{ (account?.name || '?').slice(0, 1).toUpperCase() }}
        </button>
      </div>
    </nav>

    <main class="flex-1 min-h-0 flex flex-col">
      <slot />
    </main>

    <footer v-if="hints.length" class="shrink-0 flex gap-10 mx-24 mt-6 mb-10 text-[1.375rem] text-[#a89c8b]">
      <span v-for="[k, label] in hints" :key="k + label" class="flex items-center gap-2.5">
        <span class="px-3 py-0.5 border-2 border-[#6b6155] rounded-lg font-extrabold">{{ k }}</span>{{ label }}
      </span>
    </footer>

    <!-- Choix du cercle affiché sur cette TV -->
    <div v-if="menu === 'circle'" data-tv-scope class="fixed inset-0 z-[70] bg-black/80 flex items-center justify-center">
      <div class="w-[44rem] rounded-[2.25rem] bg-[#211d17] p-12 flex flex-col gap-4">
        <h2 class="font-display text-[3rem] font-bold mb-4">Quel cercle afficher ?</h2>
        <button
          v-for="c in circles"
          :key="c.id"
          type="button"
          data-tv
          :data-tv-autofocus="c.id === circle?.id ? '' : null"
          class="tv-option"
          @click="chooseCircle(c)"
        >
          {{ c.name }}<span v-if="c.id === circle?.id" class="ml-auto text-[#fcd34d]">affiché</span>
        </button>
      </div>
    </div>

    <!-- Compte / télévision -->
    <div v-if="menu === 'account'" data-tv-scope class="fixed inset-0 z-[70] bg-black/80 flex items-center justify-center">
      <div class="w-[48rem] rounded-[2.25rem] bg-[#211d17] p-12 flex flex-col gap-4">
        <h2 class="font-display text-[3rem] font-bold">{{ account?.device || 'Cette télévision' }}</h2>
        <p class="text-[1.75rem] text-[#c9bfb1] mb-4">Reliée au compte de {{ account?.name }}.</p>
        <button type="button" data-tv data-tv-autofocus class="tv-option" @click="menu = null">Continuer</button>
        <button v-if="account?.device" type="button" data-tv class="tv-option text-[#fca5a5]" @click="unlink">Délier cette télévision</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { installRemote, focusInitial, onTvKey } from '@/Tv/remote';

const props = defineProps({
  tab: { type: String, default: null },
  circle: { type: Object, default: null },
  circles: { type: Array, default: () => [] },
  account: { type: Object, default: null },
  // Page précédente (touche Retour) ; null sur l'accueil.
  back: { type: String, default: null },
  // Aide télécommande en pied de page : [touche, libellé].
  hints: { type: Array, default: () => [['OK', 'Ouvrir'], ['◀ ▲ ▼ ▶', 'Se déplacer'], ['↩', 'Retour']] },
  // Sans barre de navigation (appairage, jeu).
  bare: { type: Boolean, default: false },
  // Page à hauteur d'écran (pas de défilement de la page entière).
  fill: { type: Boolean, default: false },
  navClass: { type: String, default: '' },
});

const TABS = [
  { key: 'home', label: 'Accueil', href: '/tv/ecran/accueil' },
  { key: 'albums', label: 'Albums', href: '/tv/ecran/albums' },
  { key: 'game', label: 'Qui est-ce ?', href: '/tv/ecran/qui-est-ce' },
];

const menu = ref(null);
watch(menu, async () => {
  await nextTick();
  focusInitial();
});

onTvKey((action) => {
  if (menu.value && action === 'back') {
    menu.value = null;
    return true;
  }
  return false;
});

const chooseCircle = (c) => {
  menu.value = null;
  if (c.id !== props.circle?.id) router.post('/tv/ecran/cercle', { household_id: c.id });
};
const unlink = () => router.post('/tv/ecran/delier');

let uninstall = null;
let interceptor = null;
const html = document.documentElement;
const previous = { fontSize: html.style.fontSize, background: document.body.style.background };

onMounted(() => {
  html.style.fontSize = 'min(calc(100vw / 120), calc(100vh / 67.5))';
  document.body.style.background = '#12100c';
  uninstall = installRemote(() => {
    if (props.back) router.visit(props.back);
  });
  // TV déliée depuis le téléphone pendant qu'elle tourne : retour au code.
  interceptor = axios.interceptors.response.use(null, (error) => {
    if (error.response?.status === 401) window.location.href = '/tv/ecran';
    return Promise.reject(error);
  });
  nextTick(() => focusInitial());
});

onBeforeUnmount(() => {
  uninstall?.();
  axios.interceptors.response.eject(interceptor);
  html.style.fontSize = previous.fontSize;
  document.body.style.background = previous.background;
});
</script>

<style>
.tv-root { scrollbar-width: none; }
.tv-root ::-webkit-scrollbar, html:has(.tv-root)::-webkit-scrollbar { display: none; }
html:has(.tv-root) { scrollbar-width: none; }
.tv-root [data-tv] { outline: none; scroll-margin: 6rem 6rem 4rem; transition: transform .18s ease, box-shadow .18s ease, outline-color .18s ease; }
.tv-root [data-tv]:focus { outline: 0.375rem solid #f7f2ea; outline-offset: 0.375rem; }
.tv-pill { padding: 0.75rem 1.75rem; border-radius: 999px; color: #d8cfc2; font-size: 1.75rem; font-weight: 600; }
.tv-pill-on { background: #3a2f1e; color: #fcd34d; font-weight: 700; }
.tv-root .tv-pill:focus { background: #f7f2ea; color: #14110d; outline-offset: 0.25rem; }
.tv-chip { padding: 0.625rem 1.375rem; border-radius: 999px; border: 2px solid #b45309; color: #fcd34d; font-size: 1.5rem; font-weight: 700; }
.tv-option { display: flex; white-space: nowrap; align-items: center; gap: 1rem; padding: 1.25rem 2rem; border-radius: 1.5rem; background: #2c261e; font-size: 2rem; font-weight: 700; text-align: left; }
.tv-root .tv-option:focus { background: #f7f2ea; color: #14110d; }
.tv-btn { display: inline-flex; white-space: nowrap; align-items: center; gap: 0.875rem; padding: 1.125rem 2.125rem; border-radius: 999px; font-size: 1.875rem; font-weight: 800; background: rgba(255, 255, 255, .14); color: #f7f2ea; }
.tv-btn-primary { background: #f59e0b; color: #14110d; }
.tv-root .tv-btn:focus { transform: scale(1.05); box-shadow: 0 1.125rem 2.5rem -0.625rem rgba(0, 0, 0, .7); }
.tv-root .tv-btn:not(.tv-btn-primary):focus { background: #f7f2ea; color: #14110d; }
.tv-card:focus { transform: scale(1.06); }
</style>
