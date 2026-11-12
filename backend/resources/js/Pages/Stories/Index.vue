<template>
  <Head :title="$t('Histoires')" />
  <AppLayout>
    <AlbumSegments />
    <div class="max-w-5xl mx-auto py-4 sm:py-9 px-4 sm:px-8">
      <div class="flex items-end justify-between gap-4 mb-5">
        <div class="min-w-0">
          <h1 class="font-display text-[26px] sm:text-4xl font-bold text-surface-900">{{ $t('Histoires') }}</h1>
          <p class="mt-1.5 text-sm text-surface-500">{{ $t('Ce qui s’est passé, et pourquoi on s’en souvient. Les plus proches de vous d’abord.') }}</p>
        </div>
        <Link href="/stories/create" class="btn-primary shrink-0">{{ $t('Raconter une histoire') }}</Link>
      </div>

      <div v-if="stories.length" class="grid gap-4 sm:grid-cols-2">
        <Link
          v-for="s in stories"
          :key="s.id"
          :href="`/stories/${s.id}`"
          class="group flex flex-col rounded-2xl bg-white border border-surface-200 overflow-hidden hover:border-brand-400 hover:shadow-sm transition"
        >
          <div v-if="s.cover" class="h-40 bg-surface-100 overflow-hidden">
            <img :src="s.cover.url" alt="" class="w-full h-full object-cover object-top group-hover:scale-[1.02] transition" loading="lazy" />
          </div>
          <div class="p-4 flex flex-col gap-2 flex-1">
            <div class="flex flex-wrap items-center gap-2 text-xs text-surface-500">
              <span class="px-2 py-0.5 rounded-full bg-brand-100 text-brand-800 dark:bg-brand-500/15 dark:text-brand-300 font-semibold">{{ kindLabel(s.kind) }}</span>
              <span v-if="s.period">{{ s.period }}</span>
              <span v-if="!s.published" class="px-2 py-0.5 rounded-full bg-surface-100 text-surface-600 font-semibold">{{ $t('Brouillon') }}</span>
            </div>
            <h2 class="font-display text-xl font-bold text-surface-900 leading-snug">{{ s.title }}</h2>
            <p v-if="s.summary" class="text-sm text-surface-600 line-clamp-3">{{ s.summary }}</p>
            <div v-if="s.main" class="mt-auto pt-2 flex items-center gap-2 text-sm">
              <PersonAvatar :person="s.main" :size="7" />
              <span class="min-w-0 truncate">
                <span class="font-semibold text-surface-800">{{ s.main.name }}</span>
                <span v-if="s.main.relation" class="text-surface-500"> · {{ s.main.relation }}</span>
              </span>
            </div>
          </div>
        </Link>
      </div>

      <div v-else class="rounded-2xl border border-dashed border-surface-300 px-6 py-12 text-center flex flex-col items-center gap-3">
        <p class="font-display text-xl text-surface-900">{{ $t('Aucune histoire pour l’instant') }}</p>
        <p class="max-w-md text-sm text-surface-500">{{ $t('Une anecdote de grand-père, un voyage, une affaire retrouvée dans les archives : racontez-la, la famille pourra la lire et la compléter.') }}</p>
        <Link href="/stories/create" class="btn-primary">{{ $t('Raconter une histoire') }}</Link>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import AlbumSegments from '@/Components/AlbumSegments.vue';
import { storyKindLabel as kindLabel } from '@/utils/stories';

defineProps({ stories: { type: Array, default: () => [] } });
</script>
