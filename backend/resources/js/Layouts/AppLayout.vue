<template>
  <div class="min-h-screen bg-page">
    <ImpersonationBanner />
    <!-- Fête d'anniversaire : première visite du jour d'un anniversaire -->
    <BirthdayGreeting />
    <nav class="bg-white border-b border-surface-200 sticky top-0 z-40">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <!-- Logo + nav links -->
          <div class="flex">
            <div class="shrink-0 flex items-center">
              <Link href="/dashboard" class="flex items-center gap-2 group" aria-label="Accueil">
                <!-- Logo SVG polaroid -->
                <span class="w-8 h-8 rounded-lg bg-brand-100 flex items-center justify-center group-hover:bg-brand-200 transition-colors">
                  <svg class="w-5 h-5 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                    <circle cx="12" cy="10.5" r="3.5"/>
                    <path d="M3 16l4-4 3 3 4-5 4 6"/>
                  </svg>
                </span>
                <span class="hidden sm:inline text-display text-lg text-surface-900 group-hover:text-brand-700 transition-colors">
                  MemoryLane
                </span>
              </Link>
            </div>

            <div v-if="user" class="flex items-center ml-3 sm:ml-5 min-w-0">
              <CircleSwitcher class="hidden sm:block" />
              <CircleSwitcher sheet class="sm:hidden" @open="showAccountSheet = true" />
              <!-- Un seul cercle (téléphone) : juste son nom, pas de sélecteur -->
              <span v-if="circles.length <= 1 && current" class="sm:hidden text-sm font-semibold text-surface-700 truncate">{{ current.name }}</span>
            </div>

            <div class="hidden sm:ml-6 sm:flex sm:space-x-1">
              <NavLink href="/dashboard" :active="$page.component === 'Dashboard'">Accueil</NavLink>
              <NavLink href="/media" :active="$page.component.startsWith('Media/') && $page.component !== 'Media/Upload'">Mes photos</NavLink>
              <NavLink href="/media/upload" :active="$page.component === 'Media/Upload'">Ajouter</NavLink>
              <NavLink href="/albums" :active="$page.component.startsWith('Albums/')">Albums</NavLink>
              <NavLink href="/people" :active="$page.component.startsWith('People/')">{{ peopleLabel }}</NavLink>
              <NavLink v-if="isFriends" href="/network" :active="$page.component.startsWith('Network/')">Réseau</NavLink>
              <NavLink v-else href="/family-tree" :active="$page.component.startsWith('FamilyTree/') || $page.component.startsWith('Network/')">Arbre</NavLink>
              <NavLink href="/map" :active="$page.component.startsWith('Map/')">Carte</NavLink>
            </div>
          </div>

          <!-- Right: admin + user -->
          <div class="hidden sm:flex sm:items-center gap-3">
            <SearchBar v-if="user" />

            <button
              @click="toggleTheme"
              class="p-2 rounded-lg text-surface-500 hover:text-surface-700 hover:bg-surface-100
                     focus:outline-hidden focus:ring-2 focus:ring-brand-500 transition-colors"
              :aria-label="isDark ? 'Passer en mode clair' : 'Passer en mode sombre'"
              :title="isDark ? 'Mode clair' : 'Mode sombre'"
            >
              <IconTheme :dark="isDark" icon-class="icon-sm" />
            </button>

            <a v-if="isAdmin" href="/admin" class="btn-admin">
              <IconSettings icon-class="icon-sm mr-1.5" />
              Admin
            </a>

            <template v-if="user">
              <div class="relative" ref="dropdownRef">
                <button
                  @click="showDropdown = !showDropdown"
                  class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-surface-700
                         hover:bg-surface-100 hover:text-surface-900 focus:outline-hidden transition-colors"
                >
                  <PersonAvatar :name="user.name" :size="7" />
                  <span>{{ user.name }}</span>
                  <IconChevron class="w-4 h-4 text-surface-400 transition-transform" :class="{ 'rotate-180': showDropdown }" />
                </button>

                <div v-show="showDropdown" class="dropdown">
                  <div class="px-4 py-2.5 border-b border-surface-100">
                    <p class="text-xs font-medium text-surface-500">Connecté en tant que</p>
                    <p class="text-sm font-semibold text-surface-900 truncate">{{ user.email }}</p>
                  </div>
                  <Link href="/profile" class="dropdown-item" @click="showDropdown = false">Mon profil</Link>
                  <Link href="/households" class="dropdown-item" @click="showDropdown = false">Cercles</Link>
                  <Link href="/tags" class="dropdown-item" @click="showDropdown = false">Tags</Link>
                  <Link href="/guide" class="dropdown-item" @click="showDropdown = false">Guide d'utilisation</Link>
                  <button type="button" class="dropdown-item w-full text-left" @click="launchTour">Visite guidée</button>
                  <hr class="dropdown-divider" />
                  <button type="button" @click="logout" :disabled="loggingOut" class="dropdown-item w-full text-left text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-700 dark:hover:text-red-300">
                    Déconnexion
                  </button>
                </div>
              </div>
            </template>
            <template v-else>
              <Link href="/login" class="btn-primary">Connexion</Link>
            </template>
          </div>

          <!-- Téléphone : recherche + compte (feuille) ; la navigation est dans la barre d'onglets -->
          <div class="flex items-center sm:hidden">
            <!-- Recherche (téléphone) : ouvre un panneau sous la barre -->
            <button
              v-if="user"
              type="button"
              @click="toggleMobileSearch"
              class="p-2 rounded-lg text-surface-500 hover:text-surface-700 hover:bg-surface-100 focus:outline-hidden focus:ring-2 focus:ring-brand-500 transition-colors"
              :class="{ 'bg-surface-100 text-surface-700': showMobileSearch }"
              :aria-label="showMobileSearch ? 'Fermer la recherche' : 'Rechercher'"
              :aria-expanded="showMobileSearch"
              aria-controls="mobile-search"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </button>
            <button
              v-if="user"
              type="button"
              class="w-11 h-11 flex items-center justify-center rounded-full focus:outline-hidden focus:ring-2 focus:ring-brand-500"
              aria-label="Cercles et compte"
              data-tour="account"
              @click="showAccountSheet = true"
            >
              <PersonAvatar :name="user.name" :size="8" />
            </button>
            <Link v-else href="/login" class="btn-primary">Connexion</Link>
          </div>
        </div>
      </div>

      <!-- Recherche (téléphone) : la barre desktop est masquée sous sm -->
      <div
        v-if="user && showMobileSearch"
        id="mobile-search"
        class="sm:hidden border-t border-surface-100 bg-white px-4 py-3"
      >
        <SearchBar full-width autofocus />
      </div>

    </nav>

    <!-- Départ de l'application programmé : rappel et annulation, partout. -->
    <div v-if="user?.departure_at" class="bg-amber-100 dark:bg-amber-500/15 text-amber-900 dark:text-amber-200 text-sm px-4 py-2 flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
      <span>Votre départ de MemoryLane est programmé pour le {{ formatLongDate(user.departure_at) }}.</span>
      <Link href="/account/departure" class="font-semibold underline">Annuler ou voir les détails</Link>
    </div>

    <main class="pb-[calc(4rem+env(safe-area-inset-bottom))] sm:pb-0">
      <slot />
    </main>

    <!-- Téléphone : barre d'onglets + feuilles « Ajouter » et « Cercles & compte » -->
    <template v-if="user">
      <MobileTabBar @add="showAddSheet = true" />
      <AddSheet :open="showAddSheet" @close="showAddSheet = false" />
      <AccountSheet
        :open="showAccountSheet"
        :show-install="showInstallEntry"
        :logging-out="loggingOut"
        @close="showAccountSheet = false"
        @logout="logout"
        @tour="launchTour"
        @install="openInstall"
      />
    </template>

    <ToastContainer />
    <BringInDialog />
    <ConfirmDialog />
    <GuidedTour v-if="user" />
    <InstallAppTutorial v-if="showInstallTutorial" :device="installDevice" @close="showInstallTutorial = false" />
  </div>
</template>

<script setup>
import { ref, watchEffect, onMounted, onUnmounted } from 'vue';
import { formatLongDate } from '@/utils/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import NavLink from '@/Components/NavLink.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import MobileTabBar from '@/Components/MobileTabBar.vue';
import AddSheet from '@/Components/AddSheet.vue';
import AccountSheet from '@/Components/AccountSheet.vue';
import SearchBar from '@/Components/SearchBar.vue';
import CircleSwitcher from '@/Components/CircleSwitcher.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import BringInDialog from '@/Components/BringInDialog.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import GuidedTour from '@/Components/GuidedTour.vue';
import { startTour } from '@/Composables/useTour';
import InstallAppTutorial from '@/Components/InstallAppTutorial.vue';
import { useInstallApp } from '@/Composables/useInstallApp';
import axios from 'axios';
import { IconSettings, IconChevron, IconTheme } from '@/Components/Icons';
import { useAuth } from '@/Composables/useAuth';
import { useTheme } from '@/Composables/useTheme';
import { startBackgroundFaceScan } from '@/Composables/useBackgroundFaceScan';
import ImpersonationBanner from '@/Components/ImpersonationBanner.vue';
import BirthdayGreeting from '@/Components/BirthdayGreeting.vue';
import { usePush } from '@/Composables/usePush';
import { useCircle } from '@/Composables/useCircle';

const { user, isAdmin } = useAuth();
const { isDark, toggle: toggleTheme } = useTheme();
const { syncOnce: syncPushSubscription, unsubscribeThisDevice, permission: pushPermission } = usePush();
const { mode, isFriends, circles, current, peopleLabel } = useCircle();

// Accent du mode (ambre famille, teal amis) : app.css recolore la palette
// `brand` sous [data-mode=friends].
watchEffect(() => {
  if (typeof document !== 'undefined') document.documentElement.dataset.mode = mode.value;
});

const showDropdown = ref(false);
const showMobileSearch = ref(false);
const showAddSheet = ref(false);
const showAccountSheet = ref(false);

const toggleMobileSearch = () => {
  showMobileSearch.value = !showMobileSearch.value;
};

// Toute navigation referme les feuilles et la recherche.
const offNavigate = router.on('navigate', () => {
  showAddSheet.value = false;
  showAccountSheet.value = false;
  showMobileSearch.value = false;
});
onUnmounted(offNavigate);

// Déconnexion : cet appareil ne doit plus recevoir les notifications du
// compte. On le désabonne AVANT (le DELETE serveur exige encore la session),
// sans jamais bloquer : un échec ou plus de 3 s d'attente → on se déconnecte
// quand même (le serveur purge de lui-même un abonnement mort).
const loggingOut = ref(false);
const PUSH_UNSUBSCRIBE_TIMEOUT_MS = 3000;
const logout = async () => {
  if (loggingOut.value) return;
  loggingOut.value = true;
  showDropdown.value = false;
  showAccountSheet.value = false;
  if (pushPermission.value === 'granted') {
    try {
      await Promise.race([
        unsubscribeThisDevice([], { background: true }),
        new Promise((resolve) => setTimeout(resolve, PUSH_UNSUBSCRIBE_TIMEOUT_MS)),
      ]);
    } catch {
      /* sans gravité : la déconnexion passe avant */
    }
  }
  router.post('/logout', {}, {
    onFinish: () => { loggingOut.value = false; },
  });
};

const page = usePage();
const launchTour = () => {
  showDropdown.value = false;
  showAccountSheet.value = false;
  startTour(page);
};

// Installation en application (PWA) : entrée du menu mobile tant que l'app
// n'est pas ouverte depuis son icône ; on retient (une fois par compte) qu'elle
// a été installée, pour le suivi dans le back-office.
const { device: installDevice, isMobile, standalone, canPromptNative, promptInstall } = useInstallApp();
const showInstallEntry = isMobile && !standalone;
const showInstallTutorial = ref(false);
const openInstall = async () => {
  showAccountSheet.value = false;
  if (canPromptNative.value && await promptInstall()) return;
  showInstallTutorial.value = true;
};
const dropdownRef = ref(null);

const handleClickOutside = (event) => {
  if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
    showDropdown.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
  // Analyse des visages en tâche de fond pendant la navigation (silencieux,
  // singleton, sans coût s'il n'y a rien à traiter).
  if (page.props.faces_engine !== 'insightface') startBackgroundFaceScan();
  // « Se connecter en tant que » : ne pas lier ce navigateur (notifications,
  // installation) au compte du membre consulté.
  const impersonating = Boolean(page.props.impersonating);
  // Resynchronise l'abonnement push de cet appareil (rotation d'endpoint).
  if (!impersonating) syncPushSubscription();
  if (!impersonating && standalone && user.value && !user.value.onboarding?.pwa) {
    user.value.onboarding = { ...(user.value.onboarding || {}), pwa: new Date().toISOString() };
    axios.post('/onboarding/pwa').catch(() => {});
  }
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
