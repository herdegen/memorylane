<template>
  <!-- Accueil : propositions de correction sur des fiches communes à examiner -->
  <Link
    v-if="counts?.to_decide"
    href="/shared-people"
    class="flex items-center gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-5 hover:border-amber-300 dark:border-amber-500/30 dark:bg-amber-500/10"
  >
    <span class="w-11 h-11 shrink-0 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center dark:bg-amber-500/15 dark:text-amber-300">
      <CircleKindIcon kind="family" class="w-5 h-5" />
    </span>
    <span class="min-w-0 flex-1">
      <span class="block font-semibold text-surface-900">{{ $t('Fiches communes') }}</span>
      <span class="block text-sm text-surface-600">
        {{ $tc(':count proposition de correction attend votre avis.|:count propositions de correction attendent votre avis.', counts.to_decide) }}
      </span>
    </span>
    <span class="text-surface-400" aria-hidden="true">›</span>
  </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import CircleKindIcon from '@/Components/CircleKindIcon.vue';
import { useSharedPeople } from '@/Composables/useSharedPeople';

const { counts } = useSharedPeople();
</script>
