#!/usr/bin/env bash
# Déploiement en production — le serveur sert directement ce dépôt.
#
#   scripts/deploy.sh <branche|commit>
#
# Avance main en fast-forward jusqu'à <branche|commit>, avec : vérifications
# (dont tests validés sur le dev par scripts/test.sh pour CE commit) →
# dump de la base → maintenance → code → dépendances si les verrous ont
# changé → migrations → build front → caches Laravel → conteneurs → fin de
# maintenance → contrôle des pages, des files de tâches de fond (workers à
# jour) et des pages clés dans un navigateur sans écran.
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
# Verrou : le commit doit avoir passé scripts/test.sh sur le dev (suite
# complète + build). DEPLOY_SANS_TESTS=1 pour une urgence, en connaissance de cause.
TESTS_OK="$HOME/.memorylane-tests-ok/$NEW"
if [ -f "$TESTS_OK" ]; then
    echo "Tests validés : $(cat "$TESTS_OK")"
elif [ "${DEPLOY_SANS_TESTS:-}" = 1 ]; then
    echo "⚠️  Déploiement SANS validation des tests (DEPLOY_SANS_TESTS=1)."
else
    echo "Commit ${NEW:0:7} non validé par les tests. Dans ~/memorylane-dev : se placer sur ce commit puis lancer scripts/test.sh."
    exit 1
fi
git log --oneline "$OLD..$NEW"
changed() { git diff --name-only "$OLD" "$NEW" -- "$@" | grep -q .; }

step "Sauvegarde de la base"
mkdir -p backups
DUMP="backups/pre-deploy-$(date -u +%Y%m%d-%H%M%S).dump"
docker exec memorylane_postgres sh -c 'pg_dump -Fc -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$DUMP"
echo "$DUMP ($(du -h "$DUMP" | cut -f1))"

step "Maintenance"
# Supervision : pas d'alerte pendant le déploiement (redémarrages attendus).
artisan memorylane:health-check --pause=30 || true
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
# Les workers démarrés après cet instant tournent avec le nouveau code.
DEPLOY_START=$(date +%s)
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
[ "$fail" = 0 ] || { echo "⚠️  Contrôle en échec : vérifier le site et storage/logs."; exit 1; }

# Contrôle approfondi (DEPLOY_SANS_CONTROLE=1 pour le sauter) : chaque file de
# tâches de fond traitée par un worker à jour, puis les pages clés ouvertes
# dans un navigateur sans écran (compte admin, lecture seule).
if [ "${DEPLOY_SANS_CONTROLE:-}" != 1 ]; then
    step "Tâches de fond"
    artisan memorylane:deploy-probe --since="$DEPLOY_START" || fail=1

    step "Pages clés (navigateur)"
    control=$(artisan tinker --execute='
        $admin = App\Models\User::where("role", "admin")->orderBy("created_at")->first();
        $paths = array_values(array_filter([
            ($id = App\Models\Media::where("user_id", $admin->id)->where("type", "photo")->latest("created_at")->value("id")) ? "/media/$id" : null,
            ($id = App\Models\Album::where("user_id", $admin->id)->latest("created_at")->value("id")) ? "/albums/$id" : null,
            $admin->person_id ? "/people/$admin->person_id" : null,
        ]));
        echo json_encode(["link" => App\Notifications\MagicLoginLink::urlFor($admin), "paths" => $paths]);
    ' | tail -1)
    (cd backend && node scripts/post-deploy-check.mjs "$control") || fail=1
fi

[ "$fail" = 0 ] && echo "✅ Déployé : $(git log --oneline -1)" || { echo "⚠️  Déployé (site en ligne) mais contrôle approfondi en échec : voir ci-dessus."; exit 1; }
