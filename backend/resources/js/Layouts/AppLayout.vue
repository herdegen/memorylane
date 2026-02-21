<template>
  <div class="min-h-screen bg-page">
    <nav class="bg-white border-b border-surface-200 sticky top-0 z-40">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <!-- Logo + nav links -->
          <div class="flex">
            <div class="flex-shrink-0 flex items-center">
              <Link href="/dashboard" class="flex items-center gap-2 group">
                <!-- Logo SVG polaroid -->
                <span class="w-8 h-8 rounded-lg bg-brand-100 flex items-center justify-center group-hover:bg-brand-200 transition-colors">
                  <svg class="w-5 h-5 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                    <circle cx="12" cy="10.5" r="3.5"/>
                    <path d="M3 16l4-4 3 3 4-5 4 6"/>
                  </svg>
                </span>
                <span class="text-display text-lg text-surface-900 group-hover:text-brand-700 transition-colors">
                  MemoryLane
                </span>
              </Link>
            </div>

            <div class="hidden sm:ml-8 sm:flex sm:space-x-1">
              <NavLink href="/dashboard" :active="$page.component === 'Dashboard'">Accueil</NavLink>
              <NavLink href="/media" :active="$page.component.startsWith('Media/')">Galerie</NavLink>
              <NavLink href="/media/upload" :active="$page.component === 'Media/Upload'">Télécharger</NavLink>
              <NavLink href="/tags" :active="$page.component.startsWith('Tags/')">Tags</NavLink>
              <NavLink href="/albums" :active="$page.component.startsWith('Albums/')">Albums</NavLink>
              <NavLink href="/people" :active="$page.component.startsWith('People/')">Personnes</NavLink>
              <NavLink href="/family-tree" :active="$page.component.startsWith('FamilyTree/')">Arbre</NavLink>
              <NavLink href="/map" :active="$page.component.startsWith('Map/')">Carte</NavLink>
            </div>
          </div>

          <!-- Right: admin + user -->
          <div class="hidden sm:flex sm:items-center gap-3">
            <a v-if="isAdmin" href="/admin" class="btn-admin">
              <IconSettings icon-class="icon-sm mr-1.5" />
              Admin
            </a>

            <template v-if="user">
              <div class="relative" ref="dropdownRef">
                <button
                  @click="showDropdown = !showDropdown"
                  class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-surface-700
                         hover:bg-surface-100 hover:text-surface-900 focus:outline-none transition-colors"
                >
                  <span class="w-7 h-7 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-bold">
                    {{ user.name?.charAt(0)?.toUpperCase() }}
                  </span>
                  <span>{{ user.name }}</span>
                  <IconChevron class="w-4 h-4 text-surface-400 transition-transform" :class="{ 'rotate-180': showDropdown }" />
                </button>

                <div v-show="showDropdown" class="dropdown">
                  <div class="px-4 py-2.5 border-b border-surface-100">
                    <p class="text-xs font-medium text-surface-500">Connecté en tant que</p>
                    <p class="text-sm font-semibold text-surface-900 truncate">{{ user.email }}</p>
                  </div>
                  <Link href="/profile" class="dropdown-item" @click="showDropdown = false">Mon Profil</Link>
                  <Link href="/profile/edit" class="dropdown-item" @click="showDropdown = false">Paramètres</Link>
                  <hr class="dropdown-divider" />
                  <Link href="/logout" method="post" as="button" class="dropdown-item w-full text-left text-red-600 hover:bg-red-50 hover:text-red-700">
                    Déconnexion
                  </Link>
                </div>
              </div>
            </template>
            <template v-else>
              <Link href="/login" class="btn-primary">Connexion</Link>
            </template>
          </div>

          <!-- Mobile menu button -->
          <div class="flex items-center sm:hidden">
            <button
              @click="showMobileMenu = !showMobileMenu"
              class="p-2 rounded-lg text-surface-500 hover:text-surface-700 hover:bg-surface-100 focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors"
            >
              <IconMenu :open="showMobileMenu" />
            </button>
          </div>
        </div>
      </div>

      <!-- Mobile menu -->
      <div v-show="showMobileMenu" class="sm:hidden border-t border-surface-100 bg-surface-50">
        <div class="py-2 space-y-0.5 px-3">
          <MobileNavLink href="/dashboard" :active="$page.component === 'Dashboard'">Accueil</MobileNavLink>
          <MobileNavLink href="/media" :active="$page.component.startsWith('Media/')">Galerie</MobileNavLink>
          <MobileNavLink href="/media/upload" :active="$page.component === 'Media/Upload'">Télécharger</MobileNavLink>
          <MobileNavLink href="/tags" :active="$page.component.startsWith('Tags/')">Tags</MobileNavLink>
          <MobileNavLink href="/albums" :active="$page.component.startsWith('Albums/')">Albums</MobileNavLink>
          <MobileNavLink href="/people" :active="$page.component.startsWith('People/')">Personnes</MobileNavLink>
          <MobileNavLink href="/family-tree" :active="$page.component.startsWith('FamilyTree/')">Arbre</MobileNavLink>
          <MobileNavLink href="/map" :active="$page.component.startsWith('Map/')">Carte</MobileNavLink>
        </div>
        <div class="pt-3 pb-4 border-t border-surface-200 mx-3">
          <template v-if="user">
            <div class="flex items-center gap-3 px-1 mb-3">
              <span class="w-9 h-9 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-sm font-bold">
                {{ user.name?.charAt(0)?.toUpperCase() }}
              </span>
              <div>
                <div class="text-sm font-semibold text-surface-900">{{ user.name }}</div>
                <div class="text-xs text-surface-500">{{ user.email }}</div>
              </div>
            </div>
            <div class="space-y-0.5">
              <MobileNavLink href="/profile">Mon Profil</MobileNavLink>
              <MobileNavLink href="/profile/edit">Paramètres</MobileNavLink>
              <a v-if="isAdmin" href="/admin"
                class="block pl-3 pr-4 py-2.5 border-l-4 border-transparent text-base font-medium
                       text-brand-700 hover:bg-brand-50 hover:border-brand-300 rounded-r-lg transition-all">
                Administration
              </a>
              <Link href="/logout" method="post" as="button"
                class="block w-full text-left pl-3 pr-4 py-2.5 border-l-4 border-transparent text-base font-medium
                       text-red-600 hover:bg-red-50 hover:border-red-300 rounded-r-lg transition-all">
                Déconnexion
              </Link>
            </div>
          </template>
          <template v-else>
            <Link href="/login" class="btn-primary btn-full">Connexion</Link>
          </template>
        </div>
      </div>
    </nav>

    <main>
      <slot />
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import NavLink from '@/Components/NavLink.vue';
import MobileNavLink from '@/Components/MobileNavLink.vue';
import { IconSettings, IconChevron, IconMenu } from '@/Components/Icons';
import { useAuth } from '@/Composables/useAuth';

const { user, isAdmin } = useAuth();

const showDropdown = ref(false);
const showMobileMenu = ref(false);
const dropdownRef = ref(null);

const handleClickOutside = (event) => {
  if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
    showDropdown.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
