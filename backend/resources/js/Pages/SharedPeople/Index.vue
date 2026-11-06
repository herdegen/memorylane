<template>
  <Head :title="$t('Fiches communes')" />
  <AppLayout>
    <div class="py-8 sm:py-12">
      <div class="max-w-3xl mx-auto px-4 sm:px-6">
        <h1 class="text-display text-3xl sm:text-4xl text-surface-900">{{ $t('Fiches communes') }}</h1>
        <p class="mt-2 text-surface-600">
          {{ $t('Les fiches que votre famille partage avec d’autres familles. La famille référente d’une fiche (la plus proche de la personne) la corrige directement ; les autres complètent ce qui manque et proposent leurs corrections.') }}
        </p>
        <div v-if="families.length" class="mt-3 flex flex-wrap gap-2">
          <span
            v-for="f in families"
            :key="f.name"
            class="inline-flex items-center gap-1.5 h-8 px-3 rounded-full text-xs font-semibold border border-amber-300 bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200"
          >
            <CircleKindIcon kind="family" class="w-3.5 h-3.5" />
            {{ $tc(':count fiche avec :family|:count fiches avec :family', f.count, { family: f.name }) }}
          </span>
        </div>

        <section v-if="toDecide.length" class="mt-8">
          <h2 class="text-lg font-semibold text-surface-900 mb-3">
            {{ $t('À examiner') }}
            <span class="ml-1 inline-flex items-center justify-center min-w-6 h-6 px-1.5 rounded-full bg-brand-600 text-white text-xs font-bold align-middle">{{ toDecide.length }}</span>
          </h2>
          <div class="space-y-3">
            <SharedProposalCard v-for="p in toDecide" :key="p.id" :proposal="p" @changed="reload" />
          </div>
        </section>

        <section v-if="news.length" class="mt-8">
          <h2 class="text-lg font-semibold text-surface-900">{{ $t('Changé par les autres familles') }}</h2>
          <p class="text-sm text-surface-500 mb-3">{{ $t('Si quelque chose vous semble faux, dites-le : l’ancienne valeur revient le temps d’en discuter.') }}</p>
          <ul class="bg-white rounded-2xl border border-surface-200 shadow-warm-sm divide-y divide-surface-100">
            <li v-for="c in news" :key="c.id" class="p-4">
              <div class="flex items-start gap-3">
                <Link :href="`/people/${c.person.id}`" class="shrink-0">
                  <PersonAvatar :name="c.person.name" :src="c.person.avatar" :size="9" />
                </Link>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-x-2">
                    <Link :href="`/people/${c.person.id}`" class="font-semibold text-surface-900 hover:underline">{{ c.person.name }}</Link>
                    <span v-if="c.is_new" class="rounded-full bg-brand-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ $t('Nouveau') }}</span>
                    <span v-if="c.reverted" class="rounded-full bg-surface-100 px-2 py-0.5 text-[11px] font-semibold text-surface-600">{{ $t('Contesté') }}</span>
                  </div>
                  <p class="text-xs text-surface-500">
                    {{ c.family ? $t(':name (:family)', { name: c.by || $t('Quelqu’un'), family: c.family }) : (c.by || $t('Quelqu’un')) }} · {{ formatDate(c.at) }}
                  </p>
                  <ul class="mt-1.5 space-y-1 text-sm">
                    <li v-for="(item, i) in c.items" :key="i" class="flex flex-wrap items-baseline gap-x-2">
                      <span class="font-medium text-surface-700">{{ item.label }} :</span>
                      <span v-if="item.old" class="text-surface-500 line-through decoration-surface-400">{{ item.old }}</span>
                      <span v-if="item.old" aria-hidden="true" class="text-surface-400">→</span>
                      <span class="font-semibold text-surface-900">{{ item.new ?? $t('(effacé)') }}</span>
                    </li>
                  </ul>

                  <div v-if="c.can_dispute" class="mt-2">
                    <button v-if="disputing !== c.id" type="button" class="text-sm font-semibold text-brand-700 dark:text-brand-300 hover:underline" @click="startDispute(c)">
                      {{ $t('Je ne suis pas d’accord') }}
                    </button>
                    <form v-else class="space-y-2" @submit.prevent="dispute(c)">
                      <textarea
                        v-model="disputeMessage"
                        rows="2"
                        maxlength="2000"
                        required
                        class="w-full px-3 py-2 text-sm border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                        :placeholder="$t('Pourquoi ? Une source (acte, livret de famille, souvenir…) aide à trancher.')"
                        :aria-label="$t('Pourquoi ?')"
                      />
                      <div class="flex flex-wrap gap-2">
                        <button type="submit" class="btn-primary h-11" :disabled="busy || !disputeMessage.trim()">{{ $t('Remettre l’ancienne valeur et en discuter') }}</button>
                        <button type="button" class="btn-secondary h-11" @click="disputing = null">{{ $t('Annuler') }}</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
            </li>
          </ul>
        </section>

        <section v-if="mine.length" class="mt-8">
          <h2 class="text-lg font-semibold text-surface-900 mb-3">{{ $t('Vos propositions') }}</h2>
          <div class="space-y-3">
            <SharedProposalCard v-for="p in mine" :key="p.id" :proposal="p" @changed="reload" />
          </div>
        </section>

        <section v-if="others.length" class="mt-8">
          <h2 class="text-lg font-semibold text-surface-900">{{ $t('En attente de l’autre famille') }}</h2>
          <p class="text-sm text-surface-500 mb-3">{{ $t('Propositions de votre famille, que l’autre famille doit examiner.') }}</p>
          <div class="space-y-3">
            <SharedProposalCard v-for="p in others" :key="p.id" :proposal="p" @changed="reload" />
          </div>
        </section>

        <div v-if="!toDecide.length && !news.length && !mine.length && !others.length" class="mt-10 rounded-2xl border border-dashed border-surface-300 p-8 text-center text-surface-600">
          {{ families.length ? $t('Rien à examiner pour le moment.') : $t('Votre famille ne partage encore aucune fiche avec une autre famille.') }}
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CircleKindIcon from '@/Components/CircleKindIcon.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import SharedProposalCard from '@/Components/SharedProposalCard.vue';
import { useToast } from '@/Composables/useToast';
import { apiError } from '@/utils/apiError';
import { formatDate } from '@/utils/format';
import { t } from '@/i18n';

defineProps({
  toDecide: { type: Array, default: () => [] },
  mine: { type: Array, default: () => [] },
  others: { type: Array, default: () => [] },
  news: { type: Array, default: () => [] },
  families: { type: Array, default: () => [] },
});

const toast = useToast();
const busy = ref(false);
const disputing = ref(null);
const disputeMessage = ref('');

const reload = () => router.reload({ preserveScroll: true });

const startDispute = (change) => {
  disputing.value = change.id;
  disputeMessage.value = '';
};

const dispute = async (change) => {
  busy.value = true;
  try {
    const { data } = await axios.post(`/shared-people/changes/${change.id}/dispute`, { message: disputeMessage.value.trim() });
    toast.success(data.message);
    disputing.value = null;
    reload();
  } catch (error) {
    toast.error(apiError(error, t('Erreur lors de l\'enregistrement.')));
  } finally {
    busy.value = false;
  }
};
</script>
