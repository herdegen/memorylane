import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { escapeHtml } from '@/utils/html';
import { t, tc } from '@/i18n';

/**
 * Premiers pas, en deux temps (nouveaux comptes) :
 *
 * 1. Un accueil très court sur la page d'accueil (le souvenir du jour, les
 *    quêtes), qui mène à l'arbre.
 * 2. Puis un mini-tuto la PREMIÈRE fois qu'on ouvre chaque page (arbre, sa
 *    fiche, une photo, Mes photos, Albums, Histoires) : 2-3 bulles, au moment
 *    où la personne en a besoin, plutôt que tout d'un coup.
 *
 * Les tutos de page ne s'activent que pour qui a suivi le nouvel accueil (les
 * comptes existants n'en voient pas) ; « Visite guidée » dans le menu les
 * réarme. Les pages qui montrent quelque chose en mouvement (l'arbre qui
 * dézoome, la marche du lien de parenté, le diaporama de la frise) fournissent
 * leurs gestes via provideTourActions().
 */

const STORAGE_KEY = 'ml_tour_step';
let active = null; // instance driver.js courante
let ctx = null; // { page } fourni par GuidedTour
let tourNavigating = false; // vrai pendant une navigation lancée par la visite
let pageTour = null; // tuto de page en cours : { key, steps, i }
const pageActions = {}; // composant Inertia → gestes fournis par la page

// driver.js (JS + CSS) n'est chargé qu'à l'affichage d'une étape.
let driverPromise = null;
const loadDriver = () => {
  driverPromise ??= Promise.all([
    import('driver.js'),
    import('driver.js/dist/driver.css'),
  ])
    .then(([mod]) => mod.driver)
    .catch((error) => {
      driverPromise = null;
      throw error;
    });
  return driverPromise;
};

const firstName = (name) => (name || '').trim().split(' ')[0] || '';
const screenIsMobile = () => typeof window !== 'undefined' && window.innerWidth < 640;

/** Une page fournit ses gestes au tuto (appel dans son setup). */
export function provideTourActions(component, actions) {
  pageActions[component] = actions;
}

// ── Accueil (nouveaux comptes) ───────────────────────────────────────────

function welcomeSteps(page) {
  const user = page.props.auth?.user ?? {};
  const circle = page.props.circle ?? {};
  const toTree = circle.mode !== 'friends' && circle.family !== false && !!user.person_id;
  const mobile = tourMobile();

  return [
    {
      // driver.js insère le titre en innerHTML : le prénom (saisi) est échappé.
      title: t('Bienvenue, :name !', { name: escapeHtml(firstName(user.name)) }),
      text: t('MemoryLane rassemble les souvenirs et l’histoire de votre famille. Un petit tour d’une minute ?'),
    },
    {
      element: '[data-tour="today"]',
      title: t('Chaque jour, un souvenir'),
      text: t('L\'accueil vous montre les photos du même jour les années passées, la personne du jour, les anniversaires et les fêtes.'),
    },
    {
      element: '[data-tour="quests"]',
      title: t('Les petites questions'),
      text: t('« Qui est sur cette photo ? », « En quelle année est-elle née ? »… Répondez quand vous savez : c\'est ainsi que vos souvenirs se complètent.'),
    },
    ...(mobile ? [{
      element: '[data-tour="tabbar"]',
      title: t('Tout est en bas'),
      text: circle.mode === 'friends'
        ? t('Accueil, Photos, Albums et Amis : les onglets restent sous votre pouce, sur toutes les pages.')
        : t('Accueil, Photos, Albums et Proches : les onglets restent sous votre pouce, sur toutes les pages.'),
    }] : []),
    {
      title: toTree ? t('Et maintenant, votre famille') : t('C\'est parti !'),
      text: toTree
        ? t('Vous êtes déjà dans l’arbre : allons voir. Ensuite, chaque page vous montrera l’essentiel la première fois que vous l’ouvrirez.')
        : t('Chaque page vous montrera l’essentiel la première fois que vous l’ouvrirez. Bonne balade dans les souvenirs !'),
      nextLabel: toTree ? t('Voir l’arbre →') : t('Terminer'),
      onNext: () => {
        finishTour();
        if (toTree) router.visit('/family-tree');
      },
    },
  ];
}

// ── Tutos de page (première ouverture de chaque page) ────────────────────

/** Tuto de la page courante, ou null (page sans tuto, ou cas non concerné). */
function pageTourFor(page) {
  const user = page.props.auth?.user ?? {};
  const circle = page.props.circle ?? {};
  const actions = pageActions[page.component] || {};
  const family = circle.mode !== 'friends';

  switch (page.component) {
    case 'FamilyTree/Index': {
      if (!family || !user.person_id) return null;
      return {
        key: 'page_tree',
        steps: [
          {
            before: async () => { await actions.ready?.(); actions.focusSelf?.(); await new Promise((r) => setTimeout(r, 800)); },
            element: `.card[data-id="${user.person_id}"]`,
            title: t('Vous voici'),
            text: t('Votre carte dans l’arbre de la famille. Touchez une carte pour voir la personne, puis ouvrez sa fiche.'),
          },
          {
            before: async () => { actions.zoomOut?.(); await new Promise((r) => setTimeout(r, 900)); },
            // Ordinateur : bulle à côté du compteur (colonne de gauche), l'arbre reste visible.
            element: screenIsMobile() ? undefined : '[data-tour="tree-count"]',
            title: t('Et voici votre famille'),
            text: () => tc(':count personne, de génération en génération. Faites glisser l’arbre, zoomez, touchez une carte.|:count personnes, de génération en génération. Faites glisser l’arbre, zoomez, touchez une carte.', actions.count?.() ?? 0),
          },
          {
            when: () => !!actions.farAncestor?.(),
            title: t('D’où vous venez'),
            text: () => t('Voyons votre lien avec :name, de carte en carte.', { name: escapeHtml(actions.farAncestor().name) }),
            nextLabel: t('Voir le chemin →'),
            onNext: () => { const target = actions.farAncestor(); finishPageTour(); actions.walkTo?.(target.id); },
          },
        ],
      };
    }
    case 'People/Show': {
      if (!page.props.isSelf || !family) return null;
      return {
        key: 'page_fiche',
        steps: [
          {
            before: async () => { await actions.ready?.(); },
            element: '#frise',
            title: t('Votre frise de vie'),
            text: () => ((actions.eventsCount?.() ?? 0) < 5
              ? t('Elle est encore un peu vide : c’est à vous de la remplir ! Naissance, études, métiers, déménagements, voyages : chaque moment s’y place, avec ses photos.')
              : t('Toute votre vie sur une ligne : chaque moment, avec ses photos.')),
          },
          {
            element: '[data-tour="tell-life"]',
            title: t('Racontez votre vie'),
            text: t('Étape par étape, à l’écrit ou à voix haute : chaque réponse remplit la frise.'),
            nextLabel: () => ((actions.eventsCount?.() ?? 0) > 0 ? t('Voir ma vie en diaporama →') : t('Terminer')),
            onNext: () => {
              const play = (actions.eventsCount?.() ?? 0) > 0;
              finishPageTour();
              if (play) actions.play?.();
            },
          },
        ],
      };
    }
    case 'Media/Show': {
      if (page.props.media?.type !== 'photo') return null;
      const hasFaces = (page.props.media.detected_faces || []).length > 0;
      return {
        key: 'page_photo',
        steps: [
          hasFaces
            ? {
              element: '[data-tour="faces"]',
              title: t('Les visages'),
              text: t('MemoryLane repère les visages. Touchez un cadre pour dire qui c’est : la photo rejoint sa fiche.'),
            }
            : {
              element: '[data-tour="people"]',
              title: t('Qui est sur la photo ?'),
              text: t('Ajoutez les personnes présentes : la photo apparaîtra sur leur fiche.'),
            },
          {
            element: '[data-tour="voice"]',
            title: t('Racontez cette photo'),
            text: t('Enregistrez un souvenir à voix haute : la famille l’écoutera en regardant la photo.'),
          },
          {
            element: '[data-tour="info"]',
            title: t('Une date, un lieu'),
            text: t('Corrigez la date (même approximative : « vers 1950 ») ou le lieu : toute la famille en profite.'),
          },
        ],
      };
    }
    case 'Media/Index':
      return {
        key: 'page_media',
        steps: [
          {
            element: '[data-tour="filters"]',
            title: t('Retrouver une photo'),
            text: t('Triez, choisissez une période ou des personnes : « Mamie et moi dans les années 90 ». Les lettres et papiers scannés sont dans « Documents ».'),
          },
          {
            element: '[data-tour="gallery"]',
            title: t('Mes photos'),
            text: t('Tout ce que vous avez envoyé, rangé par date. Ouvrez une photo pour mettre des noms sur les visages ou corriger la date.'),
          },
        ],
      };
    case 'Albums/Index':
      return {
        key: 'page_albums',
        steps: [
          {
            element: '[data-tour="albums"]',
            title: t('Les albums'),
            text: t('Les souvenirs rangés par histoire : un mariage, des vacances, une cousinade. Les vôtres et ceux que vos proches partagent.'),
          },
          ...(family ? [{
            title: t('Et les histoires'),
            text: t('Un album raconte souvent une histoire : ouvrez-en un et touchez « Raconter l’histoire ». Toutes les histoires de la famille sont dans « Histoires ».'),
          }] : []),
        ],
      };
    case 'Stories/Index':
      return {
        key: 'page_stories',
        steps: [{
          element: '[data-tour="new-story"]',
          title: t('Les histoires de famille'),
          text: t('Une anecdote, un souvenir, une affaire retrouvée dans les archives : écrivez-la, la famille la lit et ajoute ce qu’elle sait.'),
        }],
      };
    default:
      return null;
  }
}

/** Ce compte a-t-il déjà vu le tuto de cette page (depuis la dernière activation) ? */
function pageTourDone(user, key) {
  const armed = Date.parse(user.onboarding?.page_tours || '');
  if (Number.isNaN(armed)) return true; // tutos de page non activés (comptes existants)
  const done = Date.parse(user.onboarding?.[key] || '');

  return !Number.isNaN(done) && done >= armed;
}

function markOnboarding(step) {
  const user = ctx?.page.props.auth?.user;
  if (!user) return;
  user.onboarding = { ...(user.onboarding || {}), [step]: new Date().toISOString() };
  axios.post(`/onboarding/${step}`).catch(() => {});
}

function finishPageTour() {
  const current = pageTour;
  pageTour = null;
  closeActive();
  if (current) markOnboarding(current.key);
}

async function goToPage(i) {
  if (!pageTour) return;
  const { steps } = pageTour;
  // Étapes sans objet ici (pas d'ancêtre connu…) : sautées.
  while (i < steps.length && steps[i].when && !steps[i].when()) i++;
  if (i >= steps.length) {
    finishPageTour();
    return;
  }
  pageTour.i = i;
  const step = steps[i];
  const token = pageTour;
  try {
    await step.before?.();
  } catch (_) { /* geste indisponible : la bulle s'affiche quand même */ }
  if (pageTour !== token) return;
  const visible = steps.filter((s) => !s.when || s.when());
  render({
    step,
    index: visible.indexOf(step),
    total: visible.length,
    isFirst: i === 0,
    isLast: steps.slice(i + 1).every((s) => s.when && !s.when()),
    component: ctx.page.component,
    onNext: () => (step.onNext ? step.onNext() : goToPage(i + 1)),
    onPrev: () => goToPage(Math.max(0, i - 1)),
    onClose: finishPageTour,
    stillCurrent: () => pageTour === token && pageTour.i === i,
  });
}

// ── Accueil : étapes mémorisées (une recharge reprend où on en était) ────

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
const waitFor = (selector, timeout = 4000) => new Promise((resolve) => {
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

export function finishTour() {
  closeActive();
  clearStep();
  const user = ctx?.page.props.auth?.user;
  if (user && !user.onboarding?.tour) markOnboarding('tour');
}

function goTo(i) {
  if (ctx.page.component !== 'Dashboard') {
    // L'accueil vit sur la page d'accueil : on y retourne.
    writeStep(i);
    closeActive();
    tourNavigating = true;
    router.visit('/dashboard');
    return;
  }
  const steps = welcomeSteps(ctx.page);
  if (i < 0) return;
  if (i >= steps.length) {
    finishTour();
    return;
  }
  writeStep(i);
  const step = steps[i];
  render({
    step,
    index: i,
    total: steps.length,
    isFirst: i === 0,
    isLast: i === steps.length - 1,
    component: 'Dashboard',
    onNext: () => (step.onNext ? step.onNext() : goTo(i + 1)),
    onPrev: () => (i === 0 ? finishTour() : goTo(i - 1)),
    onClose: finishTour,
    stillCurrent: () => readStep() === i,
    welcome: true,
  });
}

// ── Affichage d'une bulle (accueil et tutos de page) ─────────────────────

const valueOf = (v) => (typeof v === 'function' ? v() : v);

async function render({ step, index, total, isFirst, isLast, component, onNext, onPrev, onClose, stillCurrent, welcome = false }) {
  closeActive();
  let el;
  let driver;
  try {
    [el, driver] = await Promise.all([waitFor(step.element), loadDriver()]);
  } catch (error) {
    console.error('Visite guidée indisponible :', error);
    return;
  }
  // La page a pu changer pendant l'attente.
  if (ctx.page.component !== component || !stillCurrent()) return;
  closeActive();

  const nextLabel = valueOf(step.nextLabel)
    ?? (welcome && isFirst ? t('C\'est parti →') : (isLast ? t('Terminer') : t('Suivant →')));
  const prevLabel = welcome && isFirst ? t('Plus tard') : '←';

  active = driver({
    popoverClass: 'ml-tour',
    animate: true,
    smoothScroll: true,
    // Bulle centrée (vue d'ensemble de l'arbre…) : fond moins sombre, on voit derrière.
    overlayOpacity: el ? 0.55 : 0.3,
    stagePadding: 8,
    stageRadius: 14,
    allowKeyboardControl: true,
    disableActiveInteraction: true,
    // Clic sur le fond : on ne ferme pas par mégarde.
    overlayClickBehavior: () => {},
    showButtons: isFirst && !welcome ? ['next', 'close'] : ['next', 'previous', 'close'],
    nextBtnText: nextLabel,
    doneBtnText: nextLabel,
    prevBtnText: prevLabel,
    onNextClick: onNext,
    onPrevClick: onPrev,
    onCloseClick: onClose,
    onDestroyStarted: onClose, // Échap
    steps: [{
      element: el || undefined,
      popover: {
        title: valueOf(step.title),
        description: `${valueOf(step.text)}${total > 1 ? `<span class="ml-tour-progress">${index + 1} / ${total}</span>` : ''}`,
      },
    }],
  });
  active.drive();
}

/** Une visite est-elle en cours ? Les pages évitent alors d'ouvrir leurs propres fenêtres d'aide. */
export function tourInProgress() {
  return readStep() !== null || pageTour !== null;
}

/**
 * À appeler au montage de chaque page (GuidedTour.vue) : reprend l'accueil en
 * cours, le propose à la première arrivée sur l'accueil, ou lance le tuto de
 * la page à sa première ouverture.
 */
export function attachTour(page) {
  ctx = { page };
  pageTour = null;
  const user = page.props.auth?.user;
  if (!user || user.simple_mode || user.is_guest) return;

  const current = readStep();
  const fromTour = tourNavigating;
  tourNavigating = false;
  if (current !== null) {
    // Partie ailleurs par elle-même pendant l'accueil : la visite s'efface.
    if (!fromTour && page.component !== 'Dashboard') {
      closeActive();
      clearStep();
    } else {
      goTo(current);
      return;
    }
  }
  if (page.component === 'Dashboard' && !user.onboarding?.tour) {
    // Nouveau compte : l'accueil, puis les tutos de page.
    markOnboarding('page_tours');
    goTo(0);
    return;
  }

  const tour = pageTourFor(page);
  if (tour && !pageTourDone(user, tour.key)) {
    // Vu une fois suffit : même quitté en route, il ne revient pas.
    markOnboarding(tour.key);
    pageTour = { ...tour, i: 0 };
    goToPage(0);
  }
}

export function detachTour() {
  pageTour = null;
  closeActive();
}

/** « Visite guidée » du menu : l'accueil, et les tutos de page réarmés. */
export function startTour(page) {
  ctx = { page };
  markOnboarding('page_tours');
  writeStep(0);
  goTo(0);
}
