import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { escapeHtml } from '@/utils/html';

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

const firstName = (name) => (name || '').trim().split(' ')[0] || '';

function buildSteps(page) {
  const user = page.props.auth?.user ?? {};
  const circle = page.props.circle ?? {};
  const isFriends = circle.mode === 'friends';
  const seesFamily = circle.family !== false;

  const steps = [
    {
      component: 'Dashboard', path: '/dashboard',
      // driver.js insère le titre en innerHTML : le prénom (saisi) est échappé.
      title: `Bienvenue, ${escapeHtml(firstName(user.name))} !`,
      text: 'Une petite visite de 2 minutes pour découvrir MemoryLane ? Vous pourrez la relancer à tout moment depuis le menu.',
    },
    {
      component: 'Dashboard', path: '/dashboard', element: '[data-tour="today"]',
      title: 'Chaque jour, un souvenir',
      text: 'L\'accueil vous montre les photos du même jour les années passées, la personne du jour, les anniversaires et les fêtes.',
    },
    {
      component: 'Dashboard', path: '/dashboard', element: '[data-tour="quests"]',
      title: 'Les petites questions',
      text: '« Qui est sur cette photo ? », « En quelle année est-elle née ? »… Répondez quand vous savez : c\'est ainsi que la mémoire de la famille se complète.',
    },
    {
      component: 'Media/Upload', path: '/media/upload', element: '[data-tour="upload-pick"]',
      title: 'Ajouter vos photos',
      text: 'C\'est ici, depuis votre téléphone ou votre ordinateur. Elles sont partagées avec la famille par défaut.',
    },
    {
      component: 'Media/Index', path: '/media', element: '[data-tour="gallery"]',
      title: 'Mes photos',
      text: 'Tout ce que vous avez envoyé, rangé par date. Ouvrez une photo pour mettre des noms sur les visages ou corriger la date.',
    },
  ];

  if (!isFriends && seesFamily) {
    steps.push({
      component: 'FamilyTree/Index', path: '/family-tree', element: '[data-tour="tree"]',
      title: 'L\'arbre de la famille',
      text: 'Touchez une carte pour voir la personne, puis ouvrez sa fiche complète.',
    });
  }

  if (user.person_id && !isFriends) {
    steps.push({
      component: 'People/Show', path: `/people/${user.person_id}`, element: '[data-tour="family-panel"]',
      title: 'Votre fiche',
      text: 'Complétez la famille ici : ajoutez un enfant, un conjoint ou un parent oublié. Sur la fiche d\'un proche, le bouton « Inviter sur MemoryLane » lui envoie une invitation.',
    });
  }

  steps.push(
    {
      component: 'Albums/Index', path: '/albums', element: '[data-tour="albums"]',
      title: 'Les albums',
      text: 'Les souvenirs rangés par histoire : un mariage, des vacances, une cousinade. Les vôtres et ceux que la famille partage.',
    },
    {
      component: 'Albums/Index', path: '/albums',
      title: 'C\'est parti !',
      text: 'Vous savez l\'essentiel. Le guide complet et cette visite restent dans le menu (votre nom, en haut à droite). Bonne balade dans les souvenirs !',
    },
  );

  return steps;
}

// Une visite abandonnée en route ne reprend pas des heures plus tard.
const STALE_MS = 30 * 60 * 1000;

const readStep = () => {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
    if (!saved || Date.now() - saved.t > STALE_MS) return null;
    return saved.i;
  } catch (_) {
    return null;
  }
};
const writeStep = (i) => {
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify({ i, t: Date.now() })); } catch (_) { /* navigation privée */ }
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
    router.visit(step.path); // GuidedTour reprend sur la page suivante
    return;
  }
  render(i, steps);
}

async function render(i, steps) {
  closeActive();
  const step = steps[i];
  const el = await waitFor(step.element);
  // La page a pu changer pendant l'attente.
  if (ctx.page.component !== step.component || readStep() !== i) return;

  const isFirst = i === 0;
  const isLast = i === steps.length - 1;
  // Une instance driver.js par étape : elle se croit toujours à sa dernière
  // étape, d'où le même libellé pour « suivant » et « terminé ».
  const nextLabel = isFirst ? 'C\'est parti →' : (isLast ? 'Terminer' : 'Suivant →');

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
    prevBtnText: isFirst ? 'Plus tard' : '←',
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

/**
 * À appeler au montage de chaque page (GuidedTour.vue) : reprend une visite en
 * cours, ou la propose à la première arrivée sur l'accueil.
 */
export function attachTour(page) {
  ctx = { page };
  const user = page.props.auth?.user;
  if (!user || user.simple_mode || user.is_guest) return;

  const current = readStep();
  if (current !== null) {
    goTo(current);
    return;
  }
  if (page.component === 'Dashboard' && !user.onboarding?.tour) {
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
