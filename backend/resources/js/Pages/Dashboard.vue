<template>
  <AppLayout>
    <div class="page-container">
      <div class="page-content space-y-8">

        <!-- Hero / Welcome -->
        <div class="relative bg-white rounded-2xl border border-surface-200 shadow-sm overflow-hidden">
          <!-- Warm gradient background -->
          <div class="absolute inset-0 bg-gradient-to-br from-brand-50 via-white to-white pointer-events-none" />
          <div class="relative p-8 sm:p-10">
            <div class="flex flex-col sm:flex-row sm:items-center gap-6">
              <div class="flex-1">
                <p class="text-xs font-semibold uppercase tracking-widest text-brand-600 mb-2">Votre espace famille</p>
                <h1 class="text-display text-4xl sm:text-5xl text-surface-900 mb-3 leading-tight">
                  Bienvenue<span v-if="user">, {{ user.name.split(' ')[0] }}</span>.
                </h1>
                <p class="text-surface-500 text-lg max-w-xl">
                  Tous vos souvenirs en famille — photos, vidéos, personnes et histoires — réunis en un seul endroit.
                </p>
              </div>
              <!-- Decorative photo stack -->
              <div class="hidden lg:block flex-shrink-0">
                <div class="relative w-36 h-36">
                  <div class="absolute inset-0 rotate-6 rounded-xl bg-brand-200/60 shadow-sm" />
                  <div class="absolute inset-0 rotate-3 rounded-xl bg-brand-100/80 shadow-sm" />
                  <div class="absolute inset-0 rounded-xl bg-brand-50 border-2 border-brand-100 shadow flex items-center justify-center">
                    <svg class="w-14 h-14 text-brand-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                      <rect x="3" y="3" width="18" height="18" rx="3"/>
                      <circle cx="12" cy="10.5" r="3.5"/>
                      <path d="M3 16l4-4 3 3 4-5 4 6"/>
                    </svg>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div>
          <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400 mb-4">Accès rapide</h2>
          <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <Link href="/media/upload" class="action-card group">
              <div class="flex items-start gap-4">
                <div class="action-card-icon action-card-icon--brand flex-shrink-0">
                  <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-semibold text-surface-900">Télécharger</h3>
                  <p class="text-sm text-surface-500 mt-0.5">Ajouter des médias</p>
                </div>
              </div>
            </Link>

            <Link href="/media" class="action-card group">
              <div class="flex items-start gap-4">
                <div class="action-card-icon action-card-icon--teal flex-shrink-0">
                  <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-semibold text-surface-900">Galerie</h3>
                  <p class="text-sm text-surface-500 mt-0.5">Tous vos médias</p>
                </div>
              </div>
            </Link>

            <Link href="/people" class="action-card group">
              <div class="flex items-start gap-4">
                <div class="action-card-icon action-card-icon--rose flex-shrink-0">
                  <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-semibold text-surface-900">Personnes</h3>
                  <p class="text-sm text-surface-500 mt-0.5">Votre famille</p>
                </div>
              </div>
            </Link>

            <Link href="/family-tree" class="action-card group">
              <div class="flex items-start gap-4">
                <div class="action-card-icon action-card-icon--violet flex-shrink-0">
                  <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-semibold text-surface-900">Arbre</h3>
                  <p class="text-sm text-surface-500 mt-0.5">Généalogie</p>
                </div>
              </div>
            </Link>
          </div>
        </div>

        <!-- Features -->
        <div class="bg-white rounded-2xl border border-surface-200 shadow-sm p-6 sm:p-8">
          <h2 class="text-xl font-semibold text-surface-900 mb-6">Fonctionnalités disponibles</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div v-for="feature in features" :key="feature.title" class="feature-item">
              <div class="feature-icon" :class="feature.available ? 'feature-icon--available' : 'feature-icon--coming'">
                <svg v-if="feature.available" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <svg v-else class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div class="feature-content">
                <h3>{{ feature.title }}</h3>
                <p>{{ feature.description }}</p>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useAuth } from '@/Composables/useAuth';

const { user } = useAuth();

const features = [
  { title: 'Galerie Photos & Vidéos', description: 'Upload, organisation et visualisation de vos médias', available: true },
  { title: 'Système de Tags', description: 'Organisez vos médias avec des tags personnalisés', available: true },
  { title: 'Géolocalisation', description: 'Carte interactive avec vos photos géolocalisées', available: true },
  { title: 'Extraction EXIF', description: 'Métadonnées automatiquement extraites de vos photos', available: true },
  { title: 'Albums & Partage', description: 'Créez des albums et partagez-les', available: true },
  { title: 'Arbre Généalogique', description: 'Visualisation et import GEDCOM', available: true },
  { title: 'Vision IA', description: 'Détection de visages et labels automatiques', available: true },
  { title: 'Reconnaissance Faciale', description: 'Identification automatique des personnes', available: false },
];
</script>
