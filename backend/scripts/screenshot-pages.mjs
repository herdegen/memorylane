#!/usr/bin/env node
/**
 * Capture les pages de l'app avec une session authentifiée, en light et dark,
 * desktop et mobile. Sert d'« yeux » pour les phases d'amélioration graphique.
 *
 * Usage :
 *   node scripts/screenshot-pages.mjs '<url-login-magique>' /dashboard /media /media/<id> ...
 *
 * - L'URL de login magique (signée, TTL court) est générée côté serveur :
 *   php artisan tinker --execute="echo URL::temporarySignedRoute('login.magic.verify', now()->addMinutes(15), ['user' => App\Models\User::where('role','admin')->first()->id]);"
 * - Sortie : scripts/screenshots/<page>-<viewport>-<theme>.png (gitignoré).
 */
import puppeteer from 'puppeteer';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const [magicUrl, ...pages] = process.argv.slice(2);
if (!magicUrl || pages.length === 0) {
  console.error('Usage: node scripts/screenshot-pages.mjs <url-login-magique> <chemin1> [chemin2…]');
  process.exit(1);
}

const origin = new URL(magicUrl).origin;
const outDir = join(dirname(fileURLToPath(import.meta.url)), 'screenshots');
mkdirSync(outDir, { recursive: true });

const VIEWPORTS = {
  desktop: { width: 1440, height: 900 },
  mobile: { width: 390, height: 844 },
};
const THEMES = ['light', 'dark'];

const slug = (path) => path.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '-').replace(/-+$/, '') || 'home';

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

  // 2. Captures.
  for (const path of pages) {
    for (const [vpName, viewport] of Object.entries(VIEWPORTS)) {
      for (const theme of THEMES) {
        const page = await browser.newPage();
        await page.setViewport(viewport);
        // Le script anti-FOUC de app.blade.php lit localStorage.theme.
        await page.evaluateOnNewDocument((t) => localStorage.setItem('theme', t), theme);
        await page.goto(origin + path, { waitUntil: 'networkidle0', timeout: 45000 });
        // Laisse retomber les animations/chargements paresseux.
        await new Promise((r) => setTimeout(r, 1200));
        const file = join(outDir, `${slug(path)}--${vpName}-${theme}.png`);
        await page.screenshot({ path: file, fullPage: vpName === 'desktop' });
        console.log('✓', file);
        await page.close();
      }
    }
  }
} finally {
  await browser.close();
}
