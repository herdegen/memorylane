<template>
  <div class="inline-flex items-center gap-1 text-sm" role="group" :aria-label="$t('Langue')">
    <button
      v-for="(label, code) in available"
      :key="code"
      type="button"
      class="rounded-md px-2 py-1 transition"
      :class="code === current
        ? 'font-semibold text-surface-900 bg-surface-100'
        : 'text-surface-500 hover:text-surface-800 hover:bg-surface-50'"
      :aria-pressed="code === current"
      :lang="code"
      :disabled="busy"
      @click="choose(code)"
    >
      {{ label }}
    </button>
  </div>
</template>

<script setup>
// Bascule de langue compacte (page de connexion, invitation) : mémorisée
// dans la session tant qu'on n'est pas connecté, puis rechargement.
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { setLocale } from '@/utils/locale';

const page = usePage();
const available = computed(() => page.props.i18n?.available ?? { fr: 'Français', en: 'English' });
const current = computed(() => page.props.i18n?.locale ?? 'fr');
const busy = ref(false);

const choose = async (code) => {
  if (code === current.value || busy.value) return;
  busy.value = true;
  await setLocale(code);
};
</script>
