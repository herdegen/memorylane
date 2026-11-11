<template>
  <Head :title="form.title || $t('Raconter une histoire')" />
  <AppLayout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 pt-4 sm:pt-8 pb-48 sm:pb-32 flex flex-col gap-8">
      <div class="flex items-center justify-between gap-3 text-sm">
        <Link :href="story.id ? `/stories/${story.id}` : '/stories'" class="text-surface-500 hover:text-brand-700">← {{ story.id ? $t('Retour à l’histoire') : $t('Histoires') }}</Link>
        <span v-if="!story.published" class="px-2 py-0.5 rounded-full bg-surface-100 text-surface-600 text-xs font-semibold">{{ $t('Brouillon') }}</span>
      </div>

      <!-- L'histoire -->
      <section class="flex flex-col gap-4">
        <input
          id="story-title"
          v-model="form.title"
          type="text"
          maxlength="200"
          class="w-full border-0 border-b border-surface-200 bg-transparent px-0 pb-2 font-display text-3xl sm:text-4xl font-bold text-surface-900 placeholder:text-surface-300 focus:ring-0 focus:border-brand-500"
          :placeholder="$t('Titre de l’histoire')"
          :aria-label="$t('Titre de l’histoire')"
        />
        <p v-if="form.errors.title" class="text-sm text-red-600">{{ form.errors.title }}</p>
        <div>
          <label for="story-summary" class="form-label">{{ $t('En quelques lignes') }}</label>
          <textarea id="story-summary" v-model="form.summary" rows="3" class="form-input" :placeholder="$t('Ce qui s’est passé, en deux ou trois phrases. C’est ce qu’on lit en premier.')"></textarea>
        </div>
        <fieldset>
          <legend class="form-label">{{ $t('C’est…') }}</legend>
          <div class="grid gap-2 sm:grid-cols-3">
            <label
              v-for="k in STORY_KINDS"
              :key="k"
              class="cursor-pointer rounded-xl border px-3 py-2.5 flex flex-col gap-0.5 transition"
              :class="form.kind === k ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-surface-200 bg-white hover:border-surface-300'"
            >
              <input v-model="form.kind" type="radio" name="story-kind" :value="k" class="sr-only" />
              <span class="text-sm font-semibold text-surface-900">{{ storyKindLabel(k) }}</span>
              <span class="text-xs text-surface-500">{{ storyKindHint(k) }}</span>
            </label>
          </div>
        </fieldset>
        <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
          <div>
            <label for="story-credits" class="form-label">{{ $t('Qui l’a recherchée ou racontée') }}</label>
            <input id="story-credits" v-model="form.credits" type="text" maxlength="255" class="form-input" :placeholder="$t('Recherches et documents : Annick · Raconté par Mamie')" />
          </div>
          <label class="flex items-center gap-2 text-sm text-surface-700 sm:pb-2.5">
            <input id="story-sensitive" v-model="form.sensitive" type="checkbox" class="rounded border-surface-300 text-brand-600 focus:ring-brand-500" />
            {{ $t('Histoire douloureuse') }}
          </label>
        </div>
      </section>

      <!-- Les personnes -->
      <section class="flex flex-col gap-3">
        <h2 class="font-display text-xl font-bold text-surface-900">{{ $t('Les personnes') }}</h2>
        <p class="text-sm text-surface-500 -mt-2">{{ $t('L’histoire apparaît sur leur fiche. Ceux qui voient la fiche du personnage principal peuvent la lire.') }}</p>
        <ul class="flex flex-col gap-2">
          <li v-for="(p, i) in form.people" :key="p.id" class="flex flex-wrap items-center gap-3 rounded-xl bg-white border border-surface-200 px-3 py-2">
            <PersonAvatar :person="p" :size="9" />
            <span class="font-semibold text-surface-900 min-w-0 flex-1 truncate">{{ p.name }}</span>
            <input
              :id="`role-${p.id}`"
              v-model="p.role"
              type="text"
              maxlength="120"
              class="form-input !w-auto flex-1 min-w-[10rem] !py-1.5 text-sm"
              :placeholder="i === 0 ? $t('Rôle (facultatif)') : $t('Son rôle : sa mère, son frère…')"
              :aria-label="$t('Rôle dans l’histoire')"
            />
            <label class="flex items-center gap-1.5 text-xs text-surface-600 whitespace-nowrap">
              <input v-model="mainId" type="radio" name="story-main" :value="p.id" class="text-brand-600 focus:ring-brand-500" />
              {{ $t('Personnage principal') }}
            </label>
            <button type="button" class="text-surface-400 hover:text-red-600 p-1" :aria-label="$t('Retirer :name', { name: p.name })" @click="removePerson(i)">✕</button>
          </li>
        </ul>
        <div class="relative">
          <input
            id="story-person-search"
            v-model="personQuery"
            type="search"
            class="form-input"
            :placeholder="$t('Ajouter une personne…')"
            :aria-label="$t('Ajouter une personne')"
            autocomplete="off"
            @focus="loadDirectory"
          />
          <ul v-if="personResults.length" class="absolute z-20 mt-1 w-full rounded-xl bg-white border border-surface-200 shadow-lg overflow-hidden">
            <li v-for="r in personResults" :key="r.id">
              <button type="button" class="w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-surface-50" @click="addPerson(r)">
                <PersonAvatar :person="r" :size="7" />
                <span class="text-sm text-surface-900">{{ r.name }}</span>
                <span v-if="r.birth_date" class="text-xs text-surface-500">{{ r.birth_date.slice(0, 4) }}</span>
              </button>
            </li>
          </ul>
        </div>
        <p v-if="form.errors.people" class="text-sm text-red-600">{{ form.errors.people }}</p>
      </section>

      <!-- Le récit -->
      <section class="flex flex-col gap-4">
        <h2 class="font-display text-xl font-bold text-surface-900">{{ $t('Le récit') }}</h2>
        <p class="text-sm text-surface-500 -mt-3">{{ $t('Découpez l’histoire en chapitres. Dans chacun, alternez le texte, les citations et les photos ou documents.') }}</p>

        <div v-for="(c, ci) in form.chapters" :key="c.key" class="rounded-2xl bg-white border border-surface-200 p-4 sm:p-5 flex flex-col gap-3">
          <div class="flex items-start gap-2">
            <div class="flex-1 grid gap-2 sm:grid-cols-[14rem_1fr]">
              <input :id="`when-${c.key}`" v-model="c.when_label" type="text" maxlength="100" class="form-input !py-1.5 text-sm" :placeholder="$t('Quand : 1890, été 1944…')" :aria-label="$t('Quand')" />
              <input :id="`title-${c.key}`" v-model="c.title" type="text" maxlength="200" class="form-input !py-1.5 font-display text-lg font-semibold" :placeholder="$t('Titre du chapitre')" :aria-label="$t('Titre du chapitre')" />
            </div>
            <MoveButtons :first="ci === 0" :last="ci === form.chapters.length - 1" @up="move(form.chapters, ci, -1)" @down="move(form.chapters, ci, 1)" @remove="removeChapter(ci)" />
          </div>
          <p v-if="form.errors[`chapters.${ci}.title`]" class="text-sm text-red-600">{{ $t('Donnez un titre à ce chapitre.') }}</p>

          <div v-for="(b, bi) in c.blocks" :key="b.key" class="flex items-start gap-2">
            <div class="flex-1 min-w-0">
              <textarea
                v-if="b.type === 'text'"
                :id="`block-${b.key}`"
                v-model="b.text"
                rows="5"
                class="form-input text-[15px] leading-relaxed"
                :placeholder="$t('Racontez…')"
                :aria-label="$t('Paragraphe')"
              ></textarea>
              <div v-else-if="b.type === 'quote'" class="border-l-[3px] border-brand-500 pl-3 flex flex-col gap-1.5">
                <textarea :id="`block-${b.key}`" v-model="b.text" rows="2" class="form-input font-display italic" :placeholder="$t('« Les mots exacts… »')" :aria-label="$t('Citation')"></textarea>
                <input :id="`cite-${b.key}`" v-model="b.cite" type="text" maxlength="255" class="form-input !py-1.5 text-sm" :placeholder="$t('Qui l’a dit ou écrit, et où')" :aria-label="$t('Source de la citation')" />
              </div>
              <div v-else-if="b.type === 'media'" class="rounded-xl bg-surface-100 p-2.5 flex flex-col gap-2">
                <div class="flex gap-3 items-start">
                  <img v-if="thumb(b)" :src="thumb(b)" alt="" class="w-20 h-20 object-cover rounded-lg shrink-0 bg-surface-200" />
                  <div class="flex-1 min-w-0 flex flex-col gap-1.5">
                    <input :id="`caption-${b.key}`" v-model="b.caption" type="text" maxlength="255" class="form-input !py-1.5 text-sm" :placeholder="b.media?.title || $t('Légende')" :aria-label="$t('Légende')" />
                    <button type="button" class="self-start text-xs font-semibold text-brand-700 hover:underline" @click="b.showTranscript = !b.showTranscript">
                      {{ b.media?.transcription ? $t('Modifier la transcription') : $t('Ajouter la transcription du texte') }}
                    </button>
                  </div>
                </div>
                <div v-if="b.showTranscript" class="flex flex-col gap-1.5">
                  <textarea
                    :id="`transcript-${b.key}`"
                    v-model="b.transcriptDraft"
                    rows="8"
                    class="form-input text-sm"
                    :placeholder="$t('Recopiez le texte du document : il deviendra lisible sur téléphone et retrouvable par la recherche.')"
                    :aria-label="$t('Transcription')"
                  ></textarea>
                  <div class="flex items-center gap-2">
                    <button type="button" class="btn-secondary btn-sm" :disabled="b.savingTranscript" @click="saveTranscript(b)">{{ $t('Enregistrer la transcription') }}</button>
                    <span v-if="b.transcriptSaved" class="text-xs text-green-700">{{ $t('Enregistrée') }}</span>
                  </div>
                </div>
              </div>
            </div>
            <MoveButtons :first="bi === 0" :last="bi === c.blocks.length - 1" @up="move(c.blocks, bi, -1)" @down="move(c.blocks, bi, 1)" @remove="c.blocks.splice(bi, 1)" />
          </div>

          <div class="flex flex-wrap gap-2 pt-1">
            <button type="button" class="btn-secondary btn-sm" @click="addBlock(c, 'text')">+ {{ $t('Paragraphe') }}</button>
            <button type="button" class="btn-secondary btn-sm" @click="addBlock(c, 'quote')">+ {{ $t('Citation') }}</button>
            <button type="button" class="btn-secondary btn-sm" @click="pickFor = c">+ {{ $t('Photos ou documents') }}</button>
          </div>
        </div>

        <button type="button" class="self-start btn-secondary" @click="addChapter">+ {{ $t('Nouveau chapitre') }}</button>
      </section>

      <!-- Doutes et questions -->
      <section class="flex flex-col gap-3">
        <h2 class="font-display text-xl font-bold text-surface-900">{{ $t('Doutes et questions') }}</h2>
        <div>
          <label for="story-disagreements" class="form-label">{{ $t('Ce sur quoi les sources ne sont pas d’accord (une ligne par point)') }}</label>
          <textarea id="story-disagreements" v-model="form.disagreements" rows="4" class="form-input text-sm" :placeholder="$t('Son âge : 20 ans selon un journal, 23 ans selon le jugement.')"></textarea>
        </div>
        <div class="flex flex-col gap-2">
          <span class="form-label !mb-0">{{ $t('Ce qu’on ignore encore : la famille pourra répondre') }}</span>
          <div v-for="(q, qi) in form.questions" :key="q.key" class="flex items-center gap-2">
            <input :id="`question-${q.key}`" v-model="q.text" type="text" maxlength="500" class="form-input text-sm" :placeholder="$t('Où et quand est-il mort ?')" :aria-label="$t('Question')" />
            <button type="button" class="text-surface-400 hover:text-red-600 p-1" :aria-label="$t('Retirer la question')" @click="form.questions.splice(qi, 1)">✕</button>
          </div>
          <button type="button" class="self-start btn-secondary btn-sm" @click="form.questions.push({ key: uid(), id: null, text: '' })">+ {{ $t('Question') }}</button>
        </div>
      </section>

      <button v-if="story.id" type="button" class="self-start text-sm text-red-600 hover:underline" @click="destroy">{{ $t('Supprimer cette histoire') }}</button>
    </div>

    <!-- Barre d'enregistrement -->
    <!-- Téléphone : posée au-dessus de la barre d'onglets (comme le récit de vie). -->
    <div class="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] sm:bottom-0 z-30 bg-white/95 dark:bg-surface-50/95 backdrop-blur border-t border-surface-200 px-4 py-3">
      <div class="max-w-3xl mx-auto flex items-center justify-end gap-2">
        <span v-if="Object.keys(form.errors).length" class="mr-auto text-sm text-red-600">{{ $t('Il manque quelque chose : voir plus haut.') }}</span>
        <button v-if="!story.published" type="button" class="btn-secondary" :disabled="form.processing" @click="save(false)">{{ $t('Enregistrer le brouillon') }}</button>
        <button type="button" class="btn-primary" :disabled="form.processing || !form.title.trim()" @click="save(true)">
          {{ story.published ? $t('Enregistrer') : $t('Publier pour la famille') }}
        </button>
      </div>
    </div>

    <PhotoPicker
      :open="!!pickFor"
      :album-id="story.album_id"
      :person-id="mainPerson?.id"
      :person-name="mainPerson?.name || ''"
      :max="60"
      @close="pickFor = null"
      @done="addMedia"
    />
  </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import PhotoPicker from '@/Components/PhotoPicker.vue';
import MoveButtons from '@/Components/Stories/MoveButtons.vue';
import { usePeopleDirectory } from '@/Composables/usePeopleDirectory';
import { useConfirm } from '@/Composables/useConfirm';
import { t } from '@/i18n';
import { searchPeople } from '@/utils/personSearch';
import { STORY_KINDS, storyKindLabel, storyKindHint } from '@/utils/stories';

const props = defineProps({ story: { type: Object, required: true } });

let seq = 0;
const uid = () => `k${++seq}`;
const withKeys = (blocks) => (blocks || []).map((b) => ({
  key: uid(), text: '', cite: '', caption: '', ...b,
  transcriptDraft: b.media?.transcription || '', showTranscript: false, savingTranscript: false, transcriptSaved: false,
}));

const form = useForm({
  title: props.story.title || '',
  summary: props.story.summary || '',
  kind: props.story.kind || 'memory',
  credits: props.story.credits || '',
  sensitive: !!props.story.sensitive,
  disagreements: props.story.disagreements || '',
  album_id: props.story.album_id || null,
  people: (props.story.people || []).map((p) => ({ ...p, role: p.role || '' })),
  chapters: (props.story.chapters || []).map((c) => ({ key: uid(), title: c.title, when_label: c.when_label || '', blocks: withKeys(c.blocks) })),
  questions: (props.story.questions || []).map((q) => ({ key: uid(), id: q.id, text: q.text })),
});
if (!form.chapters.length) form.chapters.push({ key: uid(), title: '', when_label: '', blocks: withKeys([{ type: 'text' }]) });

// Personnage principal (le premier par défaut).
const mainId = ref((form.people.find((p) => p.is_main) || form.people[0])?.id || null);
const mainPerson = computed(() => form.people.find((p) => p.id === mainId.value) || null);
watch(() => form.people.length, () => {
  if (!form.people.some((p) => p.id === mainId.value)) mainId.value = form.people[0]?.id || null;
});

// Ajout de personnes depuis l'annuaire.
const directory = usePeopleDirectory();
const people = ref([]);
const personQuery = ref('');
const loadDirectory = async () => { if (!people.value.length) people.value = await directory.load().catch(() => []); };
const personResults = computed(() => {
  const q = personQuery.value.trim();
  if (q.length < 2) return [];
  const taken = new Set(form.people.map((p) => p.id));
  return searchPeople(q, people.value.filter((p) => !taken.has(p.id)), (p) => p.name).slice(0, 8);
});
const addPerson = (p) => {
  form.people.push({ id: p.id, name: p.name, avatar_url: p.avatar_url, avatar_position: p.avatar_position, role: '' });
  personQuery.value = '';
};
const removePerson = (i) => form.people.splice(i, 1);

// Chapitres et blocs.
const move = (list, i, d) => {
  const j = i + d;
  if (j < 0 || j >= list.length) return;
  [list[i], list[j]] = [list[j], list[i]];
};
const addChapter = () => form.chapters.push({ key: uid(), title: '', when_label: '', blocks: withKeys([{ type: 'text' }]) });
const removeChapter = (i) => form.chapters.splice(i, 1);
const addBlock = (c, type) => c.blocks.push(...withKeys([{ type }]));
const pickFor = ref(null);
const addMedia = (selected) => {
  if (pickFor.value) {
    const known = new Set(pickFor.value.blocks.filter((b) => b.type === 'media').map((b) => b.media_id));
    pickFor.value.blocks.push(...withKeys(selected.filter((m) => !known.has(m.id)).map((m) => ({ type: 'media', media_id: m.id, thumbnail_url: m.thumbnail_url }))));
  }
  pickFor.value = null;
};
const thumb = (b) => b.media?.thumb || b.media?.url || b.thumbnail_url || null;

// Transcription d'un document : enregistrée sur le média lui-même.
const saveTranscript = async (b) => {
  b.savingTranscript = true;
  try {
    await axios.put(`/media/${b.media_id}`, { transcription: b.transcriptDraft }, { headers: { Accept: 'application/json' } });
    b.media = { ...(b.media || {}), transcription: b.transcriptDraft };
    b.transcriptSaved = true;
    setTimeout(() => { b.transcriptSaved = false; }, 2500);
  } finally {
    b.savingTranscript = false;
  }
};

const save = (publish) => {
  form
    .transform((data) => ({
      ...data,
      publish,
      people: data.people.map((p) => ({ id: p.id, role: p.role || null, is_main: p.id === mainId.value })),
      chapters: data.chapters.map((c) => ({
        title: c.title,
        when_label: c.when_label || null,
        blocks: c.blocks.map((b) => (b.type === 'media'
          ? { type: 'media', media_id: b.media_id, caption: b.caption || null }
          : b.type === 'quote' ? { type: 'quote', text: b.text, cite: b.cite || null } : { type: 'text', text: b.text })),
      })),
      questions: data.questions.filter((q) => q.text.trim()).map((q) => ({ id: q.id, text: q.text })),
    }));
  if (props.story.id) form.put(`/stories/${props.story.id}`, { preserveScroll: true });
  else form.post('/stories', { preserveScroll: true });
};

const { confirm } = useConfirm();
const destroy = async () => {
  const ok = await confirm({
    title: t('Supprimer cette histoire ?'),
    message: t('Le récit et les ajouts de la famille seront supprimés. Les photos et documents restent dans leurs albums.'),
    confirmLabel: t('Supprimer'),
    danger: true,
  });
  if (ok) router.delete(`/stories/${props.story.id}`);
};
</script>
