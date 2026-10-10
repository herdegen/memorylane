<template>
  <!-- Sélecteur de cercle : seulement si le compte est dans plusieurs cercles -->
  <div v-if="circles.length > 1" class="relative" ref="rootRef">
    <button
      type="button"
      class="flex items-center gap-2 h-9 pl-1 pr-2.5 rounded-full border border-brand-300 bg-brand-100 text-sm font-semibold text-surface-900
             hover:bg-brand-200 focus:outline-hidden focus:ring-2 focus:ring-brand-500 transition-colors max-w-56"
      :aria-expanded="open"
      aria-haspopup="menu"
      @click="open = !open"
    >
      <span class="w-7 h-7 shrink-0 rounded-full bg-brand-600 text-white flex items-center justify-center">
        <CircleKindIcon :kind="current?.kind" class="w-4 h-4" />
      </span>
      <span class="truncate">{{ current?.name }}</span>
      <IconChevron class="w-4 h-4 shrink-0 text-surface-500 transition-transform" :class="{ 'rotate-180': open }" />
    </button>

    <div v-show="open" role="menu" class="absolute left-0 mt-2 w-80 bg-white border border-surface-200 rounded-2xl shadow-warm-lg p-2 z-50">
      <div class="px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-surface-500">Mes cercles</div>
      <button
        v-for="c in circles"
        :key="c.id"
        type="button"
        role="menuitem"
        class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left hover:bg-surface-50"
        :class="{ 'bg-surface-100': c.id === current?.id }"
        @click="pick(c)"
      >
        <span
          class="w-9 h-9 shrink-0 rounded-lg flex items-center justify-center"
          :class="c.kind === 'friends' ? 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'"
        >
          <CircleKindIcon :kind="c.kind" class="w-5 h-5" />
        </span>
        <span class="flex-1 min-w-0">
          <span class="block text-sm font-semibold text-surface-900 truncate">{{ c.name }}</span>
          <span class="block text-xs text-surface-500">{{ KIND_LABELS[c.kind] }}</span>
        </span>
        <svg v-if="c.id === current?.id" class="w-4 h-4 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path d="M5 13l4 4L19 7" /></svg>
      </button>

      <template v-if="canManage">
        <hr class="my-2 border-surface-200" />
        <div class="px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-surface-500">Administration</div>
        <Link href="/households" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-surface-700 hover:bg-surface-50" @click="open = false">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
          Créer et gérer les cercles
        </Link>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { IconChevron } from '@/Components/Icons';
import CircleKindIcon from '@/Components/CircleKindIcon.vue';
import { useCircle, KIND_LABELS } from '@/Composables/useCircle';

const { circles, current, canManage } = useCircle();

const open = ref(false);
const rootRef = ref(null);

function pick(c) {
  open.value = false;
  if (c.id === current.value?.id) return;
  router.post('/circles/current', { household_id: c.id }, { preserveScroll: true });
}

const onClickOutside = (e) => {
  if (rootRef.value && !rootRef.value.contains(e.target)) open.value = false;
};
onMounted(() => document.addEventListener('click', onClickOutside));
onUnmounted(() => document.removeEventListener('click', onClickOutside));
</script>
