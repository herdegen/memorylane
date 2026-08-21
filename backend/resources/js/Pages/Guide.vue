<template>
  <Head title="Guide de prise en main" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-10">
          <div class="text-xs font-semibold uppercase tracking-widest text-brand-700">Bien démarrer</div>
          <h1 class="font-display text-4xl font-semibold text-surface-900 mt-1">Guide de prise en main</h1>
          <p class="mt-3 text-surface-500">
            MemoryLane rassemble les photos, les personnes et les histoires de la famille.
            Voici le tour du propriétaire, dans l'ordre où on s'en sert.
          </p>
        </div>

        <div class="space-y-6">
          <section v-for="(step, i) in steps" :key="step.title" class="bg-white rounded-card border border-surface-200 shadow-warm-sm p-6">
            <div class="flex items-start gap-4">
              <div class="w-9 h-9 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center font-bold shrink-0">
                {{ i + 1 }}
              </div>
              <div class="min-w-0">
                <h2 class="text-lg font-semibold text-surface-900">{{ step.title }}</h2>
                <p class="mt-1.5 text-sm text-surface-600 whitespace-pre-line">{{ step.body }}</p>
                <Link v-if="step.link" :href="step.link.href" class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline">
                  {{ step.link.label }}
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                </Link>
              </div>
            </div>
          </section>
        </div>

        <p class="mt-10 text-sm text-surface-400 text-center">
          Ce guide s'enrichira au fil des nouveautés. Une question ? Demandez à la personne qui administre votre MemoryLane.
        </p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const steps = [
  {
    title: 'Importer vos photos et vidéos',
    body: "Glissez vos fichiers dans la page Télécharger (les grosses vidéos passent aussi). Vous pouvez importer directement depuis Google Photos ou une archive Google Takeout. Les dates, lieux et métadonnées sont lus automatiquement.",
    link: { href: '/media/upload', label: 'Ouvrir Télécharger' },
  },
  {
    title: 'Retrouver vos souvenirs dans Mes photos',
    body: "Toute votre bibliothèque, classée par année, avec recherche et filtres. Sélectionnez plusieurs médias (shift+clic pour une plage) pour les dater, les géolocaliser, les ajouter à un album ou les partager d'un coup.",
    link: { href: '/media', label: 'Ouvrir Mes photos' },
  },
  {
    title: 'Créer des albums et les partager',
    body: "Regroupez un événement dans un album (ou laissez un album intelligent se remplir tout seul selon des critères). Partagez-le à des comptes choisis, à tous les connectés, ou par lien pour la famille qui n'a pas de compte.",
    link: { href: '/albums', label: 'Ouvrir Albums' },
  },
  {
    title: 'Identifier les personnes',
    body: "Les visages sont détectés automatiquement dans votre navigateur, puis l'application suggère qui est qui — confirmez d'un clic sur la fiche d'une photo. Chaque personne a sa fiche : sa famille, la frise de sa vie, toutes ses photos.",
    link: { href: '/people', label: 'Ouvrir Personnes' },
  },
  {
    title: 'Partager en foyer',
    body: "Un foyer est votre cercle familial : les photos que vous y partagez deviennent visibles de ses membres, et chacun peut aider à identifier les visages. Depuis Mes photos : sélection → « Foyer ».",
    link: { href: '/households', label: 'Ouvrir Foyers' },
  },
  {
    title: "Explorer l'arbre et la carte",
    body: "L'arbre généalogique se construit depuis les fiches (ou un import GEDCOM) ; la carte affiche tous vos souvenirs géolocalisés. Les deux se visitent comme un album de famille.",
    link: { href: '/family-tree', label: "Ouvrir l'Arbre" },
  },
];
</script>
