<template>
  <Head :title="pageTitle" />
  <AppLayout>
    <div class="pt-5 pb-32 sm:pb-36">
      <div class="max-w-2xl mx-auto px-4 sm:px-6">
        <Link :href="`/people/${person.id}#frise`" class="text-sm font-medium text-brand-700 dark:text-brand-300 hover:underline">← {{ person.name }}</Link>
        <div class="mt-2 flex items-center gap-3">
          <PersonAvatar :name="person.name" :src="person.avatar" :size="10" />
          <div class="min-w-0">
            <h1 class="font-display text-2xl sm:text-3xl font-bold text-surface-900 leading-tight">{{ pageTitle }}</h1>
            <p class="text-sm text-surface-500">{{ $t('Étape :n sur :total', { n: index + 1, total: chapters.length }) }}</p>
          </div>
        </div>

        <!-- Étapes : où on en est, on peut sauter -->
        <nav class="mt-4 -mx-4 px-4 flex gap-1.5 overflow-x-auto pb-1" :aria-label="$t('Étapes')">
          <button
            v-for="(c, i) in chapters"
            :key="c.key"
            type="button"
            class="shrink-0 inline-flex items-center gap-1 h-8 px-3 rounded-full text-xs font-semibold border transition-colors"
            :class="i === index
              ? 'bg-brand-600 border-brand-600 text-white'
              : (answers[c.key]?.status === 'done' ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200'
                : (answers[c.key]?.status === 'skipped' ? 'border-surface-200 text-surface-400' : 'border-surface-300 text-surface-700'))"
            :aria-current="i === index ? 'step' : null"
            @click="go(i)"
          >
            <span v-if="answers[c.key]?.status === 'done' && i !== index" aria-hidden="true">✓</span>
            {{ c.title }}
          </button>
        </nav>

        <!-- Fin du parcours -->
        <section v-if="finished" class="mt-6 bg-white rounded-2xl border border-surface-200 p-6 text-center">
          <p class="text-4xl" aria-hidden="true">🎉</p>
          <h2 class="mt-2 font-display text-2xl font-bold text-surface-900">{{ $t('Merci !') }}</h2>
          <p class="mt-2 text-surface-600">{{ $tc(':count moment est sur la frise.|:count moments sont sur la frise.', events.length) }}</p>
          <p class="mt-1 text-sm text-surface-500">{{ $t('Les récits seront relus pour ajouter les détails (dates, lieux, personnes) à l’arbre.') }}</p>
          <div class="mt-5 flex flex-wrap justify-center gap-2">
            <Link :href="`/people/${person.id}#frise`" class="btn-primary h-11">{{ $t('Voir la frise') }}</Link>
            <button type="button" class="btn-secondary h-11" @click="go(0)">{{ $t('Revoir les étapes') }}</button>
          </div>
        </section>

        <section v-else class="mt-5 space-y-5">
          <div>
            <h2 class="font-display text-2xl font-bold text-surface-900">{{ chapter.title }}</h2>
            <p v-if="chapter.years" class="text-sm text-surface-500">
              {{ chapter.years[1] ? $t('Vers :from – :to', { from: chapter.years[0], to: chapter.years[1] }) : $t('Depuis :from environ', { from: chapter.years[0] }) }}
            </p>
            <p v-if="chapter.optional" class="text-sm text-surface-500">{{ $t('Si cela ne concerne pas :name, passez cette étape.', { name: person.first_name }) }}</p>
          </div>

          <!-- Ce que l'arbre sait déjà -->
          <div v-if="chapter.facts.length" class="rounded-xl bg-surface-100 px-4 py-3 text-sm text-surface-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-surface-500 mb-1">{{ $t('Déjà dans l’arbre') }}</p>
            <p v-for="(f, i) in chapter.facts" :key="i">{{ f }}</p>
          </div>

          <!-- Éléments datés, par type -->
          <div v-for="type in chapter.rows" :key="type" class="space-y-2">
            <LifeStoryRow
              v-for="row in rowsOf(type)"
              :key="row.key"
              :row="row"
              :def="rowTypes[row.type]"
              :years="chapter.years"
              @remove="removeRow(row)"
            />
            <button type="button" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 dark:text-brand-300" @click="addRow(type)">
              <span aria-hidden="true">＋</span> {{ rowTypes[type].add }}
            </button>
          </div>

          <!-- Lieux de vie de la période -->
          <div v-if="chapter.moves" class="space-y-2 rounded-2xl border border-surface-200 bg-white p-4">
            <p class="font-semibold text-surface-900">🏠 {{ chapter.moves }}</p>
            <LifeStoryRow
              v-for="row in rowsOf('residence')"
              :key="row.key"
              :row="row"
              :def="rowTypes.residence"
              :years="chapter.years"
              @remove="removeRow(row)"
            />
            <button type="button" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 dark:text-brand-300" @click="addRow('residence')">
              <span aria-hidden="true">＋</span> {{ rowTypes.residence.add }}
            </button>
          </div>

          <!-- Le récit : écrit ou à l'oral -->
          <div class="rounded-2xl border border-surface-200 bg-white p-4 space-y-3">
            <p class="font-semibold text-surface-900">✍️ {{ $t('Racontez') }}</p>
            <ul class="flex flex-wrap gap-1.5">
              <li v-for="(p, i) in chapter.prompts" :key="i" class="rounded-full bg-amber-50 px-2.5 py-1 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">{{ p }}</li>
            </ul>
            <textarea
              v-model="body"
              rows="5"
              maxlength="20000"
              class="w-full px-3 py-2 text-[15px] border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
              :placeholder="$t('Quelques phrases, des souvenirs, des noms, des dates… tout compte.')"
            ></textarea>

            <!-- À l'oral -->
            <div class="flex flex-wrap items-center gap-2">
              <template v-if="voice.state.value === 'idle' || voice.state.value === 'sent' || voice.state.value === 'error'">
                <button type="button" class="btn-secondary h-10" @click="voice.start()">🎤 {{ answers[chapter.key]?.audio_url ? $t('Réenregistrer à l’oral') : $t('Répondre à l’oral') }}</button>
                <span v-if="voice.error.value" class="text-xs text-red-600 dark:text-red-400">{{ voice.error.value }}</span>
              </template>
              <template v-else-if="voice.state.value === 'recording'">
                <span class="w-2.5 h-2.5 rounded-full bg-red-600 animate-pulse" aria-hidden="true"></span>
                <span class="font-semibold tabular-nums">{{ voice.elapsedLabel.value }}</span>
                <button type="button" class="btn-primary h-10" @click="voice.stop()">■ {{ $t('Arrêter') }}</button>
              </template>
              <template v-else-if="voice.state.value === 'recorded'">
                <audio :src="voice.playbackUrl.value" controls class="h-10 max-w-full"></audio>
                <button type="button" class="btn-primary h-10" @click="sendVoice">{{ $t('Garder') }}</button>
                <button type="button" class="btn-secondary h-10" @click="voice.reset()">{{ $t('Recommencer') }}</button>
              </template>
              <span v-else-if="voice.state.value === 'sending'" class="text-sm text-surface-500">{{ $t('Envoi…') }}</span>
            </div>
            <audio v-if="answers[chapter.key]?.audio_url && voice.state.value !== 'recorded'" :src="answers[chapter.key].audio_url" controls preload="none" class="w-full"></audio>
          </div>
        </section>
      </div>
    </div>

    <!-- Navigation, sous le pouce -->
    <div v-if="!finished" class="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] sm:bottom-0 z-30 border-t border-surface-200 bg-white/95 backdrop-blur dark:bg-surface-50/95">
      <div class="max-w-2xl mx-auto px-4 sm:px-6 py-3 flex items-center gap-2">
        <button type="button" class="btn-secondary h-11" :disabled="index === 0 || saving" @click="go(index - 1)">←</button>
        <button type="button" class="btn-secondary h-11" :disabled="saving" @click="save('skipped')">{{ $t('Passer') }}</button>
        <button type="button" class="btn-primary h-11 flex-1" :disabled="saving" @click="save('done')">
          {{ saving ? $t('Enregistrement...') : $t('Valider et continuer') }}
        </button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import LifeStoryRow from '@/Components/LifeStoryRow.vue';
import { useVoiceRecorder } from '@/Composables/useVoiceRecorder';
import { useConfirm } from '@/Composables/useConfirm';
import { useToast } from '@/Composables/useToast';
import { apiError } from '@/utils/apiError';
import { t } from '@/i18n';

const props = defineProps({
  person: { type: Object, required: true },
  chapters: { type: Array, required: true },
  rowTypes: { type: Object, required: true },
  events: { type: Array, default: () => [] },
  answers: { type: [Object, Array], default: () => ({}) },
});

const toast = useToast();
const { confirm } = useConfirm();
const events = ref([...props.events]);
const answers = ref(Array.isArray(props.answers) ? {} : { ...props.answers });

const pageTitle = computed(() => (props.person.is_self ? t('Mon récit de vie') : t('Le récit de vie de :name', { name: props.person.first_name })));

// Reprise : l'étape demandée, sinon la première pas encore faite.
const startIndex = () => {
  const asked = new URLSearchParams(window.location.search).get('etape');
  const byKey = props.chapters.findIndex((c) => c.key === asked);
  if (byKey >= 0) return byKey;
  const todo = props.chapters.findIndex((c) => !answers.value[c.key]?.status);
  return todo >= 0 ? todo : 0;
};
const index = ref(startIndex());
const finished = ref(false);
const chapter = computed(() => props.chapters[index.value]);

// Formulaire de l'étape : moments existants de la période + nouveaux.
let seq = 0;
const rows = ref([]);
const removed = ref([]);
const body = ref('');
const dirty = ref(false);

function load() {
  const key = chapter.value.key;
  rows.value = events.value
    .filter((e) => e.chapters.includes(key) && (chapter.value.rows.includes(e.type) || (e.type === 'residence' && chapter.value.moves)))
    .map((e) => ({ ...e, key: e.id }));
  removed.value = [];
  body.value = answers.value[key]?.body || '';
  voice.reset();
  dirty.value = false;
}

const rowsOf = (type) => rows.value.filter((r) => r.type === type);

function addRow(type) {
  const def = props.rowTypes[type];
  const years = chapter.value.years;
  // Écoles : les années suggérées d'après l'âge ; ailleurs, au choix.
  const suggest = chapter.value.suggest === type && years;
  rows.value.push({
    key: `new-${++seq}`,
    id: null,
    type,
    title: def.title && !def.title_label ? def.title : '',
    place: '',
    from: suggest ? years[0] : null,
    to: suggest && def.range ? years[1] : null,
    editable: true,
    deletable: true,
  });
}

function removeRow(row) {
  if (row.id) removed.value.push(row.id);
  rows.value = rows.value.filter((r) => r.key !== row.key);
}

watch([rows, body], () => { dirty.value = true; }, { deep: true });

async function go(i) {
  if (i < 0 || i >= props.chapters.length) return;
  if (dirty.value && !(await confirm({ title: t('Changer d’étape'), message: t('Ce que vous avez saisi à cette étape n’est pas encore validé.'), confirmLabel: t('Changer quand même') }))) return;
  finished.value = false;
  index.value = i;
}

watch(index, () => {
  load();
  const url = new URL(window.location.href);
  url.searchParams.set('etape', chapter.value.key);
  window.history.replaceState(window.history.state, '', url);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}, { immediate: false });

const saving = ref(false);
async function save(status) {
  const filled = rows.value.filter((r) => r.editable && ((r.title || '').trim() || (r.place || '').trim()));
  if (status === 'done' && filled.some((r) => !r.from)) {
    toast.error(t('Indiquez au moins l’année de chaque ligne.'));
    return;
  }
  saving.value = true;
  try {
    const { data } = await axios.post(`/people/${props.person.id}/recit/${chapter.value.key}`, {
      status,
      body: body.value,
      rows: filled.filter((r) => r.from).map(({ id, type, title, place, from, to, lat, lng }) => ({ id, type, title, place, from, to, lat, lng })),
      removed: removed.value,
    });
    events.value = data.events;
    answers.value = { ...answers.value, [chapter.value.key]: data.answer };
    dirty.value = false;
    if (index.value < props.chapters.length - 1) {
      index.value++;
    } else {
      finished.value = true;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  } catch (error) {
    toast.error(apiError(error, t('Erreur lors de l\'enregistrement.')));
  } finally {
    saving.value = false;
  }
}

// Récit à l'oral (une prise par étape, remplace la précédente).
const voice = useVoiceRecorder({ maxSeconds: 1800 });
async function sendVoice() {
  if (!voice.blob.value) return;
  const type = voice.blob.value.type;
  const ext = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm';
  const form = new FormData();
  form.append('audio', voice.blob.value, `recit.${ext}`);
  form.append('duration', String(Math.max(1, voice.elapsed.value)));
  voice.state.value = 'sending';
  try {
    const { data } = await axios.post(`/people/${props.person.id}/recit/${chapter.value.key}/audio`, form);
    answers.value = { ...answers.value, [chapter.value.key]: { ...(answers.value[chapter.value.key] || {}), ...data.answer } };
    voice.reset();
    toast.success(t('Récit enregistré.'));
  } catch (error) {
    voice.state.value = 'recorded';
    toast.error(apiError(error, t('Erreur lors de l\'envoi du fichier.')));
  }
}

load();
</script>
