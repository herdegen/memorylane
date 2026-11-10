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

SHA=$(git rev-parse HEAD)
echo "▶ Validation de $(git log --oneline -1 "$SHA")"

# Dépendances PHP alignées sur le composer.lock du commit (rapide si à jour).
docker compose exec -T app composer install --no-interaction --quiet

echo "▶ Tests PHPUnit"
LOG=$(mktemp)
docker compose exec -T app php -d memory_limit=512M vendor/bin/phpunit --colors=never > "$LOG" 2>&1 || true
if ! grep -q '^OK (' "$LOG"; then
    { grep -v '^/var/www/html/vendor' "$LOG" | grep -A25 '^There w' | head -60; tail -3 "$LOG"; } || true
    echo "✗ Tests en échec : commit ${SHA:0:7} NON validé."
    rm -f "$LOG"; exit 1
fi
SUMMARY=$(grep '^OK (' "$LOG")
rm -f "$LOG"

echo "▶ Build du front"
(cd backend && npm run build > /dev/null) || { echo "✗ Build du front en échec : commit ${SHA:0:7} NON validé."; exit 1; }

mkdir -p "$HOME/.memorylane-tests-ok"
echo "$(date '+%Y-%m-%d %H:%M') ${SUMMARY}" > "$HOME/.memorylane-tests-ok/$SHA"
echo "✓ Commit ${SHA:0:7} validé (${SUMMARY}) : déployable avec scripts/deploy.sh."
