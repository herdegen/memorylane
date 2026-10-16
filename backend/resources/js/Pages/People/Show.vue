<template>
  <Head :title="personLabel(person)" />
  <AppLayout>
    <!-- ================= En-tête doux pleine largeur ================= -->
    <div class="relative bg-linear-to-br from-brand-100 via-page to-page border-b border-surface-200">
      <!-- Retour (téléphone : en haut à gauche, comme une app) -->
      <Link
        href="/people"
        class="sm:hidden absolute top-3 left-4 z-20 inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/90 backdrop-blur text-surface-900 shadow-warm-md"
        :aria-label="`Retour aux ${peopleLabel.toLowerCase()}`"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 19l-7-7 7-7" /></svg>
      </Link>

      <!-- Actions flottantes (téléphone : ⋯ seulement ; parenté, réseau et
           diaporama vivent dans la barre d'actions sous le portrait) -->
      <div class="absolute top-3 right-4 sm:top-5 sm:right-8 z-20 flex items-center gap-2.5">
        <!-- Amis : pas de parenté, on la retrouve dans le réseau du cercle -->
        <Link
          v-if="isFriends"
          :href="`/network?focus=${person.id}`"
          class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/90 backdrop-blur text-sm font-semibold text-surface-900 shadow-warm-md hover:bg-white transition"
          title="Voir ses liens dans le réseau du cercle"
        >
          <CircleKindIcon kind="friends" class="w-4 h-4" />
          Voir dans le réseau
        </Link>
        <Link
          v-else-if="viewerPersonId && viewerPersonId !== person.id"
          :href="`/family-tree?kinship=${person.id}`"
          class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/90 backdrop-blur text-sm font-semibold text-surface-900 shadow-warm-md hover:bg-white transition"
          title="Voir notre lien de parenté dans l'arbre"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
          </svg>
          Lien de parenté
        </Link>
        <button
          v-if="!isFriends"
          @click="playLifeSlideshow"
          class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/90 backdrop-blur text-sm font-semibold text-surface-900 shadow-warm-md hover:bg-white transition"
        >
          <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
          Diaporama de sa vie
        </button>
        <div class="relative" ref="actionsMenuRef">
          <button
            @click="showActionsMenu = !showActionsMenu"
            class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/90 backdrop-blur text-surface-900 shadow-warm-md hover:bg-white transition"
            title="Plus d'actions"
            aria-label="Plus d'actions"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <circle cx="12" cy="5" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="19" r="1.6" />
            </svg>
          </button>
          <div v-show="showActionsMenu" class="dropdown top-full w-56">
            <button v-if="canSetSelf || isSelf" @click="menuAction(toggleSelf)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4" :class="isSelf ? 'text-brand-600' : 'text-surface-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
              {{ isSelf ? "C'est moi ✓ (retirer)" : "C'est moi" }}
            </button>
            <button v-if="canManage" @click="menuAction(() => showEditModal = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
              Modifier la fiche
            </button>
            <button v-if="canManage && media.length > 0" @click="menuAction(() => showAvatarPicker = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              Choisir un avatar
            </button>
            <button v-if="hasCircles" @click="menuAction(() => showCircles = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <CircleKindIcon kind="friends" class="w-4 h-4 text-surface-400" />
              Cercles…
            </button>
            <template v-if="canDelete">
              <hr class="dropdown-divider" />
              <button @click="menuAction(deletePerson)" class="dropdown-item w-full text-left flex items-center gap-2.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                Supprimer
              </button>
            </template>
          </div>
        </div>
      </div>

      <div class="flex flex-col items-center text-center sm:flex-row sm:items-center sm:text-left gap-3 sm:gap-7 px-4 sm:px-8 pt-14 sm:pt-9 pb-5 sm:pb-8">
        <Link
          href="/people"
          class="hidden sm:inline-flex items-center justify-center w-11 h-11 rounded-full bg-white border border-surface-200 text-surface-600 shadow-warm-sm hover:bg-surface-50 transition shrink-0"
          :title="`Retour aux ${peopleLabel.toLowerCase()}`"
          :aria-label="`Retour aux ${peopleLabel.toLowerCase()}`"
        >
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>

        <!-- Avatar (clic = choisir parmi les photos) -->
        <div class="relative group shrink-0">
          <PersonAvatar
            :person="person"
            :alt="person.name"
            eager
            class="w-28 h-28 sm:w-32 sm:h-32 bg-brand-100 border-4 border-white shadow-warm-md"
            fallback-class="text-5xl font-bold text-brand-700"
          />
          <button
            v-if="canManage && media.length > 0"
            @click="showAvatarPicker = true"
            class="absolute inset-0 rounded-full bg-black/0 group-hover:bg-black/40 flex items-center justify-center transition-all"
            title="Changer l'avatar"
          >
            <svg class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </button>
        </div>

        <div class="min-w-0">
          <div class="hidden sm:block text-xs font-semibold uppercase tracking-widest text-brand-700">{{ peopleLabel }}</div>
          <h1 class="font-display text-[26px] sm:text-[42px] font-bold text-surface-900 leading-tight">
            {{ personLabel(person) }}
            <span v-if="person.gender === 'M'" class="text-surface-400 text-2xl align-middle" title="Masculin">&#9794;</span>
            <span v-else-if="person.gender === 'F'" class="text-surface-400 text-2xl align-middle" title="Féminin">&#9792;</span>
          </h1>
          <div class="mt-1.5 flex flex-wrap items-center justify-center sm:justify-start gap-x-3 gap-y-1 text-sm text-surface-500">
            <span v-if="relationLabel" class="font-semibold text-brand-700 dark:text-brand-300">{{ capitalize(relationLabel) }}</span>
            <span v-if="relationLabel && person.birth_date">·</span>
            <span v-if="person.birth_date">
              {{ formatLongDate(person.birth_date) }}{{ person.death_date ? ' – ' + formatLongDate(person.death_date) : '' }}
            </span>
            <span v-if="person.birth_date">·</span>
            <span>{{ person.media_count || 0 }} {{ (person.media_count || 0) === 1 ? 'média' : 'médias' }}</span>
            <span
              v-if="isSelf"
              class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-brand-100 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 text-xs font-medium"
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
              C'est moi
            </span>
          </div>
          <!-- Adresse : présente dans les props uniquement si le serveur
               l'autorise (propriétaire/admin, ou foyer avec opt-in) -->
          <div v-if="person.address" class="mt-1.5 flex items-center justify-center sm:justify-start gap-1.5 text-sm text-surface-500">
            <svg class="w-4 h-4 shrink-0 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            <span>{{ person.address }}</span>
          </div>
          <p v-if="person.notes" class="mt-2 text-sm text-surface-600 whitespace-pre-wrap max-w-2xl">
            {{ person.notes }}
          </p>
          <!-- Compte MemoryLane : présent, invitation en cours, ou bouton d'invitation -->
          <div v-if="account.hasAccount" class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            Sur MemoryLane
          </div>
          <div v-else-if="account.pendingInvitation" class="mt-3 inline-flex items-center gap-1.5 text-xs text-surface-500">
            <svg class="w-4 h-4 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
            Invitation à MemoryLane envoyée{{ account.pendingInvitation.inviter ? ' par ' + account.pendingInvitation.inviter : '' }}{{ account.pendingInvitation.sent_at ? ' le ' + formatLongDate(account.pendingInvitation.sent_at) : '' }}
          </div>
          <button
            v-else-if="account.canInvite"
            type="button"
            @click="showInviteModal = true"
            class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-full bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-warm-sm transition"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
            Inviter sur MemoryLane
          </button>
        </div>
      </div>
    </div>

    <!-- Téléphone : actions sous le pouce — Sa vie (diaporama) · Parenté/Réseau · Photos -->
    <div class="sm:hidden px-4 pt-3 grid gap-2" :class="mobileActions.length === 3 ? 'grid-cols-3' : mobileActions.length === 2 ? 'grid-cols-2' : 'grid-cols-1'">
      <component
        :is="a.href ? Link : 'button'"
        v-for="a in mobileActions"
        :key="a.label"
        :href="a.href"
        :type="a.href ? undefined : 'button'"
        class="h-12 rounded-2xl border text-sm font-bold flex items-center justify-center gap-1.5"
        :class="a.primary ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white border-surface-300 text-surface-900'"
        @click="a.onClick && a.onClick()"
      >
        <svg v-if="a.icon === 'play'" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
        <svg v-else-if="a.icon === 'tree'" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="2.5" /><circle cx="6" cy="19" r="2.5" /><circle cx="18" cy="19" r="2.5" /><path d="M12 7.5V12M12 12l-6 4.5M12 12l6 4.5" /></svg>
        <svg v-else-if="a.icon === 'network'" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" /><circle cx="5" cy="6" r="2" /><circle cx="19" cy="6" r="2" /><circle cx="5" cy="18" r="2" /><circle cx="19" cy="18" r="2" /><path d="M9.5 10.5L6.5 7.5M14.5 10.5l3-3M9.5 13.5l-3 3M14.5 13.5l3 3" /></svg>
        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3" /><path d="M3 16l5-5 4 4 3-3 6 6" /></svg>
        {{ a.label }}
      </component>
    </div>

    <!-- ================= Mode amis : la rencontre, à la place du mini-arbre ================= -->
    <section v-if="isFriends && viewerPersonId && !isSelf" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-8">
      <div class="bg-white border border-surface-200 rounded-2xl p-5 sm:p-6 grid gap-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
        <HowWeMetPanel :person-id="person.id" />
        <Link href="/network" class="btn-secondary self-start">Voir dans le réseau</Link>
      </div>
    </section>

    <!-- ================= Famille (mini-arbre) ================= -->
    <FamilyPanel
      v-if="!isFriends"
      data-tour="family-panel"
      :person="person"
      :father="father"
      :mother="mother"
      :spouses="spouses"
      :children="children"
      :siblings="siblings"
      :can-manage="canManage"
    />

    <!-- ================= Sa vie (frise horizontale) ================= -->
    <!-- Amis : « Vos souvenirs ensemble » à la place de la frise de vie -->
    <FriendshipTimeline v-if="isFriends" :person-id="person.id" :person-name="personLabel(person)" />
    <PersonTimeline v-else ref="timelineRef" :person-id="person.id" :person-name="personLabel(person)" :can-manage="canManage" />

    <!-- ================= Galeries, séparées par album puis par année ================= -->
    <div v-if="media.length > 0" id="photos" class="pt-7 sm:pt-9 pb-12 scroll-mt-16">
      <div class="flex items-center justify-between px-4 sm:px-8 mb-2">
        <h2 class="font-display text-xl sm:text-2xl font-semibold text-surface-900">Ses photos</h2>
        <span class="text-sm text-surface-400">{{ media.length }} médias visibles</span>
      </div>

      <!-- Une section par album -->
      <div
        v-for="album in mediaGroups.albums"
        :key="'album-' + album.id"
        class="pt-5"
      >
        <div class="flex items-baseline gap-2.5 px-4 sm:px-8 mb-3">
          <svg class="w-4.5 h-4.5 text-brand-600 shrink-0 self-center" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V6z" />
          </svg>
          <span class="text-base font-semibold text-surface-900">{{ album.name }}</span>
          <span class="text-sm text-surface-400">{{ album.media_ids.length }}</span>
          <Link :href="`/albums/${album.id}`" class="ml-auto text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline shrink-0">
            Voir l'album →
          </Link>
        </div>
        <div class="grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-8 gap-1 px-1">
          <MediaCard
            v-for="id in album.media_ids"
            :key="id"
            :media="mediaById[id]"
            fill
            class="aspect-square"
            @click="goToMedia(mediaById[id])"
          />
        </div>
        <div class="border-t border-surface-200/70 mx-4 sm:mx-8 mt-6"></div>
      </div>

      <!-- Hors album, regroupé par année -->
      <div
        v-for="group in mediaGroups.by_year"
        :key="'year-' + (group.year ?? 'none')"
        class="pt-5"
      >
        <div class="flex items-baseline gap-2.5 px-4 sm:px-8 mb-3">
          <span class="text-sm font-semibold uppercase tracking-wider text-surface-500">{{ group.year ?? 'Sans date' }}</span>
          <span class="text-sm text-surface-400">{{ group.media_ids.length }}</span>
        </div>
        <div class="grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-8 gap-1 px-1">
          <MediaCard
            v-for="id in group.media_ids"
            :key="id"
            :media="mediaById[id]"
            fill
            class="aspect-square"
            @click="goToMedia(mediaById[id])"
          />
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="text-center py-12 bg-white rounded-lg shadow-xs">
        <svg class="mx-auto h-12 w-12 text-surface-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <h3 class="mt-4 text-lg font-medium text-surface-900">
          {{ canDelete ? 'Aucun média' : 'Aucune photo partagée avec vous' }}
        </h3>
        <p class="mt-2 text-surface-500">
          {{ canDelete
            ? "Cette personne n'apparaît sur aucun média pour le moment."
            : "Les photos de cette personne ne vous sont pas accessibles. Elles apparaîtront ici lorsqu'elles seront partagées avec vous." }}
        </p>
      </div>
    </div>

    <InviteToMemoryLaneModal
      v-if="showInviteModal"
      :person="person"
      @close="showInviteModal = false"
      @sent="onInvited"
    />

    <!-- Edit Modal -->
    <BaseModal v-if="showCircles" :title="`Cercles de ${personLabel(person)}`" max-width="md" @close="showCircles = false">
      <div class="px-6 py-5">
        <PersonCircles :person-id="person.id" :person-name="personLabel(person)" @changed="router.reload({ preserveScroll: true })" />
      </div>
    </BaseModal>

    <PersonFormModal
      v-if="showEditModal"
      :person="person"
      @close="showEditModal = false"
      @updated="handlePersonUpdated"
    />

    <!-- Avatar Picker Modal -->
    <BaseModal
      v-if="showAvatarPicker"
      title="Choisir un avatar"
      max-width="2xl"
      @close="showAvatarPicker = false"
    >
      <div class="p-6 grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-3 max-h-96 overflow-y-auto">
        <button
          v-for="item in photoMedia"
          :key="item.id"
          @click="setAvatar(item.id)"
          class="aspect-square rounded-lg overflow-hidden border-2 hover:border-brand-500 transition-colors"
          :class="person.avatar_media_id === item.id ? 'border-brand-500 ring-2 ring-brand-300' : 'border-surface-200'"
        >
          <img
            :src="conversionUrl(item, 'thumbnail') || item.url"
            class="w-full h-full object-cover"
            loading="lazy"
            decoding="async"
          />
        </button>
      </div>
      <template #footer>
        <!-- mr-auto : pousse l'action destructive à gauche du pied justify-end -->
        <button
          v-if="person.avatar_media_id"
          @click="removeAvatar"
          class="mr-auto px-4 py-2 text-sm font-medium text-red-600 dark:text-red-400 bg-white border border-red-300 dark:border-red-500/40 rounded-lg hover:bg-red-50 dark:hover:bg-red-500/10"
        >
          Supprimer l'avatar
        </button>
        <button @click="showAvatarPicker = false" class="btn-secondary">
          Fermer
        </button>
      </template>
    </BaseModal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import MediaCard from '@/Components/MediaCard.vue';
import { usePhotoSwipe } from '@/Composables/usePhotoSwipe';
import PersonFormModal from '@/Components/PersonFormModal.vue';
import FamilyPanel from '@/Components/FamilyPanel.vue';
import HowWeMetPanel from '@/Components/HowWeMetPanel.vue';
import PersonCircles from '@/Components/PersonCircles.vue';
import FriendshipTimeline from '@/Components/FriendshipTimeline.vue';
import CircleKindIcon from '@/Components/CircleKindIcon.vue';
import { useCircle } from '@/Composables/useCircle';
import PersonTimeline from '@/Components/PersonTimeline.vue';
import InviteToMemoryLaneModal from '@/Components/InviteToMemoryLaneModal.vue';
import { personLabel } from '@/utils/personName';
import { formatLongDate } from '@/utils/format';
import { useToast } from '@/Composables/useToast';
import { useConfirm } from '@/Composables/useConfirm';
import { apiError } from '@/utils/apiError';
import { conversionUrl } from '@/utils/media';

const toast = useToast();
const { confirm } = useConfirm();
const { isFriends, peopleLabel } = useCircle();

const props = defineProps({
  person: {
    type: Object,
    required: true,
  },
  media: {
    type: Array,
    default: () => [],
  },
  mediaGroups: {
    type: Object,
    default: () => ({ albums: [], by_year: [] }),
  },
  father: {
    type: Object,
    default: null,
  },
  mother: {
    type: Object,
    default: null,
  },
  spouses: {
    type: Array,
    default: () => [],
  },
  children: {
    type: Array,
    default: () => [],
  },
  siblings: {
    type: Array,
    default: () => [],
  },
  isSelf: {
    type: Boolean,
    default: false,
  },
  canManage: {
    type: Boolean,
    default: false,
  },
  // Créateur de la fiche ou admin : suppression, « c'est moi ».
  canDelete: {
    type: Boolean,
    default: false,
  },
  canSetSelf: {
    type: Boolean,
    default: false,
  },
  account: {
    type: Object,
    default: () => ({ hasAccount: false, pendingInvitation: null, canInvite: false }),
  },
  // Parenté avec le visiteur (« votre fille »), null hors famille / sans fiche « moi ».
  relationLabel: {
    type: String,
    default: null,
  },
});

const capitalize = (t) => t.charAt(0).toUpperCase() + t.slice(1);

const showInviteModal = ref(false);
const onInvited = () => {
  showInviteModal.value = false;
  toast.success('Invitation envoyée !');
  router.reload({ only: ['account'] });
};

// Fiche « moi » du visiteur : conditionne le bouton « Lien de parenté ».
const viewerPersonId = computed(() => usePage().props.auth?.user?.person_id ?? null);

const toggleSelf = () => {
  router.post(`/people/${props.person.id}/set-self`, {}, { preserveScroll: true });
};

const showEditModal = ref(false);
const showCircles = ref(false);
// Menu « Cercles… » : seulement pour un compte qui a des cercles.
const hasCircles = computed(() => (usePage().props.circle?.list?.length ?? 0) > 0 || usePage().props.circle?.can_manage);
const showAvatarPicker = ref(false);

// Le diaporama vit dans PersonTimeline (il connaît les slides) ; le bouton
// d'en-tête le déclenche via la ref.
const timelineRef = ref(null);
const playLifeSlideshow = () => timelineRef.value?.play();

// Barre d'actions téléphone : Sa vie (famille) · Parenté / Réseau · Photos.
const scrollToPhotos = () => document.getElementById('photos')?.scrollIntoView({ behavior: 'smooth' });
const mobileActions = computed(() => {
  const list = [];
  if (!isFriends.value) list.push({ label: 'Sa vie', icon: 'play', primary: true, onClick: playLifeSlideshow });
  if (isFriends.value) list.push({ label: 'Réseau', icon: 'network', href: `/network?focus=${props.person.id}` });
  else if (viewerPersonId.value && viewerPersonId.value !== props.person.id) list.push({ label: 'Parenté', icon: 'tree', href: `/family-tree?kinship=${props.person.id}` });
  if (props.media.length > 0) list.push({ label: 'Photos', icon: 'photos', primary: isFriends.value, onClick: scrollToPhotos });
  return list;
});

// Menu ⋯ flottant (même pattern que la page album).
const showActionsMenu = ref(false);
const actionsMenuRef = ref(null);

const closeMenuOnOutsideClick = (event) => {
  if (showActionsMenu.value && actionsMenuRef.value && !actionsMenuRef.value.contains(event.target)) {
    showActionsMenu.value = false;
  }
};
onMounted(() => document.addEventListener('click', closeMenuOnOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', closeMenuOnOutsideClick));

const menuAction = (action) => {
  showActionsMenu.value = false;
  action();
};

// Index id -> média : les sections ne portent que des IDs, on résout ici.
const mediaById = computed(() => {
  const map = {};
  for (const m of props.media) map[m.id] = m;
  return map;
});

const photoMedia = computed(() => props.media.filter((m) => m.type === 'photo'));

const setAvatar = async (mediaId) => {
  try {
    await axios.put(`/people/${props.person.id}`, {
      name: props.person.name,
      avatar_media_id: mediaId,
    });
    showAvatarPicker.value = false;
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || "Erreur lors du changement d'avatar.");
  }
};

const removeAvatar = async () => {
  try {
    await axios.put(`/people/${props.person.id}`, {
      name: props.person.name,
      avatar_media_id: null,
    });
    showAvatarPicker.value = false;
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || "Erreur lors de la suppression de l'avatar.");
  }
};

// Photos et vidéos s'ouvrent dans la visionneuse (bouton « i » vers la
// fiche) ; les documents vont directement sur leur page.
const { open: openLightbox } = usePhotoSwipe(() => props.media, {
  watchSource: () => props.media,
  detailLink: true,
});

const goToMedia = (media) => {
  if (!openLightbox(media)) {
    router.visit(`/media/${media.id}`);
  }
};

const handlePersonUpdated = () => {
  router.reload();
};

const deletePerson = async () => {
  const ok = await confirm({
    title: 'Supprimer cette fiche',
    message: `Êtes-vous sûr de vouloir supprimer ${props.person.name} ? Cette action ne supprimera pas les médias.`,
    confirmLabel: 'Supprimer',
    danger: true,
  });
  if (!ok) return;

  try {
    await axios.delete(`/people/${props.person.id}`);
    router.visit('/people');
  } catch (error) {
    toast.error(apiError(error, 'Erreur lors de la suppression de la personne.'));
  }
};
</script>
