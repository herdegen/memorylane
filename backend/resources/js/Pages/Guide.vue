<template>
  <Head :title="$t('Guide d\'utilisation')" />
  <AppLayout>
    <div class="page-container">
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 scroll-smooth">

        <!-- En-tête -->
        <div class="page-header">
          <div class="text-xs font-semibold uppercase tracking-widest text-brand-700 mb-2">{{ $t('Bien démarrer') }}</div>
          <h1>{{ $t('Guide d\'utilisation') }}</h1>
          <p v-if="isFriends">
            {{ $t('MemoryLane rassemble vos photos entre amis : qui était là, quand, où — et comment vous vous êtes connus.') }}
            {{ $t('Suivez les premiers pas ci-dessous, puis piochez dans les fiches pour aller plus loin.') }}
          </p>
          <p v-else>
            {{ $t('MemoryLane rassemble les photos, les personnes et les histoires de la famille.') }}
            {{ $t('Suivez les premiers pas ci-dessous, puis piochez dans les fiches pour aller plus loin.') }}
          </p>
        </div>

        <!-- Sommaire -->
        <nav class="flex flex-wrap gap-2 mb-10" :aria-label="$t('Sommaire du guide')">
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
        <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400 mb-4">{{ $t('Vos premiers pas') }}</h2>
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
                        {{ $t('En savoir plus') }}
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
        <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400 mb-4">{{ $t('Toutes les fonctionnalités') }}</h2>
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
          {{ $t('Ce guide s\'enrichira au fil des nouveautés.') }}
          {{ $t('Une question ? Demandez à la personne qui administre votre MemoryLane.') }}
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
import { t } from '@/i18n';

const { isFriends } = useCircle();

// Les noms d'images correspondent aux vignettes générées par
// scripts/guide-captures.mjs (storage/app/guide/, servies authentifiées).

const steps = [
  {
    title: t('Importez vos photos et vidéos'),
    body: t('Glissez vos fichiers dans la page « Ajouter des photos » — les grosses vidéos passent aussi. Vous pouvez importer directement depuis Google Photos, Google Drive ou une archive Google Takeout. Les dates, lieux et autres informations sont lus automatiquement.'),
    image: 'upload',
    link: { href: '/media/upload', label: t('Ajouter des photos') },
    more: 'importer',
  },
  {
    title: t('Retrouvez tout dans Mes photos'),
    body: t('Toute votre bibliothèque, classée par année, avec recherche et filtres. Sélectionnez plusieurs médias (maj+clic pour une plage entière) pour les dater, les géolocaliser, les ranger en album ou les partager d\'un coup.'),
    image: 'mes-photos',
    link: { href: '/media', label: t('Ouvrir Mes photos') },
    more: 'photos',
  },
  {
    title: t('Créez des albums et partagez-les'),
    body: t('Regroupez un événement dans un album, ou laissez un album intelligent se remplir tout seul. Partagez à des comptes choisis, à tous les connectés, ou par lien pour la famille qui n\'a pas de compte.'),
    image: 'albums',
    link: { href: '/albums', label: t('Ouvrir Albums') },
    more: 'albums',
  },
  {
    title: t('Mettez des noms sur les visages'),
    body: t('Les visages sont détectés automatiquement pendant que vous naviguez, puis MemoryLane suggère qui est qui — confirmez d\'un clic. Chaque personne gagne sa fiche : sa famille, la frise de sa vie, toutes ses photos.'),
    image: 'personnes',
    link: { href: '/people', label: t('Ouvrir Personnes') },
    more: 'personnes',
  },
  {
    title: t('Partagez en foyer'),
    body: t('Un foyer, c\'est votre cercle familial : les photos que vous y partagez deviennent visibles de ses membres, et chacun peut aider à identifier les visages. Depuis Mes photos : sélection → « Foyer ».'),
    image: 'foyers',
    link: { href: '/households', label: t('Ouvrir Foyers') },
    more: 'foyers',
  },
  {
    title: t('Explorez l\'arbre et la carte'),
    body: t('L\'arbre généalogique se construit tout seul depuis les fiches des personnes ; la carte affiche vos souvenirs là où ils ont été pris. Deux façons de se promener dans la mémoire familiale.'),
    image: 'arbre',
    link: { href: '/family-tree', label: t('Ouvrir l\'Arbre') },
    more: 'arbre',
  },
];

const sections = [
  {
    id: 'accueil',
    emoji: '🏡',
    title: t('La page d\'accueil'),
    intro: t('Chaque jour, l\'accueil vous raconte quelque chose : il change selon la date et selon ce que la famille a déjà rempli.'),
    image: 'dashboard',
    points: [
      { title: t('Vos souvenirs du jour'), text: t('« Il y a N ans, ce jour-là » — cliquez une carte pour revivre les photos en diaporama.') },
      { title: t('Fêtes & anniversaires'), text: t('Anniversaires, fêtes des prénoms et anniversaires de mariage des 15 prochains jours.') },
      { title: t('La personne du jour'), text: t('Quand aucun souvenir ne tombe ce jour-là, une personne de la famille est mise à l\'honneur.') },
      { title: t('Les petites questions'), text: t('« Complétez la mémoire familiale » : une question à la fois (une date, un visage, un lieu…). Répondez si vous savez — chaque réponse enrichit les fiches pour tout le monde. « Je ne sais pas » et « Passer » existent aussi !') },
    ],
    link: { href: '/dashboard', label: t('Ouvrir l\'accueil') },
  },
  {
    id: 'photos',
    emoji: '🖼️',
    title: t('Mes photos'),
    intro: t('Votre bibliothèque complète, classée par année. C\'est ici qu\'on trie, qu\'on complète et qu\'on partage en masse.'),
    image: 'mes-photos',
    points: [
      { title: t('Filtres'), text: t('Par tags, type, durée ou qualité vidéo — combinables avec la recherche.') },
      { title: t('Sélection multiple'), text: t('Bouton « Sélectionner », puis clic par clic ou maj+clic pour toute une plage. « Tout sélectionner » prend l\'ensemble du filtre courant.') },
      { title: t('Actions de masse'), text: t('Dater, géolocaliser sur une carte, ajouter à un album ou partager au foyer — en une fois pour toute la sélection.') },
    ],
    link: { href: '/media', label: t('Ouvrir Mes photos') },
  },
  {
    id: 'fiche-photo',
    emoji: '🔍',
    title: t('La fiche d\'une photo ou vidéo'),
    intro: t('Cliquez n\'importe quel média pour ouvrir sa fiche : tout ce qu\'on sait de lui, et tout ce qu\'on peut lui apprendre.'),
    points: [
      { title: t('Visages'), text: t('Les visages détectés apparaissent encadrés : confirmez une suggestion ou choisissez la bonne personne.') },
      { title: t('Date et lieu'), text: t('Corrigez la date de prise de vue ou placez la photo sur la carte.') },
      { title: t('Tags'), text: t('Ajoutez des étiquettes (« Noël », « vacances »…) pour retrouver les photos par thème.') },
      { title: t('Clips vidéo'), text: t('Découpez un extrait d\'une longue vidéo pour n\'en garder que le meilleur moment.') },
    ],
  },
  {
    id: 'importer',
    emoji: '📥',
    title: t('Importer'),
    intro: t('Quatre chemins pour faire entrer vos souvenirs, du plus simple au plus massif.'),
    image: 'upload',
    points: [
      { title: t('Glisser-déposer'), text: t('Déposez photos et vidéos directement dans la page « Ajouter des photos », même par centaines.') },
      { title: 'Google Photos', text: t('Connectez votre compte et choisissez ce que vous rapatriez.') },
      { title: 'Google Drive', text: t('Piochez dans les dossiers de votre Drive : les photos arrivent en pleine qualité, sans passer par votre ordinateur.') },
      { title: t('Archive Takeout'), text: t('Pour tout récupérer d\'un coup, avec les lieux et les albums d\'origine. Les photos déjà présentes sont enrichies, pas dupliquées.') },
    ],
    link: { href: '/media/upload', label: t('Ajouter des photos') },
  },
  {
    id: 'telephone',
    emoji: '📱',
    title: t('Depuis votre téléphone'),
    intro: t('MemoryLane s\'installe comme une application et reçoit vos photos en deux gestes.'),
    points: [
      { title: t('Installer l\'app'), text: t('Ouvrez le site sur votre téléphone puis « Ajouter à l\'écran d\'accueil » (sur iPhone : bouton Partager de Safari) : MemoryLane se comporte comme une app.') },
      { title: t('Envoyer ses photos'), text: t('Page « Ajouter des photos » › « Choisir dans mes photos » : cochez-en autant que vous voulez, puis « Envoyer ». Un mini-tuto s\'affiche à la première visite.') },
      { title: t('Partager → MemoryLane (Android)'), text: t('Une fois l\'app installée sur Android, depuis la galerie : Partager puis MemoryLane. (Sur iPhone, passez par la page « Ajouter des photos ».)') },
      { title: t('Connexion sans mot de passe'), text: t('Mot de passe oublié ? La page de connexion vous envoie un lien par e-mail : rien à retenir.') },
    ],
  },
  {
    id: 'albums',
    emoji: '📚',
    title: t('Albums et partage'),
    intro: t('L\'album, c\'est l\'histoire racontée : un mariage, un été, une année. Et c\'est l\'unité de partage la plus simple.'),
    image: 'albums',
    points: [
      { title: t('Albums classiques'), text: t('Créez, choisissez la couverture, réordonnez les photos, ajoutez un diaporama.') },
      { title: t('Albums intelligents'), text: t('Définissez des critères (personnes, tags, dates) : l\'album se remplit tout seul au fil des imports.') },
      { title: t('Partage par compte'), text: t('Ouvrez l\'album à des comptes choisis ou à tous les connectés.') },
      { title: t('Partage avec un cercle'), text: t('Dans le panneau de partage, ajoutez un cercle entier (la famille, un groupe d\'amis) : tous ses membres voient l\'album, même ceux qui le rejoindront plus tard.') },
      { title: t('Partage par lien'), text: t('Un lien privé pour ceux qui n\'ont pas de compte — révocable à tout moment.') },
    ],
    link: { href: '/albums', label: t('Ouvrir Albums') },
  },
  {
    id: 'personnes',
    emoji: '👪',
    title: t('Personnes et visages'),
    intro: t('Chaque membre de la famille a sa fiche, et les photos s\'y rattachent presque toutes seules.'),
    image: 'personne',
    points: [
      { title: t('Détection automatique'), text: t('Les visages sont repérés discrètement pendant que vous naviguez — rien à lancer.') },
      { title: t('Suggestions'), text: t('MemoryLane reconnaît les visages déjà nommés et propose : confirmez ou corrigez d\'un clic.') },
      { title: t('La fiche personne'), text: t('Sa famille en mini-arbre, la frise de sa vie (naissance, mariage, métiers…), toutes ses photos.') },
      { title: t('Le récit de vie'), text: t('Bouton Diaporama sur la fiche : la carte vole de lieu en lieu et raconte sa vie en photos.') },
      { title: t('Lien de parenté'), text: t('Sur la fiche de quelqu\'un d\'autre, découvrez votre lien exact — l\'arbre le rejoue pas à pas.') },
    ],
    link: { href: '/people', label: t('Ouvrir Personnes') },
  },
  {
    id: 'foyers',
    emoji: '🤝',
    title: t('Foyers'),
    intro: t('Le foyer est votre cercle proche : on y partage des photos et on y identifie les visages ensemble.'),
    image: 'foyers',
    points: [
      { title: t('Partager des photos'), text: t('Depuis Mes photos, sélection → « Foyer » : elles deviennent visibles des membres.') },
      { title: t('Identifier ensemble'), text: t('Chaque membre peut nommer ou corriger les visages des photos partagées — la mamie qui reconnaît tout le monde devient précieuse.') },
      { title: t('Inviter'), text: t('Ajoutez un membre par son adresse email depuis la page du foyer.') },
    ],
    link: { href: '/households', label: t('Ouvrir Foyers') },
  },
  {
    id: 'arbre',
    emoji: '🌳',
    title: t('L\'arbre généalogique'),
    intro: t('L\'arbre se dessine tout seul à partir des fiches (parents, conjoints, enfants) et se visite comme un album.'),
    image: 'arbre',
    points: [
      { title: t('Navigation'), text: t('Cliquez une personne pour recentrer l\'arbre sur elle ; zoomez, déplacez-vous librement. Sur mobile, un encart en bas donne accès à sa fiche.') },
      { title: t('Le panneau détail'), text: t('À gauche : état civil (naissance, décès, âge) et famille proche cliquable — parents, conjoints, enfants, fratrie.') },
      { title: t('Édition rapide'), text: t('Le crayon du panneau permet de compléter dates et lieux de naissance ou de décès sans quitter l\'arbre.') },
      { title: t('Lien de parenté animé'), text: t('Depuis une fiche, le bouton « Lien de parenté » fait marcher l\'arbre de vous jusqu\'à elle, en surlignant le chemin.') },
    ],
    link: { href: '/family-tree', label: t('Ouvrir l\'Arbre') },
  },
  {
    id: 'carte',
    emoji: '🗺️',
    title: t('La carte'),
    intro: t('Tous les souvenirs géolocalisés, posés sur la carte du monde.'),
    image: 'carte',
    points: [
      { title: t('Explorer'), text: t('Zoomez sur une région pour retrouver les photos qui y ont été prises.') },
      { title: t('Compléter'), text: t('Une photo sans lieu ? Placez-la depuis sa fiche, ou en masse depuis Mes photos.') },
    ],
    link: { href: '/map', label: t('Ouvrir la Carte') },
  },
  {
    id: 'recherche',
    emoji: '🔎',
    title: t('Recherche et tags'),
    intro: t('La barre de recherche en haut trouve tout : personnes, albums, photos — même avec des fautes ou sans les accents.'),
    image: 'recherche',
    points: [
      { title: t('Recherche globale'), text: t('Tapez un prénom, un lieu, un mot du titre : les résultats arrivent en direct, les personnes proches d\'abord.') },
      { title: t('Tags'), text: t('Créez vos étiquettes colorées (« Noël », « école »…) et posez-les sur les photos pour naviguer par thème.') },
    ],
    link: { href: '/tags', label: t('Gérer les tags') },
  },
  {
    id: 'compte',
    emoji: '⚙️',
    title: t('Votre compte et le confort'),
    intro: t('Les petits réglages qui rendent l\'usage quotidien agréable.'),
    points: [
      { title: t('Mon profil'), text: t('Vos informations et votre mot de passe, dans le menu sous votre avatar.') },
      { title: t('Mode sombre'), text: t('Le bouton lune/soleil dans la barre bascule toute l\'application — elle suit sinon votre réglage système.') },
      { title: t('Ce guide'), text: t('Retrouvez-le à tout moment dans le menu sous votre avatar, même après avoir masqué le bloc de l\'accueil.') },
    ],
    link: { href: '/profile', label: t('Ouvrir Mon profil') },
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
    title: t('Mettez des noms sur les visages'),
    body: t('Les visages sont détectés automatiquement, puis MemoryLane suggère qui est qui — confirmez d\'un clic. Chaque ami gagne sa fiche : vos souvenirs ensemble, comment vous vous êtes connus, toutes ses photos.'),
    image: 'personnes',
    link: { href: '/people', label: t('Ouvrir Amis') },
    more: 'personnes',
  },
  {
    title: t('Créez votre groupe d\'amis'),
    body: t('Les copains du lycée, le club de sport, les voisins : créez autant de groupes que vous voulez et invitez-y qui vous voulez. Partagez-y un album d\'un clic — ce qui est partagé dans un groupe reste dans ce groupe.'),
    image: 'foyers',
    link: { href: '/households', label: t('Ouvrir Cercles') },
    more: 'foyers',
  },
  {
    title: t('Explorez le réseau et la carte'),
    body: t('Le réseau se tisse tout seul : deux amis sont reliés quand ils apparaissent sur les mêmes photos. La carte, elle, pose vos souvenirs là où ils ont été pris.'),
    image: 'carte',
    link: { href: '/network', label: t('Ouvrir le Réseau') },
    more: 'arbre',
  },
];

const friendsSectionOverrides = {
  accueil: {
    id: 'accueil',
    emoji: '🏡',
    title: t('La page d\'accueil'),
    intro: t('Chaque jour, l\'accueil du groupe vous raconte quelque chose, à partir des photos du groupe.'),
    image: 'dashboard',
    points: [
      { title: t('Vos souvenirs du jour'), text: t('« Il y a N ans, ce jour-là » : les photos du groupe prises ce jour-là, en diaporama.') },
      { title: t('Anniversaires'), text: t('Les anniversaires des amis des 15 prochains jours (sans l\'âge : entre amis, on fête, on ne compte pas).') },
      { title: t('Les petites questions'), text: t('« Comment as-tu connu Léa ? », « Qui est sur cette photo ? » : une question à la fois, sur les photos du groupe. « Je ne sais pas » et « Passer » existent aussi !') },
    ],
    link: { href: '/dashboard', label: t('Ouvrir l\'accueil') },
  },
  personnes: {
    id: 'personnes',
    emoji: '🧑‍🤝‍🧑',
    title: t('Amis et visages'),
    intro: t('Chaque ami a sa fiche, et les photos s\'y rattachent presque toutes seules.'),
    image: 'personne',
    points: [
      { title: t('Suggestions de visages'), text: t('MemoryLane reconnaît les visages déjà nommés et propose : confirmez ou corrigez d\'un clic.') },
      { title: t('Vos souvenirs ensemble'), text: t('Sur la fiche d\'un ami : vos photos communes, de la première à la dernière, rangées par album.') },
      { title: t('Comment vous vous êtes connus'), text: t('Chacun raconte sa version de la rencontre ; les autres versions s\'affichent à côté.') },
      { title: t('Une fiche qui existe déjà'), text: t('Quelqu\'un de la famille ou d\'un autre groupe ? Choisissez-le dans la liste : MemoryLane propose de l\'ajouter au groupe plutôt que de créer un doublon.') },
    ],
    link: { href: '/people', label: t('Ouvrir Amis') },
  },
  foyers: {
    id: 'foyers',
    emoji: '🤝',
    title: t('Vos groupes d\'amis'),
    intro: t('Un cercle d\'amis réunit des comptes qui partagent leurs souvenirs. Tout le monde peut créer le sien.'),
    image: 'foyers',
    points: [
      { title: t('Créer un groupe'), text: t('Page Cercles › « Nouveau cercle » : vous en devenez le responsable (membres, réglages).') },
      { title: t('Inviter'), text: t('Ajoutez un compte depuis la page du groupe, ou invitez quelqu\'un par mail depuis sa fiche. Le responsable choisit qui peut inviter : lui seul ou tous les membres.') },
      { title: t('Étanche'), text: t('Les fiches et les photos d\'un groupe ne sont visibles que de ses membres. Sur une photo commune, on voit seulement le nom des personnes des autres groupes.') },
      { title: t('Et la famille ?'), text: t('Famille et amis sont deux mondes séparés. Un album peut être partagé avec les deux — celui d\'un mariage, par exemple.') },
    ],
    link: { href: '/households', label: t('Ouvrir Cercles') },
  },
  arbre: {
    id: 'arbre',
    emoji: '🕸️',
    title: t('Le réseau'),
    intro: t('Le réseau du groupe se dessine tout seul à partir des photos : pas d\'amis à ajouter, pas de demandes, juste vos souvenirs.'),
    image: 'carte',
    points: [
      { title: t('Qui avec qui'), text: t('Deux personnes sont reliées quand elles apparaissent sur les mêmes photos ; plus le lien est épais, plus elles sont souvent ensemble.') },
      { title: t('Les autres groupes'), text: t('Les amis d\'autres groupes présents sur vos photos apparaissent en îlots autour, avec le nom de leur groupe.') },
      { title: t('Le temps qui passe'), text: t('La frise en bas montre le réseau année après année.') },
      { title: t('Depuis une fiche'), text: t('Le bouton « Voir dans le réseau » centre le réseau sur cet ami.') },
    ],
    link: { href: '/network', label: t('Ouvrir le Réseau') },
  },
};

const guideSteps = computed(() => (isFriends.value ? friendsSteps : steps));
const guideSections = computed(() => (isFriends.value
  ? sections.map((s) => friendsSectionOverrides[s.id] ?? s)
  : sections));
</script>
