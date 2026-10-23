<template>
  <!-- « En faire un moment » : 1) pour qui (personnes de la photo d'abord,
       ou une recherche dans les fiches du cercle), 2) le formulaire du
       moment pré-rempli (date de prise de vue, position, photos). -->
  <component
    :is="mobile ? BottomSheet : BaseModal"
    v-if="mobile || (open && step === 'person')"
    v-bind="mobile ? { open: open && step === 'person', title: $t('Un moment pour qui ?') } : { title: $t('Un moment pour qui ?'), maxWidth: 'md' }"
    @close="$emit('close')"
  >
    <div class="flex flex-col gap-3" :class="mobile ? '' : 'p-6'">
      <div v-if="photos.length" class="flex gap-1.5 overflow-x-auto">
        <img
          v-for="p in photos.slice(0, 8)"
          :key="p.id"
          :src="p.thumbnail_url"
          alt=""
          class="w-12 h-12 rounded-lg object-cover shrink-0"
        />
        <span v-if="photos.length > 8" class="w-12 h-12 rounded-lg bg-surface-200 text-surface-700 text-xs font-bold flex items-center justify-center shrink-0">+{{ photos.length - 8 }}</span>
      </div>
      <p class="text-sm text-surface-600">
        {{ photos.length > 1 ? $t('Le moment rejoindra la frise de la personne choisie, avec ces photos.') : $t('Le moment rejoindra la frise de la personne choisie, avec cette photo.') }}
      </p>

      <div v-if="people.length" class="flex flex-col gap-1.5">
        <span class="text-xs font-bold uppercase tracking-wider text-surface-500">{{ photos.length > 1 ? $t('Sur ces photos') : $t('Sur la photo') }}</span>
        <button
          v-for="p in people"
          :key="p.id"
          type="button"
          class="flex items-center gap-3 h-12 px-2 rounded-xl border border-surface-200 bg-white text-left hover:border-brand-300"
          @click="choose(p)"
        >
          <PersonAvatar :person="p" :size="9" class="shrink-0" />
          <span class="flex-1 min-w-0 text-[15px] font-semibold text-surface-900 truncate">{{ p.name }}</span>
          <span class="text-surface-400">›</span>
        </button>
      </div>

      <div class="flex flex-col gap-1.5">
        <span class="text-xs font-bold uppercase tracking-wider text-surface-500">{{ people.length ? $t('Ou quelqu\'un d\'autre') : $t('Chercher une personne') }}</span>
        <input v-model="q" type="search" class="form-input" :placeholder="$t('Prénom, nom…')" autocomplete="off" />
        <div v-if="q.trim()" class="flex flex-col gap-1 max-h-60 overflow-y-auto">
          <button
            v-for="p in results"
            :key="p.id"
            type="button"
            class="flex items-center gap-3 h-11 px-2 rounded-lg text-left hover:bg-surface-50"
            @click="choose(p)"
          >
            <PersonAvatar :person="p" :size="8" class="shrink-0" />
            <span class="flex-1 min-w-0 text-sm font-medium text-surface-900 truncate">{{ p.name }}</span>
          </button>
          <p v-if="!results.length" class="px-2 py-2 text-sm text-surface-500">{{ $t('Aucune fiche ne correspond.') }}</p>
        </div>
      </div>
    </div>
  </component>

  <LifeEventFormModal
    v-if="open && step === 'form' && person"
    :person-id="person.id"
    :person-name="person.name"
    :prefill="formPrefill"
    @close="$emit('close')"
    @saved="onSaved"
  />
</template>

<script setup>
import { ref, computed, watch, defineAsyncComponent } from 'vue';
import BaseModal from '@/Components/BaseModal.vue';
import BottomSheet from '@/Components/BottomSheet.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import { usePeopleDirectory } from '@/Composables/usePeopleDirectory';
import { searchPeople } from '@/utils/personSearch';
import { useToast } from '@/Composables/useToast';
import { t } from '@/i18n';

const LifeEventFormModal = defineAsyncComponent(() => import('@/Components/LifeEventFormModal.vue'));

const props = defineProps({
  open: { type: Boolean, default: false },
  // [{ id, thumbnail_url, taken_at }]
  photos: { type: Array, default: () => [] },
  // Personnes proposées en premier (identifiées sur la photo) : [{ id, name }]
  people: { type: Array, default: () => [] },
  // { title, latitude, longitude }
  prefill: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'saved']);

const mobile = typeof window !== 'undefined' && window.innerWidth < 640;
const toast = useToast();
const directory = usePeopleDirectory();

const step = ref('person');
const person = ref(null);
const q = ref('');
const everyone = ref([]);

watch(() => props.open, async (v) => {
  if (!v) return;
  step.value = 'person';
  person.value = null;
  q.value = '';
  if (!everyone.value.length) {
    try {
      // Fiches du cercle courant seulement (pas les « ailleurs »).
      everyone.value = (await directory.load()).filter((p) => !p.elsewhere);
    } catch {
      everyone.value = [];
    }
  }
}, { immediate: true });

const results = computed(() => searchPeople(q.value, everyone.value).slice(0, 20));

const choose = (p) => {
  person.value = p;
  step.value = 'form';
};

// Date : la plus ancienne prise de vue des photos.
const formPrefill = computed(() => {
  const dates = props.photos.map((p) => p.taken_at).filter(Boolean).map((d) => String(d).slice(0, 10)).sort();
  return {
    title: props.prefill.title || '',
    event_date: dates[0] || '',
    // Les métadonnées arrivent parfois en texte (décimaux) : le formulaire veut des nombres.
    latitude: props.prefill.latitude != null && props.prefill.longitude != null ? Number(props.prefill.latitude) : null,
    longitude: props.prefill.latitude != null && props.prefill.longitude != null ? Number(props.prefill.longitude) : null,
    photos: props.photos.map((p) => ({ id: p.id, thumbnail_url: p.thumbnail_url })),
  };
});

const onSaved = () => {
  toast.success(t('Moment ajouté à la frise de :name.', { name: person.value.name.split(/\s+/)[0] }));
  emit('saved', person.value);
  emit('close');
};
</script>
