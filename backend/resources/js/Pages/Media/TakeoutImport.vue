<template>
  <Head title="Import Google Takeout" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
          <h1 class="text-display text-4xl text-surface-900">Importer une archive Google Takeout</h1>
          <p class="mt-2 text-surface-600">
            La seule façon de récupérer vos photos Google <strong>avec leur géolocalisation</strong> :
            Google la retire des téléchargements classiques, mais la conserve dans les exports Takeout.
          </p>
        </div>

        <!-- Mode d'emploi -->
        <div class="card card--padded mb-6">
          <h2 class="card-title">1. Créer l'export chez Google</h2>
          <ol class="list-decimal list-inside space-y-2 text-sm text-surface-600">
            <li>
              Ouvrez
              <a href="https://takeout.google.com" target="_blank" rel="noopener" class="text-brand-600 hover:text-brand-700 font-medium">takeout.google.com</a>
              et cliquez « Tout désélectionner »
            </li>
            <li>Cochez uniquement <strong>Google Photos</strong> (vous pouvez choisir certains albums via « Toutes les archives de photos incluses »)</li>
            <li>En bas : « Étape suivante », exporter une fois, type <strong>.zip</strong>, taille <strong>2 Go</strong></li>
            <li>Google prépare l'export (quelques minutes à quelques heures pour de grosses bibliothèques) et vous envoie un lien de téléchargement</li>
          </ol>
        </div>

        <!-- Upload -->
        <div class="card card--padded">
          <h2 class="card-title">2. Déposer les archives ici</h2>
          <p class="text-sm text-surface-500 mb-4">
            Les photos déjà présentes dans MemoryLane ne seront pas dupliquées :
            elles seront enrichies de leur géolocalisation et de leur date.
            <strong>Vos albums Google Photos sont recréés automatiquement</strong>
            avec leurs photos.
          </p>

          <form @submit.prevent="submit" class="space-y-4">
            <label
              class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-surface-300 rounded-xl p-8 cursor-pointer hover:border-brand-400 hover:bg-brand-50/40 transition-colors"
            >
              <svg class="w-10 h-10 text-brand-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
              </svg>
              <span class="text-sm font-medium text-surface-700">
                {{ form.archives.length > 0 ? selectedLabel : 'Choisir les fichiers ZIP Takeout' }}
              </span>
              <span class="text-xs text-surface-400">ZIP jusqu'à 2 Go chacun — plusieurs fichiers possibles</span>
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
              Envoi : {{ form.progress.percentage }} % — ne fermez pas cette page
            </p>

            <button
              type="submit"
              :disabled="form.archives.length === 0 || form.processing"
              class="btn-primary w-full"
            >
              {{ form.processing ? 'Envoi en cours…' : 'Lancer l\'import' }}
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

const form = useForm({
  archives: [],
});

const selectedLabel = computed(() => {
  const files = form.archives;
  if (files.length === 1) return files[0].name;
  const totalMo = Math.round(files.reduce((sum, f) => sum + f.size, 0) / 1024 / 1024);
  return `${files.length} archives — ${totalMo} Mo`;
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
