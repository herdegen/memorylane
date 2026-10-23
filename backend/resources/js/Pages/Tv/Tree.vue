<template>
  <!-- Arbre « à la télécommande » : une personne au centre, ses parents
       au-dessus, ses frères et sœurs à gauche, son ou ses conjoints à
       droite, ses enfants en dessous. OK sur quelqu'un : il passe au centre.
       OK sur la personne du centre : sa fiche. Retour : la personne d'avant. -->
  <Head :title="$t('Arbre — Télévision')" />
  <TvLayout
    :tab="tab" :circle="circle" :circles="circles" :account="account"
    back="/tv/ecran/accueil"
    :hints="[['◀ ▲ ▼ ▶', $t('Parents, enfants, frères et sœurs, conjoint')], ['OK', current && focusIsActive ? $t('Sa fiche') : $t('Au centre')], ['↩', trail.length ? $t('Personne précédente') : $t('Accueil')]]"
  >
    <Transition name="tvtree" mode="out-in">
      <div v-if="current" :key="current.focus.id" class="px-24 pt-4 flex flex-col items-center gap-0">
        <!-- Parents -->
        <div class="flex justify-center gap-6 min-h-[11.5rem]" data-tv-row>
          <TvTreeCard v-for="p in current.parents" :key="p.id" :person="p" @pick="recenter" />
          <p v-if="!current.parents.length" class="self-center text-[1.5rem] text-[#7c7164]">{{ $t('Parents inconnus') }}</p>
        </div>
        <span class="tv-tree-line h-7"></span>

        <!-- Frères et sœurs · la personne · conjoints -->
        <div class="w-full grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-8">
          <div class="flex flex-col items-end gap-2 min-w-0">
            <span v-if="current.siblings.length" class="text-[1.375rem] text-[#a89c8b] pr-3">{{ siblingsLabel }}</span>
            <div class="flex gap-3 overflow-x-auto max-w-full py-3 px-3 tv-tree-rtl" data-tv-row>
              <TvTreeCard v-for="p in current.siblings" :key="p.id" :person="p" class="tv-tree-ltr" @pick="recenter" />
            </div>
          </div>
          <TvTreeCard :person="current.focus" big data-tv-autofocus @pick="openFocus" />
          <div class="flex flex-col items-start gap-2 min-w-0">
            <span v-if="current.spouses.length" class="text-[1.375rem] text-[#a89c8b] pl-3">{{ current.spouses.length > 1 ? $t('Unions') : $t('Union') }}</span>
            <div class="flex gap-3 overflow-x-auto max-w-full py-3 px-3" data-tv-row>
              <TvTreeCard v-for="p in current.spouses" :key="p.id" :person="p" :badge="p.union" @pick="recenter" />
            </div>
          </div>
        </div>

        <span class="tv-tree-line h-7"></span>
        <!-- Enfants -->
        <div class="max-w-full flex flex-col items-center gap-1">
          <span v-if="current.children.length" class="text-[1.375rem] text-[#a89c8b]">{{ $tc(':count enfant|:count enfants', current.children.length) }}</span>
          <div class="flex gap-3 overflow-x-auto max-w-full py-3 px-3 min-h-[11.5rem]" data-tv-row>
            <TvTreeCard v-for="p in current.children" :key="p.id" :person="p" @pick="recenter" />
            <p v-if="!current.children.length" class="self-center text-[1.5rem] text-[#7c7164]">{{ $t('Pas d\'enfant connu') }}</p>
          </div>
        </div>
      </div>
    </Transition>
    <p v-if="error" class="px-24 pt-6 text-[1.75rem] text-[#fca5a5]">{{ error }}</p>
  </TvLayout>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import TvLayout from '@/Layouts/TvLayout.vue';
import TvTreeCard from '@/Components/Tv/TvTreeCard.vue';
import { focusInitial, onTvKey } from '@/Tv/remote';
import { t } from '@/i18n';

const props = defineProps({ tab: String, circle: Object, circles: Array, account: Object, start: Object });

const current = ref(props.start);
// Personnes déjà passées au centre : Retour y revient.
const trail = ref([]);
const error = ref('');
const loading = ref(false);
const focusIsActive = ref(true);

const siblingsLabel = computed(() => {
  const s = current.value?.siblings || [];
  const women = s.filter((p) => p.gender === 'F').length;
  if (s.length === 1) return women ? t('Sa sœur') : t('Son frère');
  return women === s.length ? t('Ses sœurs') : women === 0 ? t('Ses frères') : t('Frères et sœurs');
});

const show = async (personId, { remember = true } = {}) => {
  if (loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const { data } = await axios.get(`/tv/ecran/arbre/${personId}/famille`);
    if (remember && current.value) trail.value.push(current.value.focus.id);
    current.value = data;
    window.history.replaceState(window.history.state, '', `/tv/ecran/arbre/${personId}`);
    await nextTick();
    // Après la transition : focus sur la personne du centre.
    setTimeout(() => focusInitial(), 260);
  } catch (e) {
    error.value = t('Impossible d\'afficher cette personne.');
  } finally {
    loading.value = false;
  }
};

const recenter = (person) => show(person.id);
const openFocus = () => router.visit(`/tv/ecran/proches/${current.value.focus.id}`);

const onFocusIn = (e) => {
  focusIsActive.value = !!e.target?.closest?.('[data-tv-autofocus]');
};

onTvKey((action) => {
  if (action === 'back' && trail.value.length) {
    show(trail.value.pop(), { remember: false });
    return true;
  }
  return false;
});

onMounted(() => document.addEventListener('focusin', onFocusIn));
onBeforeUnmount(() => document.removeEventListener('focusin', onFocusIn));
</script>

<style scoped>
.tv-tree-line { width: 0.25rem; border-radius: 999px; background: #6b5434; flex: none; }
/* Frères et sœurs collés à la personne du centre (défilent vers la gauche). */
.tv-tree-rtl { direction: rtl; }
.tv-tree-ltr { direction: ltr; }
.tvtree-enter-active, .tvtree-leave-active { transition: opacity .22s ease, transform .22s ease; }
.tvtree-enter-from { opacity: 0; transform: scale(.97); }
.tvtree-leave-to { opacity: 0; transform: scale(1.02); }
</style>
