#!/usr/bin/env node
// Captures mobiles (390×844, DPR 2) de l'environnement de DEV, connecté en
// admin par lien magique. Usage : node scripts/shot-dev.mjs <lien-magique> <dossier> /chemin1 [/chemin2…]
// DARK=1 node scripts/shot-dev.mjs … : en mode sombre.
// Un chemin peut enchaîner un clic : « /media|click:.media-card », « |text:Quitter le cercle », ou « |full » (page entière).
// Le lien se génère dans ~/memorylane-dev :
//   docker compose exec -T app php artisan tinker --execute='echo App\Notifications\MagicLoginLink::urlFor(App\Models\User::where("role","admin")->first());'
// Identifiants basic-auth lus dans ~/.memorylane-dev-password.
import puppeteer from 'puppeteer';
import { readFileSync, mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
const [magic, out, ...paths] = process.argv.slice(2);
const creds = Object.fromEntries(readFileSync(`${homedir()}/.memorylane-dev-password`, 'utf8').split('\n').filter(Boolean).map((l) => { const i = l.indexOf(':'); return [l.slice(0, i).trim(), l.slice(i + 1).trim()]; }));
const origin = new URL(magic).origin;
mkdirSync(out, { recursive: true });
const b = await puppeteer.launch({ headless: 'shell', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const p = await b.newPage();
// DARK=1 : captures en mode sombre (préférence système, sans toucher au compte)
if (process.env.DARK) await p.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
await p.authenticate({ username: creds['identifiant'], password: creds['mot de passe'] });
const errs = []; p.on('pageerror', (e) => errs.push(String(e))); p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
await p.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
await p.goto(magic, { waitUntil: 'networkidle0', timeout: 45000 });
console.log('login →', p.url());
if (p.url().includes('/login')) { console.error('Lien magique refusé (déjà utilisé ou expiré) : en générer un nouveau.'); await b.close(); process.exit(1); }
let i = 0;
for (const spec of paths) {
  // « /chemin|click:<sélecteur> » : clique l'élément après chargement (visionneuse, feuille…)
  const [path, ...actions] = spec.split('|');
  await p.goto(origin + path, { waitUntil: 'networkidle0', timeout: 45000 });
  await new Promise((r) => setTimeout(r, 900));
  for (const a of actions) {
    if (a.startsWith('click:')) {
      await p.click(a.slice(6));
      await new Promise((r) => setTimeout(r, 1500));
    }
    // « text:Quitter le cercle » : clique le premier bouton/lien visible portant ce texte
    if (a.startsWith('text:')) {
      await p.evaluate((t) => [...document.querySelectorAll('button, a')].find((el) => el.textContent.trim().includes(t) && el.offsetParent !== null)?.click(), a.slice(5));
      await new Promise((r) => setTimeout(r, 1500));
    }
  }
  // « |full » : page entière (défilement compris)
  await p.screenshot({ path: `${out}/d${i++}.png`, fullPage: actions.includes('full') });
}
console.log('errors:', JSON.stringify(errs));
await b.close();
