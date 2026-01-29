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
              <NavLink href="/media" :active="$page.component.startsWith('Media/')">
                Galerie
              </NavLink>
              <NavLink href="/media/upload" :active="$page.component === 'Media/Upload'">
                Télécharger
              </NavLink>
              <NavLink href="/tags" :active="$page.component.startsWith('Tags/')">
                Tags
              </NavLink>
            </div>
          </div>
        </div>
      </div>
    </nav>

    <main>
      <slot />
    </main>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import NavLink from '@/Components/NavLink.vue';
</script>
