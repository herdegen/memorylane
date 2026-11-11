#!/usr/bin/env node
/**
 * Contrôle après déploiement (appelé par scripts/deploy.sh) : un navigateur
 * sans écran, connecté par un lien magique à usage unique, ouvre les pages
 * clés du site EN PROD et signale toute page qui ne s'affiche pas, toute
 * erreur JavaScript ou réponse serveur en erreur. Lecture seule.
 *
 *   node scripts/post-deploy-check.mjs '{"link": "<lien magique>", "paths": ["/media/<id>", …]}'
 *
 * Attrape ce que les tests de code ne voient pas : fichiers JS de l'admin non
 * republiés (Livewire), assets du build manquants, 502 nginx, page blanche.
 */
import puppeteer from 'puppeteer';

const { link: magicUrl, paths: extraPaths = [] } = JSON.parse(process.argv[2] || '{}');
if (!magicUrl) {
  console.error('Usage : node scripts/post-deploy-check.mjs \'{"link": "<lien magique>", "paths": [...]}\'');
  process.exit(2);
}

const origin = new URL(magicUrl).origin;

// Pages de l'application (Inertia) puis de l'administration (Filament/Livewire).
const APP_PAGES = ['/dashboard', '/media', '/albums', '/people', '/family-tree', '/map', '/guide', ...extraPaths];
const ADMIN_PAGES = ['/admin', '/admin/users'];

const browser = await puppeteer.launch({ headless: 'shell', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const failures = [];

try {
  const page = await browser.newPage();
  // Reconnaissable dans les journaux nginx (« qui visite ? »).
  await page.setUserAgent(`${await browser.userAgent()} MemoryLaneControle/1`);
  await page.setViewport({ width: 1280, height: 900 });

  let problems = [];
  page.on('pageerror', (e) => problems.push(`erreur JS : ${e.message.split('\n')[0]}`));
  page.on('console', (m) => {
    // Les ressources en échec sont relevées plus précisément ci-dessous.
    if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) {
      problems.push(`console : ${m.text().slice(0, 200)}`);
    }
  });
  page.on('response', (r) => {
    const url = r.url();
    if (url.startsWith(origin) && (r.status() >= 500 || (r.status() === 404 && /\.(js|css|woff2?)(\?|$)/.test(url)))) {
      problems.push(`${r.status()} sur ${url.replace(origin, '')}`);
    }
  });
  page.on('requestfailed', (r) => {
    const err = r.failure()?.errorText || '';
    if (r.url().startsWith(origin) && !err.includes('ERR_ABORTED')) {
      problems.push(`requête en échec ${r.url().replace(origin, '')} (${err})`);
    }
  });

  // Connexion (lien à usage unique).
  await page.goto(magicUrl, { waitUntil: 'networkidle2', timeout: 45000 });
  if (page.url().includes('/login')) {
    throw new Error(`connexion refusée par le lien magique (page finale : ${page.url()})`);
  }

  const visit = async (path, check) => {
    problems = [];
    let response;
    try {
      response = await page.goto(origin + path, { waitUntil: 'networkidle2', timeout: 45000 });
      await new Promise((r) => setTimeout(r, 1500)); // erreurs tardives (composants chargés à la demande)
    } catch (e) {
      problems.push(`chargement impossible : ${e.message.split('\n')[0]}`);
    }
    if (response && response.status() >= 400) problems.push(`statut ${response.status()}`);
    if (page.url().includes('/login')) problems.push('renvoyé vers la connexion');
    if (!problems.length) {
      const missing = await page.evaluate(check).catch((e) => `vérification impossible : ${e.message}`);
      if (missing) problems.push(missing);
    }

    if (problems.length) {
      failures.push(path);
      console.log(`✗ ${path}\n    ${[...new Set(problems)].join('\n    ')}`);
    } else {
      console.log(`✓ ${path}`);
    }
  };

  for (const path of APP_PAGES) {
    // Page blanche = l'application Vue ne s'est pas montée.
    await visit(path, () => (document.querySelector('#app')?.children.length ? null : 'page blanche (application non montée)'));
  }
  for (const path of ADMIN_PAGES) {
    await visit(path, () => (window.Livewire ? null : 'Livewire absent : boutons de l\'admin inactifs'));
  }
} catch (e) {
  failures.push('connexion');
  console.log(`✗ ${e.message}`);
} finally {
  await browser.close();
}

if (failures.length) {
  console.log(`\n${failures.length} page(s) en échec.`);
  process.exit(1);
}
console.log('\nToutes les pages clés s\'affichent sans erreur.');
