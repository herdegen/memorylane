<template>
  <BaseModal title="Qui était là ?" max-width="lg" @close="$emit('close')">
    <div class="px-6 py-5 space-y-4">
      <p class="text-sm text-surface-600">
        Indiquez les personnes présentes sur « {{ albumName }} ». Sur ces photos, MemoryLane ne proposera
        qu'elles, et rangera en « personne inconnue » les visages qui ne ressemblent à aucune
        (passants, foules devant un monument). Rien n'est nommé automatiquement, et c'est réversible.
      </p>

      <div v-if="loading" class="text-sm text-surface-500">Chargement…</div>
      <template v-else>
        <div class="flex flex-wrap gap-2">
          <span
            v-for="p in selected"
            :key="p.id"
            class="inline-flex items-center gap-1.5 h-8 pl-3 pr-1.5 rounded-full bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200 text-sm font-semibold"
          >
            {{ p.name }}
            <button
              v-if="canEdit"
              type="button"
              class="w-5 h-5 rounded-full hover:bg-black/10"
              :aria-label="`Retirer ${p.name}`"
              @click="remove(p)"
            >×</button>
          </span>
          <span v-if="!selected.length" class="text-sm text-surface-500">Personne pour l'instant.</span>
        </div>

        <RelationshipPicker
          v-if="canEdit"
          label="Ajouter une personne"
          :current-person="null"
          :exclude-ids="selected.map((p) => p.id)"
          @select="add"
        />

        <div v-if="result" class="rounded-xl bg-surface-100 px-4 py-3 text-sm text-surface-700 space-y-1">
          <p>
            <strong>{{ result.auto_rejected }}</strong> visage{{ result.auto_rejected > 1 ? 's' : '' }} rangé{{ result.auto_rejected > 1 ? 's' : '' }} en « inconnu »
            <template v-if="result.to_identify !== undefined">
              · <strong>{{ result.to_identify }}</strong> encore à identifier (ressemblent peut-être à un participant).
            </template>
          </p>
          <p v-if="result.missing_refs?.length" class="text-amber-700 dark:text-amber-300">
            Aucun visage encore identifié pour {{ result.missing_refs.join(', ') }} : tant que ce n'est pas le cas,
            rien n'est rangé automatiquement (impossible d'affirmer qu'un visage n'est pas eux).
            Identifiez-les sur une photo, puis réenregistrez.
          </p>
        </div>
      </template>
    </div>

    <template #footer>
      <button type="button" class="btn-secondary" @click="$emit('close')">Fermer</button>
      <button v-if="canEdit" type="button" class="btn-primary" :disabled="saving || loading" @click="save">
        {{ saving ? 'Tri en cours…' : 'Enregistrer et trier les visages' }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import RelationshipPicker from '@/Components/RelationshipPicker.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
  albumId: { type: String, required: true },
  albumName: { type: String, default: '' },
});
const emit = defineEmits(['close', 'saved']);

const toast = useToast();
const loading = ref(true);
const saving = ref(false);
const canEdit = ref(false);
const selected = ref([]);
const result = ref(null);

onMounted(async () => {
  try {
    const { data } = await axios.get(`/albums/${props.albumId}/participants`);
    selected.value = data.participants;
    canEdit.value = data.can_edit;
    if (data.auto_rejected) result.value = { auto_rejected: data.auto_rejected };
  } finally {
    loading.value = false;
  }
});

const add = (person) => {
  if (!selected.value.some((p) => p.id === person.id)) selected.value.push({ id: person.id, name: person.name });
};
const remove = (person) => {
  selected.value = selected.value.filter((p) => p.id !== person.id);
};

async function save() {
  saving.value = true;
  try {
    const { data } = await axios.put(`/albums/${props.albumId}/participants`, {
      person_ids: selected.value.map((p) => p.id),
    });
    selected.value = data.participants;
    result.value = data;
    emit('saved');
  } catch (e) {
    toast.error(e.response?.data?.message || "Impossible d'enregistrer.");
  } finally {
    saving.value = false;
  }
}
</script>
