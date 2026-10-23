<template>
  <BaseModal :title="$t('Inviter :name sur MemoryLane', { name: firstName })" @close="$emit('close')">
    <form id="invite-form" class="px-6 py-5 space-y-4" @submit.prevent="submit">
      <p class="text-sm text-surface-600">
        {{ $t(":name recevra un mail qui présente MemoryLane et l'invite à créer son accès (un mot de passe à choisir). Son compte sera relié à cette fiche.", { name: firstName }) }}
      </p>

      <div>
        <label for="invite-email" class="block text-sm font-medium text-surface-700 mb-1">{{ $t('Son adresse e-mail') }}</label>
        <input
          id="invite-email"
          v-model="form.email"
          type="email"
          required
          autocomplete="off"
          :placeholder="$t('prenom@exemple.fr')"
          class="form-input w-full"
        />
        <p v-if="errors.email" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ errors.email }}</p>
        <p v-if="errors.person_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ errors.person_id }}</p>
      </div>

      <div>
        <label for="invite-message" class="block text-sm font-medium text-surface-700 mb-1">
          {{ $t('Un petit mot') }} <span class="font-normal text-surface-400">{{ $t('(facultatif)') }}</span>
        </label>
        <textarea
          id="invite-message"
          v-model="form.message"
          rows="3"
          maxlength="1000"
          :placeholder="$t('Coucou :name, j\'ai pensé à toi…', { name: firstName })"
          class="form-input w-full"
        ></textarea>
      </div>

      <label class="flex items-center gap-2.5 text-sm text-surface-700 cursor-pointer">
        <input v-model="form.formal" type="checkbox" class="h-4 w-4 rounded border-surface-300 text-brand-600 focus:ring-brand-500" />
        {{ $t('Vouvoyer dans le mail') }}
      </label>

      <p v-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn-secondary" @click="$emit('close')">{{ $t('Annuler') }}</button>
      <button type="submit" form="invite-form" class="btn-primary" :disabled="sending || !form.email">
        {{ sending ? $t('Envoi…') : $t('Envoyer l\'invitation') }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import { t } from '@/i18n';

const props = defineProps({
  person: { type: Object, required: true },
});

const emit = defineEmits(['close', 'sent']);

const firstName = computed(() => props.person.first_name || props.person.name.split(' ')[0]);

const form = reactive({ email: '', message: '', formal: false });
const errors = ref({});
const errorMessage = ref(null);
const sending = ref(false);

const submit = async () => {
  sending.value = true;
  errors.value = {};
  errorMessage.value = null;
  try {
    await axios.post(`/people/${props.person.id}/invite`, form);
    emit('sent');
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = Object.fromEntries(
        Object.entries(error.response.data.errors || {}).map(([k, v]) => [k, v[0]])
      );
    } else {
      errorMessage.value = error.response?.data?.message || t("L'invitation n'a pas pu être envoyée. Réessayez plus tard.");
    }
  } finally {
    sending.value = false;
  }
};
</script>
