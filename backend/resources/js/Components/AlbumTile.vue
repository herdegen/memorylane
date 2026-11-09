<template>
  <Link
    :href="`/albums/${album.id}`"
    class="group block min-w-0 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-600"
    @mouseenter="hovering = true"
    @mouseleave="hovering = false"
  >
    <!-- Photo seule : le titre est dessous, jamais par-dessus -->
    <div class="relative aspect-[3/2] sm:aspect-[4/3] rounded-xl overflow-hidden bg-surface-100 shadow-warm-sm transition duration-200 group-hover:shadow-warm-md group-hover:-translate-y-0.5">
      <!-- Couverture vivante : les photos défilent en fondu (survol sur
           ordinateur, carte au centre de l'écran sur téléphone). Seule la
           première est chargée tant que la carte n'a pas été animée. -->
      <template v-if="frames.length">
        <img
          v-for="(src, k) in loadedFrames"
          :key="src"
          :src="src"
          alt=""
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
        alt=""
        class="absolute inset-0 w-full h-full object-cover"
        loading="lazy"
        decoding="async"
      />
      <div v-else class="absolute inset-0 flex items-center justify-center bg-linear-to-br from-brand-50 to-surface-200">
        <svg class="h-12 w-12 text-brand-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      </div>

      <!-- Un badge seulement pour l'exception -->
      <div class="absolute top-2 right-2 flex items-center gap-1.5">
        <span
          v-if="album.is_smart"
          class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-violet-100/95 text-violet-700"
          :title="$t('Album intelligent : se remplit automatiquement')"
        >✦ {{ $t('Auto') }}</span>
        <span
          v-if="isPrivate"
          class="w-6 h-6 rounded-full bg-ink/55 text-snow grid place-items-center"
          :title="$t('Privé')"
        >
          <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 018 0v4" /></svg>
          <span class="sr-only">{{ $t('Privé') }}</span>
        </span>
      </div>
    </div>

    <h3 class="mt-2 px-0.5 font-display text-[15px] sm:text-base font-semibold leading-snug text-surface-900 line-clamp-3 sm:line-clamp-2">
      {{ album.name }}
    </h3>
    <p class="mt-0.5 px-0.5 text-xs text-surface-500">{{ subLine }}</p>
  </Link>
</template>

<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import { t, tc, intlLocale } from '@/i18n';

const props = defineProps({
  album: { type: Object, required: true },
  // Téléphone : la carte au centre de l'écran s'anime (choisie par la page).
  live: { type: Boolean, default: false },
  // Nom du propriétaire, affiché quand l'album est partagé AVEC moi.
  ownerName: { type: String, default: null },
});

// ---- Couverture vivante ------------------------------------------------------
const frames = computed(() => (props.album.previews || []).map((p) => p.small));
const index = ref(0);
const hovering = ref(false);
const activated = ref(false);
const reducedMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const animating = computed(() => !reducedMotion && frames.value.length > 1 && (hovering.value || props.live));
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

// Cadenas : seulement sur MES albums que personne d'autre ne voit.
const isPrivate = computed(() => props.album.is_owner !== false
  && !(props.album.households_count > 0)
  && !(props.album.accesses_count > 0)
  && !props.album.has_share_link);

// « juin 2020 », « 2018 », ou « 2009 → 2018 » pour un album sur plusieurs années.
const monthYear = (d) => new Date(`${d}T12:00:00`).toLocaleDateString(intlLocale(), { month: 'long', year: 'numeric' });
const period = computed(() => {
  const { first_at: a, last_at: b } = props.album;
  if (!a) return null;
  if (!b || a.slice(0, 7) === b.slice(0, 7)) return monthYear(a);
  if (a.slice(0, 4) === b.slice(0, 4)) return a.slice(0, 4);
  return `${a.slice(0, 4)} → ${b.slice(0, 4)}`;
});

const subLine = computed(() => [
  tc(':count média|:count médias', props.album.media_count || 0),
  period.value,
  props.ownerName ? t('partagé par :name', { name: props.ownerName }) : null,
].filter(Boolean).join(' · '));
</script>
