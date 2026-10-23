<template>
  <div
    class="relative group aspect-[4/3] rounded-2xl overflow-hidden bg-surface-100 cursor-pointer shadow-warm-sm transition-all duration-200 hover:shadow-warm-md hover:scale-[1.01]"
    @click="$emit('click', album)"
    @mouseenter="hovering = true"
    @mouseleave="hovering = false"
  >
    <!-- Couverture vivante : les photos de l'album défilent en fondu (survol
         sur ordinateur, carte au centre de l'écran sur téléphone). Seule la
         première est chargée tant que la carte n'a pas été animée. -->
    <template v-if="frames.length">
      <img
        v-for="(src, k) in loadedFrames"
        :key="src"
        :src="src"
        :alt="k === 0 ? album.name : ''"
        class="absolute inset-0 w-full h-full object-cover transition-[opacity,transform] ease-out"
        :class="k === index ? 'opacity-100' : 'opacity-0'"
        :style="{ transitionDuration: k === index && animating ? '700ms, 2600ms' : '700ms, 300ms', transform: k === index && animating ? 'scale(1.08)' : 'scale(1)' }"
        loading="lazy"
        decoding="async"
      />
    </template>
    <img
      v-else-if="album.cover_url"
      :src="album.cover_url"
      :alt="album.name"
      class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
      loading="lazy"
      decoding="async"
    />
    <div
      v-else
      class="absolute inset-0 flex items-center justify-center bg-linear-to-br from-brand-50 to-surface-200"
    >
      <svg class="h-16 w-16 text-brand-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.5"
          d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
        />
      </svg>
    </div>

    <!-- Badges posés sur la photo -->
    <div class="absolute top-2 right-2 sm:top-2.5 sm:right-2.5 z-10 flex items-center gap-1.5">
      <span
        v-if="album.is_smart"
        class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-violet-100/95 text-violet-700 backdrop-blur-sm"
        :title="$t('Album intelligent : se remplit automatiquement')"
      >
        ✦ {{ $t('Auto') }}
      </span>
      <span
        :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold backdrop-blur-sm', visibility.class]"
      >
        {{ visibility.label }}
      </span>
    </div>

    <!-- Cartouche givré (même langage que les cartes de l'arbre familial) -->
    <div class="absolute inset-x-1.5 bottom-1.5 sm:inset-x-2 sm:bottom-2 rounded-xl bg-white/85 backdrop-blur-sm px-2.5 py-2 sm:px-3.5 sm:py-2.5">
      <h3 class="font-display text-[15px] sm:text-[17px] font-semibold text-surface-900 truncate leading-snug">
        {{ album.name }}
      </h3>
      <p class="text-[11px] sm:text-xs text-surface-600 mt-0.5 truncate">{{ subLine }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { t, tc, intlLocale } from '@/i18n';

const props = defineProps({
  album: {
    type: Object,
    required: true,
  },
  // Téléphone : la carte au centre de l'écran s'anime (choisie par la page).
  live: {
    type: Boolean,
    default: false,
  },
  // Nom du propriétaire, affiché quand l'album est partagé AVEC moi.
  ownerName: {
    type: String,
    default: null,
  },
});

defineEmits(['click']);

// ---- Couverture vivante ------------------------------------------------------
const frames = computed(() => (props.album.previews || []).map((p) => p.small));
const index = ref(0);
const hovering = ref(false);
const activated = ref(false);
const reducedMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const animating = computed(() => !reducedMotion && frames.value.length > 1 && (hovering.value || props.live));
// Les photos suivantes ne sont chargées qu'à la première animation.
const loadedFrames = computed(() => (activated.value ? frames.value : frames.value.slice(0, 1)));
let timer = null;
const stop = () => { clearInterval(timer); timer = null; };
watch(animating, (on) => {
  stop();
  if (!on) return;
  activated.value = true;
  timer = setInterval(() => { index.value = (index.value + 1) % frames.value.length; }, 1600);
});
onBeforeUnmount(stop);

const visibility = computed(() => {
  if ((props.album.households_count || 0) > 0) {
    return { label: t('Cercle'), class: 'bg-teal-100/95 text-teal-800' };
  }
  if ((props.album.accesses_count || 0) > 0 || props.album.has_share_link) {
    return { label: t('Partagé'), class: 'bg-amber-100/95 text-amber-800' };
  }
  return { label: t('Privé'), class: 'bg-white/85 text-surface-600' };
});

// « juin 2020 », ou « 1939 → 1972 » pour un album qui couvre plusieurs années.
const monthYear = (d) => new Date(`${d}T12:00:00`).toLocaleDateString(intlLocale(), { month: 'long', year: 'numeric' });
const period = computed(() => {
  const { first_at: a, last_at: b } = props.album;
  if (!a) return null;
  if (!b || a.slice(0, 7) === b.slice(0, 7)) return monthYear(a);
  if (a.slice(0, 4) === b.slice(0, 4)) return a.slice(0, 4);
  return `${a.slice(0, 4)} → ${b.slice(0, 4)}`;
});

const subLine = computed(() => {
  const count = props.album.media_count || 0;
  return [
    tc(':count média|:count médias', count),
    period.value,
    props.ownerName ? t('partagé par :name', { name: props.ownerName }) : null,
    props.album.description || null,
  ].filter(Boolean).join(' · ');
});
</script>
