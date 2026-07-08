# Sauvegarde des secrets (`backend/.env`)

## Pourquoi
Le `backend/.env` de prod n'est **pas** versionné (secrets) et **n'existe nulle
part ailleurs** : clés OAuth Google Photos, clés Scaleway S3, credentials
Vision, `APP_KEY`… Si le fichier est écrasé, ces valeurs sont **définitivement
perdues**. C'est déjà arrivé (client_id Google Photos disparu le 2026-07-07).

## Solution retenue : Bitwarden Secrets Manager (`bws`)
Le [Secrets Manager](https://bitwarden.com/products/secrets-manager/) de
Bitwarden est fait pour les secrets machine : stockage clé/valeur, accès
programmatique via la CLI `bws` et un **access token** de compte machine,
rotation possible. On y stocke le `.env` complet comme **un seul secret**
(`memorylane-prod-env`), simple à sauvegarder et à restaurer.

### Mise en place (une fois, côté Bitwarden web)
1. Activer Secrets Manager, créer une **organisation/projet** (ex. `memorylane`).
2. Créer un **compte machine** + générer un **access token**.
3. Sur le serveur : installer la CLI (`bws`) et exposer le token :
   ```
   export BWS_ACCESS_TOKEN="<access-token>"        # ⚠️ à garder secret
   ```
   Bootstrapping : ce token est le seul secret à conserver côté serveur (bien
   moins critique que tout le `.env`, et rotable). Le ranger p.ex. dans un
   fichier root-only `~/.config/memorylane/bws.token` sourcé au besoin.

### Sauvegarder (après toute modif du .env)
```
export BWS_ACCESS_TOKEN=...           # cf. ci-dessus
backend/scripts/backup-env.sh         # crée/màj le secret memorylane-prod-env
```

### Restaurer
```
export BWS_ACCESS_TOKEN=...
bws secret list | jq -r '.[] | select(.key=="memorylane-prod-env") | .id'   # → <id>
bws secret get <id> | jq -r '.value' > backend/.env
docker compose exec app php artisan optimize
```

## ⚠️ Deux CLI Bitwarden distinctes
- **`bws`** = CLI **Secrets Manager** (binaire Rust, pas npm) — approche
  ci-dessus.
- **`bw`** = CLI du **coffre** (password manager), **installable via npm** :
  `npm i -g @bitwarden/cli`. Elle stocke le `.env` dans une **note sécurisée**.

`backend/scripts/backup-env.sh` gère les deux automatiquement : Secrets Manager
si `bws` est présent, sinon note sécurisée si `bw` est présent.

## Fallback simple : note sécurisée du coffre (`bw`, npm)
1. `npm i -g @bitwarden/cli`
2. `bw login && export BW_SESSION="$(bw unlock --raw)"`
3. `backend/scripts/backup-env.sh` → crée/met à jour la note « MemoryLane prod .env ».

Ou 100 % manuel : copier le contenu de `backend/.env` dans une note sécurisée
Bitwarden nommée `MemoryLane prod .env` (restauration par copier-coller).

## Pourquoi pas les secrets GitHub Actions
- Pas de pipeline CI/CD (déploiement = `git pull` manuel) → un secret GitHub
  n'alimente pas le `.env` du serveur.
- Les secrets GitHub sont non-relisibles (write-only) → inutilisables comme
  sauvegarde restaurable.

## Ne jamais committer
`backend/.env`, l'`BWS_ACCESS_TOKEN`, ou toute valeur de secret — le dépôt est
**public**.
