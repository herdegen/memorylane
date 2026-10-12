<template>
  <BaseModal v-if="state.open" :title="`Ajouter ${state.person?.name} ?`" max-width="md" @close="close('cancel')">
    <div class="px-6 py-5 space-y-3 text-sm text-surface-700">
      <p>
        <strong>{{ state.person?.name }}</strong> a déjà une fiche
        (<span class="font-semibold">{{ state.person?.elsewhere_label }}</span>).
      </p>
      <p v-if="state.circle">
        L'ajouter à <strong>{{ state.circle.name }}</strong> la rend visible de tous ses membres
        (sa fiche, ses dates, ses photos visibles). Sinon, vous pouvez l'utiliser ici sans l'ajouter :
        les membres verront seulement son nom.
      </p>
    </div>
    <template #footer>
      <button type="button" class="btn-secondary" :disabled="state.busy" @click="close('cancel')">Annuler</button>
      <button type="button" class="btn-secondary" :disabled="state.busy" @click="close('only')">Juste l'utiliser ici</button>
      <button v-if="state.circle" type="button" class="btn-primary" :disabled="state.busy" @click="add">
        Ajouter à {{ state.circle.name }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import BaseModal from '@/Components/BaseModal.vue';
import { useBringIn } from '@/Composables/useBringIn';

const { state, add, close } = useBringIn();
</script>
