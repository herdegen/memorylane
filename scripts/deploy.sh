#!/usr/bin/env bash
# Déploiement en production — le serveur sert directement ce dépôt.
#
#   scripts/deploy.sh <branche|commit>
#
# Avance main en fast-forward jusqu'à <branche|commit>, avec : vérifications →
# dump de la base → maintenance → code → dépendances si les verrous ont
# changé → migrations → build front → caches Laravel → conteneurs → fin de
# maintenance → contrôle des pages.
# En cas d'échec une fois la maintenance activée, le site RESTE en maintenance
# (rien n'est annulé automatiquement) : corriger, puis
#   docker compose exec app php artisan up
# Jamais de migrate:rollback en prod : corriger par une nouvelle migration.
set -euo pipefail
cd "$(dirname "$0")/.."

REF=${1:?usage : scripts/deploy.sh <branche|commit>}
URL=${DEPLOY_CHECK_URL:-https://memory-lane.fr}

artisan() { docker compose exec -T app php artisan "$@"; }
step() { printf '\n\033[1m▶ %s\033[0m\n' "$*"; }

step "Vérifications"
[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || { echo "Le dépôt doit être sur main."; exit 1; }
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "Modifications locales non commitées : abandon."; exit 1; }
OLD=$(git rev-parse HEAD)
NEW=$(git rev-parse --verify "$REF^{commit}")
[ "$OLD" != "$NEW" ] || { echo "Rien à déployer."; exit 0; }
git merge-base --is-ancestor "$OLD" "$NEW" || { echo "$REF ne descend pas de main : fast-forward impossible."; exit 1; }
git log --oneline "$OLD..$NEW"
changed() { git diff --name-only "$OLD" "$NEW" -- "$@" | grep -q .; }

step "Sauvegarde de la base"
mkdir -p backups
DUMP="backups/pre-deploy-$(date -u +%Y%m%d-%H%M%S).dump"
docker exec memorylane_postgres sh -c 'pg_dump -Fc -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$DUMP"
echo "$DUMP ($(du -h "$DUMP" | cut -f1))"

step "Maintenance"
artisan down --retry=30
trap 'echo; echo "⚠️  Échec : le site est resté en maintenance. Corriger, puis : docker compose exec app php artisan up"' ERR

step "Code"
git merge --ff-only "$NEW"

if changed backend/composer.lock; then
    step "Dépendances PHP"
    docker compose exec -T app composer install --no-interaction --optimize-autoloader
fi
if changed backend/package-lock.json; then
    step "Dépendances JS"
    (cd backend && npm ci)
fi

step "Migrations"
artisan migrate --force

step "Front"
(cd backend && npm run build >/dev/null)

step "Caches Laravel"
artisan optimize

step "Conteneurs"
if changed docker-compose.yml docker-compose.prod.yml docker/app docker/nginx; then
    # Workers arrêtés d'abord : sinon ils perdent Redis/Postgres pendant leur
    # recréation et remplissent le journal d'erreurs de connexion.
    docker compose stop horizon scheduler
    docker compose up -d --build
fi
# Les workers gardent l'ancien code en mémoire : toujours redémarrés.
docker compose restart app horizon scheduler
# default.conf est monté : un reload applique ses changements éventuels.
docker exec memorylane_nginx nginx -s reload

step "Fin de maintenance"
artisan up
trap - ERR

step "Contrôle"
# Traefik ne route vers nginx qu'une fois le conteneur « healthy ».
for _ in $(seq 1 45); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' memorylane_nginx)" = healthy ] && break
    sleep 2
done
fail=0
for path in /up /login; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "$URL$path" || true)
    echo "$path → $code"
    [ "$code" = 200 ] || fail=1
done
[ "$fail" = 0 ] && echo "✅ Déployé : $(git log --oneline -1)" || { echo "⚠️  Contrôle en échec : vérifier le site et storage/logs."; exit 1; }
