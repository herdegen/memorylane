<template>
  <!-- « Qui est-ce ? » au salon : un visage cerclé sur sa photo, les prénoms
       que propose la reconnaissance, et trois sorties (passer, inconnu, pas un
       visage). Les réponses passent par /quests/answer, comme sur le téléphone. -->
  <Head title="Qui est-ce ? — Télévision" />
  <TvLayout
    :tab="tab" :circle="circle" :circles="circles" :account="account"
    :back="album ? `/tv/ecran/albums/${album.id}` : '/tv/ecran/accueil'" fill
    :hints="[['▲ ▼', 'Choisir'], ['OK', 'Valider'], ['↩', 'Quitter le jeu']]"
  >
    <div class="flex-1 min-h-0 px-24 pt-6 flex flex-col gap-6">
      <header class="shrink-0 flex items-center justify-between">
        <div class="flex items-baseline gap-6">
          <span class="font-display text-[3rem] font-bold">Qui est-ce ?</span>
          <span class="text-[1.625rem] text-[#b9ad9c]">{{ album ? album.name : circle?.name }}<template v-if="card"> · visage {{ done + 1 }} sur {{ totalLabel }}</template></span>
        </div>
        <div class="flex items-center gap-5">
          <span v-if="streak >= 2" class="flex items-center gap-2.5 px-5.5 py-2.5 rounded-full bg-[#3a2f1e] text-[#fcd34d] text-[1.625rem] font-extrabold">
            <svg class="w-6.5 h-6.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2c1 3.5 5 5.5 5 10a5 5 0 01-10 0c0-2 1-3.5 2-4.5 0 2 1 3 2 3 0-3-1-5.5 1-8.5z" /></svg>Série de {{ streak }}
          </span>
          <span v-if="named" class="text-[1.625rem] text-[#c9bfb1]">{{ named }} prénom{{ named > 1 ? 's' : '' }} trouvé{{ named > 1 ? 's' : '' }}</span>
        </div>
      </header>

      <div v-if="card" class="flex-1 min-h-0 grid grid-cols-[52rem_minmax(0,1fr)] gap-20 items-center">
        <!-- La photo, le visage cerclé, et le visage en grand -->
        <div class="flex flex-col gap-5 min-h-0">
          <div class="relative h-[36rem] rounded-[1.75rem] overflow-hidden bg-[#1c160e] flex items-center justify-center">
            <div class="relative max-w-full max-h-full">
              <img :key="card.subject_id" :src="card.face.image_url" alt="" class="block max-w-[52rem] max-h-[36rem]" />
              <div v-if="boxStyle" class="absolute rounded-full border-[0.375rem] border-[#fcd34d] shadow-[0_0_0_200rem_rgba(0,0,0,.45)]" :style="boxStyle"></div>
            </div>
            <img :src="card.face.crop_url" alt="" class="absolute left-6 bottom-6 w-[11rem] h-[11rem] rounded-full object-cover border-[0.375rem] border-[#fcd34d] shadow-[0_1.5rem_3rem_-1rem_rgba(0,0,0,.8)] bg-[#2c261e]" />
          </div>
          <div class="text-[1.75rem] text-[#e7e0d6] truncate">{{ whereLabel }}</div>
        </div>

        <!-- Les propositions -->
        <div class="flex flex-col gap-4">
          <span class="text-[1.75rem] text-[#c9bfb1]">{{ hint }}</span>
          <button
            v-for="(s, i) in suggestions"
            :key="s.person.id"
            type="button"
            data-tv
            :data-tv-autofocus="i === 0 ? '' : null"
            class="tv-choice flex items-center gap-5 px-7 py-3.5 rounded-3xl bg-[#211d17] text-left"
            :disabled="busy"
            @click="confirmPerson(s.person)"
          >
            <img v-if="s.person.avatar_url" :src="s.person.avatar_url" alt="" class="w-[4rem] h-[4rem] shrink-0 rounded-full object-cover bg-[#3a2f1e]" />
            <span v-else class="w-[4rem] h-[4rem] shrink-0 rounded-full bg-[#fcd34d] text-[#14110d] text-[2rem] font-extrabold flex items-center justify-center">{{ s.person.name.slice(0, 1) }}</span>
            <span class="flex flex-col gap-1 min-w-0">
              <span class="text-[2.125rem] font-extrabold truncate leading-tight">{{ s.person.name }}</span>
              <span class="tv-choice-sub text-[1.5rem] text-[#b9ad9c]">{{ s.person.birth_year ? `né·e en ${s.person.birth_year}` : '' }}{{ s.person.birth_year ? ' · ' : '' }}ressemblance {{ Math.round(s.score * 100) }} %</span>
            </span>
          </button>
          <p v-if="!suggestions.length" class="text-[1.875rem] text-[#e7e0d6]">Aucune idée de la reconnaissance pour ce visage. Pour lui donner un nom, passez par le téléphone.</p>
          <div class="flex gap-4 mt-2" data-tv-row>
            <button type="button" data-tv :data-tv-autofocus="suggestions.length ? null : ''" class="tv-option flex-1 justify-center px-4 text-[1.625rem]" :disabled="busy" @click="skip">Passer</button>
            <button type="button" data-tv class="tv-option flex-1 justify-center px-4 text-[1.625rem]" :disabled="busy" @click="reject">Personne inconnue</button>
            <button type="button" data-tv class="tv-option flex-1 justify-center px-4 text-[1.625rem]" :disabled="busy" @click="notAFace">Pas un visage</button>
          </div>
        </div>
      </div>

      <div v-else-if="loading" class="flex-1 flex items-center justify-center text-[2rem] text-[#c9bfb1]">Chargement des visages…</div>

      <div v-else class="flex-1 flex items-center justify-center">
        <div class="w-[52rem] rounded-[2.25rem] bg-[#211d17] p-14 flex flex-col items-center gap-6 text-center">
          <span class="text-[5rem]">🎉</span>
          <h2 class="font-display text-[3.5rem] font-bold">{{ done ? 'Bravo !' : 'Rien à identifier' }}</h2>
          <p class="text-[1.875rem] text-[#c9bfb1]">
            <template v-if="done">{{ done }} visage{{ done > 1 ? 's' : '' }} passé{{ done > 1 ? 's' : '' }} en revue, {{ named }} nommé{{ named > 1 ? 's' : '' }}{{ bestStreak >= 3 ? ` — meilleure série : ${bestStreak}` : '' }}.</template>
            <template v-else>Tous les visages {{ album ? 'de cet album' : 'de ce cercle' }} ont déjà un nom ou ont été mis de côté.</template>
          </p>
          <Link :href="album ? `/tv/ecran/albums/${album.id}` : '/tv/ecran/accueil'" data-tv data-tv-autofocus class="tv-btn tv-btn-primary mt-4">Terminer</Link>
        </div>
      </div>
    </div>

    <Transition name="tvpop">
      <div v-if="feedback" style="transform: translateX(-50%)" class="fixed top-12 left-1/2 z-[80] px-9 py-4 rounded-full bg-[#fcd34d] text-[#14110d] text-[2rem] font-extrabold shadow-[0_1rem_3rem_rgba(0,0,0,.5)]">{{ feedback }}</div>
    </Transition>
  </TvLayout>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import TvLayout from '@/Layouts/TvLayout.vue';
import { focusInitial } from '@/Tv/remote';

const props = defineProps({
  tab: String,
  circle: Object,
  circles: Array,
  account: Object,
  album: { type: Object, default: null },
});

const queue = ref([]);
const seen = ref([]);
const total = ref(0);
const done = ref(0);
const named = ref(0);
const streak = ref(0);
const bestStreak = ref(0);
const loading = ref(true);
const busy = ref(false);
const feedback = ref('');

const card = computed(() => queue.value[0] || null);
const suggestions = computed(() => (card.value?.face?.suggestions || []).slice(0, 4));
const totalLabel = computed(() => (total.value >= 200 ? '200+' : Math.max(total.value, done.value + 1)));
const firstName = (name) => (name || '').split(/\s+/)[0];

const whereLabel = computed(() => {
  const o = card.value?.face?.origin;
  if (!o) return '';
  return [o.albums?.[0]?.name, o.date ? String(o.date).slice(0, 4) : null].filter(Boolean).join(' · ');
});
const hint = computed(() => {
  const w = card.value?.face?.with || [];
  if (w.length === 1) return `Sur une photo avec ${firstName(w[0])}. Qui reconnaissez-vous ?`;
  if (w.length > 1) return `Sur une photo avec ${w.slice(0, -1).map(firstName).join(', ')} et ${firstName(w[w.length - 1])}. Qui reconnaissez-vous ?`;
  return 'Qui reconnaissez-vous ?';
});
const boxStyle = computed(() => {
  const b = card.value?.face?.bounding_box;
  if (!b) return null;
  // Cercle un peu plus large que le cadre du visage, et visible à 3 m même
  // pour un visage minuscule (foule) : 4,5 rem au moins.
  return {
    left: `${b.x + b.width / 2}%`,
    top: `${b.y + b.height / 2}%`,
    width: `max(4.5rem, ${b.width * 1.3}%)`,
    aspectRatio: '1',
    transform: 'translate(-50%, -50%)',
  };
});

let filling = null;
const fill = () => {
  if (queue.value.length >= 2) return filling;
  filling ??= (async () => {
    if (!queue.value.length) loading.value = true;
    try {
      const { data } = await axios.get('/quests/faces/next', { params: { album: props.album?.id || undefined, exclude: seen.value.slice(-300) } });
      const skipIds = new Set([...queue.value.map((q) => q.subject_id), ...seen.value]);
      for (const q of data.questions) if (!skipIds.has(q.subject_id)) queue.value.push(q);
      if (!total.value && !done.value) total.value = data.total;
      // Le visage suivant, préchargé.
      if (queue.value[1]) new Image().src = queue.value[1].face.image_url;
    } catch (e) {
      say('Impossible de charger les visages.');
    } finally {
      loading.value = false;
      filling = null;
    }
  })();
  return filling;
};

let feedbackTimer = null;
const say = (text) => {
  feedback.value = text;
  clearTimeout(feedbackTimer);
  feedbackTimer = setTimeout(() => { feedback.value = ''; }, 1400);
};

const advance = (text, good) => {
  say(text);
  done.value++;
  if (good) {
    streak.value++;
    bestStreak.value = Math.max(bestStreak.value, streak.value);
  } else streak.value = 0;
  seen.value.push(card.value.subject_id);
  queue.value.shift();
  fill();
};

const send = async (kind, payload, text, good) => {
  if (!card.value || busy.value) return;
  busy.value = true;
  const current = card.value;
  try {
    await axios.post('/quests/answer', { question_type: current.type, subject_id: current.subject_id, answer_kind: kind, payload });
    if (kind === 'answered') named.value++;
    advance(text, good);
  } catch (e) {
    if (e.response?.status === 409) advance('Déjà identifié par quelqu\'un', false);
    else say(e.response?.data?.message || 'Réponse non enregistrée.');
  } finally {
    busy.value = false;
  }
};

const confirmPerson = (p) => send('answered', { person_id: p.id }, `C'est ${firstName(p.name)} ! +1`, true);
const reject = () => send('no', { reason: 'stranger' }, 'Marqué inconnu', true);
const notAFace = () => send('no', { reason: 'not_face' }, 'Pas un visage', true);
const skip = () => send('skipped', null, 'On y reviendra', false);

// Nouveau visage : le focus revient sur la première proposition.
watch(() => card.value?.subject_id, async () => {
  await nextTick();
  focusInitial();
});

onMounted(fill);
</script>

<style scoped>
.tv-root .tv-choice:focus { background: #f7f2ea; color: #14110d; box-shadow: 0 0 0 0.375rem #12100c, 0 0 0 0.75rem #f59e0b; transform: scale(1.02); }
.tv-choice:focus .tv-choice-sub { color: #57534e; }
.tvpop-enter-active, .tvpop-leave-active { transition: all .2s ease; }
.tvpop-enter-from, .tvpop-leave-to { opacity: 0; transform: translate(-50%, -0.5rem) scale(.9); }
</style>
