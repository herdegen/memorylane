// Copie les poids face-api.js nécessaires depuis la dépendance npm vers
// public/models/ (servi à /models par nginx/Vite). Les poids ne sont pas
// versionnés (cf .gitignore) : ils se régénèrent à partir de node_modules.
//
// À lancer après `npm install`, avant de servir l'app :  npm run sync-models
import { copyFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const src = `${root}/node_modules/@vladmandic/face-api/model/`;
const dest = `${root}/public/models/`;

const files = [
  'ssd_mobilenetv1_model-weights_manifest.json',
  'ssd_mobilenetv1_model.bin',
  'face_landmark_68_model-weights_manifest.json',
  'face_landmark_68_model.bin',
  'face_recognition_model-weights_manifest.json',
  'face_recognition_model.bin',
];

if (!existsSync(src)) {
  console.error(`[sync-models] source introuvable : ${src} — lancez d'abord "npm install".`);
  process.exit(1);
}

mkdirSync(dest, { recursive: true });
for (const f of files) {
  copyFileSync(src + f, dest + f);
}
console.log(`[sync-models] ${files.length} fichiers copiés vers public/models/`);
