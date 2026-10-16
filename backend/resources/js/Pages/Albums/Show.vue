<template>
  <Head :title="album.name" />
  <AppLayout>
    <!-- ================= Héro pleine largeur =================
         Fond : photo ou vidéo de l'album choisie (« Fond du titre ») ; sinon
         carte centrée sur la géolocalisation de l'album ; à défaut la photo
         de couverture ; à défaut un dégradé brand. Titre blanc sur voile
         sombre (photo, vidéo), noir sur voile clair (carte, dégradé). -->
    <div class="relative" :class="album.hero ? 'h-64 sm:h-[420px]' : 'h-56 sm:h-[340px]'">
      <template v-if="album.hero">
        <!-- Vidéo : extrait muet en boucle ; image fixe s'il n'est pas prêt
             ou si l'appareil demande moins d'animations / de données. -->
        <video
          v-if="album.hero.type === 'video' && album.hero.src && heroVideoPlays"
          class="absolute inset-0 w-full h-full object-cover"
          :src="album.hero.src"
          :poster="album.hero.poster"
          autoplay
          muted
          loop
          playsinline
          disablepictureinpicture
          aria-hidden="true"
        ></video>
        <div
          v-else
          class="absolute inset-0 bg-cover bg-center"
          :style="{ backgroundImage: `url(${album.hero.type === 'video' ? album.hero.poster : album.hero.src})` }"
        ></div>
      </template>
      <div v-else-if="album.hero_location" ref="heroMapEl" class="absolute inset-0 z-0"></div>
      <div
        v-else-if="album.cover_url"
        class="absolute inset-0 bg-cover bg-center"
        :style="{ backgroundImage: `url(${album.cover_url})` }"
      ></div>
      <div v-else class="absolute inset-0 bg-linear-to-br from-brand-200 via-brand-50 to-brand-100"></div>

      <!-- Voile de lisibilité (plus dense en bas, sous le titre) -->
      <div
        class="absolute inset-0 z-10 pointer-events-none bg-linear-to-b"
        :class="heroLight ? 'from-snow/10 via-transparent to-snow/75' : 'from-black/30 via-black/5 to-black/75'"
      ></div>

      <!-- Retour (téléphone : en haut à gauche, comme une app) -->
      <Link
        href="/albums"
        class="sm:hidden absolute top-3 left-4 z-20 inline-flex items-center justify-center w-10 h-10 rounded-full bg-snow/90 backdrop-blur text-ink shadow-warm-md"
        aria-label="Retour aux albums"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 19l-7-7 7-7" /></svg>
      </Link>

      <!-- Actions flottantes (téléphone : partage et ⋯ seulement, le
           diaporama vit dans la barre sous le héro) -->
      <div class="absolute top-3 right-4 sm:top-5 sm:right-8 z-20 flex items-center gap-2.5">
        <button
          v-if="hasPlayableMedia"
          @click="startSlideshow"
          class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-full bg-snow/90 backdrop-blur text-sm font-semibold text-ink shadow-warm-md hover:bg-snow transition"
        >
          <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
          Diaporama
        </button>
        <button
          v-if="isOwner"
          @click="showSharePanel = !showSharePanel"
          class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-snow/90 backdrop-blur text-ink shadow-warm-md hover:bg-snow transition"
          title="Partager"
          aria-label="Partager"
        >
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
        </button>
        <div v-if="album.media && album.media.length > 0" class="relative" ref="actionsMenuRef">
          <button
            @click="showActionsMenu = !showActionsMenu"
            class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-snow/90 backdrop-blur text-ink shadow-warm-md hover:bg-snow transition"
            title="Plus d'actions"
            aria-label="Plus d'actions"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <circle cx="12" cy="5" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="19" r="1.6" />
            </svg>
          </button>
          <div v-show="showActionsMenu" class="dropdown top-full w-56">
            <button v-if="isOwner" @click="openFromMenu(() => showAddMediaModal = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
              Ajouter des médias
            </button>
            <button @click="openFromMenu(toggleSelectionMode)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
              {{ selectionMode ? 'Terminer la sélection' : 'Sélectionner' }}
            </button>
            <button v-if="isOwner && album.hero" @click="openFromMenu(() => setHero(null))" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
              Fond du titre par défaut
            </button>
            <button @click="openFromMenu(() => showParticipants = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              Qui était là ?
            </button>
            <button v-if="isOwner" @click="openFromMenu(() => showGeolocateModal = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              Géolocaliser
            </button>
            <button v-if="isOwner" @click="openFromMenu(() => showEditModal = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
              Modifier
            </button>
            <template v-if="isOwner">
              <hr class="dropdown-divider" />
              <button @click="openFromMenu(deleteAlbum)" class="dropdown-item w-full text-left flex items-center gap-2.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                Supprimer l'album
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Titre + retour (bas gauche) -->
      <div class="absolute left-4 right-4 sm:left-8 sm:right-8 bottom-4 sm:bottom-6 z-20 flex items-center gap-4">
        <Link
          href="/albums"
          class="hidden sm:inline-flex items-center justify-center w-11 h-11 rounded-full border backdrop-blur-sm transition shrink-0"
          :class="heroLight ? 'bg-snow/70 border-ink/20 text-ink hover:bg-snow' : 'bg-snow/15 border-snow/35 text-snow hover:bg-snow/30'"
          title="Retour aux albums"
          aria-label="Retour aux albums"
        >
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div class="min-w-0">
          <div class="text-xs font-medium uppercase tracking-widest" :class="heroLight ? 'text-ink/65' : 'text-snow/75'">Albums</div>
          <h1
            class="font-display text-[28px] leading-tight sm:text-5xl font-bold truncate"
            :class="heroLight ? 'text-ink [text-shadow:0_1px_10px_rgba(255,255,255,0.8)]' : 'text-snow [text-shadow:0_2px_12px_rgba(23,20,15,0.55)]'"
          >
            {{ album.name }}
          </h1>
          <div class="mt-1.5 flex items-center gap-3 text-sm" :class="heroLight ? 'text-ink/80' : 'text-snow/85'">
            <span>{{ album.media_count || 0 }} médias</span>
            <span
              class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border"
              :class="heroLight ? 'bg-snow/70 border-ink/20 text-ink' : 'bg-snow/20 border-snow/30 text-snow'"
            >
              {{ album.is_owner ? 'Mon album' : 'Partagé avec moi' }}
            </span>
            <span v-if="album.description" class="hidden sm:inline truncate">{{ album.description }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Téléphone : actions sous le pouce (diaporama, visages à identifier,
         sélection) puis la carte « Qui était là ? » -->
    <div v-if="album.media && album.media.length > 0" class="sm:hidden px-4 pt-3 pb-1 flex flex-col gap-3">
      <div class="grid gap-2" :class="pendingFacesCount > 0 ? 'grid-cols-3' : 'grid-cols-2'">
        <button
          v-if="hasPlayableMedia"
          type="button"
          class="h-12 rounded-2xl bg-brand-600 text-white text-sm font-bold flex items-center justify-center gap-1.5"
          @click="startSlideshow"
        >
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
          Diaporama
        </button>
        <button
          v-if="pendingFacesCount > 0"
          type="button"
          class="h-12 rounded-2xl border text-sm font-bold flex items-center justify-center gap-1.5"
          :class="unidentifiedOnly ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white border-surface-300 text-surface-900'"
          :aria-pressed="unidentifiedOnly"
          @click="unidentifiedOnly = !unidentifiedOnly"
        >
          <span class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-full text-[11px] font-bold" :class="unidentifiedOnly ? 'bg-white/25' : 'bg-brand-100 text-brand-800'">{{ pendingFacesCount > 999 ? '999+' : pendingFacesCount }}</span>
          <span class="text-[13px] whitespace-nowrap">Qui est-ce ?</span>
        </button>
        <button
          type="button"
          class="h-12 rounded-2xl border text-sm font-bold flex items-center justify-center gap-1.5"
          :class="selectionMode ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white border-surface-300 text-surface-900'"
          :aria-pressed="selectionMode"
          @click="toggleSelectionMode"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3" /><path d="M8 12l3 3 5-6" /></svg>
          {{ selectionMode ? 'Terminer' : 'Sélection' }}
        </button>
      </div>

      <button
        v-if="participantsPreview.length || isOwner"
        type="button"
        class="w-full flex items-center gap-3 p-3 rounded-2xl bg-white border border-surface-200 text-left"
        @click="showParticipants = true"
      >
        <div v-if="participantsPreview.length" class="flex -space-x-2 shrink-0">
          <PersonAvatar
            v-for="p in participantsPreview.slice(0, 4)"
            :key="p.id"
            :name="p.name"
            :size="8"
            class="ring-2 ring-white dark:ring-surface-900"
          />
          <span v-if="participantsPreview.length > 4" class="w-8 h-8 rounded-full bg-surface-200 text-surface-700 text-[11px] font-bold flex items-center justify-center ring-2 ring-white dark:ring-surface-900">
            +{{ participantsPreview.length - 4 }}
          </span>
        </div>
        <span v-else class="w-10 h-10 rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5" /><circle cx="17" cy="10" r="2.5" /><path d="M3 19a6 6 0 0112 0M15 19a4 4 0 016 0" /></svg>
        </span>
        <span class="flex-1 min-w-0">
          <span class="block text-[15px] font-bold text-surface-900">Qui était là ?</span>
          <span class="block text-xs text-surface-500 truncate">
            <template v-if="participantsPreview.length">{{ participantsPreview.length }} participant{{ participantsPreview.length > 1 ? 's' : '' }} · les inconnus sont triés</template>
            <template v-else>Dites qui était présent : les visages inconnus seront triés</template>
          </span>
        </span>
        <span class="text-surface-400">›</span>
      </button>
    </div>

    <!-- Bandeau du mode sélection (téléphone : fixé en bas, sous le pouce ;
         desktop : collant sous la nav) -->
    <div
      v-if="selectionMode"
      class="fixed inset-x-0 bottom-0 z-50 pb-[env(safe-area-inset-bottom)] sm:pb-3 sm:static sm:sticky sm:top-16 sm:z-30 sm:inset-auto
             flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2 sm:gap-3 bg-brand-600 px-4 sm:px-8 py-3 text-white shadow-md"
    >
      <span class="font-medium">
        {{ selectedMediaIds.length }} sélectionné{{ selectedMediaIds.length > 1 ? 's' : '' }}
      </span>
      <div class="flex flex-nowrap sm:flex-wrap items-center gap-2 sm:ml-auto overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 sm:overflow-visible [&>button]:shrink-0 [&>button]:whitespace-nowrap">
        <button
          v-if="selectedMediaIds.length === 1"
          @click="editSelected"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition"
        >
          Éditer
        </button>
        <button
          v-if="selectedMediaIds.length === 1 && isOwner"
          @click="setAsCover"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition"
        >
          Couverture
        </button>
        <button
          v-if="isOwner && selectedHeroCandidate"
          @click="chooseHero(selectedHeroCandidate)"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition"
        >
          Fond du titre
        </button>
        <button
          v-if="isOwner && selectedToPin.length > 0"
          @click="pinSelected(true)"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition"
          title="En tête de l'album, dans l'ordre où vous les avez cochées"
        >
          ★ À la une ({{ selectedToPin.length }})
        </button>
        <button
          v-if="isOwner && selectedPinned.length > 0"
          @click="pinSelected(false)"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition"
        >
          Retirer de la une ({{ selectedPinned.length }})
        </button>
        <button
          v-if="removableSelectedIds.length > 0"
          @click="removeSelectedMedia"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-red-600 hover:bg-red-50 transition"
        >
          Retirer ({{ removableSelectedIds.length }})
        </button>
        <button
          @click="toggleSelectionMode"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium bg-white/15 hover:bg-white/25 transition"
        >
          Terminer
        </button>
      </div>
    </div>

    <!-- Share Panel -->
    <div v-if="showSharePanel" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
      <SharePanel
        :album="album"
        :is-owner="album.is_owner !== false"
        @updated="handleAlbumUpdated"
      />
    </div>

    <!-- Diaporama plein écran -->
    <FullscreenSlideshow
      ref="slideshowEl"
      :slides="slideshowSlides"
      :shuffle="!travels"
      :travel="travels"
      ken-burns
    />

    <!-- Visages à identifier : seules ces photos -->
    <div
      v-if="unidentifiedOnly"
      class="flex items-center gap-3 px-4 sm:px-8 py-3 bg-surface-50 border-b border-surface-200"
    >
      <span class="text-sm text-surface-800">
        <strong>{{ albumMedia.length }}</strong> photo{{ albumMedia.length > 1 ? 's' : '' }} avec des visages à identifier · touchez-en une pour nommer
      </span>
      <button type="button" class="ml-auto text-sm font-medium text-brand-700 hover:text-brand-900 shrink-0" @click="unidentifiedOnly = false">
        Tout l’album
      </button>
    </div>

    <!-- Étape choisie sur la carte-trajet : seuls ses médias -->
    <div
      v-if="placeFilter"
      class="flex flex-wrap items-center gap-3 px-4 sm:px-8 py-3 bg-surface-50 border-b border-surface-200"
    >
      <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-amber-500 text-sm font-bold text-snow">{{ placeFilter.index }}</span>
      <span class="text-sm text-surface-800">
        Étape {{ placeFilter.index }} · <strong>{{ placeFilter.label }}</strong>
        <span class="text-surface-500">({{ placeFilter.media_ids.length }} média{{ placeFilter.media_ids.length > 1 ? 's' : '' }})</span>
      </span>
      <button type="button" class="ml-auto text-sm font-medium text-brand-700 hover:text-brand-900" @click="placeFilter = null">
        Tout l’album
      </button>
    </div>

    <!-- ================= Galerie mosaïque pleine largeur ================= -->
    <div
      v-if="album.media && album.media.length > 0"
      id="album-grid"
      class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 auto-rows-[124px] sm:auto-rows-[200px] xl:auto-rows-[230px] grid-flow-dense gap-0.5 sm:gap-1 p-0.5 sm:p-1 mt-2 sm:mt-0"
    >
      <div
        v-for="(media, index) in albumMedia"
        :key="media.id"
        class="relative"
        :class="mosaicSpan(index)"
      >
        <span
          v-if="isOwner && isPinned(media)"
          class="absolute top-1.5 right-1.5 z-10 pointer-events-none rounded-full bg-black/55 px-1.5 text-xs text-amber-300"
          title="À la une"
        >★</span>
        <MediaCard
          :media="media"
          fill
          :selectable="selectionMode"
          :is-selected="isSelected(media.id)"
          @click="(m, event) => handleMediaClick(media, index, event)"
          @toggle-selection="(m, event) => applySelection(media, index, event)"
        />
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="text-center py-16 bg-white rounded-lg shadow-xs">
        <svg class="mx-auto h-16 w-16 text-surface-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <h3 class="mt-4 text-lg font-medium text-surface-900">Aucun média</h3>
        <p class="mt-2 text-surface-500">Ajoutez des photos et vidéos à cet album.</p>
        <button
          v-if="isOwner"
          @click="showAddMediaModal = true"
          class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 dark:bg-brand-500/10 rounded-lg hover:bg-brand-100 dark:hover:bg-brand-500/20"
        >
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Ajouter des médias
        </button>
      </div>
    </div>

    <!-- Fond du titre : début de l'extrait vidéo -->
    <BaseModal v-if="heroVideoChoice" title="Vidéo en fond du titre" max-width="md" @close="heroVideoChoice = null">
      <div class="px-6 py-5 space-y-3">
        <p class="text-sm text-surface-600">
          Un extrait muet d’environ 25 secondes tournera en boucle derrière le titre. À partir de quel moment de la vidéo ?
        </p>
        <label class="block text-sm font-medium text-surface-800">
          Début (en secondes)
          <input
            v-model.number="heroVideoStart"
            type="number"
            min="0"
            :max="heroVideoChoice.duration ? Math.floor(heroVideoChoice.duration) - 1 : undefined"
            class="mt-1 w-32 rounded-lg border-surface-300 text-sm"
          />
        </label>
        <p v-if="heroVideoChoice.duration" class="text-xs text-surface-500">La vidéo dure {{ formatDuration(heroVideoChoice.duration) }}.</p>
      </div>
      <template #footer>
        <button class="px-4 py-2 text-sm rounded-lg text-surface-700 hover:bg-surface-100" @click="heroVideoChoice = null">Annuler</button>
        <button class="px-4 py-2 text-sm font-semibold rounded-lg bg-brand-600 text-white hover:bg-brand-700" @click="setHero(heroVideoChoice, heroVideoStart)">
          Utiliser cet extrait
        </button>
      </template>
    </BaseModal>

    <!-- Modals -->
    <AlbumFormModal
      v-if="showEditModal"
      :album="album"
      @close="showEditModal = false"
      @saved="handleAlbumUpdated"
    />

    <MediaPickerModal
      v-if="showAddMediaModal"
      :album-id="album.id"
      :exclude-media-ids="albumMediaIds"
      @close="showAddMediaModal = false"
      @added="handleMediaAdded"
    />

    <GeolocatePickerModal
      v-if="showGeolocateModal"
      :title="`Géolocaliser « ${album.name} »`"
      description="Cliquez sur la carte pour situer toutes les photos de cet album. Pratique quand la localisation d'origine a été perdue à l'import."
      apply-label="Appliquer à l'album"
      :saving="geolocating"
      :error-message="geolocateError"
      @close="showGeolocateModal = false"
      @apply="applyAlbumGeolocation"
    />
      <AlbumParticipantsModal
      v-if="showParticipants"
      :album-id="album.id"
      :album-name="album.name"
      @close="showParticipants = false"
      @saved="router.reload({ only: ['album'] })"
    />
  </AppLayout>
</template>

<script setup>
import { escapeHtml } from '@/utils/html';
import { ref, computed, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import MediaCard from '@/Components/MediaCard.vue';
import AlbumFormModal from '@/Components/AlbumFormModal.vue';
import SharePanel from '@/Components/SharePanel.vue';
import MediaPickerModal from '@/Components/MediaPickerModal.vue';
import FullscreenSlideshow from '@/Components/FullscreenSlideshow.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { formatDuration } from '@/utils/format';
import { usePhotoSwipe } from '@/Composables/usePhotoSwipe';
import { useToast } from '@/Composables/useToast';
import { useConfirm } from '@/Composables/useConfirm';
import { slideUrl } from '@/utils/media';
import AlbumParticipantsModal from '@/Components/AlbumParticipantsModal.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';

// Chargé à l'ouverture seulement : la carte embarque Leaflet (~45 ko gzip).
const GeolocatePickerModal = defineAsyncComponent(() => import('@/Components/GeolocatePickerModal.vue'));

const props = defineProps({
  album: {
    type: Object,
    required: true,
  },
});

const toast = useToast();
const { confirm } = useConfirm();

const showSharePanel = ref(false);

// ---- Héro carte : fond Leaflet statique centré sur l'album --------------
// Leaflet (JS + CSS) n'est chargé que pour un album géolocalisé.
const heroMapEl = ref(null);
const heroVideoPlays = !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
  && !navigator.connection?.saveData;
let heroMap = null;
let unmounted = false;

onMounted(async () => {
  if (!props.album.hero_location || !heroMapEl.value) return;
  let L;
  try {
    [L] = await Promise.all([
      import('leaflet').then((m) => m.default),
      import('leaflet/dist/leaflet.css'),
    ]);
  } catch (e) {
    // Fond décoratif : sans la carte, le héros reste lisible (voile + titre).
    console.error('Chargement de la carte impossible :', e);
    return;
  }
  // La page a pu être quittée pendant le chargement.
  if (unmounted || !heroMapEl.value) return;
  const { latitude, longitude, count } = props.album.hero_location;

  // Carte décorative : toutes les interactions sont coupées (le fond ne doit
  // pas voler le scroll de la page).
  heroMap = L.map(heroMapEl.value, {
    zoomControl: false,
    dragging: false,
    scrollWheelZoom: false,
    doubleClickZoom: false,
    boxZoom: false,
    keyboard: false,
    touchZoom: false,
  }).setView([latitude, longitude], 11);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap',
  }).addTo(heroMap);

  // Album à plusieurs étapes : carte-trajet. Toutes les étapes numérotées,
  // reliées en pointillé dans l'ordre ; un clic montre les médias d'une étape.
  if (travels.value) {
    const pts = places.value.map((p) => [p.latitude, p.longitude]);
    L.polyline(pts, { color: '#f59e0b', weight: 3, opacity: 0.9, dashArray: '6 8', interactive: false }).addTo(heroMap);
    for (const place of places.value) {
      L.marker([place.latitude, place.longitude], {
        icon: L.divIcon({
          className: 'journey-stop',
          html: `<div style="display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:15px;background:#f59e0b;color:#fff;font-weight:700;font-size:14px;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.35);cursor:pointer;">${place.index}</div>`,
          iconSize: [30, 30],
          iconAnchor: [15, 15],
        }),
        title: `${place.index}. ${place.label}`,
        keyboard: true,
      })
        .bindTooltip(`${place.index}. ${escapeHtml(place.label)}`, { direction: 'top', offset: [0, -14] })
        .on('click', () => showPlace(place))
        .addTo(heroMap);
    }
    // Marges : le titre occupe le bas, les boutons le haut à droite.
    heroMap.fitBounds(L.latLngBounds(pts), { paddingTopLeft: [40, 70], paddingBottomRight: [40, 130], maxZoom: 12 });
    return;
  }

  // Marqueur-vignette, même langage visuel que la page Carte.
  const badge = count > 1
    ? `<div style="position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#f59e0b;color:#fff;font-size:11px;font-weight:600;line-height:18px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.3);">${count}</div>`
    : '';
  const thumb = props.album.cover_url
    ? `<img src="${escapeHtml(props.album.cover_url)}" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none'" />`
    : '';
  L.marker([latitude, longitude], {
    icon: L.divIcon({
      className: 'custom-thumb-marker',
      html: `<div style="position:relative;width:56px;height:56px;border-radius:10px;overflow:visible;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);background:#e7e5e4;"><div style="width:100%;height:100%;border-radius:7px;overflow:hidden;">${thumb}</div>${badge}</div>
        <div style="width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid #fff;margin:0 auto;filter:drop-shadow(0 2px 2px rgba(0,0,0,0.2));"></div>`,
      iconSize: [56, 64],
      iconAnchor: [28, 64],
    }),
    interactive: false,
  }).addTo(heroMap);
});

onBeforeUnmount(() => {
  unmounted = true;
  heroMap?.remove();
  heroMap = null;
  document.removeEventListener('click', closeMenuOnOutsideClick);
});

const showParticipants = ref(false);

// ---- Menu ⋯ flottant ------------------------------------------------------
const showActionsMenu = ref(false);
const actionsMenuRef = ref(null);

const closeMenuOnOutsideClick = (event) => {
  if (showActionsMenu.value && actionsMenuRef.value && !actionsMenuRef.value.contains(event.target)) {
    showActionsMenu.value = false;
  }
};
onMounted(() => document.addEventListener('click', closeMenuOnOutsideClick));

const openFromMenu = (action) => {
  showActionsMenu.value = false;
  action();
};

// ---- Mosaïque --------------------------------------------------------------
// Motif déterministe (cycle de 10) : quelques tuiles 2x2 et panoramiques
// cassent la grille uniforme ; grid-flow-dense rebouche les trous.
const mosaicSpan = (index) => {
  const i = index % 10;
  if (i === 0) return 'col-span-2 row-span-2';
  if (i === 4) return 'col-span-2';
  if (i === 7) return 'col-span-2';
  return '';
};

// Diaporama
const slideshowEl = ref(null);
const hasPlayableMedia = computed(() =>
  (props.album.media || []).some((m) => m.type === 'photo' || m.type === 'video')
);
const startSlideshow = () => slideshowEl.value?.open(0);
const showEditModal = ref(false);
const showAddMediaModal = ref(false);
const showGeolocateModal = ref(false);

const geolocating = ref(false);
const geolocateError = ref(null);

const applyAlbumGeolocation = async ({ latitude, longitude }) => {
  geolocating.value = true;
  geolocateError.value = null;
  try {
    await axios.post(`/albums/${props.album.id}/geolocate`, { latitude, longitude });
    showGeolocateModal.value = false;
    router.reload();
  } catch (e) {
    geolocateError.value = e.response?.data?.message || 'Erreur lors de la géolocalisation.';
  } finally {
    geolocating.value = false;
  }
};
// Mode sélection : les cases à cocher n'apparaissent qu'une fois activé
// via le menu ⋯ (demande UX : pas de cases par défaut).
const selectionMode = ref(false);
const selectedMediaIds = ref([]);

const toggleSelectionMode = () => {
  selectionMode.value = !selectionMode.value;
  if (!selectionMode.value) {
    selectedMediaIds.value = [];
    lastSelectedIndex.value = null;
  }
};

const albumMediaIds = computed(() => {
  return props.album.media?.map((m) => m.id) || [];
});

// Étape choisie sur la carte-trajet (null : tout l'album).
const placeFilter = ref(null);
// « Qui est-ce ? » (téléphone) : seules les photos avec des visages à nommer.
const unidentifiedOnly = ref(false);
const pendingFacesCount = computed(() =>
  (props.album.media || []).reduce((n, m) => n + (m.pending_faces_count || 0), 0)
);
const participantsPreview = computed(() => props.album.participants_preview || []);
const albumMedia = computed(() => {
  let all = props.album.media || [];
  if (unidentifiedOnly.value) all = all.filter((m) => (m.pending_faces_count || 0) > 0);
  if (!placeFilter.value) return all;
  const ids = new Set(placeFilter.value.media_ids);
  return all.filter((m) => ids.has(m.id));
});
// Album tourné à plusieurs endroits : carte-trajet et diaporama qui voyage.
const places = computed(() => props.album.places || []);
const travels = computed(() => places.value.length > 1);
const placeOfMedia = computed(() => {
  const map = new Map();
  for (const p of places.value) for (const id of p.media_ids) map.set(id, p);
  return map;
});
const showPlace = (place) => {
  placeFilter.value = place;
  requestAnimationFrame(() => document.getElementById('album-grid')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
};

// Propriétaire de l'album (les actions de gestion — couverture, modif,
// partage — lui sont réservées ; le backend le vérifie aussi).
const isOwner = computed(() => props.album.is_owner !== false);
const currentUserId = computed(() => usePage().props.auth?.user?.id ?? null);

// Médias de la sélection que l'utilisateur a le droit de RETIRER : tout s'il
// est propriétaire de l'album, sinon uniquement ceux qu'il a lui-même ajoutés
// (dont il est propriétaire).
const removableSelectedIds = computed(() => {
  if (isOwner.value) {
    return selectedMediaIds.value;
  }
  return selectedMediaIds.value.filter((id) => {
    const media = albumMedia.value.find((m) => m.id === id);
    return media && media.user_id === currentUserId.value;
  });
});

const isSelected = (id) => selectedMediaIds.value.includes(id);

// Ancre de la dernière sélection, pour la sélection de plage au shift+clic
// (même comportement que la galerie).
const lastSelectedIndex = ref(null);

// Applique la sélection : shift+clic depuis une ancre = ajoute toute la
// plage (dans l'ordre affiché) ; sinon bascule l'élément.
const applySelection = (media, index, event) => {
  if (event?.shiftKey && lastSelectedIndex.value !== null) {
    const [start, end] = lastSelectedIndex.value < index
      ? [lastSelectedIndex.value, index]
      : [index, lastSelectedIndex.value];
    const rangeIds = albumMedia.value.slice(start, end + 1).map((m) => m.id);
    selectedMediaIds.value = Array.from(new Set([...selectedMediaIds.value, ...rangeIds]));
  } else {
    const position = selectedMediaIds.value.indexOf(media.id);
    if (position === -1) {
      selectedMediaIds.value.push(media.id);
    } else {
      selectedMediaIds.value.splice(position, 1);
    }
  }
  lastSelectedIndex.value = index;
};

const editSelected = () => {
  if (selectedMediaIds.value.length === 1) {
    router.visit(`/media/${selectedMediaIds.value[0]}`);
  }
};

// Fond du titre : clair (carte, dégradé) → titre noir ; image ou vidéo → blanc.
const heroLight = computed(() => !props.album.hero && (Boolean(props.album.hero_location) || !props.album.cover_url));
const selectedHeroCandidate = computed(() => {
  if (selectedMediaIds.value.length !== 1) return null;
  const media = mediaById.value.get(selectedMediaIds.value[0]);
  return media && ['photo', 'video'].includes(media.type) ? media : null;
});
const heroVideoChoice = ref(null);
const heroVideoStart = ref(0);
const chooseHero = (media) => {
  if (media.type === 'video') {
    heroVideoStart.value = 0;
    heroVideoChoice.value = media;
  } else {
    setHero(media);
  }
};
const setHero = async (media, start = 0) => {
  try {
    const { data } = await axios.post(`/albums/${props.album.id}/hero`, { media_id: media?.id ?? null, start });
    heroVideoChoice.value = null;
    selectedMediaIds.value = [];
    toast.success(data.message);
    router.reload({ only: ['album'] });
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible de changer le fond du titre.');
  }
};

// « À la une » : en tête de l'album, dans l'ordre de sélection.
const isPinned = (media) => media.pivot?.pin_order !== null && media.pivot?.pin_order !== undefined;
const mediaById = computed(() => new Map((props.album.media || []).map((m) => [m.id, m])));
const selectedToPin = computed(() => selectedMediaIds.value.filter((id) => !isPinned(mediaById.value.get(id) || {})));
const selectedPinned = computed(() => selectedMediaIds.value.filter((id) => isPinned(mediaById.value.get(id) || {})));
const pinSelected = async (pinned) => {
  try {
    await axios.post(`/albums/${props.album.id}/pin`, {
      media_ids: pinned ? selectedToPin.value : selectedPinned.value,
      pinned,
    });
    selectedMediaIds.value = [];
    router.reload({ only: ['album'] });
  } catch (error) {
    toast.error(error.response?.data?.message || 'Impossible de modifier la une.');
  }
};

const setAsCover = async () => {
  if (selectedMediaIds.value.length !== 1) return;
  try {
    await axios.post(`/albums/${props.album.id}/cover`, { media_id: selectedMediaIds.value[0] });
    selectedMediaIds.value = [];
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors de la définition de la couverture.');
  }
};

// En mode sélection, le clic sur la tuile coche/décoche (comme la galerie) ;
// sinon il ouvre la visionneuse (photos et vidéos) ou la fiche (documents).
const handleMediaClick = (media, index, event) => {
  if (selectionMode.value) {
    applySelection(media, index, event);
    return;
  }
  if (!openLightbox(media)) {
    router.visit(`/media/${media.id}`);
  }
};

const handleAlbumUpdated = () => {
  router.reload();
};

const handleMediaAdded = () => {
  router.reload();
};

const removeSelectedMedia = async () => {
  const ids = removableSelectedIds.value;
  if (ids.length === 0) return;

  const ok = await confirm({
    title: "Retirer de l'album",
    message: `Retirer ${ids.length} média${ids.length > 1 ? 's' : ''} de l'album ? Les médias ne seront pas supprimés.`,
    confirmLabel: 'Retirer',
    danger: true,
  });
  if (!ok) return;

  try {
    await axios.delete(`/albums/${props.album.id}/media`, {
      data: { media_ids: ids },
    });
    selectedMediaIds.value = [];
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || "Impossible de retirer ces médias de l'album.");
  }
};

const deleteAlbum = async () => {
  const ok = await confirm({
    title: 'Supprimer cet album',
    message: 'Êtes-vous sûr de vouloir supprimer cet album ? Les médias ne seront pas supprimés.',
    confirmLabel: 'Supprimer',
    danger: true,
  });
  if (!ok) return;

  try {
    await axios.delete(`/albums/${props.album.id}`);
    router.visit('/albums');
  } catch (error) {
    toast.error(error.response?.data?.message || "Impossible de supprimer l'album.");
  }
};

// Visionneuse partagée (reconstruite quand la liste de médias change)
const { open: openLightbox } = usePhotoSwipe(() => albumMedia.value, {
  watchSource: () => props.album.media,
  detailLink: true,
});

// Slides du diaporama : médias jouables normalisés (poids fort aux visages).
// Album à plusieurs étapes : tout l'album, dans l'ordre chronologique, chaque
// média portant son lieu (le diaporama vole d'une étape à la suivante).
const chronoKey = (m) => [m.taken_at || '9999', String(m.clip_start ?? 0).padStart(8, '0'), m.created_at || ''].join('|');
const slideshowSlides = computed(() => {
  const playable = (props.album.media || []).filter((m) => m.type === 'photo' || m.type === 'video');
  const list = travels.value ? [...playable].sort((a, b) => chronoKey(a).localeCompare(chronoKey(b))) : playable;
  return list.map((m) => {
    const place = placeOfMedia.value.get(m.id);
    return {
      key: m.id,
      type: m.type,
      src: slideUrl(m),
      label: m.title || m.original_name,
      weight: m.matched_faces_count > 0 ? 3 : 1,
      place: place ? { index: place.index, label: place.label, latitude: place.latitude, longitude: place.longitude } : null,
    };
  });
});
</script>
