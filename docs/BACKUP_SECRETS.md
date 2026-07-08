# Sauvegarde des secrets (`backend/.env`)

## Pourquoi
Le `backend/.env` de prod n'est **pas** versionné (secrets) et **n'existe nulle
part ailleurs** : clés OAuth Google Photos, clés Scaleway S3, credentials
Vision, `APP_KEY`… Si le fichier est écrasé, ces valeurs sont **définitivement
perdues**. C'est déjà arrivé (client_id Google Photos disparu le 2026-07-07).

## Solution retenue : note sécurisée Bitwarden
On garde une copie du `.env` dans **Bitwarden** (coffre chiffré, hors machine,
restaurable par copier-coller).

### Sauvegarde (à refaire après toute modif du .env)
1. Ouvrir `backend/.env` sur le serveur.
2. Copier tout son contenu.
3. Bitwarden → nouvelle **note sécurisée** nommée **`MemoryLane prod .env`**
   (ou mettre à jour l'existante) → coller le contenu.

### Restauration
1. Récupérer la note `MemoryLane prod .env` dans Bitwarden.
2. Recréer `backend/.env` avec ce contenu.
3. `docker compose exec app php artisan optimize` (la config est cachée).

### Option automatisée (si un jour la CLI `bw` est installée)
`backend/scripts/backup-env.sh` pousse le `.env` dans la note Bitwarden.
Prérequis : `npm i -g @bitwarden/cli`, `bw login`, `export BW_SESSION=$(bw unlock --raw)`, `jq`.

## Pourquoi pas les secrets GitHub Actions
- Pas de pipeline CI/CD (déploiement = `git pull` manuel) → un secret GitHub
  n'alimente pas le `.env` du serveur.
- Les secrets GitHub sont non-relisibles (write-only) → inutilisables comme
  sauvegarde restaurable.
À reconsidérer seulement si on met en place un workflow de déploiement.

## Ne jamais committer
`backend/.env`, `.env.encrypted` en clair de clé, ou toute valeur de secret —
le dépôt est **public**.
