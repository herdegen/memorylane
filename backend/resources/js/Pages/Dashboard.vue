<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Welcome Section -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6 text-gray-900">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
              Bienvenue{{ user ? `, ${user.name}` : '' }}
            </h1>
            <p class="text-gray-600">
              Votre hub familial pour partager photos, vidéos et souvenirs.
            </p>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
          <Link
            href="/media/upload"
            class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition border-l-4 border-indigo-500"
          >
            <div class="flex items-center">
              <div class="p-3 rounded-full bg-indigo-100 text-indigo-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
              </div>
              <div class="ml-4">
                <h3 class="font-semibold text-gray-900">Télécharger</h3>
                <p class="text-sm text-gray-500">Ajouter des médias</p>
              </div>
            </div>
          </Link>

          <Link
            href="/media"
            class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition border-l-4 border-green-500"
          >
            <div class="flex items-center">
              <div class="p-3 rounded-full bg-green-100 text-green-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
              <div class="ml-4">
                <h3 class="font-semibold text-gray-900">Galerie</h3>
                <p class="text-sm text-gray-500">Voir tous les médias</p>
              </div>
            </div>
          </Link>

          <Link
            href="/map"
            class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition border-l-4 border-blue-500"
          >
            <div class="flex items-center">
              <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </div>
              <div class="ml-4">
                <h3 class="font-semibold text-gray-900">Carte</h3>
                <p class="text-sm text-gray-500">Explorer par lieu</p>
              </div>
            </div>
          </Link>

          <Link
            href="/tags"
            class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition border-l-4 border-purple-500"
          >
            <div class="flex items-center">
              <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                </svg>
              </div>
              <div class="ml-4">
                <h3 class="font-semibold text-gray-900">Tags</h3>
                <p class="text-sm text-gray-500">Organiser par tags</p>
              </div>
            </div>
          </Link>
        </div>

        <!-- Features Section -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Fonctionnalités</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                <div class="flex-shrink-0">
                  <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-gray-900">Galerie Photos & Vidéos</h3>
                  <p class="text-sm text-gray-500">Upload, organisation et visualisation de vos médias</p>
                </div>
              </div>

              <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                <div class="flex-shrink-0">
                  <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-gray-900">Système de Tags</h3>
                  <p class="text-sm text-gray-500">Organisez vos médias avec des tags personnalisés</p>
                </div>
              </div>

              <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                <div class="flex-shrink-0">
                  <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-gray-900">Géolocalisation</h3>
                  <p class="text-sm text-gray-500">Carte interactive avec vos photos géolocalisées</p>
                </div>
              </div>

              <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                <div class="flex-shrink-0">
                  <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-gray-900">Extraction EXIF</h3>
                  <p class="text-sm text-gray-500">Métadonnées automatiquement extraites de vos photos</p>
                </div>
              </div>

              <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                <div class="flex-shrink-0">
                  <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-gray-900">Albums</h3>
                  <p class="text-sm text-gray-500">Bientôt disponible</p>
                </div>
              </div>

              <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                <div class="flex-shrink-0">
                  <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-gray-900">Reconnaissance Faciale</h3>
                  <p class="text-sm text-gray-500">Bientôt disponible</p>
                </div>
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
</script>
