<template>
  <!-- Jeu « Qui est-ce ? » plein écran (téléphone d'abord) : une pile de
       cartes, un visage par carte, les suggestions avec leur ressemblance,
       trois gestes — ← inconnu · ↓ passer · → c'est la première suggestion.
       Les réponses passent par /quests/answer (même journal que le Dashboard). -->
  <Head title="Qui est-ce ?" />
  <div class="fixed inset-0 z-[60] bg-[#1c1917] text-white flex flex-col select-none" :style="{ paddingTop: 'env(safe-area-inset-top)' }">
    <!-- Haut : quitter, progression, série -->
    <div class="flex items-center gap-2 px-2 pt-2">
      <button type="button" class="h-10 pl-2.5 pr-3.5 rounded-full bg-white/12 border border-white/20 flex items-center gap-1.5 text-[13px] font-bold shrink-0 active:bg-white/20" @click="quit">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
        Quitter
      </button>
      <div class="flex-1 min-w-0 flex flex-col gap-1.5">
        <div class="flex justify-between text-xs font-bold text-stone-300">
          <span class="truncate">{{ album ? album.name : 'Qui est-ce ?' }}</span>
          <span class="shrink-0 ml-2">{{ done }} / {{ totalLabel }}</span>
        </div>
        <div class="h-1.5 rounded-full bg-white/15 overflow-hidden">
          <div class="h-full rounded-full bg-amber-500 transition-all duration-500" :style="{ width: progress + '%' }"></div>
        </div>
      </div>
      <div
        class="inline-flex items-center gap-1 h-8 px-2.5 rounded-full text-[13px] font-extrabold text-[#1c1917] shrink-0 ml-1 transition-colors"
        :class="streak >= 5 ? 'bg-amber-300' : 'bg-stone-300'"
        title="Série de bonnes réponses"
      >
        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2c1 4 5 5 5 10a5 5 0 01-10 0c0-2 1-3 1-3s0 2 2 2c0-4-1-6 2-9z" /></svg>{{ streak }}
      </div>
    </div>

    <!-- Pile de cartes -->
    <div class="flex-1 relative flex items-center justify-center px-4 pt-3 min-h-0">
      <!-- Retour visuel après réponse -->
      <Transition name="pop">
        <div v-if="feedback" class="absolute top-2 left-1/2 -translate-x-1/2 px-4.5 py-2.5 rounded-full bg-amber-300 text-[#1c1917] text-sm font-extrabold shadow-[0_10px_30px_rgba(0,0,0,.4)] whitespace-nowrap">{{ feedback }}</div>
      </Transition>
      <div v-if="card" class="relative w-full max-w-[350px]">
        <!-- cartes derrière (même hauteur que la carte courante) -->
        <div class="absolute inset-0 rounded-[28px] bg-stone-700 translate-y-6 scale-90 opacity-50"></div>
        <div v-if="queue.length > 1" class="absolute inset-0 rounded-[28px] bg-stone-600 translate-y-3 scale-95 opacity-80"></div>

        <div
          ref="cardEl"
          :key="card.subject_id"
          class="relative w-full rounded-[28px] bg-white text-[#1c1917] shadow-[0_30px_60px_-20px_rgba(0,0,0,.6)] overflow-hidden touch-none"
          :class="{ 'card-leave': leaving }"
          :style="cardStyle"
          @pointerdown="onPointerDown"
          @pointermove="onPointerMove"
          @pointerup="onPointerUp"
          @pointercancel="onPointerUp"
        >
          <!-- Le visage, grand -->
          <div class="relative h-[300px] bg-stone-200">
            <img :src="card.face.crop_url" alt="" class="absolute inset-0 w-full h-full object-cover" draggable="false" />
            <span v-if="whereLabel" class="absolute left-3.5 top-3.5 max-w-[70%] truncate px-2.5 py-1 rounded-full bg-[#1c1917]/75 text-white text-xs font-bold">{{ whereLabel }}</span>
            <!-- Vignette de la photo entière : tap = voir le contexte -->
            <button
              type="button"
              class="absolute right-3 bottom-3 w-16 h-12 rounded-[10px] overflow-hidden border-2 border-white shadow-[0_6px_16px_rgba(0,0,0,.4)] bg-stone-300"
              aria-label="Voir la photo entière"
              @click.stop="showContext = true"
            >
              <img :src="card.face.image_url" alt="" class="w-full h-full object-cover" draggable="false" />
            </button>
            <!-- Indices du geste en cours -->
            <span class="absolute left-4 top-14 px-3 py-1 rounded-lg border-[3px] border-red-400 text-red-400 font-black text-lg -rotate-12 transition-opacity" :style="{ opacity: dragHint === 'reject' ? 1 : 0 }">INCONNU</span>
            <span v-if="top" class="absolute right-4 top-14 px-3 py-1 rounded-lg border-[3px] border-emerald-400 text-emerald-400 font-black text-lg rotate-12 transition-opacity" :style="{ opacity: dragHint === 'confirm' ? 1 : 0 }">C'EST {{ firstName(top.name).toUpperCase() }}</span>
          </div>

          <div class="p-4 pb-3.5 flex flex-col gap-3">
            <div class="font-display text-xl leading-snug">{{ hint }}</div>
            <button
              v-for="s in card.face.suggestions.slice(0, 3)"
              :key="s.person.id"
              type="button"
              class="flex items-center gap-2.5 h-12 pl-1.5 pr-2.5 rounded-[14px] border border-stone-200 bg-[#faf7f1] text-left active:bg-amber-50"
              @click.stop="confirmPerson(s.person)"
            >
              <PersonAvatar :person="s.person" :size="9" class="shrink-0" />
              <span class="flex-1 min-w-0">
                <span class="block text-[15px] font-bold truncate">{{ s.person.name }}</span>
                <span class="block h-1 rounded-full bg-stone-200 mt-1 overflow-hidden">
                  <span class="block h-full rounded-full" :class="s.score >= 0.75 ? 'bg-emerald-600' : 'bg-amber-500'" :style="{ width: Math.round(s.score * 100) + '%' }"></span>
                </span>
              </span>
              <span class="text-[13px] font-bold text-stone-600 shrink-0">{{ Math.round(s.score * 100) }} %</span>
            </button>
            <button type="button" class="h-10 rounded-xl border border-dashed border-stone-300 text-sm font-semibold text-stone-600" @click.stop="openPicker">
              Quelqu'un d'autre…
            </button>
          </div>
        </div>

      </div>

      <!-- Chargement / fin de partie -->
      <div v-else-if="loading" class="text-stone-400 text-sm">Chargement des visages…</div>
      <div v-else class="w-full max-w-[350px] rounded-[28px] bg-white text-[#1c1917] p-7 text-center flex flex-col items-center gap-3">
        <span class="text-5xl">🎉</span>
        <h2 class="font-display text-2xl">{{ done ? 'Bravo !' : 'Rien à identifier' }}</h2>
        <p class="text-sm text-stone-600">
          <template v-if="done">{{ done }} visage{{ done > 1 ? 's' : '' }} passé{{ done > 1 ? 's' : '' }} en revue, {{ named }} nommé{{ named > 1 ? 's' : '' }}{{ bestStreak >= 3 ? ` — meilleure série : ${bestStreak}` : '' }}.</template>
          <template v-else>Tous les visages {{ album ? 'de cet album' : 'visibles' }} sont déjà identifiés ou mis de côté.</template>
        </p>
        <button type="button" class="mt-2 h-12 w-full rounded-2xl bg-amber-500 text-[#1c1917] font-bold" @click="quit">Terminer</button>
      </div>
    </div>

    <!-- Gestes : balayer ← inconnu · → c'est bien lui/elle · ↓ passer -->
    <div class="flex items-center justify-between px-6 pt-3 pb-[calc(1.5rem+env(safe-area-inset-bottom))]" :class="{ 'invisible': !card }">
      <button type="button" class="flex flex-col items-center gap-1.5 text-stone-300 text-[11px] font-bold" :disabled="busy" @click="reject">
        <span class="w-[58px] h-[58px] rounded-full bg-stone-800 border-2 border-stone-600 flex items-center justify-center text-red-400">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
        </span>Inconnu
      </button>
      <button type="button" class="flex flex-col items-center gap-1.5 text-stone-300 text-[11px] font-bold" :disabled="busy" @click="skip">
        <span class="w-12 h-12 rounded-full bg-stone-800 border-2 border-stone-600 flex items-center justify-center text-stone-300">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14m-6-6l6 6 6-6" /></svg>
        </span>Passer
      </button>
      <button type="button" class="flex flex-col items-center gap-1.5 text-stone-300 text-[11px] font-bold" :class="{ 'opacity-40': !top }" :disabled="busy || !top" @click="top && confirmPerson(top)">
        <span class="w-[58px] h-[58px] rounded-full bg-amber-500 flex items-center justify-center text-[#1c1917] shadow-[0_10px_24px_-8px_rgba(245,158,11,.8)]">
          <svg class="w-[26px] h-[26px]" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 13l4 4L19 7" /></svg>
        </span>{{ top ? `C'est ${firstName(top.name)}` : 'Aucune idée' }}
      </button>
    </div>

    <!-- Photo entière, avec le cadre du visage -->
    <div v-if="showContext && card" class="fixed inset-0 z-[80] bg-black/95 flex items-center justify-center" @click="showContext = false">
      <div class="relative max-w-full max-h-full">
        <img :src="card.face.image_url" alt="" class="max-w-[100vw] max-h-[100dvh] object-contain" />
        <div v-if="boxStyle" class="absolute border-2 border-amber-400 rounded-sm shadow-[0_0_0_9999px_rgba(0,0,0,.35)]" :style="boxStyle"></div>
      </div>
      <button type="button" class="absolute top-3 right-3 w-11 h-11 rounded-full bg-white/15 text-white flex items-center justify-center" aria-label="Fermer" :style="{ marginTop: 'env(safe-area-inset-top)' }">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
      </button>
    </div>

    <!-- Quelqu'un d'autre : recherche dans les fiches du cercle -->
    <BottomSheet :open="pickerOpen" title="Qui est-ce ?" @close="pickerOpen = false">
      <input
        ref="pickerInput"
        v-model="pickerQuery"
        type="search"
        placeholder="Prénom, nom…"
        class="form-input w-full h-12 text-base"
        autocomplete="off"
      />
      <p v-if="peopleLoading" class="mt-3 text-sm text-surface-500">Chargement des fiches…</p>
      <div v-else class="mt-2 -mx-4 divide-y divide-surface-100 max-h-[50vh] overflow-y-auto">
        <button
          v-for="p in pickerResults"
          :key="p.id"
          type="button"
          class="w-full flex items-center gap-3 px-4 h-14 text-left active:bg-surface-50"
          @click="confirmPerson(p)"
        >
          <PersonAvatar :person="p" :size="10" class="shrink-0" />
          <span class="flex-1 min-w-0">
            <span class="block text-[15px] font-semibold text-surface-900 truncate">{{ p.name }}</span>
            <span v-if="p.birth_date" class="block text-xs text-surface-500">{{ String(p.birth_date).slice(0, 4) }}</span>
          </span>
        </button>
        <p v-if="pickerQuery && !pickerResults.length" class="px-4 py-4 text-sm text-surface-500">Aucune fiche ne correspond.</p>
      </div>
      <button type="button" class="mt-3 w-full h-11 rounded-xl border border-surface-300 text-sm font-semibold text-surface-700" @click="notAFace">
        Ce n'est pas un visage
      </button>
    </BottomSheet>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import BottomSheet from '@/Components/BottomSheet.vue';
import { searchPeople } from '@/utils/personSearch';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
  album: { type: Object, default: null },
});

const toast = useToast();

// ---- Partie ---------------------------------------------------------------
const queue = ref([]);          // questions à venir (la première = carte courante)
const seen = ref([]);           // visages déjà passés (exclus des prochains lots)
const total = ref(0);           // visages restant au début de la partie
const done = ref(0);
const named = ref(0);
const streak = ref(0);
const bestStreak = ref(0);
const loading = ref(true);
const busy = ref(false);
const feedback = ref('');
const leaving = ref(false);

const card = computed(() => queue.value[0] || null);
const top = computed(() => card.value?.face?.suggestions?.[0]?.person || null);
const totalLabel = computed(() => (total.value >= 200 ? '200+' : Math.max(total.value, done.value)));
const progress = computed(() => (total.value ? Math.min(100, Math.round((100 * done.value) / Math.max(total.value, done.value))) : 0));
const firstName = (name) => (name || '').split(/\s+/)[0];

const whereLabel = computed(() => {
  const o = card.value?.face?.origin;
  if (!o) return '';
  const parts = [];
  if (o.albums?.length) parts.push(o.albums[0].name);
  if (o.date) parts.push(String(o.date).slice(0, 4));
  return parts.join(' · ');
});
const hint = computed(() => {
  const f = card.value?.face;
  if (!f) return '';
  const w = f.with || [];
  if (w.length === 1) return `Sur une photo avec ${firstName(w[0])}`;
  if (w.length > 1) return `Sur une photo avec ${w.slice(0, -1).map(firstName).join(', ')} et ${firstName(w[w.length - 1])}`;
  if (f.origin?.owner) return `Photo envoyée par ${firstName(f.origin.owner)}`;
  return 'Qui est cette personne ?';
});

const params = (extra = {}) => ({ album: props.album?.id || undefined, ...extra });

const fill = async () => {
  if (queue.value.length >= 2) return;
  try {
    const { data } = await axios.get('/quests/faces/next', { params: params({ exclude: seen.value.slice(-300) }) });
    const known = new Set(queue.value.map((q) => q.subject_id));
    for (const q of data.questions) if (!known.has(q.subject_id)) queue.value.push(q);
    if (!total.value && !done.value) total.value = data.total;
  } catch (e) {
    toast.error('Impossible de charger les visages.');
  } finally {
    loading.value = false;
  }
};

const advance = (text, good) => {
  feedback.value = text;
  setTimeout(() => { if (feedback.value === text) feedback.value = ''; }, 1200);
  done.value++;
  if (good) {
    streak.value++;
    bestStreak.value = Math.max(bestStreak.value, streak.value);
  } else streak.value = 0;
  seen.value.push(card.value.subject_id);
  queue.value.shift();
  resetDrag();
  fill();
};

const send = async (kind, payload, text, good) => {
  if (!card.value || busy.value) return;
  busy.value = true;
  const current = card.value;
  try {
    await axios.post('/quests/answer', {
      question_type: current.type,
      subject_id: current.subject_id,
      answer_kind: kind,
      payload,
    });
    if (kind === 'answered') named.value++;
    advance(text, good);
  } catch (e) {
    if (e.response?.status === 409) {
      // Quelqu'un d'autre a répondu entre-temps : on passe à la suivante.
      advance('Déjà identifié par quelqu’un', false);
    } else {
      toast.error(e.response?.data?.message || 'Réponse non enregistrée.');
      resetDrag();
    }
  } finally {
    busy.value = false;
  }
};

const confirmPerson = (p) => {
  pickerOpen.value = false;
  send('answered', { person_id: p.id }, `C'est ${firstName(p.name)} ! +1`, true);
};
const reject = () => send('no', { reason: 'stranger' }, 'Marqué inconnu', true);
const notAFace = () => { pickerOpen.value = false; send('no', { reason: 'not_face' }, 'Pas un visage', true); };
const skip = () => send('skipped', null, 'On y reviendra', false);

// ---- Gestes sur la carte --------------------------------------------------
const cardEl = ref(null);
const dx = ref(0);
const dy = ref(0);
const dragging = ref(false);
let start = null;
const THRESHOLD = 90;

const cardStyle = computed(() => ({
  transform: `translate(${dx.value}px, ${dy.value}px) rotate(${dx.value / 18}deg)`,
  transition: dragging.value ? 'none' : 'transform .25s ease',
}));
const dragHint = computed(() => {
  if (dx.value > THRESHOLD * 0.5 && top.value) return 'confirm';
  if (dx.value < -THRESHOLD * 0.5) return 'reject';
  return null;
});
const resetDrag = () => { dx.value = 0; dy.value = 0; dragging.value = false; start = null; };

const onPointerDown = (e) => {
  if (busy.value || e.button) return;
  start = { x: e.clientX, y: e.clientY };
  dragging.value = true;
  cardEl.value?.setPointerCapture?.(e.pointerId);
};
const onPointerMove = (e) => {
  if (!dragging.value || !start) return;
  dx.value = e.clientX - start.x;
  dy.value = Math.max(0, e.clientY - start.y);
};
const onPointerUp = () => {
  if (!dragging.value) return;
  dragging.value = false;
  if (dx.value > THRESHOLD && top.value) { dx.value = 500; confirmPerson(top.value); return; }
  if (dx.value < -THRESHOLD) { dx.value = -500; reject(); return; }
  if (dy.value > THRESHOLD) { dy.value = 700; skip(); return; }
  dx.value = 0; dy.value = 0;
};

// ---- Photo entière ---------------------------------------------------------
const showContext = ref(false);
const boxStyle = computed(() => {
  const b = card.value?.face?.bounding_box;
  if (!b) return null;
  return { left: `${b.x}%`, top: `${b.y}%`, width: `${b.width}%`, height: `${b.height}%` };
});

// ---- « Quelqu'un d'autre » --------------------------------------------------
const pickerOpen = ref(false);
const pickerQuery = ref('');
const pickerInput = ref(null);
const people = ref(null);
const peopleLoading = ref(false);
const pickerResults = computed(() => {
  const list = people.value || [];
  if (!pickerQuery.value.trim()) return list.slice(0, 30);
  return searchPeople(pickerQuery.value, list).slice(0, 30);
});
const openPicker = async () => {
  pickerQuery.value = '';
  pickerOpen.value = true;
  if (!people.value) {
    peopleLoading.value = true;
    try {
      const { data } = await axios.get('/people', { headers: { Accept: 'application/json' } });
      people.value = data;
    } finally {
      peopleLoading.value = false;
    }
  }
  await nextTick();
  pickerInput.value?.focus();
};
watch(pickerOpen, (v) => { if (!v) resetDrag(); });

// Sortie : toujours vers une page connue de l'appli (l'album d'où l'on vient,
// sinon l'accueil) — un « retour » navigateur pouvait ne mener nulle part.
const quit = () => {
  router.visit(props.album ? `/albums/${props.album.id}` : '/dashboard');
};

// Échap (clavier) : ferme la photo entière, sinon quitte la partie.
const onKey = (e) => {
  if (e.key !== 'Escape' || pickerOpen.value) return;
  if (showContext.value) showContext.value = false;
  else quit();
};

onMounted(() => {
  fill();
  document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<style scoped>
.pop-enter-active, .pop-leave-active { transition: all .2s ease; }
.pop-enter-from, .pop-leave-to { opacity: 0; transform: translateY(-8px) scale(.9); }
</style>
