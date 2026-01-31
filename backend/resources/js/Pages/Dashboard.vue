<template>
  <div class="min-h-screen bg-gray-100">
    <nav class="bg-white shadow-sm">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex">
            <div class="flex-shrink-0 flex items-center">
              <Link href="/" class="text-xl font-bold text-gray-900 hover:text-indigo-600 transition">
                MemoryLane
              </Link>
            </div>
            <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
              <NavLink href="/" :active="$page.component === 'Dashboard'">
                Accueil
              </NavLink>
              <NavLink href="/media" :active="$page.component === 'Media/Index'">
                Galerie
              </NavLink>
              <NavLink href="/media/upload" :active="$page.component === 'Media/Upload'">
                Télécharger
              </NavLink>
            </div>
          </div>
        </div>
      </div>
    </nav>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-gray-900">
            <h2 class="text-2xl font-semibold mb-4">Bienvenue sur MemoryLane</h2>
            <p class="text-gray-600 mb-6">
              Votre hub familial pour partager photos, vidéos et souvenirs.
            </p>

            <!-- Quick actions -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-8">
              <Link
                href="/media/upload"
                class="p-6 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition border-2 border-indigo-200"
              >
                <div class="text-indigo-600 text-2xl mb-2">📤</div>
                <h3 class="font-semibold text-lg text-gray-900 mb-1">Télécharger des médias</h3>
                <p class="text-gray-600 text-sm">Ajoutez vos photos, vidéos et documents</p>
              </Link>

              <Link
                href="/media"
                class="p-6 bg-green-50 rounded-lg hover:bg-green-100 transition border-2 border-green-200"
              >
                <div class="text-green-600 text-2xl mb-2">🖼️</div>
                <h3 class="font-semibold text-lg text-gray-900 mb-1">Voir la galerie</h3>
                <p class="text-gray-600 text-sm">Parcourez tous vos médias</p>
              </Link>

              <div class="p-6 bg-gray-50 rounded-lg border-2 border-gray-200">
                <div class="text-gray-400 text-2xl mb-2">📊</div>
                <h3 class="font-semibold text-lg text-gray-900 mb-1">Statistiques</h3>
                <p class="text-gray-600 text-sm">Bientôt disponible</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import NavLink from '@/Components/NavLink.vue';
</script>
