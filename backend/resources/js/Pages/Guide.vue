<template>
  <Head title="Guide d'utilisation" />
  <AppLayout>
    <div class="page-container">
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 scroll-smooth">

        <!-- En-tête -->
        <div class="page-header">
          <div class="text-xs font-semibold uppercase tracking-widest text-brand-700 mb-2">Bien démarrer</div>
          <h1>Guide d'utilisation</h1>
          <p>
            MemoryLane rassemble les photos, les personnes et les histoires de la famille.
            Suivez les premiers pas ci-dessous, puis piochez dans les fiches pour aller plus loin.
          </p>
        </div>

        <!-- Sommaire -->
        <nav class="flex flex-wrap gap-2 mb-10" aria-label="Sommaire du guide">
          <a
            v-for="section in sections"
            :key="section.id"
            :href="`#${section.id}`"
            class="px-3.5 py-1.5 rounded-full text-[13px] font-medium bg-white border border-surface-300 text-surface-600 hover:bg-surface-50 hover:text-surface-900 transition"
          >
            {{ section.emoji }} {{ section.title }}
          </a>
        </nav>

        <!-- ============ Parcours « Vos premiers pas » ============ -->
        <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400 mb-4">Vos premiers pas</h2>
        <div class="space-y-4 mb-14">
          <section
            v-for="(step, i) in steps"
            :key="step.title"
            class="card card--padded"
          >
            <div class="md:grid md:grid-cols-[1fr_300px] md:gap-6 items-start">
              <div>
                <div class="flex items-start gap-3.5">
                  <span class="w-9 h-9 shrink-0 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center font-bold">
                    {{ i + 1 }}
                  </span>
                  <div>
                    <h3 class="text-lg font-semibold text-surface-900">{{ step.title }}</h3>
                    <p class="mt-1.5 text-sm text-surface-600 leading-relaxed">{{ step.body }}</p>
                    <div class="mt-3 flex flex-wrap items-center gap-4">
                      <Link :href="step.link.href" class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline">
                        {{ step.link.label }}
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                      </Link>
                      <a :href="`#${step.more}`" class="text-sm text-surface-400 hover:text-surface-600 hover:underline">
                        En savoir plus
                      </a>
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-4 md:mt-0">
                <GuideImage :name="step.image" :alt="step.title" />
              </div>
            </div>
          </section>
        </div>

        <!-- ============ Sections de référence ============ -->
        <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400 mb-4">Toutes les fonctionnalités</h2>
        <div class="space-y-12">
          <section
            v-for="section in sections"
            :key="section.id"
            :id="section.id"
            class="scroll-mt-20"
          >
            <h3 class="font-display text-2xl font-semibold text-surface-900">
              {{ section.emoji }} {{ section.title }}
            </h3>
            <p class="mt-2 text-sm text-surface-600 leading-relaxed max-w-2xl">{{ section.intro }}</p>

            <div v-if="section.image" class="mt-4 max-w-2xl">
              <GuideImage :name="section.image" :alt="section.title" />
            </div>

            <div class="mt-4 grid sm:grid-cols-2 gap-3">
              <div v-for="point in section.points" :key="point.title" class="feature-item">
                <div class="feature-icon feature-icon--available">
                  <svg class="icon-sm mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div class="feature-content">
                  <h3>{{ point.title }}</h3>
                  <p>{{ point.text }}</p>
                </div>
              </div>
            </div>

            <Link
              v-if="section.link"
              :href="section.link.href"
              class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline"
            >
              {{ section.link.label }}
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
            </Link>
          </section>
        </div>

        <p class="mt-14 text-sm text-surface-400 text-center">
          Ce guide s'enrichira au fil des nouveautés. Une question ?
          Demandez à la personne qui administre votre MemoryLane.
        </p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuideImage from '@/Components/GuideImage.vue';

// Les noms d'images correspondent aux vignettes générées par
// scripts/guide-captures.mjs (storage/app/guide/, servies authentifiées).

const steps = [
  {
    title: 'Importez vos photos et vidéos',
    body: 'Glissez vos fichiers dans la page Télécharger — les grosses vidéos passent aussi. Vous pouvez importer directement depuis Google Photos ou une archive Google Takeout. Les dates, lieux et autres informations sont lus automatiquement.',
    image: 'upload',
    link: { href: '/media/upload', label: 'Ouvrir Télécharger' },
    more: 'importer',
  },
  {
    title: 'Retrouvez tout dans Mes photos',
    body: 'Toute votre bibliothèque, classée par année, avec recherche et filtres. Sélectionnez plusieurs médias (maj+clic pour une plage entière) pour les dater, les géolocaliser, les ranger en album ou les partager d\'un coup.',
    image: 'mes-photos',
    link: { href: '/media', label: 'Ouvrir Mes photos' },
    more: 'photos',
  },
  {
    title: 'Créez des albums et partagez-les',
    body: 'Regroupez un événement dans un album, ou laissez un album intelligent se remplir tout seul. Partagez à des comptes choisis, à tous les connectés, ou par lien pour la famille qui n\'a pas de compte.',
    image: 'albums',
    link: { href: '/albums', label: 'Ouvrir Albums' },
    more: 'albums',
  },
  {
    title: 'Mettez des noms sur les visages',
    body: 'Les visages sont détectés automatiquement pendant que vous naviguez, puis MemoryLane suggère qui est qui — confirmez d\'un clic. Chaque personne gagne sa fiche : sa famille, la frise de sa vie, toutes ses photos.',
    image: 'personnes',
    link: { href: '/people', label: 'Ouvrir Personnes' },
    more: 'personnes',
  },
  {
    title: 'Partagez en foyer',
    body: 'Un foyer, c\'est votre cercle familial : les photos que vous y partagez deviennent visibles de ses membres, et chacun peut aider à identifier les visages. Depuis Mes photos : sélection → « Foyer ».',
    image: 'foyers',
    link: { href: '/households', label: 'Ouvrir Foyers' },
    more: 'foyers',
  },
  {
    title: 'Explorez l\'arbre et la carte',
    body: 'L\'arbre généalogique se construit tout seul depuis les fiches des personnes ; la carte affiche vos souvenirs là où ils ont été pris. Deux façons de se promener dans la mémoire familiale.',
    image: 'arbre',
    link: { href: '/family-tree', label: 'Ouvrir l\'Arbre' },
    more: 'arbre',
  },
];

const sections = [
  {
    id: 'accueil',
    emoji: '🏡',
    title: 'La page d\'accueil',
    intro: 'Chaque jour, l\'accueil vous raconte quelque chose : il change selon la date et selon ce que la famille a déjà rempli.',
    image: 'dashboard',
    points: [
      { title: 'Vos souvenirs du jour', text: '« Il y a N ans, ce jour-là » — cliquez une carte pour revivre les photos en diaporama.' },
      { title: 'Fêtes & anniversaires', text: 'Anniversaires, fêtes des prénoms et anniversaires de mariage des 15 prochains jours.' },
      { title: 'La personne du jour', text: 'Quand aucun souvenir ne tombe ce jour-là, une personne de la famille est mise à l\'honneur.' },
      { title: 'Les petites questions', text: '« Complétez la mémoire familiale » : une question à la fois (une date, un visage, un lieu…). Répondez si vous savez — chaque réponse enrichit les fiches pour tout le monde. « Je ne sais pas » et « Passer » existent aussi !' },
    ],
    link: { href: '/dashboard', label: 'Ouvrir l\'accueil' },
  },
  {
    id: 'photos',
    emoji: '🖼️',
    title: 'Mes photos',
    intro: 'Votre bibliothèque complète, classée par année. C\'est ici qu\'on trie, qu\'on complète et qu\'on partage en masse.',
    image: 'mes-photos',
    points: [
      { title: 'Filtres', text: 'Par tags, type, durée ou qualité vidéo — combinables avec la recherche.' },
      { title: 'Sélection multiple', text: 'Bouton « Sélectionner », puis clic par clic ou maj+clic pour toute une plage. « Tout sélectionner » prend l\'ensemble du filtre courant.' },
      { title: 'Actions de masse', text: 'Dater, géolocaliser sur une carte, ajouter à un album ou partager au foyer — en une fois pour toute la sélection.' },
    ],
    link: { href: '/media', label: 'Ouvrir Mes photos' },
  },
  {
    id: 'fiche-photo',
    emoji: '🔍',
    title: 'La fiche d\'une photo ou vidéo',
    intro: 'Cliquez n\'importe quel média pour ouvrir sa fiche : tout ce qu\'on sait de lui, et tout ce qu\'on peut lui apprendre.',
    points: [
      { title: 'Visages', text: 'Les visages détectés apparaissent encadrés : confirmez une suggestion ou choisissez la bonne personne.' },
      { title: 'Date et lieu', text: 'Corrigez la date de prise de vue ou placez la photo sur la carte.' },
      { title: 'Tags', text: 'Ajoutez des étiquettes (« Noël », « vacances »…) pour retrouver les photos par thème.' },
      { title: 'Clips vidéo', text: 'Découpez un extrait d\'une longue vidéo pour n\'en garder que le meilleur moment.' },
    ],
  },
  {
    id: 'importer',
    emoji: '📥',
    title: 'Importer',
    intro: 'Trois chemins pour faire entrer vos souvenirs, du plus simple au plus massif.',
    image: 'upload',
    points: [
      { title: 'Glisser-déposer', text: 'Déposez photos et vidéos directement dans la page Télécharger, même par centaines.' },
      { title: 'Google Photos', text: 'Connectez votre compte et choisissez ce que vous rapatriez.' },
      { title: 'Archive Takeout', text: 'Pour tout récupérer d\'un coup, avec les lieux et les albums d\'origine. Les photos déjà présentes sont enrichies, pas dupliquées.' },
    ],
    link: { href: '/media/upload', label: 'Ouvrir Télécharger' },
  },
  {
    id: 'telephone',
    emoji: '📱',
    title: 'Depuis votre téléphone',
    intro: 'MemoryLane s\'installe comme une application et reçoit vos photos en deux gestes.',
    points: [
      { title: 'Installer l\'app', text: 'Ouvrez le site sur votre téléphone puis « Ajouter à l\'écran d\'accueil » : MemoryLane se comporte comme une app.' },
      { title: 'Partager → MemoryLane', text: 'Depuis la galerie du téléphone, bouton Partager puis MemoryLane : la photo part directement dans la bibliothèque.' },
      { title: 'Connexion sans mot de passe', text: 'La connexion peut se faire par lien magique envoyé par l\'administrateur — rien à retenir.' },
    ],
  },
  {
    id: 'albums',
    emoji: '📚',
    title: 'Albums et partage',
    intro: 'L\'album, c\'est l\'histoire racontée : un mariage, un été, une année. Et c\'est l\'unité de partage la plus simple.',
    image: 'albums',
    points: [
      { title: 'Albums classiques', text: 'Créez, choisissez la couverture, réordonnez les photos, ajoutez un diaporama.' },
      { title: 'Albums intelligents', text: 'Définissez des critères (personnes, tags, dates) : l\'album se remplit tout seul au fil des imports.' },
      { title: 'Partage par compte', text: 'Ouvrez l\'album à des comptes choisis ou à tous les connectés.' },
      { title: 'Partage par lien', text: 'Un lien privé pour la famille sans compte — révocable à tout moment.' },
    ],
    link: { href: '/albums', label: 'Ouvrir Albums' },
  },
  {
    id: 'personnes',
    emoji: '👪',
    title: 'Personnes et visages',
    intro: 'Chaque membre de la famille a sa fiche, et les photos s\'y rattachent presque toutes seules.',
    image: 'personne',
    points: [
      { title: 'Détection automatique', text: 'Les visages sont repérés discrètement pendant que vous naviguez — rien à lancer.' },
      { title: 'Suggestions', text: 'MemoryLane reconnaît les visages déjà nommés et propose : confirmez ou corrigez d\'un clic.' },
      { title: 'La fiche personne', text: 'Sa famille en mini-arbre, la frise de sa vie (naissance, mariage, métiers…), toutes ses photos.' },
      { title: 'Le récit de vie', text: 'Bouton Diaporama sur la fiche : la carte vole de lieu en lieu et raconte sa vie en photos.' },
      { title: 'Lien de parenté', text: 'Sur la fiche de quelqu\'un d\'autre, découvrez votre lien exact — l\'arbre le rejoue pas à pas.' },
    ],
    link: { href: '/people', label: 'Ouvrir Personnes' },
  },
  {
    id: 'foyers',
    emoji: '🤝',
    title: 'Foyers',
    intro: 'Le foyer est votre cercle proche : on y partage des photos et on y identifie les visages ensemble.',
    image: 'foyers',
    points: [
      { title: 'Partager des photos', text: 'Depuis Mes photos, sélection → « Foyer » : elles deviennent visibles des membres.' },
      { title: 'Identifier ensemble', text: 'Chaque membre peut nommer ou corriger les visages des photos partagées — la mamie qui reconnaît tout le monde devient précieuse.' },
      { title: 'Inviter', text: 'Ajoutez un membre par son adresse email depuis la page du foyer.' },
    ],
    link: { href: '/households', label: 'Ouvrir Foyers' },
  },
  {
    id: 'arbre',
    emoji: '🌳',
    title: 'L\'arbre généalogique',
    intro: 'L\'arbre se dessine tout seul à partir des fiches (parents, conjoints, enfants) et se visite comme un album.',
    image: 'arbre',
    points: [
      { title: 'Navigation', text: 'Cliquez une personne pour recentrer l\'arbre sur elle ; zoomez, déplacez-vous librement.' },
      { title: 'Lien de parenté animé', text: 'Depuis une fiche, le bouton « Lien de parenté » fait marcher l\'arbre de vous jusqu\'à elle, en surlignant le chemin.' },
    ],
    link: { href: '/family-tree', label: 'Ouvrir l\'Arbre' },
  },
  {
    id: 'carte',
    emoji: '🗺️',
    title: 'La carte',
    intro: 'Tous les souvenirs géolocalisés, posés sur la carte du monde.',
    image: 'carte',
    points: [
      { title: 'Explorer', text: 'Zoomez sur une région pour retrouver les photos qui y ont été prises.' },
      { title: 'Compléter', text: 'Une photo sans lieu ? Placez-la depuis sa fiche, ou en masse depuis Mes photos.' },
    ],
    link: { href: '/map', label: 'Ouvrir la Carte' },
  },
  {
    id: 'recherche',
    emoji: '🔎',
    title: 'Recherche et tags',
    intro: 'La barre de recherche en haut trouve tout : personnes, albums, photos — même avec des fautes ou sans les accents.',
    image: 'recherche',
    points: [
      { title: 'Recherche globale', text: 'Tapez un prénom, un lieu, un mot du titre : les résultats arrivent en direct, les personnes proches d\'abord.' },
      { title: 'Tags', text: 'Créez vos étiquettes colorées (« Noël », « école »…) et posez-les sur les photos pour naviguer par thème.' },
    ],
    link: { href: '/tags', label: 'Gérer les tags' },
  },
  {
    id: 'compte',
    emoji: '⚙️',
    title: 'Votre compte et le confort',
    intro: 'Les petits réglages qui rendent l\'usage quotidien agréable.',
    points: [
      { title: 'Mon profil', text: 'Vos informations et votre mot de passe, dans le menu sous votre avatar.' },
      { title: 'Mode sombre', text: 'Le bouton lune/soleil dans la barre bascule toute l\'application — elle suit sinon votre réglage système.' },
      { title: 'Ce guide', text: 'Retrouvez-le à tout moment dans le menu sous votre avatar, même après avoir masqué le bloc de l\'accueil.' },
    ],
    link: { href: '/profile', label: 'Ouvrir Mon profil' },
  },
];
</script>
