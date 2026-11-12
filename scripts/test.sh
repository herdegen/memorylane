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

# Faux Scaleway pour les tests de stockage (Garage, cf. docker-compose.dev.yml).
# Clé fixe réservée aux tests : conteneur jetable, sans port, données en mémoire.
S3_TEST_KEY=GK0123456789abcdef01234567
S3_TEST_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
S3_TEST_BUCKET=memorylane-test
docker compose --profile s3test up -d s3test > /dev/null 2>&1
garage() { docker compose exec -T -e RUST_LOG=warn s3test /garage "$@"; }
for _ in $(seq 1 30); do garage status > /dev/null 2>&1 && break; sleep 1; done
if ! garage bucket info "$S3_TEST_BUCKET" > /dev/null 2>&1; then
    node=$(garage status 2>/dev/null | awk '/HEALTHY NODES/ {f=1; next} f && $1 ~ /^[0-9a-f]+$/ {print $1; exit}')
    [ -n "$node" ] || { echo "✗ Stockage S3 de test (Garage) injoignable : docker compose logs s3test"; exit 1; }
    garage layout assign -z dc1 -c 1G "$node" > /dev/null
    garage layout apply --version 1 > /dev/null
    garage bucket create "$S3_TEST_BUCKET" > /dev/null
    garage key import --yes -n tests "$S3_TEST_KEY" "$S3_TEST_SECRET" > /dev/null
    garage bucket allow --read --write --owner "$S3_TEST_BUCKET" --key tests > /dev/null
fi

# Base de test réservée à la validation : d'autres sessions lancent leurs
# tests sur memorylane_test depuis d'autres dossiers (autres migrations), et
# deux suites sur la même base s'effacent les tables (vu le 2026-11-11).
DB=memorylane_validation_test
docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -tAc \"SELECT 1 FROM pg_database WHERE datname = '$DB'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" $DB"
sed -e "s/value=\"memorylane_test\"/value=\"$DB\"/" \
    -e "s#</php>#    <env name=\"S3_TEST_ENDPOINT\" value=\"http://s3test:3900\" force=\"true\"/>\n        <env name=\"S3_TEST_KEY\" value=\"$S3_TEST_KEY\" force=\"true\"/>\n        <env name=\"S3_TEST_SECRET\" value=\"$S3_TEST_SECRET\" force=\"true\"/>\n        <env name=\"S3_TEST_BUCKET\" value=\"$S3_TEST_BUCKET\" force=\"true\"/>\n    </php>#" \
    backend/phpunit.xml > backend/phpunit.validation.xml
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
