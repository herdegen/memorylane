#!/usr/bin/env node
/**
 * Génère les vignettes illustratives du guide d'utilisation (/guide) dans
 * storage/app/guide/ — servies UNIQUEMENT aux comptes connectés via
 * GET /guide/images/{name} (elles contiennent des photos de famille : jamais
 * dans public/, jamais committées, régénérables à volonté).
 *
 * Procédure de (re)génération — à relancer après tout changement visuel :
 *   1. php artisan tinker --execute="echo URL::temporarySignedRoute('login.magic.verify', now()->addMinutes(15), ['user' => App\Models\User::where('role','admin')->first()->id]);"
 *   2. cd backend && node scripts/guide-captures.mjs '<url-login-magique>' [terme-recherche]
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
  { name: 'recherche', path: `/search?q=${encodeURIComponent(searchTerm)}` },
  { name: 'tags', path: '/tags' },
];

const THEMES = ['light', 'dark'];
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
  for (const shot of SHOTS) {
    for (const theme of THEMES) {
      const page = await browser.newPage();
      await page.setViewport({ width: 1440, height: 900 });
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
