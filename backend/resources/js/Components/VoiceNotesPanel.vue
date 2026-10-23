<template>
  <div class="bg-white rounded-lg shadow-xs p-6 space-y-4">
    <div class="flex items-center justify-between gap-3">
      <h2 class="text-lg font-semibold text-surface-900">{{ $t('Souvenirs racontés') }}</h2>
      <span v-if="notes.length" class="text-sm text-surface-500">{{ notes.length }}</span>
    </div>

    <p v-if="!notes.length && state === 'idle'" class="text-sm text-surface-500">
      {{ $t("Personne n'a encore raconté cette photo. Enregistrez ce que vous en savez : qui, où, quand…") }}
    </p>

    <ul v-if="notes.length" class="space-y-3">
      <li v-for="note in notes" :key="note.id" class="space-y-1.5">
        <div class="flex items-center justify-between gap-2 text-sm">
          <span class="font-medium text-surface-800">{{ note.narrator }}</span>
          <span class="flex items-center gap-2 text-surface-500">
            {{ formatDate(note.created_at) }}
            <button
              v-if="note.can_delete"
              type="button"
              class="text-surface-400 hover:text-red-600 transition"
              :title="$t('Supprimer ce souvenir')"
              :aria-label="$t('Supprimer ce souvenir')"
              @click="remove(note)"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5h6v2m-7 0l1 12h6l1-12" /></svg>
            </button>
          </span>
        </div>
        <audio :src="note.url" controls preload="none" class="w-full h-10" />
      </li>
    </ul>

    <!-- Enregistrer -->
    <div class="pt-1">
      <button
        v-if="state === 'idle'"
        type="button"
        class="btn-secondary btn-sm inline-flex items-center gap-2"
        @click="start"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0 0 14 0M12 18v3" /></svg>
        {{ $t('Raconter cette photo') }}
      </button>

      <div v-else-if="state === 'recording'" class="flex items-center gap-3">
        <span class="w-3 h-3 rounded-full bg-red-600 animate-pulse" />
        <span class="tabular-nums text-surface-800 font-medium">{{ elapsedLabel }}</span>
        <button type="button" class="btn-primary btn-sm" @click="stop">{{ $t('Terminer') }}</button>
      </div>

      <p v-else-if="state === 'recorded' || state === 'sending'" class="text-sm text-surface-500">{{ $t('Envoi…') }}</p>

      <div v-else-if="state === 'error'" class="space-y-2">
        <p class="text-sm text-red-700">{{ error }}</p>
        <button type="button" class="btn-secondary btn-sm" @click="reset">{{ $t('OK') }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import { useVoiceRecorder } from '@/Composables/useVoiceRecorder';
import { useToast } from '@/Composables/useToast';
import { useConfirm } from '@/Composables/useConfirm';
import { formatDate } from '@/utils/format';
import { t } from '@/i18n';

const props = defineProps({
  mediaId: { type: String, required: true },
  initialNotes: { type: Array, default: () => [] },
});

const toast = useToast();
const { confirm } = useConfirm();
const notes = ref([...props.initialNotes]);
const { state, elapsedLabel, error, blob, start, stop, send, reset } = useVoiceRecorder();

watch(state, async (s) => {
  if (s === 'recorded' && blob.value) {
    const note = await send(props.mediaId);
    if (note) {
      notes.value.push(note);
      toast.success(t('Souvenir enregistré.'));
      reset();
    }
  }
});

async function remove(note) {
  const ok = await confirm({
    title: t('Supprimer ce souvenir'),
    message: t('Supprimer le souvenir raconté par :name ?', { name: note.narrator }),
    confirmLabel: t('Supprimer'),
    danger: true,
  });
  if (!ok) return;
  try {
    await axios.delete(`/voice-notes/${note.id}`);
    notes.value = notes.value.filter((n) => n.id !== note.id);
  } catch {
    toast.error(t('Suppression impossible.'));
  }
}
</script>
