<template>
  <Head title="Quitter MemoryLane" />
  <AppLayout>
    <div class="page-container max-w-2xl mx-auto px-4 sm:px-6">
      <div class="page-header">
        <h1>Quitter MemoryLane</h1>
        <p>Votre compte s'en va, pas votre place dans l'histoire : votre fiche reste dans l'arbre.</p>
      </div>

      <!-- Départ déjà programmé : annulable jusqu'à la date d'effet -->
      <div v-if="pending" class="rounded-2xl border border-amber-300 bg-amber-50 dark:bg-amber-500/10 dark:border-amber-500/40 p-5 space-y-3">
        <p class="text-surface-900 font-semibold">Votre départ est programmé pour le {{ formatLongDate(pending.effective_at) }}.</p>
        <p class="text-sm text-surface-700">D'ici là, rien n'a changé : vous pouvez continuer à utiliser MemoryLane, et annuler à tout moment.</p>
        <button type="button" class="btn-primary" :disabled="form.processing" @click="cancel">Annuler mon départ</button>
      </div>

      <template v-else>
        <!-- Ce que vous laissez -->
        <section class="bg-white rounded-2xl border border-surface-200 p-5 mb-5">
          <h2 class="text-base font-semibold text-surface-900 mb-3">Ce que vous avez apporté</h2>
          <ul class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
            <li v-for="item in counts" :key="item.label" class="rounded-xl bg-surface-50 px-3 py-2">
              <span class="block text-xl font-bold text-surface-900">{{ item.value }}</span>
              <span class="text-surface-600">{{ item.label }}</span>
            </li>
          </ul>
          <p class="mt-3 text-xs text-surface-500">
            Les fiches que vous avez créées et les moments de la frise restent toujours : ils appartiennent à l'arbre. Ils passeront à un administrateur.
          </p>
        </section>

        <!-- Avant de partir : récupérer ses originaux -->
        <section v-if="summary.photos" class="bg-white rounded-2xl border border-surface-200 p-5 mb-5 space-y-3">
          <h2 class="text-base font-semibold text-surface-900">Avant de partir : gardez vos photos</h2>
          <p class="text-sm text-surface-600">Tous vos originaux, rangés par année, en archives ZIP envoyées par mail (liens valables 7 jours).</p>
          <ExportMediaButton />
        </section>

        <div v-if="summary.is_last_admin" class="rounded-xl bg-red-50 dark:bg-red-500/10 text-red-800 dark:text-red-300 px-4 py-3 mb-5 text-sm">
          Vous êtes le dernier administrateur : nommez-en un autre (Administration → Utilisateurs) avant de partir.
        </div>

        <form v-else class="space-y-5" @submit.prevent="submit">
          <!-- Photos -->
          <fieldset class="bg-white rounded-2xl border border-surface-200 p-5 space-y-2">
            <legend class="text-base font-semibold text-surface-900 mb-2">Vos photos et albums</legend>
            <label v-for="opt in photoOptions" :key="opt.value" class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer" :class="form.photos === opt.value ? 'border-brand-400 bg-brand-50 dark:bg-brand-500/10' : 'border-surface-200'">
              <input v-model="form.photos" type="radio" :value="opt.value" class="mt-1" />
              <span class="text-sm"><strong class="block text-surface-900">{{ opt.title }}</strong><span class="text-surface-500">{{ opt.text }}</span></span>
            </label>
          </fieldset>

          <!-- Souvenirs racontés -->
          <fieldset v-if="summary.voice_notes" class="bg-white rounded-2xl border border-surface-200 p-5 space-y-2">
            <legend class="text-base font-semibold text-surface-900 mb-2">Vos {{ summary.voice_notes }} souvenir{{ summary.voice_notes > 1 ? 's' : '' }} raconté{{ summary.voice_notes > 1 ? 's' : '' }} (votre voix)</legend>
            <label class="flex items-center gap-3 text-sm"><input v-model="form.voice_notes" type="radio" value="keep" /> Les laisser à la famille</label>
            <label class="flex items-center gap-3 text-sm"><input v-model="form.voice_notes" type="radio" value="remove" /> Les retirer</label>
          </fieldset>

          <!-- Identifications -->
          <fieldset v-if="summary.tagged_photos" class="bg-white rounded-2xl border border-surface-200 p-5">
            <legend class="text-base font-semibold text-surface-900 mb-2">Votre nom sur les photos des autres</legend>
            <label class="flex items-start gap-3 text-sm">
              <input v-model="form.untag_me" type="checkbox" class="mt-1" />
              <span>Retirer mon nom des {{ summary.tagged_photos }} photo{{ summary.tagged_photos > 1 ? 's' : '' }} où je suis identifié·e <span class="text-surface-500">(les photos restent, seul votre nom disparaît)</span></span>
            </label>
          </fieldset>

          <!-- Groupes créés : qui les reprend -->
          <fieldset v-if="summary.created_circles.length" class="bg-white rounded-2xl border border-surface-200 p-5 space-y-3">
            <legend class="text-base font-semibold text-surface-900 mb-1">Les groupes que vous avez créés</legend>
            <div v-for="c in summary.created_circles" :key="c.id">
              <label class="block text-sm font-medium text-surface-800 mb-1">« {{ c.name }} »</label>
              <select v-if="c.candidates.length" v-model="form.successors[c.id]" class="form-input">
                <option v-for="m in c.candidates" :key="m.id" :value="m.id">Repris par {{ m.name }}</option>
              </select>
              <p v-else class="text-sm text-surface-500">Vous êtes le seul membre : ce groupe sera fermé.</p>
            </div>
          </fieldset>

          <!-- Confirmation -->
          <section class="bg-white rounded-2xl border border-red-200 dark:border-red-500/30 p-5 space-y-3">
            <p class="text-sm text-surface-700">
              Votre départ prendra effet dans <strong>{{ graceDays }} jours</strong>. D'ici là rien ne change et vous pouvez annuler.
            </p>
            <label class="block text-sm font-medium text-surface-800">
              Pour confirmer, tapez votre prénom « {{ firstName }} »
              <input v-model="form.confirm" type="text" class="form-input mt-1" autocomplete="off" />
            </label>
            <p v-if="form.errors.confirm || form.errors.account" class="text-sm text-red-600 dark:text-red-400">{{ form.errors.confirm || form.errors.account }}</p>
            <button
              type="submit"
              class="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-50"
              :disabled="form.processing || !form.confirm.trim()"
            >
              Programmer mon départ
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

const props = defineProps({
  summary: { type: Object, required: true },
  pending: { type: Object, default: null },
  graceDays: { type: Number, default: 30 },
});

const firstName = computed(() => (usePage().props.auth?.user?.name || '').trim().split(' ')[0]);

const counts = computed(() => [
  { label: 'photos et vidéos', value: props.summary.photos },
  { label: 'albums', value: props.summary.albums },
  { label: 'fiches créées', value: props.summary.fiches },
  { label: 'moments', value: props.summary.moments },
  { label: 'souvenirs racontés', value: props.summary.voice_notes },
  { label: 'photos où vous apparaissez', value: props.summary.tagged_photos },
]);

const photoOptions = computed(() => [
  {
    value: 'keep_shared',
    title: 'Laisser ce que j\'ai partagé, retirer le reste (recommandé)',
    text: `${props.summary.shared_photos} photo(s) partagée(s) restent à la famille et passent à un administrateur ; ${props.summary.private_photos} photo(s) privée(s) sont supprimées.`,
  },
  { value: 'keep_all', title: 'Tout laisser', text: 'Toutes vos photos et albums passent à un administrateur.' },
  { value: 'remove_all', title: 'Tout retirer', text: 'Toutes vos photos sont supprimées, y compris celles partagées.' },
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
