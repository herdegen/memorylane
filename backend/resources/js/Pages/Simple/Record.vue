<template>
  <Head title="Raconter cette photo" />
  <div class="min-h-[100dvh] bg-page text-surface-900 px-5 py-6 sm:px-10 sm:py-8 flex flex-col gap-6">

    <header>
      <Link :href="backHref" class="inline-flex min-h-[72px] px-7 rounded-full bg-white border-2 border-surface-200 items-center gap-3 text-[24px] font-semibold">
        <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7" /></svg>
        Retour à la photo
      </Link>
    </header>

    <div class="flex-1 grid gap-8 lg:gap-12 lg:grid-cols-2 items-center">

      <!-- La photo et la question -->
      <div class="flex flex-col gap-5">
        <div class="rounded-3xl overflow-hidden bg-surface-200 shadow-warm-lg max-h-[46vh] flex items-center justify-center">
          <img :src="photo.url" alt="" class="max-h-[46vh] w-full object-contain" />
        </div>
        <div class="flex flex-col gap-2">
          <div class="text-[22px] font-bold text-brand-800 uppercase tracking-wide">Racontez-nous</div>
          <div class="font-display text-3xl sm:text-4xl font-bold leading-snug">Où étiez-vous ce jour-là ? Qui est sur la photo ?</div>
        </div>
      </div>

      <!-- Le micro -->
      <div class="flex flex-col items-center gap-6 text-center">

        <template v-if="state === 'idle'">
          <button
            type="button"
            class="w-56 h-56 sm:w-60 sm:h-60 rounded-full border-[10px] border-brand-200 bg-brand-700 text-[#fff] flex items-center justify-center shadow-warm-lg active:scale-95 transition"
            aria-label="Commencer l'enregistrement"
            @click="start"
          >
            <svg class="w-24 h-24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0 0 14 0M12 18v3" /></svg>
          </button>
          <div class="text-3xl font-bold">Appuyez et parlez</div>
          <div class="text-2xl text-surface-600 max-w-md">Prenez votre temps. Vous pouvez parler aussi longtemps que vous voulez.</div>
        </template>

        <template v-else-if="state === 'recording'">
          <button
            type="button"
            class="w-56 h-56 sm:w-60 sm:h-60 rounded-full border-[10px] border-red-200 bg-red-700 text-[#fff] flex items-center justify-center animate-pulse active:scale-95 transition"
            aria-label="Arrêter l'enregistrement"
            @click="stop"
          >
            <svg class="w-20 h-20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2" /></svg>
          </button>
          <div class="text-5xl font-bold tabular-nums">{{ elapsedLabel }}</div>
          <div class="text-2xl text-surface-600">Je vous écoute… Appuyez pour terminer.</div>
        </template>

        <template v-else-if="state === 'recorded' || state === 'sending'">
          <div class="text-3xl font-bold">Envoi de votre souvenir…</div>
        </template>

        <template v-else-if="state === 'sent'">
          <div class="w-40 h-40 rounded-full bg-green-100 text-green-800 flex items-center justify-center">
            <svg class="w-20 h-20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
          </div>
          <div class="font-display text-4xl font-bold">Merci {{ firstName }} !</div>
          <div class="text-2xl text-surface-700 max-w-md">Votre souvenir est gardé avec la photo. Toute la famille pourra l'écouter.</div>
          <div class="flex gap-4 flex-wrap justify-center">
            <button type="button" class="min-h-[80px] px-7 rounded-[20px] border-2 border-surface-300 bg-white text-2xl font-semibold" @click="replay">
              {{ playing ? 'Arrêter' : 'Réécouter' }}
            </button>
            <button type="button" class="min-h-[80px] px-7 rounded-[20px] border-2 border-surface-300 bg-white text-2xl font-semibold" @click="redo">
              Recommencer
            </button>
          </div>
          <Link :href="backHref" class="min-h-[84px] px-9 rounded-[22px] bg-brand-700 text-[#fff] flex items-center text-[26px] font-bold">
            Revenir aux photos
          </Link>
        </template>

        <template v-else-if="state === 'error'">
          <div class="text-2xl text-red-800 max-w-md">{{ error }}</div>
          <button type="button" class="min-h-[84px] px-9 rounded-[22px] bg-brand-700 text-[#fff] text-[26px] font-bold" @click="retry">
            Réessayer
          </button>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { useAuth } from '@/Composables/useAuth';
import { useVoiceRecorder } from '@/Composables/useVoiceRecorder';
import { previousUrl } from '@/utils/navigation';

const props = defineProps({
  photo: { type: Object, required: true },
  maxSeconds: { type: Number, default: 1200 },
});

const { user } = useAuth();
const firstName = computed(() => user.value?.name?.split(' ')[0] ?? '');

const { state, elapsedLabel, error, blob, playbackUrl, start, stop, send, reset } = useVoiceRecorder({ maxSeconds: props.maxSeconds });

// Retour sur la même photo, dans le même diaporama (tous / les photos de X).
const backHref = (() => {
  const prev = previousUrl();
  const base = prev && /^\/simple\/photos(\?|$)/.test(prev) ? prev : '/simple/photos';
  const url = new URL(base, window.location.origin);
  url.searchParams.set('start', props.photo.id);
  return url.pathname + url.search;
})();

// Envoi automatique dès la fin de l'enregistrement : pas de bouton de plus.
const sentNote = ref(null);
watch(state, async (s) => {
  if (s === 'recorded' && blob.value) {
    sentNote.value = await send(props.photo.id);
  }
});

const playing = ref(false);
let audio = null;
function replay() {
  if (playing.value) {
    audio?.pause();
    playing.value = false;
    return;
  }
  audio = new Audio(playbackUrl.value);
  audio.onended = () => (playing.value = false);
  audio.play();
  playing.value = true;
}

/** Recommencer : retire le souvenir qu'on vient d'envoyer, puis on repart de zéro. */
async function redo() {
  audio?.pause();
  playing.value = false;
  if (sentNote.value) {
    await axios.delete(`/voice-notes/${sentNote.value.id}`).catch(() => {});
    sentNote.value = null;
  }
  reset();
}

/** Après une erreur d'envoi, on retente avec le même enregistrement. */
async function retry() {
  if (blob.value) {
    sentNote.value = await send(props.photo.id);
  } else {
    reset();
  }
}
</script>
