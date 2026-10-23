import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { escapeHtml } from '@/utils/html';
import { t } from '@/i18n';

/**
 * Visite guidée « premiers pas », page par page. Chaque étape vit sur une
 * page (composant Inertia) : pour passer à une étape d'une autre page, on
 * mémorise l'étape (localStorage), on navigue, et GuidedTour.vue reprend la
 * visite au montage de la page suivante. Une étape dont l'élément n'existe pas
 * (page vide, carte masquée) s'affiche centrée.
 */

const STORAGE_KEY = 'ml_tour_step';
let active = null; // instance driver.js courante
let ctx = null; // { page } fourni par GuidedTour
let tourNavigating = false; // vrai pendant une navigation lancée par la visite

// driver.js (JS + CSS) n'est chargé qu'à l'affichage d'une étape : la visite
// est rare, inutile de l'embarquer dans le layout de toutes les pages.
let driverPromise = null;
const loadDriver = () => {
  driverPromise ??= Promise.all([
    import('driver.js'),
    import('driver.js/dist/driver.css'),
  ])
    .then(([mod]) => mod.driver)
    .catch((error) => {
      driverPromise = null; // nouvel essai à la prochaine étape
      throw error;
    });
  return driverPromise;
};

const firstName = (name) => (name || '').trim().split(' ')[0] || '';

function buildSteps(page) {
  const user = page.props.auth?.user ?? {};
  const circle = page.props.circle ?? {};
  const isFriends = circle.mode === 'friends';
  const seesFamily = circle.family !== false;
  // Téléphone (barre d'onglets visible, sous sm) : on montre les onglets et le
  // bouton ⊕ plutôt que la page d'envoi, et le compte est l'initiale en haut.
  // Fixé au début de la visite (mémorisé avec l'étape) : tourner le téléphone
  // en cours de route ne doit pas changer la liste des étapes.
  const mobile = tourMobile();
  const welcomeText = mobile
    ? t('Une petite visite de 2 minutes pour découvrir MemoryLane ? Vous pourrez la relancer à tout moment depuis votre compte (votre initiale, en haut à droite).')
    : t('Une petite visite de 2 minutes pour découvrir MemoryLane ? Vous pourrez la relancer à tout moment depuis le menu.');

  const steps = [
    {
      component: 'Dashboard', path: '/dashboard',
      // driver.js insère le titre en innerHTML : le prénom (saisi) est échappé.
      title: t('Bienvenue, :name !', { name: escapeHtml(firstName(user.name)) }),
      text: welcomeText,
    },
    {
      component: 'Dashboard', path: '/dashboard', element: '[data-tour="today"]',
      title: t('Chaque jour, un souvenir'),
      text: t('L\'accueil vous montre les photos du même jour les années passées, la personne du jour, les anniversaires et les fêtes.'),
    },
    {
      component: 'Dashboard', path: '/dashboard', element: '[data-tour="quests"]',
      title: t('Les petites questions'),
      text: t('« Qui est sur cette photo ? », « En quelle année est-elle née ? »… Répondez quand vous savez : c\'est ainsi que vos souvenirs se complètent.'),
    },
    ...(mobile ? [
      {
        component: 'Dashboard', path: '/dashboard', element: '[data-tour="tabbar"]',
        title: t('Tout est en bas'),
        text: isFriends
          ? t('Accueil, Photos, Albums et Amis : les onglets restent sous votre pouce, sur toutes les pages.')
          : t('Accueil, Photos, Albums et Proches : les onglets restent sous votre pouce, sur toutes les pages.'),
      },
      {
        component: 'Dashboard', path: '/dashboard', element: '[data-tour="add"]',
        title: t('Ajouter vos photos'),
        text: t('Le bouton + : choisissez dans la galerie du téléphone, ou prenez une photo. Elles sont partagées avec votre cercle par défaut.'),
      },
    ] : [
      {
        component: 'Media/Upload', path: '/media/upload', element: '[data-tour="upload-pick"]',
        title: t('Ajouter vos photos'),
        text: t('C\'est ici, depuis votre téléphone ou votre ordinateur. Elles sont partagées avec votre cercle par défaut.'),
      },
    ]),
    {
      component: 'Media/Index', path: '/media', element: '[data-tour="gallery"]',
      title: t('Mes photos'),
      text: t('Tout ce que vous avez envoyé, rangé par date. Ouvrez une photo pour mettre des noms sur les visages ou corriger la date.'),
    },
  ];

  if (!isFriends && seesFamily) {
    steps.push({
      component: 'FamilyTree/Index', path: '/family-tree', element: '[data-tour="tree"]',
      title: t('L\'arbre de la famille'),
      text: t('Touchez une carte pour voir la personne, puis ouvrez sa fiche complète.'),
    });
  }

  if (user.person_id && !isFriends) {
    steps.push({
      component: 'People/Show', path: `/people/${user.person_id}`, element: '[data-tour="family-panel"]',
      title: t('Votre fiche'),
      text: t('Complétez la famille ici : ajoutez un enfant, un conjoint ou un parent oublié. Sur la fiche d\'un proche, le bouton « Inviter sur MemoryLane » lui envoie une invitation.'),
    });
  }

  steps.push(
    {
      component: 'Albums/Index', path: '/albums', element: '[data-tour="albums"]',
      title: t('Les albums'),
      text: t('Les souvenirs rangés par histoire : un mariage, des vacances, une cousinade. Les vôtres et ceux que vos proches partagent.'),
    },
    {
      component: 'Albums/Index', path: '/albums', element: mobile ? '[data-tour="account"]' : undefined,
      title: t('C\'est parti !'),
      text: mobile
        ? t('Vous savez l\'essentiel. Le guide complet, cette visite et vos cercles sont dans votre compte : touchez votre initiale, en haut à droite. Bonne balade dans les souvenirs !')
        : t('Vous savez l\'essentiel. Le guide complet et cette visite restent dans le menu (votre nom, en haut à droite). Bonne balade dans les souvenirs !'),
    },
  );

  return steps;
}

// Une visite abandonnée en route ne reprend pas des heures plus tard.
const STALE_MS = 30 * 60 * 1000;

const readSaved = () => {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
    return saved && Date.now() - saved.t <= STALE_MS ? saved : null;
  } catch (_) {
    return null;
  }
};
const readStep = () => readSaved()?.i ?? null;
const screenIsMobile = () => typeof window !== 'undefined' && window.innerWidth < 640;
// Parcours téléphone ou ordinateur : celui de la visite en cours, sinon l'écran actuel.
const tourMobile = () => readSaved()?.m ?? screenIsMobile();
const writeStep = (i) => {
  const m = i === 0 ? screenIsMobile() : tourMobile();
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify({ i, m, t: Date.now() })); } catch (_) { /* navigation privée */ }
};
const clearStep = () => {
  try { localStorage.removeItem(STORAGE_KEY); } catch (_) { /* idem */ }
};

// Attend qu'un élément apparaisse (contenus chargés en différé : quêtes, arbre…).
const waitFor = (selector, timeout = 3500) => new Promise((resolve) => {
  if (!selector) return resolve(null);
  const started = Date.now();
  const tick = () => {
    const el = document.querySelector(selector);
    if (el && el.getBoundingClientRect().height > 0) return resolve(el);
    if (Date.now() - started > timeout) return resolve(null);
    setTimeout(tick, 150);
  };
  tick();
});

const closeActive = () => {
  const d = active;
  active = null;
  d?.destroy();
};

function markDone() {
  const user = ctx?.page.props.auth?.user;
  if (user && !user.onboarding?.tour) {
    user.onboarding = { ...(user.onboarding || {}), tour: new Date().toISOString() };
    axios.post('/onboarding/tour').catch(() => {});
  }
}

export function finishTour() {
  closeActive();
  clearStep();
  markDone();
}

function goTo(i) {
  const steps = buildSteps(ctx.page);
  if (i < 0) return;
  if (i >= steps.length) {
    finishTour();
    return;
  }
  writeStep(i);
  const step = steps[i];
  if (ctx.page.component !== step.component) {
    closeActive();
    tourNavigating = true; // c'est la visite qui change de page
    router.visit(step.path); // GuidedTour reprend sur la page suivante
    return;
  }
  render(i, steps);
}

async function render(i, steps) {
  closeActive();
  const step = steps[i];
  let el;
  let driver;
  try {
    [el, driver] = await Promise.all([waitFor(step.element), loadDriver()]);
  } catch (error) {
    // Réseau coupé : la visite reprendra à la prochaine page (étape mémorisée).
    console.error('Visite guidée indisponible :', error);
    return;
  }
  // La page a pu changer pendant l'attente.
  if (ctx.page.component !== step.component || readStep() !== i) return;
  // Un autre rendu a pu aboutir pendant l'attente : une seule bulle à la fois.
  closeActive();

  const isFirst = i === 0;
  const isLast = i === steps.length - 1;
  // Une instance driver.js par étape : elle se croit toujours à sa dernière
  // étape, d'où le même libellé pour « suivant » et « terminé ».
  const nextLabel = isFirst ? t('C\'est parti →') : (isLast ? t('Terminer') : t('Suivant →'));

  active = driver({
    popoverClass: 'ml-tour',
    animate: true,
    smoothScroll: true,
    overlayOpacity: 0.55,
    stagePadding: 8,
    stageRadius: 14,
    allowKeyboardControl: true,
    disableActiveInteraction: true,
    // Clic sur le fond : on ne ferme pas par mégarde.
    overlayClickBehavior: () => {},
    showButtons: ['next', 'previous', 'close'],
    nextBtnText: nextLabel,
    doneBtnText: nextLabel,
    prevBtnText: isFirst ? t('Plus tard') : '←',
    onNextClick: () => goTo(i + 1),
    onPrevClick: () => (isFirst ? finishTour() : goTo(i - 1)),
    onCloseClick: () => finishTour(),
    onDestroyStarted: () => finishTour(), // Échap
    steps: [{
      element: el || undefined,
      popover: {
        title: step.title,
        description: `${step.text}<span class="ml-tour-progress">${i + 1} / ${steps.length}</span>`,
      },
    }],
  });
  active.drive();
}

/** Une visite est-elle en cours (étape mémorisée) ? Les pages évitent alors d'ouvrir leurs propres fenêtres d'aide. */
export function tourInProgress() {
  return readStep() !== null;
}

/**
 * À appeler au montage de chaque page (GuidedTour.vue) : reprend une visite en
 * cours, ou la propose à la première arrivée sur l'accueil.
 */
export function attachTour(page) {
  ctx = { page };
  const user = page.props.auth?.user;
  if (!user || user.simple_mode || user.is_guest) return;

  const current = readStep();
  const fromTour = tourNavigating;
  tourNavigating = false;
  if (current !== null) {
    // Partie ailleurs par elle-même (onglet, lien) alors que l'étape vit sur
    // une autre page : on n'impose pas un retour en arrière — la visite
    // s'efface (sans être marquée faite : l'accueil la reproposera).
    const step = buildSteps(page)[current];
    if (!fromTour && step && step.component !== page.component) {
      closeActive();
      clearStep();
      return;
    }
    goTo(current);
    return;
  }
  // Démo : compte partagé, la visite est proposée par DemoWelcome (par navigateur).
  if (page.component === 'Dashboard' && !user.onboarding?.tour && !page.props.demo) {
    goTo(0);
  }
}

export function detachTour() {
  closeActive();
}

/** Bouton « Visite guidée » du menu. */
export function startTour(page) {
  ctx = { page };
  writeStep(0);
  goTo(0);
}
