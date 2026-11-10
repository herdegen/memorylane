#!/usr/bin/env node
/**
 * Génère les vignettes illustratives du guide d'utilisation (/guide) dans
 * storage/app/guide/ — servies UNIQUEMENT aux comptes connectés via
 * GET /guide/images/{name} (elles contiennent des photos de famille : jamais
 * dans public/, jamais committées, régénérables à volonté).
 *
 * Procédure de (re)génération — à relancer après tout changement visuel :
 *   1. php artisan tinker --execute="echo App\Notifications\MagicLoginLink::urlFor(App\Models\User::where('role','admin')->first());"
 *      (lien à usage unique)
 *   2. cd backend && node scripts/guide-captures.mjs '<url-login-magique>' [terme-recherche]
 *      ONLY=recherche,tags node scripts/… → ne régénère que ces vignettes.
 *      Pas-à-pas « dater une photo » (tuto-*) : TUTO_ALBUM=<id d'album> et
 *      TUTO_MEDIA=<id d'une photo du compte, avec des visages détectés>
 *      (sinon ces vignettes sont sautées).
 *
 * ⚠️ Utiliser un compte dont les photos peuvent figurer dans le guide (les
 * vignettes sont visibles de TOUS les comptes connectés).
 * ⚠️ Pas de fullPage : ça casse le lazy-load des images (tuiles grises).
 */
import puppeteer from 'puppeteer';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const [magicUrl, searchTerm = 'mariage'] = process.argv.slice(2);
if (!magicUrl) {
  console.error("Usage: node scripts/guide-captures.mjs '<url-login-magique>' [terme-recherche]");
  process.exit(1);
}

// Source de vérité des vignettes — garder ALIGNÉ avec les `image:` de
// resources/js/Pages/Guide.vue. Une vignette manquante n'est pas bloquante :
// le front la masque proprement (GuideImage.vue).
const TUTO_ALBUM = process.env.TUTO_ALBUM;
const TUTO_MEDIA = process.env.TUTO_MEDIA;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Entoure l'élément à repérer (bouton, champ) d'un anneau rouge bien visible.
const ring = (page, selector) => page.evaluate((sel) => {
  const el = document.querySelector(sel);
  if (!el) return false;
  el.scrollIntoView({ block: 'center' });
  el.style.outline = '4px solid #e11d48';
  el.style.outlineOffset = '4px';
  el.style.borderRadius = '12px';
  return true;
}, selector);

// Ouvre la première photo de l'album dans la visionneuse, puis entoure le
// bouton qui mène à la fiche (ⓘ sur ordinateur, « Détails » sur téléphone).
const openViewer = (target) => async (page) => {
  await page.click('[data-guide="media-tile"]');
  await page.waitForSelector('.pswp--open', { timeout: 10000 });
  await sleep(1500);
  await ring(page, target);
};
const openFace = async (page) => {
  await page.click('[data-guide="face"]');
  await page.waitForSelector('[data-guide="face-panel"]', { timeout: 10000 });
  await sleep(600);
  await ring(page, '[data-guide="face-panel"]');
  await ring(page, '[data-guide="face"]');
};

const SHOTS = [
  { name: 'dashboard', path: '/dashboard' },
  { name: 'mes-photos', path: '/media' },
  { name: 'upload', path: '/media/upload' },
  { name: 'albums', path: '/albums' },
  { name: 'personnes', path: '/people' },
  // Fiche personne : les cartes de l'annuaire naviguent en JS (router.visit,
  // pas de <a>) → on résout l'id de la première personne via l'endpoint JSON.
  {
    name: 'personne',
    path: '/people',
    resolvePath: async (page) => {
      const id = await page.evaluate(() =>
        fetch('/people', { headers: { Accept: 'application/json' } })
          .then((r) => r.json())
          .then((people) => people[0]?.id ?? null),
      );
      return id ? `/people/${id}` : null;
    },
    settle: 2500,
  },
  { name: 'foyers', path: '/households' },
  { name: 'arbre', path: '/family-tree', settle: 3000 }, // rendu + animation de l'arbre
  { name: 'carte', path: '/map', settle: 3000 }, // tuiles Leaflet
  // Recherche : c'est le menu déroulant de la barre d'en-tête (/search est
  // l'API JSON, pas une page) → on tape le terme et on attend les résultats.
  {
    name: 'recherche',
    path: '/dashboard',
    interact: async (page) => {
      const input = 'input[placeholder="Rechercher…"]';
      await page.waitForSelector(input, { visible: true });
      const results = page.waitForResponse((r) => r.url().includes('/search'), { timeout: 15000 });
      await page.click(input);
      await page.type(input, searchTerm, { delay: 60 });
      await results;
    },
    settle: 1800,
  },
  { name: 'tags', path: '/tags' },
  // Pas-à-pas « dater une photo, nommer quelqu'un » (ordinateur + téléphone).
  { name: 'tuto-fiche', path: `/albums/${TUTO_ALBUM}`, requires: TUTO_ALBUM, interact: openViewer('.pswp__button--info') },
  { name: 'tuto-fiche-phone', phone: true, path: `/albums/${TUTO_ALBUM}`, requires: TUTO_ALBUM, interact: openViewer('.pswp__mobile-bar-details') },
  { name: 'tuto-date', path: `/media/${TUTO_MEDIA}`, requires: TUTO_MEDIA, interact: (page) => ring(page, '[data-guide="media-edit"]') },
  {
    name: 'tuto-date-phone', phone: true, path: `/media/${TUTO_MEDIA}`, requires: TUTO_MEDIA,
    interact: async (page) => {
      await page.click('[data-guide="media-info"]'); // section repliée sur téléphone
      await sleep(400);
      await ring(page, '[data-guide="media-edit"]');
    },
  },
  { name: 'tuto-visage', path: `/media/${TUTO_MEDIA}`, requires: TUTO_MEDIA, interact: openFace },
  { name: 'tuto-visage-phone', phone: true, path: `/media/${TUTO_MEDIA}`, requires: TUTO_MEDIA, interact: openFace },
];

const THEMES = ['light', 'dark'];
const only = process.env.ONLY ? process.env.ONLY.split(',') : null;
const origin = new URL(magicUrl).origin;
const outDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'storage', 'app', 'guide');
mkdirSync(outDir, { recursive: true });

const browser = await puppeteer.launch({
  headless: 'shell',
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

try {
  // 1. Session : on consomme le lien magique une fois, les cookies restent.
  const login = await browser.newPage();
  await login.goto(magicUrl, { waitUntil: 'networkidle0', timeout: 30000 });
  if (login.url().includes('/login')) {
    throw new Error('Login magique refusé (lien expiré ?) — page finale : ' + login.url());
  }
  console.log('Session ouverte →', login.url());
  await login.close();

  // 2. Vignettes : viewport fixe 1440×900 (affichées ~800px → ~1.8x).
  for (const shot of SHOTS.filter((s) => !only || only.includes(s.name))) {
    if ('requires' in shot && !shot.requires) {
      console.warn(`⚠ ${shot.name} : TUTO_ALBUM / TUTO_MEDIA non fournis — vignette sautée`);
      continue;
    }
    for (const theme of THEMES) {
      const page = await browser.newPage();
      // Téléphone : 390×844 (iPhone), pour la bande « Détails » et les sections repliées.
      await page.setViewport(shot.phone
        ? { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
        : { width: 1440, height: 900 });
      // Le script anti-FOUC de app.blade.php lit localStorage.theme.
      await page.evaluateOnNewDocument((t) => localStorage.setItem('theme', t), theme);

      try {
        await page.goto(origin + shot.path, { waitUntil: 'networkidle0', timeout: 45000 });

        if (shot.resolvePath) {
          const resolved = await shot.resolvePath(page);
          if (!resolved) {
            console.warn(`⚠ ${shot.name} (${theme}) : cible introuvable — vignette sautée`);
            continue;
          }
          await page.goto(origin + resolved, { waitUntil: 'networkidle0', timeout: 45000 });
        }

        // Anti-lazy-load : petit aller-retour de scroll puis stabilisation.
        await page.evaluate(() => window.scrollBy(0, 400));
        await new Promise((r) => setTimeout(r, 400));
        await page.evaluate(() => window.scrollTo(0, 0));
        if (shot.interact) {
          await shot.interact(page);
        }
        await new Promise((r) => setTimeout(r, shot.settle ?? 1300));

        const file = join(outDir, `${shot.name}${theme === 'dark' ? '-dark' : ''}.webp`);
        await page.screenshot({ path: file, type: 'webp', quality: 80 });
        console.log('✓', file);
      } catch (error) {
        console.warn(`⚠ ${shot.name} (${theme}) : ${error.message} — vignette sautée`);
      } finally {
        await page.close();
      }
    }
  }
} finally {
  await browser.close();
}
