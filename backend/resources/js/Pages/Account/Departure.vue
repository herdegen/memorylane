<template>
  <Head :title="$t('Quitter MemoryLane')" />
  <AppLayout>
    <div class="page-container max-w-2xl mx-auto px-4 sm:px-6">
      <div class="page-header">
        <h1>{{ $t('Quitter MemoryLane') }}</h1>
        <p>{{ $t("Votre compte s'en va, pas votre place dans l'histoire : votre fiche reste dans l'arbre.") }}</p>
      </div>

      <!-- Départ déjà programmé : annulable jusqu'à la date d'effet -->
      <div v-if="pending" class="rounded-2xl border border-amber-300 bg-amber-50 dark:bg-amber-500/10 dark:border-amber-500/40 p-5 space-y-3">
        <p class="text-surface-900 font-semibold">{{ $t('Votre départ est programmé pour le :date.', { date: formatLongDate(pending.effective_at) }) }}</p>
        <p class="text-sm text-surface-700">{{ $t("D'ici là, rien n'a changé : vous pouvez continuer à utiliser MemoryLane, et annuler à tout moment.") }}</p>
        <button type="button" class="btn-primary" :disabled="form.processing" @click="cancel">{{ $t('Annuler mon départ') }}</button>
      </div>

      <template v-else>
        <!-- Ce que vous laissez -->
        <section class="bg-white rounded-2xl border border-surface-200 p-5 mb-5">
          <h2 class="text-base font-semibold text-surface-900 mb-3">{{ $t('Ce que vous avez apporté') }}</h2>
          <ul class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
            <li v-for="item in counts" :key="item.label" class="rounded-xl bg-surface-50 px-3 py-2">
              <span class="block text-xl font-bold text-surface-900">{{ item.value }}</span>
              <span class="text-surface-600">{{ item.label }}</span>
            </li>
          </ul>
          <p class="mt-3 text-xs text-surface-500">
            {{ $t("Les fiches que vous avez créées et les moments de la frise restent toujours : ils appartiennent à l'arbre. Ils passeront à un administrateur.") }}
          </p>
        </section>

        <!-- Avant de partir : récupérer ses originaux -->
        <section v-if="summary.photos" class="bg-white rounded-2xl border border-surface-200 p-5 mb-5 space-y-3">
          <h2 class="text-base font-semibold text-surface-900">{{ $t('Avant de partir : gardez vos photos') }}</h2>
          <p class="text-sm text-surface-600">{{ $t('Tous vos originaux, rangés par année, en archives ZIP envoyées par mail (liens valables 7 jours).') }}</p>
          <ExportMediaButton />
        </section>

        <div v-if="summary.is_last_admin" class="rounded-xl bg-red-50 dark:bg-red-500/10 text-red-800 dark:text-red-300 px-4 py-3 mb-5 text-sm">
          {{ $t('Vous êtes le dernier administrateur : nommez-en un autre (Administration → Utilisateurs) avant de partir.') }}
        </div>

        <form v-else class="space-y-5" @submit.prevent="submit">
          <!-- Photos -->
          <fieldset class="bg-white rounded-2xl border border-surface-200 p-5 space-y-2">
            <legend class="text-base font-semibold text-surface-900 mb-2">{{ $t('Vos photos et albums') }}</legend>
            <label v-for="opt in photoOptions" :key="opt.value" class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer" :class="form.photos === opt.value ? 'border-brand-400 bg-brand-50 dark:bg-brand-500/10' : 'border-surface-200'">
              <input v-model="form.photos" type="radio" :value="opt.value" class="mt-1" />
              <span class="text-sm"><strong class="block text-surface-900">{{ opt.title }}</strong><span class="text-surface-500">{{ opt.text }}</span></span>
            </label>
          </fieldset>

          <!-- Souvenirs racontés -->
          <fieldset v-if="summary.voice_notes" class="bg-white rounded-2xl border border-surface-200 p-5 space-y-2">
            <legend class="text-base font-semibold text-surface-900 mb-2">{{ $tc('Votre souvenir raconté (votre voix)|Vos :count souvenirs racontés (votre voix)', summary.voice_notes) }}</legend>
            <label class="flex items-center gap-3 text-sm"><input v-model="form.voice_notes" type="radio" value="keep" /> {{ $t('Les laisser à la famille') }}</label>
            <label class="flex items-center gap-3 text-sm"><input v-model="form.voice_notes" type="radio" value="remove" /> {{ $t('Les retirer') }}</label>
          </fieldset>

          <!-- Identifications -->
          <fieldset v-if="summary.tagged_photos" class="bg-white rounded-2xl border border-surface-200 p-5">
            <legend class="text-base font-semibold text-surface-900 mb-2">{{ $t('Votre nom sur les photos des autres') }}</legend>
            <label class="flex items-start gap-3 text-sm">
              <input v-model="form.untag_me" type="checkbox" class="mt-1" />
              <span>{{ $tc('Retirer mon nom de la photo où je suis identifié·e|Retirer mon nom des :count photos où je suis identifié·e', summary.tagged_photos) }} <span class="text-surface-500">{{ $t('(les photos restent, seul votre nom disparaît)') }}</span></span>
            </label>
          </fieldset>

          <!-- Groupes créés : qui les reprend -->
          <fieldset v-if="summary.created_circles.length" class="bg-white rounded-2xl border border-surface-200 p-5 space-y-3">
            <legend class="text-base font-semibold text-surface-900 mb-1">{{ $t('Les groupes que vous avez créés') }}</legend>
            <div v-for="c in summary.created_circles" :key="c.id">
              <label class="block text-sm font-medium text-surface-800 mb-1">{{ $t('« :name »', { name: c.name }) }}</label>
              <select v-if="c.candidates.length" v-model="form.successors[c.id]" class="form-input">
                <option v-for="m in c.candidates" :key="m.id" :value="m.id">{{ $t('Repris par :name', { name: m.name }) }}</option>
              </select>
              <p v-else class="text-sm text-surface-500">{{ $t('Vous êtes le seul membre : ce groupe sera fermé.') }}</p>
            </div>
          </fieldset>

          <!-- Confirmation -->
          <section class="bg-white rounded-2xl border border-red-200 dark:border-red-500/30 p-5 space-y-3">
            <p class="text-sm text-surface-700">
              <Trans text="Votre départ prendra effet dans :days. D'ici là rien ne change et vous pouvez annuler.">
                <template #days><strong>{{ $tc(':count jour|:count jours', graceDays) }}</strong></template>
              </Trans>
            </p>
            <label class="block text-sm font-medium text-surface-800">
              {{ $t('Pour confirmer, tapez votre prénom « :name »', { name: firstName }) }}
              <input v-model="form.confirm" type="text" class="form-input mt-1" autocomplete="off" />
            </label>
            <p v-if="form.errors.confirm || form.errors.account" class="text-sm text-red-600 dark:text-red-400">{{ form.errors.confirm || form.errors.account }}</p>
            <button
              type="submit"
              class="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-50"
              :disabled="form.processing || !form.confirm.trim()"
            >
              {{ $t('Programmer mon départ') }}
            </button>
          </section>
        </form>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatLongDate } from '@/utils/format';
import ExportMediaButton from '@/Components/ExportMediaButton.vue';
import Trans from '@/Components/Trans.vue';
import { t } from '@/i18n';

const props = defineProps({
  summary: { type: Object, required: true },
  pending: { type: Object, default: null },
  graceDays: { type: Number, default: 30 },
});

const firstName = computed(() => (usePage().props.auth?.user?.name || '').trim().split(' ')[0]);

const counts = computed(() => [
  { label: t('photos et vidéos'), value: props.summary.photos },
  { label: t('albums'), value: props.summary.albums },
  { label: t('fiches créées'), value: props.summary.fiches },
  { label: t('moments'), value: props.summary.moments },
  { label: t('souvenirs racontés'), value: props.summary.voice_notes },
  { label: t('photos où vous apparaissez'), value: props.summary.tagged_photos },
]);

const photoOptions = computed(() => [
  {
    value: 'keep_shared',
    title: t('Laisser ce que j\'ai partagé, retirer le reste (recommandé)'),
    text: t(':shared photo(s) partagée(s) restent à la famille et passent à un administrateur ; :private photo(s) privée(s) sont supprimées.', { shared: props.summary.shared_photos, private: props.summary.private_photos }),
  },
  { value: 'keep_all', title: t('Tout laisser'), text: t('Toutes vos photos et albums passent à un administrateur.') },
  { value: 'remove_all', title: t('Tout retirer'), text: t('Toutes vos photos sont supprimées, y compris celles partagées.') },
]);

const form = useForm({
  photos: 'keep_shared',
  voice_notes: 'keep',
  untag_me: false,
  successors: Object.fromEntries(props.summary.created_circles.filter((c) => c.candidates.length).map((c) => [c.id, c.candidates[0].id])),
  confirm: '',
});

const submit = () => form.post('/account/departure', { preserveScroll: true });
const cancel = () => router.delete('/account/departure');
</script>
