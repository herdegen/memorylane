#!/usr/bin/env node
// Captures de l'interface TÉLÉVISION (1920×1080) de l'environnement de DEV,
// connecté par lien magique. Usage :
//   node scripts/shot-tv.mjs <lien-magique> <dossier> /tv/ecran/accueil "/tv/ecran/albums|key:ArrowDown|key:ArrowRight|wait:1500"
// Actions enchaînables après « | » : key:<touche> (télécommande simulée),
// wait:<ms>, shot (capture intermédiaire). Lien magique : voir shot-dev.mjs.
import puppeteer from 'puppeteer';
import { readFileSync, mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
const [magic, out, ...paths] = process.argv.slice(2);
const creds = Object.fromEntries(readFileSync(`${homedir()}/.memorylane-dev-password`, 'utf8').split('\n').filter(Boolean).map((l) => { const i = l.indexOf(':'); return [l.slice(0, i).trim(), l.slice(i + 1).trim()]; }));
const origin = magic === 'none' ? (process.env.ORIGIN || 'https://dev.memory-lane.fr') : new URL(magic).origin;
mkdirSync(out, { recursive: true });
const b = await puppeteer.launch({ headless: 'shell', args: ['--no-sandbox', '--disable-dev-shm-usage', '--autoplay-policy=no-user-gesture-required'] });
const p = await b.newPage();
await p.authenticate({ username: creds['identifiant'], password: creds['mot de passe'] });
const errs = []; p.on('pageerror', (e) => errs.push(String(e))); p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
await p.setViewport({ width: 1920, height: 1080, deviceScaleFactor: 1 });
if (magic !== 'none') {
  await p.goto(magic, { waitUntil: 'networkidle0', timeout: 45000 });
  console.log('login →', p.url());
}
let i = 0;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
for (const spec of paths) {
  const [path, ...actions] = spec.split('|');
  await p.goto(origin + path, { waitUntil: 'networkidle0', timeout: 45000 });
  await sleep(1200);
  for (const a of actions) {
    if (a.startsWith('key:')) { await p.keyboard.press(a.slice(4)); await sleep(500); }
    if (a.startsWith('wait:')) await sleep(Number(a.slice(5)));
    if (a === 'shot') await p.screenshot({ path: `${out}/t${i++}.png` });
  }
  await sleep(600);
  await p.screenshot({ path: `${out}/t${i++}.png` });
  console.log(path, '→', p.url());
}
console.log('errors:', JSON.stringify(errs));
await b.close();
