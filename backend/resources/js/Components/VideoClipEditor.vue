<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-xs p-6">
    <div class="flex items-center justify-between mb-1">
      <h2 class="text-lg font-semibold text-surface-900">Découper en clips</h2>
      <span class="text-xs text-surface-400">{{ segments.length }} segment{{ segments.length > 1 ? 's' : '' }}</span>
    </div>
    <p class="text-sm text-surface-500 mb-4">
      Indiquez les passages à conserver. Chaque segment deviendra un clip indépendant
      (personnes, lieu, date éditables). Placez le lecteur au bon endroit puis capturez le temps.
    </p>

    <!-- État après lancement -->
    <div v-if="launched" class="rounded-lg bg-brand-50 border border-brand-200 p-4 text-sm text-brand-800">
      Découpage lancé. Les {{ launchedCount }} clip{{ launchedCount > 1 ? 's' : '' }} apparaîtront
      dans la galerie dans quelques instants (traitement en arrière-plan).
    </div>

    <template v-else>
      <div class="space-y-3">
        <div
          v-for="(seg, index) in segments"
          :key="index"
          class="rounded-lg border border-surface-200 p-3"
        >
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Clip {{ index + 1 }}</span>
            <button
              type="button"
              class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
              @click="removeSegment(index)"
            >
              Supprimer
            </button>
          </div>

          <input
            v-model="seg.title"
            type="text"
            placeholder="Titre du clip (optionnel)"
            class="w-full mb-2 px-3 py-1.5 text-sm border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
          />

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-surface-600 mb-1">Début — {{ formatTime(seg.start) }}</label>
              <div class="flex gap-1">
                <input
                  v-model.number="seg.start"
                  type="number"
                  min="0"
                  step="0.1"
                  class="w-full px-2 py-1 text-sm border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500"
                />
                <button type="button" class="shrink-0 px-2 py-1 text-xs font-medium text-brand-700 bg-brand-50 border border-brand-200 rounded-lg hover:bg-brand-100" title="Capturer le temps du lecteur" @click="capture(seg, 'start')">⏱</button>
                <button type="button" class="shrink-0 px-2 py-1 text-xs text-surface-600 border border-surface-200 rounded-lg hover:bg-surface-50" title="Aller à ce temps" @click="preview(seg.start)">▶</button>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-surface-600 mb-1">Fin — {{ formatTime(seg.end) }}</label>
              <div class="flex gap-1">
                <input
                  v-model.number="seg.end"
                  type="number"
                  min="0"
                  step="0.1"
                  class="w-full px-2 py-1 text-sm border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500"
                />
                <button type="button" class="shrink-0 px-2 py-1 text-xs font-medium text-brand-700 bg-brand-50 border border-brand-200 rounded-lg hover:bg-brand-100" title="Capturer le temps du lecteur" @click="capture(seg, 'end')">⏱</button>
                <button type="button" class="shrink-0 px-2 py-1 text-xs text-surface-600 border border-surface-200 rounded-lg hover:bg-surface-50" title="Aller à ce temps" @click="preview(seg.end)">▶</button>
              </div>
            </div>
          </div>

          <p v-if="segmentError(seg)" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ segmentError(seg) }}</p>
        </div>
      </div>

      <button
        type="button"
        class="mt-3 w-full py-2 text-sm font-medium text-brand-700 border border-dashed border-brand-300 rounded-lg hover:bg-brand-50"
        @click="addSegment"
      >
        ＋ Ajouter un segment
      </button>

      <div v-if="error" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ error }}</div>

      <button
        type="button"
        :disabled="!canSubmit || submitting"
        class="mt-4 w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-brand-600 text-white rounded-lg text-sm font-medium hover:bg-brand-700 disabled:opacity-50"
        @click="submit"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243zm0-5.758a3 3 0 10-4.243-4.243 3 3 0 004.243 4.243z" />
        </svg>
        {{ submitting ? 'Découpage…' : `Découper en ${segments.length} clip${segments.length > 1 ? 's' : ''}` }}
      </button>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
  mediaId: { type: String, required: true },
  duration: { type: Number, default: null },
  // Fonctions exposées par le lecteur (VideoPlayer).
  getCurrentTime: { type: Function, default: null },
  seekTo: { type: Function, default: null },
});

const segments = reactive([{ start: 0, end: props.duration || 0, title: '' }]);
const submitting = ref(false);
const launched = ref(false);
const launchedCount = ref(0);
const error = ref('');

const round = (n) => Math.round(n * 10) / 10;

const addSegment = () => {
  const lastEnd = segments.length ? segments[segments.length - 1].end : 0;
  segments.push({ start: round(lastEnd || 0), end: props.duration || round((lastEnd || 0) + 10), title: '' });
};

const removeSegment = (index) => {
  segments.splice(index, 1);
  if (segments.length === 0) addSegment();
};

const capture = (seg, field) => {
  if (!props.getCurrentTime) return;
  seg[field] = round(props.getCurrentTime());
};

const preview = (t) => {
  if (props.seekTo && t != null) props.seekTo(Number(t));
};

const segmentError = (seg) => {
  const start = Number(seg.start);
  const end = Number(seg.end);
  if (isNaN(start) || isNaN(end)) return 'Temps invalide.';
  if (start < 0) return 'Le début doit être positif.';
  if (end <= start) return 'La fin doit être après le début.';
  if (props.duration && end > props.duration + 1) return 'La fin dépasse la durée de la vidéo.';
  return null;
};

const canSubmit = computed(() => segments.length > 0 && segments.every((s) => !segmentError(s)));

const formatTime = (seconds) => {
  const s = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  const pad = (n) => String(n).padStart(2, '0');
  return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${m}:${pad(sec)}`;
};

const submit = async () => {
  if (!canSubmit.value) return;
  error.value = '';
  submitting.value = true;
  try {
    const payload = {
      segments: segments.map((s) => ({
        start: Number(s.start),
        end: Number(s.end),
        title: s.title?.trim() || null,
      })),
    };
    const { data } = await axios.post(`/media/${props.mediaId}/clips`, payload);
    launchedCount.value = data.count ?? segments.length;
    launched.value = true;
    // Recharge après un court délai pour laisser les premiers clips apparaître.
    setTimeout(() => router.reload(), 4000);
  } catch (e) {
    error.value = e.response?.data?.error || 'Erreur lors du lancement du découpage.';
  } finally {
    submitting.value = false;
  }
};
</script>
