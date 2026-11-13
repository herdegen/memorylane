<template>
  <!-- Choix d'une branche de l'arbre : la personne en haut de la branche,
       avec ou sans les conjoints, et l'aperçu de qui la verra. -->
  <div class="space-y-3">
    <div v-if="person" class="flex items-center gap-3 rounded-xl border border-brand-300 bg-brand-50 dark:bg-brand-500/10 px-3 py-2">
      <PersonAvatar :person="person" :size="9" />
      <span class="flex-1 min-w-0">
        <span class="block text-sm font-semibold text-surface-900 truncate">
          {{ $t('La branche de :name', { name: person.name }) }}<span v-if="person.birth_date" class="font-normal text-surface-500"> ({{ String(person.birth_date).slice(0, 4) }})</span>
        </span>
        <span class="block text-xs text-surface-500">{{ $t('Ses enfants, petits-enfants et tous leurs descendants') }}</span>
      </span>
      <button type="button" class="text-sm text-surface-500 hover:text-surface-800" :aria-label="$t('Changer de personne')" @click="clear">✕</button>
    </div>

    <template v-else>
      <label class="flex items-center gap-2 h-11 px-3.5 rounded-xl border border-surface-300 bg-white focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-100">
        <svg class="w-4 h-4 text-surface-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M21 21l-5-5m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        <input v-model="query" type="search" :placeholder="$t('Qui est en haut de la branche ? (ex. une grand-mère)')" :aria-label="$t('Chercher une personne')" class="w-full border-0 bg-transparent p-0 text-[15px] focus:ring-0" />
      </label>
      <div v-if="query.trim()" class="max-h-60 overflow-y-auto -mx-2">
        <button
          v-for="p in results"
          :key="p.id"
          type="button"
          class="w-full flex items-center gap-3 px-2 py-2 rounded-lg text-left hover:bg-surface-50"
          @click="choose(p)"
        >
          <PersonAvatar :person="p" :size="8" />
          <span class="flex-1 min-w-0 text-sm font-semibold text-surface-900 truncate">{{ p.name }}</span>
          <span v-if="p.birth_date" class="text-xs text-surface-500">{{ String(p.birth_date).slice(0, 4) }}</span>
        </button>
        <p v-if="!results.length" class="px-2 py-4 text-center text-sm text-surface-500">{{ $t('Personne ne correspond.') }}</p>
      </div>
    </template>

    <label class="flex items-start gap-2 text-sm text-surface-700">
      <input v-model="includeSpouses" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-surface-300 text-brand-600 focus:ring-brand-500" />
      <span>{{ $t('Avec leurs conjoints (mari, femme, compagnon)') }}</span>
    </label>

    <p v-if="person && preview" class="text-sm text-surface-600">
      {{ $tc(':count personne dans la branche|:count personnes dans la branche', preview.people) }} ·
      <strong>{{ $tc(':count a un compte et pourra voir|:count ont un compte et pourront voir', preview.accounts) }}</strong>
      <span v-if="preview.names.length" class="block text-xs text-surface-500 mt-0.5">{{ preview.names.join(', ') }}<span v-if="preview.accounts > preview.names.length"> …</span></span>
    </p>
    <p class="text-xs text-surface-500">{{ $t('Partage vivant : un enfant ou un conjoint ajouté plus tard à l’arbre y aura accès automatiquement.') }}</p>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import axios from 'axios';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import { usePeopleDirectory } from '@/Composables/usePeopleDirectory';
import { searchPeople } from '@/utils/personSearch';

const model = defineModel({ type: Object, default: () => ({ person: null, includeSpouses: true }) });

const query = ref('');
const directory = ref([]);
const person = ref(model.value?.person ?? null);
const includeSpouses = ref(model.value?.includeSpouses ?? true);
const preview = ref(null);

onMounted(async () => {
  directory.value = (await usePeopleDirectory().load()).filter((p) => !p.elsewhere);
});

const results = computed(() => searchPeople(query.value, directory.value).slice(0, 20));
const choose = (p) => { person.value = p; query.value = ''; };
const clear = () => { person.value = null; preview.value = null; };

watch([person, includeSpouses], async () => {
  model.value = { person: person.value, includeSpouses: includeSpouses.value };
  if (!person.value) return;
  try {
    const { data } = await axios.get('/branch-shares/preview', { params: { person_id: person.value.id, include_spouses: includeSpouses.value ? 1 : 0 } });
    preview.value = data;
  } catch {
    preview.value = null;
  }
}, { immediate: true });
</script>
