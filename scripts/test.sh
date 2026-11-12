#!/usr/bin/env bash
# Valide un commit avant déploiement, dans l'environnement de DEV (jamais sur
# la prod) : suite PHPUnit complète + build du front. En cas de succès,
# l'empreinte du commit est enregistrée dans ~/.memorylane-tests-ok/ ;
# scripts/deploy.sh refuse tout commit qui n'y figure pas.
#
#   cd ~/memorylane-dev && git checkout <branche> && scripts/test.sh
#
# Le verrou porte sur un commit précis : après un rebase ou un nouveau commit,
# relancer (c'est voulu, la combinaison a changé).
set -euo pipefail
cd "$(dirname "$0")/.."

project=$(grep -s '^COMPOSE_PROJECT_NAME=' .env | cut -d= -f2 || true)
[ "$project" = memorylane-dev ] || { echo "À lancer dans ~/memorylane-dev (jamais sur la prod)."; exit 1; }
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "Modifications non commitées : commiter d'abord, le verrou porte sur un commit."; exit 1; }

# Une seule validation à la fois : elles partagent la base memorylane_test
# (deux suites simultanées s'effacent mutuellement les tables).
mkdir -p "$HOME/.memorylane-tests-ok"
exec 9> "$HOME/.memorylane-tests-ok/.verrou"
if ! flock -n 9; then
    echo "Une autre validation tourne déjà : attente de sa fin…"
    flock 9
fi

SHA=$(git rev-parse HEAD)
echo "▶ Validation de $(git log --oneline -1 "$SHA")"

# Dépendances PHP alignées sur le composer.lock du commit (rapide si à jour).
docker compose exec -T app composer install --no-interaction --quiet

# Base de test réservée à la validation : d'autres sessions lancent leurs
# tests sur memorylane_test depuis d'autres dossiers (autres migrations), et
# deux suites sur la même base s'effacent les tables (vu le 2026-11-11).
DB=memorylane_validation_test
docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -tAc \"SELECT 1 FROM pg_database WHERE datname = '$DB'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" $DB"
sed "s/value=\"memorylane_test\"/value=\"$DB\"/" backend/phpunit.xml > backend/phpunit.validation.xml
trap 'rm -f backend/phpunit.validation.xml' EXIT

echo "▶ Tests PHPUnit (base $DB)"
LOG=$(mktemp)
docker compose exec -T app php -d memory_limit=512M vendor/bin/phpunit -c phpunit.validation.xml --colors=never > "$LOG" 2>&1 || true
if ! grep -q '^OK (' "$LOG"; then
    { grep -v '^/var/www/html/vendor' "$LOG" | grep -A25 '^There w' | head -60; tail -3 "$LOG"; } || true
    mkdir -p "$HOME/.memorylane-tests-ok"
    mv "$LOG" "$HOME/.memorylane-tests-ok/derniere-erreur.log"
    echo "✗ Tests en échec : commit ${SHA:0:7} NON validé (journal complet : ~/.memorylane-tests-ok/derniere-erreur.log)."
    exit 1
fi
SUMMARY=$(grep '^OK (' "$LOG")
rm -f "$LOG"

echo "▶ Build du front"
BUILD_LOG=$(mktemp)
(cd backend && npm run build > "$BUILD_LOG" 2>&1) || { tail -20 "$BUILD_LOG"; rm -f "$BUILD_LOG"; echo "✗ Build du front en échec : commit ${SHA:0:7} NON validé."; exit 1; }
rm -f "$BUILD_LOG"

mkdir -p "$HOME/.memorylane-tests-ok"
echo "$(date '+%Y-%m-%d %H:%M') ${SUMMARY}" > "$HOME/.memorylane-tests-ok/$SHA"
echo "✓ Commit ${SHA:0:7} validé (${SUMMARY}) : déployable avec scripts/deploy.sh."
