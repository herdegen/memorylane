<template>
  <!-- Feuille « Cercles & compte » (avatar de la barre du haut, téléphone) :
       la bascule de cercle d'abord, le compte ensuite. -->
  <BottomSheet :open="open" @close="$emit('close')">
    <template #header>
      <div class="flex items-center gap-3 min-w-0">
        <PersonAvatar :name="user?.name" :size="12" />
        <div class="min-w-0">
          <div class="text-base font-bold text-surface-900 truncate">{{ user?.name }}</div>
          <div class="text-xs text-surface-500 truncate">{{ isAdmin ? 'Administrateur' : user?.email }}</div>
        </div>
      </div>
    </template>

    <div class="flex flex-col gap-4">
      <!-- Cercles -->
      <section v-if="circles.length || canCreate">
        <h3 class="text-xs font-bold uppercase tracking-wider text-surface-500 mb-2">Mes cercles</h3>
        <div class="flex flex-col gap-1.5">
          <button
            v-for="c in circles"
            :key="c.id"
            type="button"
            class="flex items-center gap-3 h-[60px] px-2.5 rounded-2xl border text-left"
            :class="c.id === current?.id
              ? (c.kind === 'friends' ? 'border-teal-300 bg-teal-50 dark:bg-teal-500/10 dark:border-teal-500/40' : 'border-amber-300 bg-amber-50 dark:bg-amber-500/10 dark:border-amber-500/40')
              : 'border-surface-200 bg-white'"
            @click="pick(c)"
          >
            <span
              class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
              :class="c.kind === 'friends' ? 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'"
            >
              <CircleKindIcon :kind="c.kind" class="w-5 h-5" />
            </span>
            <span class="flex-1 min-w-0">
              <span class="block text-[15px] font-bold text-surface-900 truncate">{{ c.name }}</span>
              <span class="block text-xs text-surface-500">{{ KIND_LABELS[c.kind] }}</span>
            </span>
            <svg v-if="c.id === current?.id" class="w-5 h-5 text-brand-600" fill="none" stroke="currentColor" stroke-width="2.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 13l4 4L19 7" /></svg>
          </button>
          <Link v-if="canCreate || canManage" href="/households" class="flex items-center gap-3 h-12 px-2.5 rounded-2xl border border-dashed border-surface-300 text-sm font-semibold text-surface-600" @click="$emit('close')">
            <span class="w-10 h-10 flex items-center justify-center"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg></span>
            {{ canManage ? 'Créer et gérer les cercles' : "Créer un cercle d'amis" }}
          </Link>
        </div>
      </section>

      <!-- Compte -->
      <section>
        <h3 class="text-xs font-bold uppercase tracking-wider text-surface-500 mb-2">Mon compte</h3>
        <div class="bg-white border border-surface-200 rounded-2xl overflow-hidden divide-y divide-surface-100">
          <Link v-if="user?.person_id" :href="`/people/${user.person_id}`" :class="ROW" @click="$emit('close')">
            <svg class="w-5 h-5 text-surface-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0116 0" /></svg>Ma fiche<span class="ml-auto text-surface-400">›</span>
          </Link>
          <Link href="/profile" :class="ROW" @click="$emit('close')">
            <svg class="w-5 h-5 text-surface-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.6 1.6 0 00-1.8-.3 1.6 1.6 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.6 1.6 0 00-1-1.5 1.6 1.6 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.6 1.6 0 00.3-1.8 1.6 1.6 0 00-1.5-1H3a2 2 0 110-4h.1a1.6 1.6 0 001.5-1 1.6 1.6 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.6 1.6 0 001.8.3H9a1.6 1.6 0 001-1.5V3a2 2 0 114 0v.1a1.6 1.6 0 001 1.5 1.6 1.6 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 00-.3 1.8V9a1.6 1.6 0 001.5 1H21a2 2 0 110 4h-.1a1.6 1.6 0 00-1.5 1z" /></svg>Mon profil et notifications<span class="ml-auto text-surface-400">›</span>
          </Link>
          <button type="button" :class="[ROW, 'w-full']" @click="toggleTheme">
            <IconTheme :dark="isDark" icon-class="w-5 h-5 text-surface-500" />Mode sombre<span class="ml-auto text-sm font-medium text-surface-500">{{ isDark ? 'Activé' : 'Désactivé' }}</span>
          </button>
          <Link href="/tags" :class="ROW" @click="$emit('close')">
            <svg class="w-5 h-5 text-surface-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12l9 9 9-9-9-9H5a2 2 0 00-2 2z" /><circle cx="8" cy="8" r="1.5" /></svg>Tags<span class="ml-auto text-surface-400">›</span>
          </Link>
          <Link href="/guide" :class="ROW" @click="$emit('close')">
            <svg class="w-5 h-5 text-surface-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M9.1 9a3 3 0 015.8 1c0 2-3 2-3 4M12 17h.01" /></svg>Guide d'utilisation<span class="ml-auto text-surface-400">›</span>
          </Link>
          <button type="button" :class="[ROW, 'w-full']" @click="$emit('tour')">
            <svg class="w-5 h-5 text-surface-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>Visite guidée
          </button>
          <button v-if="showInstall" type="button" :class="[ROW, 'w-full text-brand-700']" @click="$emit('install')">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-5-5l5 5 5-5M4 21h16" /></svg>Installer l'application
          </button>
          <a v-if="isAdmin" href="/admin" :class="[ROW, 'text-brand-700']">
            <IconSettings icon-class="w-5 h-5" />Administration
          </a>
        </div>
      </section>

      <button type="button" class="h-11 text-sm font-semibold text-red-600 dark:text-red-400" :disabled="loggingOut" @click="$emit('logout')">
        Se déconnecter
      </button>
    </div>
  </BottomSheet>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import BottomSheet from '@/Components/BottomSheet.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import CircleKindIcon from '@/Components/CircleKindIcon.vue';
import { IconSettings, IconTheme } from '@/Components/Icons';
import { useAuth } from '@/Composables/useAuth';
import { useTheme } from '@/Composables/useTheme';
import { useCircle, KIND_LABELS } from '@/Composables/useCircle';

defineProps({
  open: { type: Boolean, default: false },
  showInstall: { type: Boolean, default: false },
  loggingOut: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'logout', 'tour', 'install']);

const ROW = 'flex items-center gap-3 h-[50px] px-3.5 text-[15px] font-semibold text-surface-900 text-left';

const { user, isAdmin } = useAuth();
const { isDark, toggle: toggleTheme } = useTheme();
const { circles, current, canManage, canCreate } = useCircle();

function pick(c) {
  emit('close');
  if (c.id === current.value?.id) return;
  const from = window.location.pathname + window.location.search;
  router.post('/circles/current', { household_id: c.id }, { preserveScroll: (page) => page.url === from });
}
</script>
