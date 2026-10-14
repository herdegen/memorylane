<template>
  <Head title="Guide d'utilisation" />
  <AppLayout>
    <div class="page-container">
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 scroll-smooth">

        <!-- En-tête -->
        <div class="page-header">
          <div class="text-xs font-semibold uppercase tracking-widest text-brand-700 mb-2">Bien démarrer</div>
          <h1>Guide d'utilisation</h1>
          <p v-if="isFriends">
            MemoryLane rassemble vos photos entre amis : qui était là, quand, où — et comment vous vous êtes connus.
            Suivez les premiers pas ci-dessous, puis piochez dans les fiches pour aller plus loin.
          </p>
          <p v-else>
            MemoryLane rassemble les photos, les personnes et les histoires de la famille.
            Suivez les premiers pas ci-dessous, puis piochez dans les fiches pour aller plus loin.
          </p>
        </div>

        <!-- Sommaire -->
        <nav class="flex flex-wrap gap-2 mb-10" aria-label="Sommaire du guide">
          <a
            v-for="section in guideSections"
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
            v-for="(step, i) in guideSteps"
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
            v-for="section in guideSections"
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
import { computed } from 'vue';
import { useCircle } from '@/Composables/useCircle';

const { isFriends } = useCircle();

// Les noms d'images correspondent aux vignettes générées par
// scripts/guide-captures.mjs (storage/app/guide/, servies authentifiées).

const steps = [
  {
    title: 'Importez vos photos et vidéos',
    body: 'Glissez vos fichiers dans la page « Ajouter des photos » — les grosses vidéos passent aussi. Vous pouvez importer directement depuis Google Photos, Google Drive ou une archive Google Takeout. Les dates, lieux et autres informations sont lus automatiquement.',
    image: 'upload',
    link: { href: '/media/upload', label: 'Ajouter des photos' },
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
    intro: 'Quatre chemins pour faire entrer vos souvenirs, du plus simple au plus massif.',
    image: 'upload',
    points: [
      { title: 'Glisser-déposer', text: 'Déposez photos et vidéos directement dans la page « Ajouter des photos », même par centaines.' },
      { title: 'Google Photos', text: 'Connectez votre compte et choisissez ce que vous rapatriez.' },
      { title: 'Google Drive', text: 'Piochez dans les dossiers de votre Drive : les photos arrivent en pleine qualité, sans passer par votre ordinateur.' },
      { title: 'Archive Takeout', text: 'Pour tout récupérer d\'un coup, avec les lieux et les albums d\'origine. Les photos déjà présentes sont enrichies, pas dupliquées.' },
    ],
    link: { href: '/media/upload', label: 'Ajouter des photos' },
  },
  {
    id: 'telephone',
    emoji: '📱',
    title: 'Depuis votre téléphone',
    intro: 'MemoryLane s\'installe comme une application et reçoit vos photos en deux gestes.',
    points: [
      { title: 'Installer l\'app', text: 'Ouvrez le site sur votre téléphone puis « Ajouter à l\'écran d\'accueil » (sur iPhone : bouton Partager de Safari) : MemoryLane se comporte comme une app.' },
      { title: 'Envoyer ses photos', text: 'Page « Ajouter des photos » › « Choisir dans mes photos » : cochez-en autant que vous voulez, puis « Envoyer ». Un mini-tuto s\'affiche à la première visite.' },
      { title: 'Partager → MemoryLane (Android)', text: 'Une fois l\'app installée sur Android, depuis la galerie : Partager puis MemoryLane. (Sur iPhone, passez par la page « Ajouter des photos ».)' },
      { title: 'Connexion sans mot de passe', text: 'Mot de passe oublié ? La page de connexion vous envoie un lien par e-mail : rien à retenir.' },
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
      { title: 'Partage avec un cercle', text: 'Dans le panneau de partage, ajoutez un cercle entier (la famille, un groupe d\'amis) : tous ses membres voient l\'album, même ceux qui le rejoindront plus tard.' },
      { title: 'Partage par lien', text: 'Un lien privé pour ceux qui n\'ont pas de compte — révocable à tout moment.' },
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
      { title: 'Navigation', text: 'Cliquez une personne pour recentrer l\'arbre sur elle ; zoomez, déplacez-vous librement. Sur mobile, un encart en bas donne accès à sa fiche.' },
      { title: 'Le panneau détail', text: 'À gauche : état civil (naissance, décès, âge) et famille proche cliquable — parents, conjoints, enfants, fratrie.' },
      { title: 'Édition rapide', text: 'Le crayon du panneau permet de compléter dates et lieux de naissance ou de décès sans quitter l\'arbre.' },
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

// ---------------------------------------------------------------------------
// Guide « amis » : mêmes bases (photos, albums, téléphone…), mais le cercle
// d'amis, le réseau et la fiche d'un ami remplacent foyer, arbre et famille.
// ---------------------------------------------------------------------------
const friendsSteps = [
  steps[0],
  steps[1],
  steps[2],
  {
    title: 'Mettez des noms sur les visages',
    body: 'Les visages sont détectés automatiquement, puis MemoryLane suggère qui est qui — confirmez d\'un clic. Chaque ami gagne sa fiche : vos souvenirs ensemble, comment vous vous êtes connus, toutes ses photos.',
    image: 'personnes',
    link: { href: '/people', label: 'Ouvrir Amis' },
    more: 'personnes',
  },
  {
    title: 'Créez votre groupe d\'amis',
    body: 'Les copains du lycée, le club de sport, les voisins : créez autant de groupes que vous voulez et invitez-y qui vous voulez. Partagez-y un album d\'un clic — ce qui est partagé dans un groupe reste dans ce groupe.',
    image: 'foyers',
    link: { href: '/households', label: 'Ouvrir Cercles' },
    more: 'foyers',
  },
  {
    title: 'Explorez le réseau et la carte',
    body: 'Le réseau se tisse tout seul : deux amis sont reliés quand ils apparaissent sur les mêmes photos. La carte, elle, pose vos souvenirs là où ils ont été pris.',
    image: 'carte',
    link: { href: '/network', label: 'Ouvrir le Réseau' },
    more: 'arbre',
  },
];

const friendsSectionOverrides = {
  accueil: {
    id: 'accueil',
    emoji: '🏡',
    title: 'La page d\'accueil',
    intro: 'Chaque jour, l\'accueil du groupe vous raconte quelque chose, à partir des photos du groupe.',
    image: 'dashboard',
    points: [
      { title: 'Vos souvenirs du jour', text: '« Il y a N ans, ce jour-là » : les photos du groupe prises ce jour-là, en diaporama.' },
      { title: 'Anniversaires', text: 'Les anniversaires des amis des 15 prochains jours (sans l\'âge : entre amis, on fête, on ne compte pas).' },
      { title: 'Les petites questions', text: '« Comment as-tu connu Léa ? », « Qui est sur cette photo ? » : une question à la fois, sur les photos du groupe. « Je ne sais pas » et « Passer » existent aussi !' },
    ],
    link: { href: '/dashboard', label: 'Ouvrir l\'accueil' },
  },
  personnes: {
    id: 'personnes',
    emoji: '🧑‍🤝‍🧑',
    title: 'Amis et visages',
    intro: 'Chaque ami a sa fiche, et les photos s\'y rattachent presque toutes seules.',
    image: 'personne',
    points: [
      { title: 'Suggestions de visages', text: 'MemoryLane reconnaît les visages déjà nommés et propose : confirmez ou corrigez d\'un clic.' },
      { title: 'Vos souvenirs ensemble', text: 'Sur la fiche d\'un ami : vos photos communes, de la première à la dernière, rangées par album.' },
      { title: 'Comment vous vous êtes connus', text: 'Chacun raconte sa version de la rencontre ; les autres versions s\'affichent à côté.' },
      { title: 'Une fiche qui existe déjà', text: 'Quelqu\'un de la famille ou d\'un autre groupe ? Choisissez-le dans la liste : MemoryLane propose de l\'ajouter au groupe plutôt que de créer un doublon.' },
    ],
    link: { href: '/people', label: 'Ouvrir Amis' },
  },
  foyers: {
    id: 'foyers',
    emoji: '🤝',
    title: 'Vos groupes d\'amis',
    intro: 'Un cercle d\'amis réunit des comptes qui partagent leurs souvenirs. Tout le monde peut créer le sien.',
    image: 'foyers',
    points: [
      { title: 'Créer un groupe', text: 'Page Cercles › « Nouveau cercle » : vous en devenez le responsable (membres, réglages).' },
      { title: 'Inviter', text: 'Ajoutez un compte depuis la page du groupe, ou invitez quelqu\'un par mail depuis sa fiche. Le responsable choisit qui peut inviter : lui seul ou tous les membres.' },
      { title: 'Étanche', text: 'Les fiches et les photos d\'un groupe ne sont visibles que de ses membres. Sur une photo commune, on voit seulement le nom des personnes des autres groupes.' },
      { title: 'Et la famille ?', text: 'Famille et amis sont deux mondes séparés. Un album peut être partagé avec les deux — celui d\'un mariage, par exemple.' },
    ],
    link: { href: '/households', label: 'Ouvrir Cercles' },
  },
  arbre: {
    id: 'arbre',
    emoji: '🕸️',
    title: 'Le réseau',
    intro: 'Le réseau du groupe se dessine tout seul à partir des photos : pas d\'amis à ajouter, pas de demandes, juste vos souvenirs.',
    image: 'carte',
    points: [
      { title: 'Qui avec qui', text: 'Deux personnes sont reliées quand elles apparaissent sur les mêmes photos ; plus le lien est épais, plus elles sont souvent ensemble.' },
      { title: 'Les autres groupes', text: 'Les amis d\'autres groupes présents sur vos photos apparaissent en îlots autour, avec le nom de leur groupe.' },
      { title: 'Le temps qui passe', text: 'La frise en bas montre le réseau année après année.' },
      { title: 'Depuis une fiche', text: 'Le bouton « Voir dans le réseau » centre le réseau sur cet ami.' },
    ],
    link: { href: '/network', label: 'Ouvrir le Réseau' },
  },
};

const guideSteps = computed(() => (isFriends.value ? friendsSteps : steps));
const guideSections = computed(() => (isFriends.value
  ? sections.map((s) => friendsSectionOverrides[s.id] ?? s)
  : sections));
</script>
