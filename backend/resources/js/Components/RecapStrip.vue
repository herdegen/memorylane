<template>
  <!-- Récaps à la une (Accueil) : des bulles façon « stories » ; anneau
       ambré tant que le récap n'a pas été regardé. -->
  <section>
    <div class="flex items-baseline justify-between gap-3 mb-3">
      <h2 class="font-display text-xl sm:text-2xl font-semibold text-surface-900">{{ $t('Récaps') }}</h2>
      <Link href="/recaps" class="text-sm font-semibold text-brand-700 dark:text-brand-400 hover:underline">{{ $t('Tous les récaps') }}</Link>
    </div>
    <div class="flex gap-4 overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 pb-1 [scrollbar-width:none]">
      <button
        v-for="r in list"
        :key="r.key"
        type="button"
        class="shrink-0 w-24 text-center group"
        @click="player?.play(r)"
      >
        <span
          class="block mx-auto w-20 h-20 rounded-full p-[3px] transition group-hover:scale-105"
          :class="r.seen ? 'bg-surface-200' : 'bg-linear-to-tr from-amber-500 via-brand-500 to-rose-500'"
        >
          <span class="block w-full h-full rounded-full p-[3px] bg-page">
            <PersonAvatar :person="{ name: r.title, avatar_url: r.avatar_url, avatar_position: r.avatar_position }" shape="circle" class="w-full h-full" />
          </span>
        </span>
        <span class="mt-1.5 block text-[13px] font-semibold text-surface-900 leading-tight line-clamp-2">{{ r.title }}</span>
        <span class="block text-[11px] text-surface-500">{{ r.subtitle }}</span>
      </button>
    </div>
    <RecapPlayer ref="player" @seen="markSeen" />
  </section>
</template>

<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import RecapPlayer from '@/Components/RecapPlayer.vue';

const props = defineProps({
  recaps: { type: Array, default: () => [] },
});
const player = ref(null);
const list = ref(props.recaps.map((r) => ({ ...r })));
const markSeen = (key) => {
  list.value = list.value.map((r) => (r.key === key ? { ...r, seen: true } : r));
};
</script>
