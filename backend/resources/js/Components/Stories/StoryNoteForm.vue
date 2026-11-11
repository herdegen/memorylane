<template>
  <!-- « Je sais quelque chose » : un ajout signé, lisible par tous les lecteurs. -->
  <form class="flex flex-col gap-2" @submit.prevent="submit">
    <textarea
      :id="`note-${questionId || 'free'}`"
      v-model="form.body"
      rows="3"
      class="form-input"
      :placeholder="$t('Ce que vous savez, d’où vous le tenez…')"
      :aria-label="$t('Votre ajout')"
      required
    ></textarea>
    <p v-if="form.errors.body" class="text-sm text-red-600">{{ form.errors.body }}</p>
    <div class="flex gap-2">
      <button type="submit" class="btn-primary btn-sm" :disabled="form.processing || !form.body.trim()">{{ $t('Ajouter') }}</button>
      <button type="button" class="btn-secondary btn-sm" @click="$emit('done')">{{ $t('Annuler') }}</button>
    </div>
  </form>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
  storyId: { type: String, required: true },
  questionId: { type: String, default: null },
});
const emit = defineEmits(['done']);

const form = useForm({ body: '', question_id: props.questionId });
const submit = () => form.post(`/stories/${props.storyId}/notes`, {
  preserveScroll: true,
  onSuccess: () => { form.reset('body'); emit('done'); },
});
</script>
