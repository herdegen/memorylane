<template>
  <!-- Interface télévision : pensée pour 1920×1080 vue à 3 mètres. Toutes les
       tailles sont en rem et la racine vaut 1/120e de la largeur (16 px en
       1080p) : l'écran garde ses proportions quelle que soit la résolution
       annoncée par le téléviseur. Couleurs fixes (pas de mode clair/sombre). -->
  <div class="tv-root min-h-screen bg-[#12100c] text-[#f7f2ea] font-sans flex flex-col" :class="{ 'h-screen overflow-hidden': fill }">
    <nav v-if="!bare" :aria-label="$t('Navigation')" class="shrink-0 flex items-center gap-9 h-[4.5rem] mx-24 mt-[3.375rem]" :class="navClass">
      <div class="font-display text-[2.25rem] font-bold whitespace-nowrap">MemoryLane</div>
      <div class="flex gap-1.5 flex-1 min-w-0" data-tv-row>
        <Link v-for="t in TABS" :key="t.key" :href="t.href" data-tv class="tv-pill" :class="{ 'tv-pill-on': tab === t.key }">{{ t.label }}</Link>
        <Link href="/tv/ecran/recherche" data-tv class="tv-pill flex items-center" :class="{ 'tv-pill-on': tab === 'search' }" :aria-label="$t('Recherche')">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" /></svg>
        </Link>
      </div>
      <div class="flex items-center gap-4" data-tv-row>
        <button v-if="circles.length > 1" type="button" data-tv class="tv-chip" @click="menu = 'circle'">{{ circle?.name || $t('Cercle') }}</button>
        <span v-else-if="circle" class="tv-chip">{{ circle.name }}</span>
        <button type="button" data-tv class="w-14 h-14 rounded-full bg-[#fcd34d] text-[#14110d] text-[1.625rem] font-extrabold flex items-center justify-center" :aria-label="$t('Compte de :name', { name: account?.name })" @click="menu = 'account'">
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

    <!-- Cadre photo (inactivité, ou lancé depuis le menu) -->
    <TvFrame v-if="frame" @close="closeFrame" />

    <!-- Choix du cercle affiché sur cette TV -->
    <div v-if="menu === 'circle'" data-tv-scope class="fixed inset-0 z-[70] bg-black/80 flex items-center justify-center">
      <div class="w-[44rem] rounded-[2.25rem] bg-[#211d17] p-12 flex flex-col gap-4">
        <h2 class="font-display text-[3rem] font-bold mb-4">{{ $t('Quel cercle afficher ?') }}</h2>
        <button
          v-for="c in circles"
          :key="c.id"
          type="button"
          data-tv
          :data-tv-autofocus="c.id === circle?.id ? '' : null"
          class="tv-option"
          @click="chooseCircle(c)"
        >
          {{ c.name }}<span v-if="c.id === circle?.id" class="ml-auto text-[#fcd34d]">{{ $t('affiché') }}</span>
        </button>
      </div>
    </div>

    <!-- Compte / télévision -->
    <div v-if="menu === 'account'" data-tv-scope class="fixed inset-0 z-[70] bg-black/80 flex items-center justify-center">
      <div class="w-[48rem] rounded-[2.25rem] bg-[#211d17] p-12 flex flex-col gap-4">
        <h2 class="font-display text-[3rem] font-bold">{{ account?.device || $t('Cette télévision') }}</h2>
        <p class="text-[1.75rem] text-[#c9bfb1] mb-4">{{ $t('Reliée au compte de :name.', { name: account?.name }) }}</p>
        <button type="button" data-tv data-tv-autofocus class="tv-option" @click="menu = null">{{ $t('Continuer') }}</button>
        <button type="button" data-tv class="tv-option" @click="openFrame">{{ $t('Lancer le cadre photo') }}</button>
        <button v-if="account?.device" type="button" data-tv class="tv-option text-[#fca5a5]" @click="unlink">{{ $t('Délier cette télévision') }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { installRemote, focusInitial, onTvKey, tvActivity } from '@/Tv/remote';
import TvFrame from '@/Components/Tv/TvFrame.vue';
import { t } from '@/i18n';
import '../../css/tv.css';

const props = defineProps({
  tab: { type: String, default: null },
  circle: { type: Object, default: null },
  circles: { type: Array, default: () => [] },
  account: { type: Object, default: null },
  // Page précédente (touche Retour) ; null sur l'accueil.
  back: { type: String, default: null },
  // Aide télécommande en pied de page : [touche, libellé].
  hints: { type: Array, default: () => [['OK', t('Ouvrir')], ['◀ ▲ ▼ ▶', t('Se déplacer')], ['↩', t('Retour')]] },
  // Sans barre de navigation (appairage, jeu).
  bare: { type: Boolean, default: false },
  // Page à hauteur d'écran (pas de défilement de la page entière).
  fill: { type: Boolean, default: false },
  navClass: { type: String, default: '' },
  // Cercle familial : onglet Arbre.
  family: { type: Boolean, default: false },
});

const ALL_TABS = [
  { key: 'home', label: t('Accueil'), href: '/tv/ecran/accueil' },
  { key: 'albums', label: t('Albums'), href: '/tv/ecran/albums' },
  { key: 'photos', label: t('Photos'), href: '/tv/ecran/photos' },
  { key: 'people', label: t('Proches'), href: '/tv/ecran/proches' },
  { key: 'tree', label: t('Arbre'), href: '/tv/ecran/arbre', family: true },
  { key: 'map', label: t('Carte'), href: '/tv/ecran/carte' },
  { key: 'game', label: t('Qui est-ce ?'), href: '/tv/ecran/qui-est-ce' },
];
// L'arbre n'a de sens que dans un cercle familial.
const page = usePage();
const TABS = computed(() => ALL_TABS.filter((t) => !t.family || props.family || page.props.family));

/** Cadre photo après ce temps sans toucher à la télécommande. */
const FRAME_AFTER_MS = 10 * 60 * 1000;

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

// ---- Cadre photo -----------------------------------------------------------
const frame = ref(false);
const openFrame = () => {
  menu.value = null;
  frame.value = true;
};
const closeFrame = async () => {
  frame.value = false;
  await nextTick();
  if (!document.activeElement || document.activeElement === document.body) focusInitial();
};
let idleTimer = null;

let viewport = null;
// Téléviseur Android : dimensions de l'écran, fixes (la fenêtre de la
// WebView varie pendant le chargement et quand le clavier s'ouvre).
// Ailleurs (aperçu dans un navigateur) : la fenêtre.
const onAndroid = /Android/i.test(navigator.userAgent);
let scaledWidth = 0;
const applyScale = () => {
  scaledWidth = window.innerWidth;
  const [w, h] = onAndroid
    ? [Math.max(screen.width, screen.height), Math.min(screen.width, screen.height)]
    : [window.innerWidth, window.innerHeight];
  html.style.fontSize = `${Math.min(w / 120, h / 67.5)}px`;
};
const onResize = () => { if (!onAndroid && window.innerWidth !== scaledWidth) applyScale(); };

let uninstall = null;
let interceptor = null;
const html = document.documentElement;
const previous = { fontSize: html.style.fontSize, background: document.body.style.background };

onMounted(() => {
  // Taille de base : 1/120e de la largeur, sans dépasser la hauteur. Recalculée
  // seulement si la LARGEUR change : le clavier de la télévision réduit la
  // hauteur de la fenêtre et ne doit pas faire rapetisser l'interface.
  applyScale();
  window.addEventListener('resize', onResize);
  // Pas de zoom automatique sur le champ de recherche (WebView des téléviseurs).
  viewport = document.querySelector('meta[name=viewport]');
  previous.viewport = viewport?.getAttribute('content');
  viewport?.setAttribute('content', 'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no');
  document.body.style.background = '#12100c';
  // Rien ne doit élargir la page (la WebView dézoomerait pour tout montrer).
  html.style.overflowX = 'hidden';
  document.body.style.overflowX = 'hidden';
  uninstall = installRemote(() => {
    if (!props.back) return false;
    router.visit(props.back);
    return true;
  });
  tvActivity.last = Date.now();
  if (!props.bare) {
    idleTimer = setInterval(() => {
      if (!frame.value && !menu.value && tvActivity.blockers === 0 && Date.now() - tvActivity.last > FRAME_AFTER_MS) frame.value = true;
    }, 20000);
  }
  // Pour essayer sans attendre : window.mlTvFrame() dans la console.
  window.mlTvFrame = openFrame;
  // TV déliée depuis le téléphone pendant qu'elle tourne : retour au code.
  interceptor = axios.interceptors.response.use(null, (error) => {
    if (error.response?.status === 401) window.location.href = '/tv/ecran';
    return Promise.reject(error);
  });
  nextTick(() => focusInitial());
});

onBeforeUnmount(() => {
  clearInterval(idleTimer);
  window.removeEventListener('resize', onResize);
  if (viewport && previous.viewport) viewport.setAttribute('content', previous.viewport);
  delete window.mlTvFrame;
  uninstall?.();
  axios.interceptors.response.eject(interceptor);
  html.style.fontSize = previous.fontSize;
  html.style.overflowX = '';
  document.body.style.overflowX = '';
  document.body.style.background = previous.background;
});
</script>

<style>
.tv-root { scrollbar-width: none; }
.tv-root ::-webkit-scrollbar, html:has(.tv-root)::-webkit-scrollbar { display: none; }
html:has(.tv-root) { scrollbar-width: none; }
/* Anneau de focus en box-shadow (suit les coins arrondis, même dans les
   vieilles WebView où outline reste rectangulaire). */
.tv-root [data-tv] { outline: none; scroll-margin: 6rem 6rem 4rem; transition: transform .18s ease, box-shadow .18s ease; }
.tv-root [data-tv]:focus { outline: none; box-shadow: 0 0 0 0.375rem #12100c, 0 0 0 0.75rem #f7f2ea; }
.tv-pill { padding: 0.75rem 1.375rem; border-radius: 999px; color: #d8cfc2; font-size: 1.625rem; font-weight: 600; white-space: nowrap; }
.tv-pill-on { background: #3a2f1e; color: #fcd34d; font-weight: 700; }
.tv-root .tv-pill:focus { background: #f7f2ea; color: #14110d; }
.tv-chip { padding: 0.625rem 1.375rem; border-radius: 999px; border: 2px solid #b45309; color: #fcd34d; font-size: 1.5rem; font-weight: 700; }
.tv-option { display: flex; white-space: nowrap; align-items: center; gap: 1rem; padding: 1.25rem 2rem; border-radius: 1.5rem; background: #2c261e; font-size: 2rem; font-weight: 700; text-align: left; }
.tv-root .tv-option:focus { background: #f7f2ea; color: #14110d; }
.tv-btn { display: inline-flex; white-space: nowrap; align-items: center; gap: 0.875rem; padding: 1.125rem 2.125rem; border-radius: 999px; font-size: 1.875rem; font-weight: 800; background: rgba(255, 255, 255, .14); color: #f7f2ea; }
.tv-btn-primary { background: #f59e0b; color: #14110d; }
.tv-root .tv-btn:focus { transform: scale(1.05); }
.tv-root .tv-btn:not(.tv-btn-primary):focus { background: #f7f2ea; color: #14110d; }
.tv-card:focus { transform: scale(1.06); }
</style>
