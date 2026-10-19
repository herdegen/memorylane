<template>
  <!-- « Télécharger mes photos » : archives préparées en tâche de fond,
       liens envoyés par mail (valables 7 jours). -->
  <div class="flex flex-wrap items-center gap-3">
    <button type="button" class="btn-secondary" :disabled="sending || sent" @click="request">
      {{ sent ? 'Demande envoyée' : (sending ? 'Envoi…' : 'Télécharger mes photos') }}
    </button>
    <p v-if="message" class="text-sm text-surface-600">{{ message }}</p>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';

const sending = ref(false);
const sent = ref(false);
const message = ref('');

const request = async () => {
  sending.value = true;
  try {
    const { data } = await axios.post('/account/export');
    message.value = data.message;
    sent.value = true;
  } catch (e) {
    message.value = e.response?.data?.message || 'Impossible de lancer l\'export pour le moment.';
    sent.value = e.response?.status === 429;
  } finally {
    sending.value = false;
  }
};
</script>
