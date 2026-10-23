#!/usr/bin/env node
// Contrôle des traductions de l'interface (texte français = clé).
//
//   node scripts/i18n-check.mjs                    clés utilisées absentes de lang/en.json
//   node scripts/i18n-check.mjs fichier.vue …      idem, pour ces fichiers seulement
//   node scripts/i18n-check.mjs --dict a.json …    avec un ou plusieurs dictionnaires
//   node scripts/i18n-check.mjs --leftovers …      texte français resté en dur (indices)
//   node scripts/i18n-check.mjs --keys …           liste des clés utilisées (JSON)
//
// Clés reconnues : t('…'), tc('…'), $t('…'), $tc('…') (JS/Vue),
// <Trans text="…">, __('…') et trans_choice('…') (PHP/Blade). La clé doit être
// une chaîne littérale.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const dicts = [];
const files = [];
let mode = 'missing';
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--dict') dicts.push(args[++i]);
  else if (args[i] === '--leftovers') mode = 'leftovers';
  else if (args[i] === '--keys') mode = 'keys';
  else files.push(resolve(args[i]));
}
if (!dicts.length) dicts.push(join(root, 'lang/en.json'));

const walk = (dir, out = []) => {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else out.push(p);
  }
  return out;
};

const sources = files.length ? files : [
  ...walk(join(root, 'resources/js')).filter((f) => /\.(vue|js)$/.test(f)),
  ...walk(join(root, 'resources/views')).filter((f) => f.endsWith('.blade.php') && !f.includes('/filament/')),
  ...walk(join(root, 'app')).filter((f) => f.endsWith('.php') && !/\/app\/(Filament|Console)\//.test(f)),
];

const unescape = (s, php) => (php
  ? s.replace(/\\(['\\])/g, '$1')
  : s.replace(/\\(u\{?[0-9a-fA-F]+\}?|x[0-9a-fA-F]{2}|.)/g, (m, c) => {
    if (c[0] === 'u') return String.fromCodePoint(parseInt(c.replace(/[u{}]/g, ''), 16));
    if (c[0] === 'x') return String.fromCharCode(parseInt(c.slice(1), 16));
    return { n: '\n', t: '\t', r: '\r' }[c] ?? c;
  }));

const CALL = /(?<![\w$.>:])(\$tc|\$t|tc|t|__|trans_choice)\(\s*(['"`])((?:\\.|(?!\2)[^\\])*)\2/gs;
const TRANS = /<Trans\b[^>]*?\stext="([^"]*)"/gs;

// Commentaires (lignes « // », « * », « /* », « # » et blocs <!-- -->) blanchis,
// longueur et lignes gardées : les exemples de documentation ne comptent pas.
const blankComments = (src) => src
  .replace(/<!--[\s\S]*?-->/g, (c) => c.replace(/[^\n]/g, ' '))
  .replace(/^[ \t]*(\/\/|\/\*|\*|#(?!\[)).*$/gm, (c) => c.replace(/./g, ' '));

export function keysOf(file) {
  const src = blankComments(readFileSync(file, 'utf8'));
  const php = file.endsWith('.php');
  const keys = [];
  for (const m of src.matchAll(CALL)) {
    if (php && !['__', 'trans_choice'].includes(m[1])) continue;
    if (m[2] === '`' && m[3].includes('${')) continue;
    keys.push({ key: unescape(m[3], php && m[2] === "'"), line: src.slice(0, m.index).split('\n').length });
  }
  if (file.endsWith('.vue')) {
    for (const m of src.matchAll(TRANS)) {
      keys.push({ key: m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'), line: src.slice(0, m.index).split('\n').length });
    }
  }
  return keys;
}

// Indices de texte resté en dur dans un composant Vue : texte entre balises
// et attributs visibles non liés (placeholder, title, alt, aria-label…).
function leftovers(file) {
  const src = readFileSync(file, 'utf8');
  const out = [];
  const lineOf = (i) => src.slice(0, i).split('\n').length;
  if (file.endsWith('.vue')) {
    const tpl = src.match(/<template>([\s\S]*)<\/template>/);
    if (tpl) {
      const start = tpl.index + '<template>'.length;
      const body = tpl[1];
      for (const m of body.matchAll(/>([^<>]+)</g)) {
        const text = m[1].replace(/\{\{[\s\S]*?\}\}/g, '').trim();
        if (/[A-Za-zÀ-ÿ]{2,}/.test(text) && !/^[\s—–·•|/×+−-]*$/.test(text)) out.push({ line: lineOf(start + m.index), text });
      }
      for (const m of body.matchAll(/\s(placeholder|title|alt|aria-label|label|confirm-label|cancel-label)="([^"]*[A-Za-zÀ-ÿ]{2,}[^"]*)"/g)) {
        out.push({ line: lineOf(start + m.index), text: `${m[1]}="${m[2]}"` });
      }
    }
  }
  // Littéraux JS/PHP contenant des lettres accentuées, hors appels de traduction
  // et commentaires.
  const code = file.endsWith('.vue') ? (src.match(/<script[\s\S]*<\/script>/) || [''])[0] : src;
  const offset = file.endsWith('.vue') ? src.indexOf(code) : 0;
  const stripped = code.replace(/\/\*[\s\S]*?\*\//g, (c) => c.replace(/[^\n]/g, ' ')).replace(/(^|[^:'"`])\/\/.*$/gm, (c, p) => p + c.slice(p.length).replace(/./g, ' ')).replace(/^\s*(#|\*).*$/gm, (c) => c.replace(/./g, ' '));
  for (const m of stripped.matchAll(/(['"`])((?:\\.|(?!\1)[^\\\n])*[À-ÿ](?:\\.|(?!\1)[^\\\n])*)\1/g)) {
    const before = stripped.slice(Math.max(0, m.index - 16), m.index);
    if (/(\$tc|\$t|\btc|\bt|__|trans_choice)\(\s*$/.test(before)) continue;
    out.push({ line: lineOf(offset + m.index), text: m[0] });
  }
  return out;
}

if (mode === 'leftovers') {
  let n = 0;
  for (const f of sources) {
    for (const l of leftovers(f)) {
      console.log(`${relative(process.cwd(), f)}:${l.line}  ${l.text.slice(0, 120)}`);
      n++;
    }
  }
  console.error(`${n} indice(s).`);
  process.exit(0);
}

const all = sources.flatMap((f) => keysOf(f).map((k) => ({ ...k, file: f })));
if (mode === 'keys') {
  console.log(JSON.stringify([...new Set(all.map((k) => k.key))], null, 2));
  process.exit(0);
}

const dict = Object.assign({}, ...dicts.map((d) => JSON.parse(readFileSync(d, 'utf8'))));
const placeholders = (s) => [...new Set([...s.matchAll(/:([A-Za-z_][A-Za-z0-9_]*)/g)].map((m) => m[1].toLowerCase()))].sort();
let problems = 0;
const seen = new Set();
for (const { key, file, line } of all) {
  if (seen.has(key)) continue;
  seen.add(key);
  if (!(key in dict)) {
    console.log(`manquante  ${relative(process.cwd(), file)}:${line}  ${JSON.stringify(key)}`);
    problems++;
    continue;
  }
  // Mêmes paramètres des deux côtés ; « :decades » vaut « :decade » suivi
  // de « s » (remplacement du plus long paramètre connu, comme Laravel).
  const a = placeholders(key).filter((p) => !(key.includes('|') && p === 'count'));
  const b = placeholders(dict[key]).map((p) => a.filter((k) => p.startsWith(k)).sort((x, y) => y.length - x.length)[0] ?? p)
    .filter((p) => !(key.includes('|') && p === 'count'));
  if (a.join() !== [...new Set(b)].sort().join()) {
    console.log(`paramètres ${relative(process.cwd(), file)}:${line}  ${JSON.stringify(key)} → ${JSON.stringify(dict[key])}`);
    problems++;
  }
}
console.error(`${seen.size} clé(s) utilisée(s), ${problems} problème(s).`);
process.exit(problems ? 1 : 0);
