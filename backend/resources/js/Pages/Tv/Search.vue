<template>
  <!-- Recherche : le seul endroit où l'on tape. Le clavier de la télévision
       s'ouvre avec OK sur le champ ; dans l'application, « Dicter » passe par
       la reconnaissance vocale d'Android. -->
  <Head :title="$t('Recherche — Télévision')" />
  <TvLayout :tab="tab" :circle="circle" :circles="circles" :account="account" back="/tv/ecran/accueil"
    :hints="[['OK', $t('Ouvrir le clavier')], ['▼', $t('Aller aux résultats')], ['↩', $t('Retour')]]">
    <div class="px-24 pt-10 pb-4 flex flex-col gap-10">
      <div class="flex items-center gap-6" data-tv-row>
        <label class="tv-search flex-1 flex items-center gap-5 h-[6.5rem] px-9 rounded-full bg-[#211d17]">
          <svg class="w-10 h-10 shrink-0 text-[#c9bfb1]" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" /></svg>
          <input
            ref="input"
            v-model="q"
            type="text"
            data-tv
            data-tv-autofocus
            enterkeyhint="search"
            autocomplete="off"
            :placeholder="$t('Un prénom, un album, une année…')"
            class="flex-1 min-w-0 bg-transparent border-0 outline-none text-[2.75rem] font-semibold text-[#f7f2ea] placeholder:text-[#7c7164]"
            :aria-label="$t('Rechercher')"
          />
        </label>
        <button v-if="canDictate" type="button" data-tv class="tv-btn h-[6.5rem] bg-[#3a2f1e] text-[#fcd34d]" @click="dictate">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0014 0M12 18v3" /></svg>
          {{ listening ? $t('Je vous écoute…') : $t('Dicter') }}
        </button>
      </div>

      <p v-if="message" class="text-[2rem] text-[#c9bfb1]">{{ message }}</p>

      <section v-if="results.people.length" class="flex flex-col gap-3">
        <h2 class="font-display text-[2.25rem] font-bold">{{ $t('Proches') }}</h2>
        <div class="flex gap-4 overflow-x-auto -mx-24 px-[5.25rem] py-5 -my-3" data-tv-row>
          <TvPersonCard v-for="p in results.people" :key="p.id" :person="p" />
        </div>
      </section>
      <section v-if="results.albums.length" class="flex flex-col gap-4">
        <h2 class="font-display text-[2.25rem] font-bold">{{ $t('Albums') }}</h2>
        <div class="flex gap-7 overflow-x-auto -mx-24 px-24 py-6 -my-3" data-tv-row>
          <TvAlbumCard v-for="a in results.albums" :key="a.id" :album="a" :width="19" />
        </div>
      </section>
      <section v-if="results.years.length" class="flex flex-col gap-4">
        <h2 class="font-display text-[2.25rem] font-bold">{{ $t('Année') }}</h2>
        <div class="flex gap-7 py-3" data-tv-row>
          <TvTile v-for="t in results.years" :key="t.key" :tile="t" :width="19" />
        </div>
      </section>
    </div>
  </TvLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import TvLayout from '@/Layouts/TvLayout.vue';
import TvPersonCard from '@/Components/Tv/TvPersonCard.vue';
import TvAlbumCard from '@/Components/Tv/TvAlbumCard.vue';
import TvTile from '@/Components/Tv/TvTile.vue';
import { t } from '@/i18n';

defineProps({ tab: String, circle: Object, circles: Array, account: Object, voice: Boolean });

const input = ref(null);
const q = ref(new URLSearchParams(window.location.search).get('q') || '');
const results = ref({ people: [], albums: [], years: [] });
const loading = ref(false);
const searched = ref('');

const empty = computed(() => !results.value.people.length && !results.value.albums.length && !results.value.years.length);
const message = computed(() => {
  if (q.value.trim().length < 2) return t('Tapez au moins deux lettres : « Mamie », « Bretagne », « 1998 »…');
  if (loading.value && empty.value) return t('Recherche…');
  if (searched.value && empty.value) return t('Rien trouvé pour « :term ».', { term: searched.value });
  return '';
});

let timer = null;
let seq = 0;
const run = async () => {
  const term = q.value.trim();
  if (term.length < 2) {
    results.value = { people: [], albums: [], years: [] };
    searched.value = '';
    return;
  }
  const mine = ++seq;
  loading.value = true;
  try {
    const { data } = await axios.get('/tv/ecran/recherche/resultats', { params: { q: term } });
    if (mine !== seq) return;
    results.value = data;
    searched.value = term;
    // Garder la recherche dans l'adresse : Retour depuis un résultat la retrouve.
    window.history.replaceState(window.history.state, '', `?q=${encodeURIComponent(term)}`);
  } catch (e) {
    /* hors ligne : on garde les résultats précédents */
  } finally {
    if (mine === seq) loading.value = false;
  }
};
watch(q, () => {
  clearTimeout(timer);
  timer = setTimeout(run, 350);
});

// Dictée : fournie par l'application Android (window.MemoryLaneTV).
const canDictate = !!window.MemoryLaneTV?.listen && (!window.MemoryLaneTV.canListen || window.MemoryLaneTV.canListen());
const listening = ref(false);
const dictate = () => {
  listening.value = true;
  window.MemoryLaneTV.listen();
};

onMounted(() => {
  window.mlVoiceResult = (text) => {
    listening.value = false;
    if (text) q.value = text;
  };
  if (q.value) run();
});
onBeforeUnmount(() => {
  clearTimeout(timer);
  delete window.mlVoiceResult;
});
</script>

<style scoped>
.tv-search:focus-within { box-shadow: 0 0 0 0.375rem #12100c, 0 0 0 0.75rem #f7f2ea; }
.tv-root .tv-search input:focus { outline: none; box-shadow: none; }
</style>
