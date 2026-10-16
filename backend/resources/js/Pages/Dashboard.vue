<template>
  <Head title="Accueil" />
  <AppLayout>
    <div class="page-container">
      <div class="page-content flex flex-col gap-6 sm:gap-9 px-4 sm:px-6 lg:px-8">

        <!-- Salutation compacte -->
        <div class="order-first flex flex-col sm:flex-row sm:flex-wrap sm:items-baseline sm:justify-between gap-0.5 sm:gap-2">
          <h1 class="font-display text-[26px] sm:text-4xl font-bold text-surface-900">
            Bonjour<span v-if="user">, {{ user.name.split(' ')[0] }}</span>.
          </h1>
          <span class="text-[13px] sm:text-sm text-surface-400">{{ formattedToday }}</span>
        </div>

        <!-- Installer l'application (téléphone, hors appli installée) -->
        <InstallAppBanner />

        <!-- Médias ajoutés à SA fiche par d'autres, à valider -->
        <FichePendingCard :initial-items="fichePending" />

        <!-- ============ Souvenirs « Il y a N ans » ============ -->
        <div v-if="onThisDay.length > 0" class="space-y-4" data-tour="today">
          <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400">Vos souvenirs du jour</h2>
            <button
              @click="playMemories(0)"
              class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-white border border-surface-300 text-sm font-medium text-surface-700 hover:bg-surface-50 transition"
            >
              <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
              Revivre en diaporama
            </button>
          </div>

          <div class="flex overflow-x-auto snap-x snap-mandatory gap-3 -mx-4 px-4 pb-1 sm:grid sm:grid-cols-2 lg:grid-cols-3 sm:gap-4 sm:mx-0 sm:px-0 sm:overflow-visible">
            <button
              v-for="(group, gi) in onThisDay"
              :key="group.year"
              type="button"
              class="relative w-[300px] sm:w-auto shrink-0 snap-start h-52 sm:h-72 rounded-2xl overflow-hidden shadow-warm-md text-left group hover:shadow-warm-lg transition"
              @click="playMemories(memoryOffset(gi))"
            >
              <!-- 1re couverture = image principale du haut de page : pas de lazy -->
              <img
                v-if="coverUrl(group)"
                :src="coverUrl(group)"
                class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                :loading="gi === 0 ? 'eager' : 'lazy'"
                :fetchpriority="gi === 0 ? 'high' : 'auto'"
                decoding="async"
              />
              <div v-else class="absolute inset-0 bg-linear-to-br from-brand-200 to-surface-300"></div>
              <div class="absolute inset-0 bg-linear-to-b from-black/10 via-transparent to-black/60"></div>
              <span class="absolute top-3.5 left-3.5 px-3 py-1 rounded-full bg-white/90 text-surface-900 text-[13px] font-bold">
                {{ group.years_ago === 1 ? 'Il y a 1 an' : `Il y a ${group.years_ago} ans` }}
              </span>
              <div class="absolute bottom-3.5 left-4 right-4 flex items-end justify-between gap-3">
                <div>
                  <div class="font-display text-xl font-semibold text-white">{{ group.year }}</div>
                  <div class="text-[13px] text-white/85">{{ group.media.length }} photo{{ group.media.length > 1 ? 's' : '' }}</div>
                </div>
                <div class="hidden sm:flex gap-1">
                  <div
                    v-for="m in group.media.slice(1, 3)"
                    :key="m.id"
                    class="w-10 h-10 rounded-lg border-2 border-white/85 overflow-hidden bg-surface-300"
                  >
                    <img v-if="thumbnailUrl(m)" :src="thumbnailUrl(m)" class="w-full h-full object-cover" loading="lazy" decoding="async" />
                  </div>
                  <div
                    v-if="group.media.length > 3"
                    class="w-10 h-10 rounded-lg border-2 border-white/85 bg-black/45 flex items-center justify-center text-white text-xs font-semibold"
                  >
                    +{{ group.media.length - 3 }}
                  </div>
                </div>
              </div>
            </button>
          </div>
        </div>

        <!-- ============ Personne du jour (repli sans souvenir daté) ============ -->
        <div v-else-if="personOfTheDay" class="space-y-4" data-tour="today">
          <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400">La personne du jour</h2>
          <div class="flex flex-col sm:flex-row bg-white border border-surface-200 rounded-2xl overflow-hidden shadow-warm-md">
            <!-- Avatar, sinon la première photo où la personne apparaît. -->
            <PersonAvatar
              :name="personOfTheDay.name"
              :src="personOfTheDay.avatar_url || (personOfTheDay.photos[0] && thumbnailUrl(personOfTheDay.photos[0]))"
              shape="none"
              eager
              class="sm:w-72 h-52 sm:h-auto bg-brand-100"
              fallback-class="text-6xl font-bold text-brand-700"
            />
            <div class="flex-1 p-6 sm:p-7 flex flex-col justify-center gap-2">
              <div class="text-xs font-semibold uppercase tracking-widest text-brand-700">Personne du jour</div>
              <div class="font-display text-3xl font-semibold text-surface-900">{{ personOfTheDay.name }}</div>
              <p class="text-sm text-surface-500">
                {{ personOfTheDay.media_count }} photo{{ personOfTheDay.media_count > 1 ? 's' : '' }} {{ isFriends ? 'dans vos souvenirs communs' : 'dans votre mémoire familiale' }}<span v-if="personOfTheDay.oldest_year"> — la plus ancienne date de {{ personOfTheDay.oldest_year }}</span>.
              </p>
              <div v-if="personOfTheDay.photos.length" class="flex gap-1.5 mt-1.5">
                <Link
                  v-for="p in personOfTheDay.photos"
                  :key="p.id"
                  :href="`/media/${p.id}`"
                  class="w-14 h-14 rounded-lg overflow-hidden bg-surface-100 hover:ring-2 hover:ring-brand-400 transition"
                >
                  <img v-if="thumbnailUrl(p)" :src="thumbnailUrl(p)" class="w-full h-full object-cover" loading="lazy" decoding="async" />
                </Link>
              </div>
              <div class="mt-3">
                <Link :href="`/people/${personOfTheDay.id}`" class="btn-primary btn-sm">Voir sa fiche</Link>
              </div>
            </div>
          </div>
        </div>

        <!-- ============ Fêtes & anniversaires ============ -->
        <div v-if="celebrations.length > 0" class="space-y-4">
          <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400">Fêtes, anniversaires &amp; souvenirs</h2>
          <div class="flex flex-nowrap sm:flex-wrap gap-3 overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 sm:overflow-visible">
            <Link
              v-for="(c, i) in celebrations"
              :key="i"
              :href="`/people/${c.person_id}`"
              class="flex items-center gap-3 py-2.5 pl-3 pr-5 bg-white rounded-full shadow-warm-sm transition hover:shadow-warm-md shrink-0 whitespace-nowrap"
              :class="[
                c.days_until === 0 ? 'border' : 'border border-dashed opacity-80 hover:opacity-100',
                c.kind === 'memorial' ? 'border-surface-300' : (c.days_until === 0 ? 'border-brand-300' : 'border-surface-300'),
              ]"
            >
              <div
                class="w-11 h-11 rounded-full overflow-hidden flex items-center justify-center shrink-0"
                :class="c.kind === 'memorial' ? 'bg-surface-100' : 'bg-brand-100'"
              >
                <img v-if="c.avatar_url" :src="c.avatar_url" class="w-full h-full object-cover" :class="{ grayscale: c.kind === 'memorial' }" loading="lazy" decoding="async" />
                <span v-else class="text-lg">{{ c.emoji }}</span>
              </div>
              <div>
                <div class="text-sm font-semibold text-surface-900">{{ c.emoji }} {{ c.title }}</div>
                <div class="text-xs text-surface-500">{{ c.sub }}</div>
              </div>
            </Link>
          </div>
        </div>

        <!-- ============ Quêtes : complétez la mémoire familiale (téléphone : en premier) ============ -->
        <div class="order-first sm:order-none">
          <QuestCard data-tour="quests" />
        </div>

        <!-- ============ Notifications push (invitation, masquable) ============ -->
        <PushPromptCard />

        <!-- ============ Bien démarrer (masquable par personne) ============ -->
        <div
          v-if="guideVisible"
          class="relative flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-5 bg-linear-to-br from-brand-50 to-white border border-brand-200 rounded-2xl px-4 sm:px-7 py-4 sm:py-5"
        >
          <button
            @click="hideGuide"
            class="absolute top-3 right-3 text-surface-400 hover:text-surface-600 transition"
            title="Ne plus afficher"
            aria-label="Ne plus afficher"
          >
            <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
          <div class="w-13 h-13 rounded-xl bg-brand-100 flex items-center justify-center shrink-0">
            <svg class="w-6.5 h-6.5 text-brand-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
            </svg>
          </div>
          <div class="flex-1 min-w-0">
            <h2 class="text-base font-semibold text-surface-900">Bien démarrer avec MemoryLane</h2>
            <p class="text-sm text-surface-500 mt-0.5">
              Importer vos photos, créer des albums, identifier les visages, partager avec vos proches — le guide pas à pas.
            </p>
          </div>
          <Link href="/guide" class="btn-primary shrink-0">
            Ouvrir le guide
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
          </Link>
        </div>

        <!-- ============ Accès rapide (desktop ; sur téléphone la barre d'onglets suffit) ============ -->
        <div class="hidden sm:block">
          <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400 mb-4">Accès rapide</h2>
          <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <Link href="/media/upload" class="action-card group">
              <div class="flex items-start gap-4">
                <div class="action-card-icon action-card-icon--brand shrink-0">
                  <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-semibold text-surface-900">Ajouter des photos</h3>
                  <p class="text-sm text-surface-500 mt-0.5">Depuis le téléphone ou l'ordinateur</p>
                </div>
              </div>
            </Link>

            <Link href="/media" class="action-card group">
              <div class="flex items-start gap-4">
                <div class="action-card-icon action-card-icon--teal shrink-0">
                  <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-semibold text-surface-900">Mes photos</h3>
                  <p class="text-sm text-surface-500 mt-0.5">Tous vos médias</p>
                </div>
              </div>
            </Link>

            <Link href="/people" class="action-card group">
              <div class="flex items-start gap-4">
                <div class="action-card-icon action-card-icon--rose shrink-0">
                  <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-semibold text-surface-900">{{ isFriends ? 'Amis' : 'Personnes' }}</h3>
                  <p class="text-sm text-surface-500 mt-0.5">{{ isFriends ? 'Les fiches du cercle' : 'Votre famille' }}</p>
                </div>
              </div>
            </Link>

            <Link :href="isFriends ? '/network' : '/family-tree'" class="action-card group">
              <div class="flex items-start gap-4">
                <div class="action-card-icon action-card-icon--violet shrink-0">
                  <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-semibold text-surface-900">{{ isFriends ? 'Réseau' : 'Arbre' }}</h3>
                  <p class="text-sm text-surface-500 mt-0.5">{{ isFriends ? 'Qui apparaît avec qui' : 'Généalogie' }}</p>
                </div>
              </div>
            </Link>
          </div>
        </div>

      </div>
    </div>

    <!-- Diaporama des souvenirs -->
    <FullscreenSlideshow ref="memoriesSlideshow" :slides="memorySlides" :photo-duration="6000" />
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import FullscreenSlideshow from '@/Components/FullscreenSlideshow.vue';
import QuestCard from '@/Components/QuestCard.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import PushPromptCard from '@/Components/PushPromptCard.vue';
import InstallAppBanner from '@/Components/InstallAppBanner.vue';
import FichePendingCard from '@/Components/FichePendingCard.vue';
import { useAuth } from '@/Composables/useAuth';
import { useCircle } from '@/Composables/useCircle';
import { conversionUrl, slideUrl, thumbnailUrl } from '@/utils/media';

const { isFriends } = useCircle();

const { user } = useAuth();

const props = defineProps({
  onThisDay: {
    type: Array,
    default: () => [],
  },
  celebrations: {
    type: Array,
    default: () => [],
  },
  personOfTheDay: {
    type: Object,
    default: null,
  },
  showGuide: {
    type: Boolean,
    default: true,
  },
  fichePending: {
    type: Array,
    default: () => [],
  },
});

const formattedToday = computed(() =>
  new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
);

// Couverture d'une carte souvenir : conversion medium de la 1re photo.
const coverUrl = (group) => {
  const first = group.media[0];
  if (!first) return null;
  return conversionUrl(first, 'medium') || thumbnailUrl(first);
};

// ---- Diaporama des souvenirs (toutes années confondues) ----
const memoriesSlideshow = ref(null);

const memorySlides = computed(() =>
  props.onThisDay.flatMap((group) =>
    group.media.map((m) => ({
      key: m.id,
      type: m.type === 'video' ? 'video' : 'photo',
      src: slideUrl(m),
      label: group.years_ago === 1 ? 'Il y a 1 an' : `Il y a ${group.years_ago} ans`,
    }))
  )
);

// Index de la première slide d'un groupe (clic sur une carte année).
const memoryOffset = (groupIndex) =>
  props.onThisDay.slice(0, groupIndex).reduce((sum, g) => sum + g.media.length, 0);

const playMemories = (index) => memoriesSlideshow.value?.open(index);

// ---- Bloc « Bien démarrer » masquable (persisté par compte) ----
const guideVisible = ref(props.showGuide);

const hideGuide = async () => {
  guideVisible.value = false;
  try {
    await axios.post('/dashboard/hide-guide');
  } catch {
    // Sans gravité : le bloc réapparaîtra à la prochaine visite.
  }
};
</script>
