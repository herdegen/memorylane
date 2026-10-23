<template>
  <Head :title="$t('Import Google Takeout')" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
          <h1 class="text-display text-4xl text-surface-900">{{ $t('Importer une archive Google Takeout') }}</h1>
          <p class="mt-2 text-surface-600">
            <Trans text="La seule façon de récupérer vos photos Google :geo : Google la retire des téléchargements classiques, mais la conserve dans les exports Takeout.">
              <template #geo><strong>{{ $t('avec leur géolocalisation') }}</strong></template>
            </Trans>
          </p>
        </div>

        <!-- Mode d'emploi -->
        <div class="card card--padded mb-6">
          <h2 class="card-title">{{ $t("1. Créer l'export chez Google") }}</h2>
          <ol class="list-decimal list-inside space-y-2 text-sm text-surface-600">
            <li>
              <Trans text="Ouvrez :link et cliquez « Tout désélectionner »">
                <template #link><a href="https://takeout.google.com" target="_blank" rel="noopener" class="text-brand-600 hover:text-brand-700 font-medium">takeout.google.com</a></template>
              </Trans>
            </li>
            <li>
              <Trans text="Cochez uniquement :product (vous pouvez choisir certains albums via « Toutes les archives de photos incluses »)">
                <template #product><strong>Google Photos</strong></template>
              </Trans>
            </li>
            <li>
              <Trans text="En bas : « Étape suivante », exporter une fois, type :type, taille :size">
                <template #type><strong>.zip</strong></template>
                <template #size><strong>{{ $t('2 Go') }}</strong></template>
              </Trans>
            </li>
            <li>{{ $t("Google prépare l'export (quelques minutes à quelques heures pour de grosses bibliothèques) et vous envoie un lien de téléchargement") }}</li>
          </ol>
        </div>

        <!-- Upload -->
        <div class="card card--padded">
          <h2 class="card-title">{{ $t('2. Déposer les archives ici') }}</h2>
          <p class="text-sm text-surface-500 mb-4">
            <Trans text="Les photos déjà présentes dans MemoryLane ne seront pas dupliquées : elles seront enrichies de leur géolocalisation et de leur date. :albums avec leurs photos.">
              <template #albums><strong>{{ $t('Vos albums Google Photos sont recréés automatiquement') }}</strong></template>
            </Trans>
          </p>

          <form @submit.prevent="submit" class="space-y-4">
            <label
              class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-surface-300 rounded-xl p-8 cursor-pointer hover:border-brand-400 hover:bg-brand-50/40 transition-colors"
            >
              <svg class="w-10 h-10 text-brand-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
              </svg>
              <span class="text-sm font-medium text-surface-700">
                {{ form.archives.length > 0 ? selectedLabel : $t('Choisir les fichiers ZIP Takeout') }}
              </span>
              <span class="text-xs text-surface-400">{{ $t("ZIP jusqu'à 2 Go chacun — plusieurs fichiers possibles") }}</span>
              <input
                type="file"
                accept=".zip"
                multiple
                class="hidden"
                @change="onFilesSelected"
              />
            </label>

            <p v-if="form.errors.archives" class="text-sm text-red-600 dark:text-red-400">{{ form.errors.archives }}</p>
            <p v-for="(err, key) in fileErrors" :key="key" class="text-sm text-red-600 dark:text-red-400">{{ err }}</p>

            <div v-if="form.progress" class="w-full bg-surface-100 rounded-full h-2 overflow-hidden">
              <div
                class="bg-brand-600 h-2 rounded-full transition-all"
                :style="{ width: form.progress.percentage + '%' }"
              ></div>
            </div>
            <p v-if="form.progress" class="text-xs text-surface-500 text-center">
              {{ $t('Envoi : :percent % — ne fermez pas cette page', { percent: form.progress.percentage }) }}
            </p>

            <button
              type="submit"
              :disabled="form.archives.length === 0 || form.processing"
              class="btn-primary w-full"
            >
              {{ form.processing ? $t('Envoi en cours…') : $t('Lancer l\'import') }}
            </button>
          </form>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Trans from '@/Components/Trans.vue';
import { tc } from '@/i18n';

const form = useForm({
  archives: [],
});

const selectedLabel = computed(() => {
  const files = form.archives;
  if (files.length === 1) return files[0].name;
  const totalMo = Math.round(files.reduce((sum, f) => sum + f.size, 0) / 1024 / 1024);
  return tc(':count archive — :size Mo|:count archives — :size Mo', files.length, { size: totalMo });
});

const fileErrors = computed(() =>
  Object.entries(form.errors)
    .filter(([key]) => key.startsWith('archives.'))
    .map(([, value]) => value)
);

const onFilesSelected = (event) => {
  form.archives = Array.from(event.target.files);
};

const submit = () => {
  form.post('/takeout');
};
</script>
