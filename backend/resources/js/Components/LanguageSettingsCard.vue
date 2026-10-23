<template>
  <!-- Langue de l'interface (préférence du membre ; vide = langue du site) -->
  <div class="bg-white overflow-hidden shadow-xs sm:rounded-lg">
    <div class="p-6 space-y-3">
      <h3 class="text-lg font-semibold text-surface-900">{{ $t('Langue') }}</h3>
      <label for="ui-locale" class="block text-sm text-surface-600">
        {{ $t('Langue de l’interface. Vos albums, noms et récits restent tels que vous les avez écrits.') }}
      </label>
      <select
        id="ui-locale"
        v-model="choice"
        class="form-select sm:!w-80"
        :disabled="saving"
        @change="save"
      >
        <option value="">{{ $t('Langue du site (:language)', { language: available[siteDefault] ?? siteDefault }) }}</option>
        <option v-for="(label, code) in available" :key="code" :value="code" :lang="code">{{ label }}</option>
      </select>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { t } from '@/i18n';
import { setLocale } from '@/utils/locale';
import { useToast } from '@/Composables/useToast';

const page = usePage();
const toast = useToast();
const available = computed(() => page.props.i18n?.available ?? {});
const siteDefault = computed(() => page.props.i18n?.default ?? 'fr');
const choice = ref(page.props.i18n?.preference ?? '');
const saving = ref(false);

async function save() {
  saving.value = true;
  try {
    await setLocale(choice.value || null);
  } catch {
    choice.value = page.props.i18n?.preference ?? '';
    toast.error(t('Impossible d\'enregistrer ce réglage.'));
    saving.value = false;
  }
}
</script>
