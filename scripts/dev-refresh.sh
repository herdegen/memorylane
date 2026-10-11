#!/usr/bin/env bash
# Rafraîchit l'environnement de DEV à partir de la prod (lecture seule côté prod) :
#   base de données (dump → restauration), photos (bucket de dev), index de
#   recherche. À lancer depuis ~/memorylane-dev :  scripts/dev-refresh.sh
#
# Données neutralisées après copie : abonnements push (sinon le dev
# notifierait les vrais téléphones), jetons de réinitialisation, sessions,
# files d'attente, cache. Les mails sont déjà forcés vers le journal
# (MAIL_FORCE_LOG=true dans backend/.env du dev).
set -euo pipefail
cd "$(dirname "$0")/.."

PROD_DB=memorylane_postgres
DEV_DB=memorylane_dev_postgres

# Garde-fous : on n'écrit QUE dans le dev.
[ "$(grep -E '^COMPOSE_PROJECT_NAME=' .env | cut -d= -f2)" = memorylane-dev ] || { echo "Pas dans l'environnement de dev : abandon."; exit 1; }
grep -qE '^MAIL_FORCE_LOG=true' backend/.env || { echo "MAIL_FORCE_LOG=true absent du backend/.env du dev : abandon."; exit 1; }
[ "$(grep -E '^SCALEWAY_BUCKET=' backend/.env | cut -d= -f2)" = memorylane-dev ] || { echo "Le dev doit utiliser le bucket memorylane-dev : abandon."; exit 1; }

step() { printf '\n\033[1m▶ %s\033[0m\n' "$*"; }
artisan() { docker compose exec -T app php artisan "$@"; }
devsql() { docker exec -i "$DEV_DB" sh -c 'psql -v ON_ERROR_STOP=1 -q -U "$POSTGRES_USER" -d "$1"' _ "$1"; }

step "Base : copie de la prod"
docker compose stop horizon >/dev/null
docker exec "$PROD_DB" sh -c 'pg_dump -Fc -U "$POSTGRES_USER" "$POSTGRES_DB"' \
    | docker exec -i "$DEV_DB" sh -c 'pg_restore --clean --if-exists --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
    || true   # pg_restore signale des avertissements bénins (extensions, commentaires)
devsql memorylane <<'SQL'
TRUNCATE push_subscriptions, password_reset_tokens, jobs, failed_jobs, cache, cache_locks;
UPDATE users SET remember_token = NULL;
SQL
echo "SELECT 'CREATE DATABASE memorylane_test' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'memorylane_test')\gexec" | devsql memorylane
echo 'CREATE EXTENSION IF NOT EXISTS "uuid-ossp"; CREATE EXTENSION IF NOT EXISTS pg_trgm;' | devsql memorylane_test
docker exec memorylane_dev_redis redis-cli FLUSHALL >/dev/null

step "Photos : bucket de dev complété (copie côté Scaleway)"
val() { grep -E "^$1=" "$2" | tail -n1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }
export RCLONE_CONFIG=/dev/null RCLONE_CONFIG_SCW_TYPE=s3 RCLONE_CONFIG_SCW_PROVIDER=Scaleway
export RCLONE_CONFIG_SCW_REGION=fr-par RCLONE_CONFIG_SCW_ENDPOINT=https://s3.fr-par.scw.cloud
RCLONE_CONFIG_SCW_ACCESS_KEY_ID=$(val SCALEWAY_ACCESS_KEY backend/.env)
RCLONE_CONFIG_SCW_SECRET_ACCESS_KEY=$(val SCALEWAY_SECRET_KEY backend/.env)
PROD_BUCKET=$(val SCALEWAY_BUCKET ../memorylane/backend/.env)
export RCLONE_CONFIG_SCW_ACCESS_KEY_ID RCLONE_CONFIG_SCW_SECRET_ACCESS_KEY
# Variables transmises par leur nom : aucune clé visible dans la liste des processus.
docker run --rm -e RCLONE_CONFIG -e RCLONE_CONFIG_SCW_TYPE -e RCLONE_CONFIG_SCW_PROVIDER \
    -e RCLONE_CONFIG_SCW_REGION -e RCLONE_CONFIG_SCW_ENDPOINT \
    -e RCLONE_CONFIG_SCW_ACCESS_KEY_ID -e RCLONE_CONFIG_SCW_SECRET_ACCESS_KEY \
    rclone/rclone:1.75.1 copy "scw:$PROD_BUCKET/media" scw:memorylane-dev/media --size-only --fast-list --transfers 32 --checkers 32 -q

step "Application"
docker compose start horizon >/dev/null
artisan migrate --force
artisan optimize:clear >/dev/null
artisan s3:configure-cors https://dev.memory-lane.fr >/dev/null
artisan scout:sync-index-settings >/dev/null
for model in Person Media Album Tag; do artisan scout:import "App\\Models\\$model" >/dev/null; done

echo; echo "✅ Dev rafraîchi : https://dev.memory-lane.fr (accès : ~/.memorylane-dev-password)"
