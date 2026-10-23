<template>
  <!-- Lettre du dimanche (e-mail hebdomadaire de la famille) -->
  <div class="bg-white overflow-hidden shadow-xs sm:rounded-lg">
    <div class="p-6 space-y-4">
      <h3 class="text-lg font-semibold text-surface-900">{{ $t('E-mails') }}</h3>
      <ToggleSwitch
        v-model="enabled"
        :label="$t('La lettre du dimanche')"
        :help="$t('Chaque dimanche soir : les nouvelles photos, les anniversaires de la semaine, la personne à l\'honneur et une découverte.')"
        :disabled="saving"
        @update:modelValue="save"
      />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import ToggleSwitch from '@/Components/Forms/ToggleSwitch.vue';
import { useToast } from '@/Composables/useToast';
import { t } from '@/i18n';

const props = defineProps({
  initial: { type: Boolean, default: true },
});

const toast = useToast();
const enabled = ref(props.initial);
const saving = ref(false);

async function save(value) {
  saving.value = true;
  try {
    await axios.put('/profile/newsletter', { enabled: value });
    toast.success(value ? t('Vous recevrez la lettre du dimanche.') : t('Vous ne recevrez plus la lettre du dimanche.'));
  } catch {
    enabled.value = !value;
    toast.error(t('Impossible d\'enregistrer ce réglage.'));
  } finally {
    saving.value = false;
  }
}
</script>
