<template>
  <BaseModal v-if="state.open" :title="$t('Ajouter :name ?', { name: state.person?.name })" max-width="md" @close="close('cancel')">
    <div class="px-6 py-5 space-y-3 text-sm text-surface-700">
      <p>
        <Trans text=":name a déjà une fiche (:where).">
          <template #name><strong>{{ state.person?.name }}</strong></template>
          <template #where><span class="font-semibold">{{ state.person?.elsewhere_label }}</span></template>
        </Trans>
      </p>
      <p v-if="state.circle">
        <Trans text="L'ajouter à :circle la rend visible de tous ses membres (sa fiche, ses dates, ses photos visibles). Sinon, vous pouvez l'utiliser ici sans l'ajouter : les membres verront seulement son nom.">
          <template #circle><strong>{{ state.circle.name }}</strong></template>
        </Trans>
      </p>
    </div>
    <template #footer>
      <button type="button" class="btn-secondary" :disabled="state.busy" @click="close('cancel')">{{ $t('Annuler') }}</button>
      <button type="button" class="btn-secondary" :disabled="state.busy" @click="close('only')">{{ $t("Juste l'utiliser ici") }}</button>
      <button v-if="state.circle" type="button" class="btn-primary" :disabled="state.busy" @click="add">
        {{ $t('Ajouter à :name', { name: state.circle.name }) }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import BaseModal from '@/Components/BaseModal.vue';
import Trans from '@/Components/Trans.vue';
import { useBringIn } from '@/Composables/useBringIn';

const { state, add, close } = useBringIn();
</script>
