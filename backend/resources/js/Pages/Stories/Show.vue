<template>
  <Head :title="story.title" />
  <AppLayout>
    <article class="max-w-3xl mx-auto px-4 sm:px-6 pt-6 sm:pt-10 pb-24">
      <!-- Fil d'Ariane + actions -->
      <div class="flex items-center justify-between gap-3 text-sm">
        <Link href="/stories" class="text-surface-500 hover:text-brand-700">← {{ $t('Histoires') }}</Link>
        <div v-if="canEdit" class="flex items-center gap-2">
          <span v-if="!story.published" class="px-2 py-0.5 rounded-full bg-surface-100 text-surface-600 text-xs font-semibold">{{ $t('Brouillon') }}</span>
          <Link :href="`/stories/${story.id}/edit`" class="btn-secondary btn-sm">{{ $t('Modifier') }}</Link>
        </div>
      </div>

      <!-- En-tête -->
      <header class="mt-6 flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-surface-500">
          <span class="px-2.5 py-0.5 rounded-full bg-brand-100 text-brand-800 dark:bg-brand-500/15 dark:text-brand-300 text-xs font-semibold uppercase tracking-wide">{{ kindLabel(story.kind) }}</span>
          <span v-if="story.period">{{ story.period }}</span>
        </div>
        <h1 class="font-display text-4xl sm:text-5xl font-bold text-surface-900 leading-tight text-balance">{{ story.title }}</h1>
        <p v-if="story.summary" class="text-lg sm:text-xl leading-relaxed text-surface-800 whitespace-pre-line">{{ story.summary }}</p>
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-surface-500">
          <span v-if="story.credits">{{ story.credits }}</span>
          <span>{{ $tc(':count min de lecture|:count min de lecture', story.reading_minutes) }}<template v-if="story.documents_count"> · {{ $tc(':count document|:count documents', story.documents_count) }}</template></span>
        </div>

        <!-- Personnes de l'histoire -->
        <div v-if="story.people.length" class="flex flex-wrap gap-2">
          <Link
            v-for="p in story.people"
            :key="p.id"
            :href="`/people/${p.id}`"
            class="flex items-center gap-2.5 rounded-full bg-white border border-surface-200 py-1 pl-1 pr-3.5 hover:border-brand-400 transition"
          >
            <PersonAvatar :person="p" :size="8" />
            <span class="text-sm leading-tight">
              <span class="font-semibold text-surface-900">{{ p.name }}</span>
              <span v-if="p.role || p.relation" class="block text-xs text-surface-500">{{ [p.role, p.relation].filter(Boolean).join(' · ') }}</span>
            </span>
          </Link>
        </div>

        <!-- Histoire sensible -->
        <p v-if="story.sensitive" class="rounded-xl bg-amber-50 text-amber-900 border border-amber-200 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200 px-4 py-3 text-sm">
          {{ $t('Cette histoire évoque des faits douloureux. Elle est racontée avec les documents de l’époque.') }}
        </p>

        <!-- Sommaire -->
        <nav v-if="story.chapters.length > 1" class="flex flex-wrap gap-x-4 gap-y-1.5 border-y border-surface-200 py-3 text-sm" :aria-label="$t('Chapitres')">
          <a v-for="c in story.chapters" :key="c.id" :href="`#ch-${c.id}`" class="text-surface-500 hover:text-brand-700">{{ c.title }}</a>
          <a v-if="hasDoubts" href="#doutes" class="text-surface-500 hover:text-brand-700">{{ $t('Ce qu’on ignore') }}</a>
        </nav>
      </header>

      <!-- Chapitres -->
      <section v-for="c in story.chapters" :id="`ch-${c.id}`" :key="c.id" class="pt-10 flex flex-col gap-4 scroll-mt-20">
        <span v-if="c.when_label" class="text-xs font-semibold uppercase tracking-widest text-brand-600">{{ c.when_label }}</span>
        <h2 class="font-display text-2xl sm:text-3xl font-bold text-surface-900 text-balance">{{ c.title }}</h2>
        <template v-for="(b, i) in c.blocks" :key="i">
          <p v-if="b.type === 'text'" class="text-[17px] leading-relaxed text-surface-800 whitespace-pre-line">{{ b.text }}</p>
          <blockquote v-else-if="b.type === 'quote'" class="border-l-[3px] border-brand-500 pl-4 py-1">
            <p class="font-display italic text-xl leading-snug text-surface-900 whitespace-pre-line">{{ b.text }}</p>
            <cite v-if="b.cite" class="block mt-1.5 not-italic text-sm text-surface-500">{{ b.cite }}</cite>
          </blockquote>
          <figure v-else-if="b.type === 'media' && b.media" class="rounded-2xl bg-surface-100 p-3 flex flex-col gap-2">
            <Link :href="`/media/${b.media.id}`" class="block">
              <img :src="b.media.url" :alt="b.caption || b.media.title || ''" class="mx-auto max-h-[480px] w-auto rounded-md" loading="lazy" />
            </Link>
            <figcaption class="flex flex-wrap justify-between gap-x-3 gap-y-1 text-sm text-surface-500">
              <span class="font-semibold text-surface-800">{{ b.caption || b.media.title }}</span>
              <button v-if="b.media.transcription" type="button" class="font-semibold text-brand-700 hover:underline" @click="toggle(b.media.id)">
                {{ openTranscripts[b.media.id] ? $t('Masquer la transcription') : $t('Lire la transcription') }}
              </button>
            </figcaption>
            <div v-if="openTranscripts[b.media.id]" class="rounded-xl bg-white px-4 py-3 text-[15px] leading-relaxed text-surface-800 whitespace-pre-line max-h-[60vh] overflow-y-auto">{{ b.media.transcription }}</div>
          </figure>
        </template>
      </section>

      <!-- Désaccords et questions -->
      <section v-if="hasDoubts" id="doutes" class="pt-10 flex flex-col gap-4 scroll-mt-20">
        <div v-if="story.disagreements.length" class="rounded-2xl bg-orange-50 dark:bg-orange-950/40 px-5 py-4">
          <h3 class="font-semibold text-orange-800 dark:text-orange-300">{{ $t('Les sources ne sont pas d’accord') }}</h3>
          <ul class="mt-2 list-disc pl-5 flex flex-col gap-1.5 text-[15px] text-surface-800">
            <li v-for="(d, i) in story.disagreements" :key="i">{{ d }}</li>
          </ul>
        </div>
        <div v-if="story.questions.length" class="rounded-2xl bg-sky-50 dark:bg-sky-950/40 px-5 py-4">
          <h3 class="font-semibold text-sky-800 dark:text-sky-300">{{ $t('Ce qu’on ignore encore') }}</h3>
          <div v-for="q in story.questions" :key="q.id" class="py-3 border-t border-sky-100 dark:border-sky-900 first-of-type:border-0 flex flex-col gap-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span class="flex-1 min-w-[14rem] text-[15px] text-surface-800">{{ q.text }}</span>
              <button v-if="story.published && answering !== q.id" type="button" class="shrink-0 rounded-full border border-sky-700 dark:border-sky-300 text-sky-800 dark:text-sky-300 bg-white px-3 py-1 text-[13px] font-semibold" @click="openAnswer(q.id)">
                {{ $t('Je sais quelque chose') }}
              </button>
            </div>
            <StoryNoteList :notes="q.notes" @deleted="reload" />
            <StoryNoteForm v-if="answering === q.id" :story-id="story.id" :question-id="q.id" @done="answering = null" />
          </div>
        </div>
      </section>

      <!-- Ajouts de la famille -->
      <section class="pt-10 flex flex-col gap-3">
        <h3 v-if="story.notes.length" class="font-display text-xl font-bold text-surface-900">{{ $t('Ce que la famille ajoute') }}</h3>
        <StoryNoteList :notes="story.notes" @deleted="reload" />
        <StoryNoteForm v-if="answering === 'free'" :story-id="story.id" @done="answering = null" />
        <div class="flex flex-wrap gap-2 pt-2">
          <button v-if="story.published && answering !== 'free'" type="button" class="btn-primary" @click="openAnswer('free')">{{ $t('Ajouter ce que je sais') }}</button>
          <Link v-if="story.album" :href="`/albums/${story.album.id}`" class="btn-secondary">{{ $t('Voir l’album') }}</Link>
        </div>
      </section>

      <p class="pt-10 text-sm text-surface-500">
        {{ $t('Raconté par :name', { name: story.author || '' }) }}
      </p>
    </article>
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import StoryNoteForm from '@/Components/Stories/StoryNoteForm.vue';
import StoryNoteList from '@/Components/Stories/StoryNoteList.vue';
import { storyKindLabel as kindLabel } from '@/utils/stories';

const props = defineProps({
  story: { type: Object, required: true },
  canEdit: { type: Boolean, default: false },
});

const openTranscripts = reactive({});
const toggle = (id) => { openTranscripts[id] = !openTranscripts[id]; };
const answering = ref(null);
const openAnswer = (id) => { answering.value = id; };
const reload = () => router.reload({ only: ['story'] });
const hasDoubts = computed(() => props.story.disagreements.length > 0 || props.story.questions.length > 0);
</script>
