<template>
  <div class="min-h-screen bg-gray-100">
    <nav class="bg-white shadow-sm">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex">
            <div class="flex-shrink-0 flex items-center">
              <Link href="/dashboard" class="text-xl font-bold text-gray-900 hover:text-indigo-600 transition">
                MemoryLane
              </Link>
            </div>
            <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
              <NavLink href="/dashboard" :active="$page.component === 'Dashboard'">
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
              <NavLink href="/albums" :active="$page.component.startsWith('Albums/')">
                Albums
              </NavLink>
              <NavLink href="/people" :active="$page.component.startsWith('People/')">
                Personnes
              </NavLink>
              <NavLink href="/family-tree" :active="$page.component.startsWith('FamilyTree/')">
                Arbre
              </NavLink>
              <NavLink href="/map" :active="$page.component.startsWith('Map/')">
                Carte
              </NavLink>
            </div>
          </div>

          <!-- User Menu -->
          <div class="hidden sm:ml-6 sm:flex sm:items-center gap-4">
            <!-- Admin Button (only for admins) -->
            <a v-if="isAdmin" href="/admin" class="btn-admin">
              <IconSettings icon-class="icon-sm mr-1.5" />
              Admin
            </a>

            <template v-if="user">
              <div class="relative" ref="dropdownRef">
                <button
                  @click="showDropdown = !showDropdown"
                  class="flex items-center text-sm font-medium text-gray-700 hover:text-gray-900 focus:outline-none transition"
                >
                  <span class="mr-2">{{ user.name }}</span>
                  <IconChevron :class="{ 'rotate-180': showDropdown }" />
                </button>

                <!-- Dropdown -->
                <div v-show="showDropdown" class="dropdown">
                  <Link href="/profile" class="dropdown-item" @click="showDropdown = false">
                    Mon Profil
                  </Link>
                  <Link href="/profile/edit" class="dropdown-item" @click="showDropdown = false">
                    Paramètres
                  </Link>
                  <hr class="dropdown-divider" />
                  <Link href="/logout" method="post" as="button" class="dropdown-item w-full text-left">
                    Déconnexion
                  </Link>
                </div>
              </div>
            </template>
            <template v-else>
              <Link href="/login" class="btn-primary">
                Connexion
              </Link>
            </template>
          </div>

          <!-- Mobile menu button -->
          <div class="flex items-center sm:hidden">
            <button
              @click="showMobileMenu = !showMobileMenu"
              class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500"
            >
              <IconMenu :open="showMobileMenu" />
            </button>
          </div>
        </div>
      </div>

      <!-- Mobile menu -->
      <div v-show="showMobileMenu" class="sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
          <MobileNavLink href="/dashboard" :active="$page.component === 'Dashboard'">
            Accueil
          </MobileNavLink>
          <MobileNavLink href="/media" :active="$page.component.startsWith('Media/')">
            Galerie
          </MobileNavLink>
          <MobileNavLink href="/media/upload" :active="$page.component === 'Media/Upload'">
            Télécharger
          </MobileNavLink>
          <MobileNavLink href="/tags" :active="$page.component.startsWith('Tags/')">
            Tags
          </MobileNavLink>
          <MobileNavLink href="/albums" :active="$page.component.startsWith('Albums/')">
            Albums
          </MobileNavLink>
          <MobileNavLink href="/people" :active="$page.component.startsWith('People/')">
            Personnes
          </MobileNavLink>
          <MobileNavLink href="/family-tree" :active="$page.component.startsWith('FamilyTree/')">
            Arbre
          </MobileNavLink>
          <MobileNavLink href="/map" :active="$page.component.startsWith('Map/')">
            Carte
          </MobileNavLink>
        </div>
        <div class="pt-4 pb-3 border-t border-gray-200">
          <template v-if="user">
            <div class="flex items-center px-4">
              <div>
                <div class="text-base font-medium text-gray-800">{{ user.name }}</div>
                <div class="text-sm text-muted">{{ user.email }}</div>
              </div>
            </div>
            <div class="mt-3 space-y-1">
              <MobileNavLink href="/profile">Mon Profil</MobileNavLink>
              <MobileNavLink href="/profile/edit">Paramètres</MobileNavLink>
              <a
                v-if="isAdmin"
                href="/admin"
                class="block px-4 py-2 text-base font-medium text-amber-700 hover:text-amber-800 hover:bg-amber-50"
              >
                Administration
              </a>
              <Link
                href="/logout"
                method="post"
                as="button"
                class="block w-full text-left px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100"
              >
                Déconnexion
              </Link>
            </div>
          </template>
          <template v-else>
            <div class="px-4">
              <Link href="/login" class="btn-primary btn-full">
                Connexion
              </Link>
            </div>
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
