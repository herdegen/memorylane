#!/usr/bin/env bash
# =============================================================================
#  Installateur de MemoryLane (installation « chez soi »)
# =============================================================================
#
#   ./install.sh                 installe MemoryLane sur cette machine, puis
#                                ouvre l'assistant dans le navigateur
#   ./install.sh finish          applique les choix de l'assistant (à relancer
#                                si le terminal a été fermé avant la fin)
#   ./install.sh update          met à jour MemoryLane (code, base, front)
#   ./install.sh status          état des services
#
# Options (installation) :
#   --port 8080                  port de l'assistant et du site (défaut 8080)
#   --bind 0.0.0.0               adresse d'écoute (127.0.0.1 : cette machine seule)
#   --project memorylane         nom du projet Docker (plusieurs installations)
#
# Ce script ne pose presque aucune question : il prépare les conteneurs, puis
# tout se règle dans le navigateur (adresse du site, stockage des photos,
# e-mails, sauvegardes, compte administrateur), étape par étape, avec les
# explications. Rien n'est envoyé ailleurs que là où vous le choisissez.
# =============================================================================
set -Eeuo pipefail
cd "$(dirname "$0")"

PORT=8080
BIND=0.0.0.0
PROJECT=memorylane
CMD=install

while [ $# -gt 0 ]; do
    case "$1" in
        finish|update|status) CMD=$1 ;;
        --port) PORT=${2:?--port demande un numéro}; shift ;;
        --bind) BIND=${2:?--bind demande une adresse}; shift ;;
        --project) PROJECT=${2:?--project demande un nom}; shift ;;
        -h|--help) sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Option inconnue : $1 (voir ./install.sh --help)"; exit 1 ;;
    esac
    shift
done

# ---- Affichage ----------------------------------------------------------------
if [ -t 1 ]; then B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; D=$'\033[2m'; N=$'\033[0m'; else B='' G='' Y='' R='' D='' N=''; fi
step() { printf '\n%s▶ %s%s\n' "$B" "$*" "$N"; }
ok()   { printf '  %s✓%s %s\n' "$G" "$N" "$*"; }
warn() { printf '  %s!%s %s\n' "$Y" "$N" "$*"; }
fail() { printf '\n%s✗ %s%s\n' "$R" "$*" "$N" >&2; exit 1; }
info() { printf '  %s%s%s\n' "$D" "$*" "$N"; }

ROOT_ENV=.env
APP_ENV_FILE=backend/.env
INSTALL_DIR=backend/storage/app/install
GARAGE_CONF=docker/selfhost/generated/garage.toml

trap 'printf "\n%s✗ Erreur inattendue (ligne %s). Relancez ./install.sh : l installation reprend où elle s est arrêtée.%s\n" "$R" "$LINENO" "$N" >&2' ERR

dc() { docker compose "$@"; }
artisan() { dc exec -T app php artisan "$@"; }

# Valeur d'une clé dans un fichier .env (sans les guillemets).
env_get() { grep -E "^$2=" "$1" 2>/dev/null | tail -n1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"; }
# Remplace (ou ajoute) KEY=valeur dans un fichier .env.
env_set() {
    local file=$1 key=$2 value=$3 tmp
    tmp=$(mktemp)
    if grep -qE "^$key=" "$file"; then
        awk -v k="$key" -v v="$value" 'BEGIN{FS=OFS="="} $1==k {print k "=" v; next} {print}' "$file" > "$tmp"
    else
        cat "$file" > "$tmp"; printf '%s=%s\n' "$key" "$value" >> "$tmp"
    fi
    cat "$tmp" > "$file"; rm -f "$tmp"
}
# Secret aléatoire (lettres et chiffres).
# « || true » : tr est interrompu par head (SIGPIPE), ce que pipefail
# prendrait pour une erreur.
randchars() { (LC_ALL=C tr -dc "$1" < /dev/urandom 2>/dev/null | head -c "$2") || true; }
secret() { randchars 'A-Za-z0-9' "${1:-40}"; }
hexsecret() { randchars 'a-f0-9' "${1:-64}"; }

# ---- Vérifications ------------------------------------------------------------------
check_requirements() {
    step "Vérification de la machine"
    [ "$(uname -s)" = Linux ] || warn "Système $(uname -s) : MemoryLane est testé sur Linux (Debian, Ubuntu…)."
    command -v docker >/dev/null || fail "Docker n'est pas installé. Voir https://docs.docker.com/engine/install/ puis relancer ./install.sh"
    docker info >/dev/null 2>&1 || fail "Docker ne répond pas, ou votre compte n'a pas le droit de l'utiliser.
  Essayez : sudo usermod -aG docker \$USER  (puis reconnectez-vous), ou lancez avec sudo."
    local v; v=$(docker compose version --short 2>/dev/null | sed 's/^v//') || true
    [ -n "$v" ] || fail "Docker Compose (v2) est absent. Installez le paquet docker-compose-plugin."
    local major minor; major=${v%%.*}; minor=$(echo "$v" | cut -d. -f2)
    if [ "$major" -lt 2 ] || { [ "$major" -eq 2 ] && [ "$minor" -lt 24 ]; }; then
        fail "Docker Compose $v est trop ancien (2.24 ou plus requis)."
    fi
    ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null), Compose $v"

    local mem_mb; mem_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
    if [ "$mem_mb" -ge 6000 ]; then ok "Mémoire : $((mem_mb / 1024)) Go"
    elif [ "$mem_mb" -ge 3500 ]; then warn "Mémoire : $((mem_mb / 1024)) Go — suffisant, mais sans la reconnaissance des visages (3 Go de plus)."
    else warn "Mémoire : ${mem_mb} Mo — 4 Go sont conseillés, l'installation risque d'être lente."; fi

    local free_gb; free_gb=$(df -Pk . | awk 'NR==2 {print int($4/1024/1024)}')
    if [ "$free_gb" -ge 20 ]; then ok "Espace disque libre : ${free_gb} Go"
    else warn "Espace disque libre : ${free_gb} Go — prévoyez la place de vos photos et vidéos."; fi

    if command -v ss >/dev/null && ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]${PORT}\$"; then
        fail "Le port ${PORT} est déjà utilisé. Choisissez-en un autre : ./install.sh --port 8090"
    fi
    ok "Port ${PORT} libre"
}

# ---- Configuration -------------------------------------------------------------------
host_ip() {
    hostname -I 2>/dev/null | tr ' ' '\n' | grep -vE '^(127\.|172\.1[6-9]\.|172\.2[0-9]\.|172\.3[01]\.|$)' | grep -v ':' | head -n1
}

write_config() {
    step "Configuration"
    local uid gid ip url
    uid=$(id -u); gid=$(id -g)
    # Root : les conteneurs ne tournent pas en root, on prend l'utilisateur 1000.
    if [ "$uid" -eq 0 ]; then uid=1000; gid=1000; chown -R 1000:1000 backend; fi

    local db_password meili_key
    db_password=$(secret 32); meili_key=$(secret 40)
    cat > "$ROOT_ENV" <<EOF
# Généré par install.sh le $(date '+%d/%m/%Y à %H:%M') — variables de Docker Compose.
# La configuration de l'application est dans backend/.env.
MEMORYLANE_SELFHOST=1
COMPOSE_FILE=docker-compose.yml:docker-compose.selfhost.yml
COMPOSE_PROJECT_NAME=${PROJECT}
# Services optionnels (réglés par l'assistant) : local-storage faces backup https
COMPOSE_PROFILES=local-storage
UID=${uid}
GID=${gid}
DB_DATABASE=memorylane
DB_USERNAME=memorylane
DB_PASSWORD=${db_password}
MEILISEARCH_KEY=${meili_key}
MEILI_NO_ANALYTICS=true
MEILI_ENV=production
# Site : port et adresse d'écoute de nginx ; domaine si HTTPS automatique
APP_PORT=${PORT}
APP_BIND=${BIND}
MEMORYLANE_DOMAIN=
# Données du stockage intégré : volume Docker, ou dossier d'un grand disque
# GARAGE_DATA_DIR=/srv/memorylane/photos
EOF
    chmod 600 "$ROOT_ENV"
    ok ".env (Docker) : mots de passe de la base et de la recherche générés"

    ip=$(host_ip); url="http://${ip:-localhost}:${PORT}"
    local app_key; app_key="base64:$(head -c 32 /dev/urandom | base64)"
    sed -e "s|__APP_KEY__|${app_key}|" -e "s|__APP_URL__|${url}|g" \
        -e "s|__DB_PASSWORD__|${db_password}|" -e "s|__MEILI_KEY__|${meili_key}|" \
        docker/selfhost/backend.env.template > "$APP_ENV_FILE"
    chmod 600 "$APP_ENV_FILE"
    ok "backend/.env (application) : clé de chiffrement générée"

    mkdir -p "$(dirname "$GARAGE_CONF")"
    sed -e "s|__RPC_SECRET__|$(hexsecret 64)|" -e "s|__ADMIN_TOKEN__|$(secret 40)|" \
        docker/selfhost/garage.toml.template > "$GARAGE_CONF"
    chmod 644 "$GARAGE_CONF"
    ok "Stockage intégré (Garage) configuré"
    create_token
}

wait_healthy() {
    local service=$1 id status
    for _ in $(seq 1 90); do
        id=$(dc ps -q "$service" 2>/dev/null)
        status=$([ -n "$id" ] && docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$id" 2>/dev/null || echo absent)
        case "$status" in healthy|running) return 0 ;; esac
        sleep 2
    done
    fail "Le service « $service » ne démarre pas. Voir : docker compose logs $service"
}

setup_garage() {
    step "Stockage intégré des photos"
    local g="dc exec -T garage /garage" node out key secret_key
    for _ in $(seq 1 30); do $g status >/dev/null 2>&1 && break; sleep 2; done
    node=$($g status 2>/dev/null | awk '/HEALTHY NODES/ {f=1; next} f && $1 ~ /^[0-9a-f]+$/ {print $1; exit}')
    [ -n "$node" ] || fail "Garage ne répond pas. Voir : docker compose logs garage"
    if ! $g layout show 2>/dev/null | grep -q "dc1"; then
        $g layout assign -z dc1 -c 1T "$node" >/dev/null
        $g layout apply --version 1 >/dev/null
    fi
    $g bucket info memorylane-files >/dev/null 2>&1 || $g bucket create memorylane-files >/dev/null
    if [ -z "$(env_get "$APP_ENV_FILE" GARAGE_ACCESS_KEY)" ]; then
        out=$($g key create memorylane-app 2>/dev/null)
        key=$(echo "$out" | awk -F': *' '/Key ID/ {print $2; exit}')
        secret_key=$(echo "$out" | awk -F': *' '/Secret key/ {print $2; exit}')
        [ -n "$key" ] && [ -n "$secret_key" ] || fail "Impossible de créer la clé du stockage intégré."
        $g bucket allow --read --write --owner memorylane-files --key memorylane-app >/dev/null
        for k in GARAGE_ACCESS_KEY:$key GARAGE_SECRET_KEY:$secret_key SCALEWAY_ACCESS_KEY:$key SCALEWAY_SECRET_KEY:$secret_key; do
            env_set "$APP_ENV_FILE" "${k%%:*}" "${k#*:}"
        done
    fi
    ok "Espace « memorylane-files » prêt (les photos restent sur ce serveur)"
}

build_and_start() {
    step "Construction des conteneurs (quelques minutes la première fois)"
    dc build app >/dev/null
    ok "Image de l'application prête"
    dc up -d postgres redis meilisearch garage >/dev/null 2>&1
    for s in postgres redis meilisearch garage; do wait_healthy "$s"; done
    ok "Base de données, cache, recherche et stockage démarrés"
    setup_garage

    step "Installation de l'application"
    dc up -d app >/dev/null 2>&1
    wait_healthy app
    dc exec -T app composer install --no-interaction --no-dev --optimize-autoloader --no-progress >/dev/null 2>&1 \
        || fail "composer install a échoué. Voir : docker compose exec app composer install"
    ok "Dépendances PHP"
    dc exec -T app sh -c 'npm ci --no-audit --no-fund >/dev/null 2>&1 && npm run build >/dev/null 2>&1' \
        || fail "La construction de l'interface a échoué. Voir : docker compose exec app npm run build"
    ok "Interface construite"
    # Redémarrage : republie les assets de l'administration (vendor installé).
    dc restart app >/dev/null 2>&1; wait_healthy app
    dc up -d nginx >/dev/null 2>&1; wait_healthy nginx
    ok "Serveur web démarré"
}

# Code d'accès de l'assistant : créé au début (une installation interrompue
# reprend avec le même code), effacé à la fin.
create_token() {
    mkdir -p "$INSTALL_DIR"
    [ -s "$INSTALL_DIR/token" ] || randchars 'A-HJ-NP-Z2-9' 8 > "$INSTALL_DIR/token"
    chmod 600 "$INSTALL_DIR/token"
    if [ "$(id -u)" -eq 0 ]; then chown -R 1000:1000 "$INSTALL_DIR"; fi
    rm -f "$INSTALL_DIR/finished.env"
}

wait_for_wizard() {
    local token=$1 ip; ip=$(host_ip)
    printf '\n%s══════════════════════════════════════════════════════════════════%s\n' "$G" "$N"
    printf '  %sMemoryLane est prêt à être configuré.%s\n\n' "$B" "$N"
    printf '  Ouvrez dans votre navigateur :  %shttp://%s:%s/install%s\n' "$B" "${ip:-localhost}" "$PORT" "$N"
    [ "$BIND" = 127.0.0.1 ] && info "(depuis cette machine : http://localhost:${PORT}/install)"
    printf '  Code d\x27accès à saisir       :  %s%s%s\n' "$B" "$token" "$N"
    printf '%s══════════════════════════════════════════════════════════════════%s\n' "$G" "$N"
    info "Laissez ce terminal ouvert : il terminera l'installation quand vous aurez"
    info "fini l'assistant. Fermé par erreur ? Relancez ./install.sh finish"
    until [ -f "$INSTALL_DIR/finished.env" ]; do sleep 3; done
    finish
}

# ---- Fin : applique les choix de l'assistant ---------------------------------------
finish() {
    [ -f "$INSTALL_DIR/finished.env" ] || fail "L'assistant n'est pas terminé (http://…/install)."
    step "Application de vos choix"
    local profiles domain bind
    profiles=$(env_get "$INSTALL_DIR/finished.env" PROFILES)
    domain=$(env_get "$INSTALL_DIR/finished.env" DOMAIN)
    bind=$(env_get "$INSTALL_DIR/finished.env" BIND)
    # Valeurs écrites par l'application : vérifiées avant de les utiliser.
    echo "$profiles" | grep -qE '^[a-z, -]*$' || fail "Choix des services illisible."
    echo "$domain" | grep -qE '^[A-Za-z0-9.-]*$' || fail "Nom de domaine invalide."
    profiles=$(echo "$profiles" | tr ' ' ',' | sed 's/,,*/,/g; s/^,//; s/,$//')
    env_set "$ROOT_ENV" COMPOSE_PROFILES "$profiles"
    env_set "$ROOT_ENV" MEMORYLANE_DOMAIN "$domain"
    # HTTPS automatique : nginx n'écoute plus que pour Caddy (cette machine).
    case "$bind" in 0.0.0.0|127.0.0.1) env_set "$ROOT_ENV" APP_BIND "$bind" ;; esac
    ok "Services : ${profiles//,/, }"

    case ",$profiles," in *,faces,*) info "Construction de la reconnaissance des visages (5 à 15 minutes la première fois)…" ;; esac
    dc up -d --build --remove-orphans >/dev/null 2>&1 || fail "Démarrage des services impossible. Voir : docker compose ps"
    case ",$profiles," in *,local-storage,*) ;; *) dc rm -sf garage >/dev/null 2>&1 || true ;; esac
    artisan optimize >/dev/null
    dc restart app horizon scheduler >/dev/null 2>&1
    wait_healthy nginx
    rm -f "$INSTALL_DIR/token"
    ok "Tous les services sont démarrés"

    local url; url=$(env_get "$APP_ENV_FILE" APP_URL)
    printf '\n%s══════════════════════════════════════════════════════════════════%s\n' "$G" "$N"
    printf '  %sMemoryLane est installé.%s  %s%s%s\n' "$B" "$N" "$B" "$url" "$N"
    if [ -n "$domain" ]; then
        info "HTTPS : le certificat est demandé à Let's Encrypt à la première visite."
        info "Le domaine ${domain} doit pointer vers ce serveur, ports 80 et 443 ouverts."
    fi
    info "Mettre à jour plus tard : ./install.sh update"
    printf '%s══════════════════════════════════════════════════════════════════%s\n' "$G" "$N"
}

# ---- Mise à jour ---------------------------------------------------------------------
update() {
    grep -q '^MEMORYLANE_SELFHOST=1' "$ROOT_ENV" 2>/dev/null || fail "Pas d'installation « chez soi » ici."
    step "Sauvegarde de la base avant mise à jour"
    mkdir -p backups
    local dump; dump="backups/avant-maj-$(date +%Y%m%d-%H%M%S).dump"
    dc exec -T postgres sh -c 'pg_dump -Fc -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$dump"
    ok "$dump"
    step "Nouveau code"
    local before; before=$(git rev-parse HEAD)
    git pull --ff-only || fail "git pull impossible (modifications locales ?)."
    git log --oneline "$before..HEAD" | head -20
    step "Mise à jour"
    artisan down --retry=30 >/dev/null || true
    dc build app >/dev/null
    dc up -d --remove-orphans >/dev/null 2>&1
    wait_healthy app
    dc exec -T app composer install --no-interaction --no-dev --optimize-autoloader --no-progress >/dev/null 2>&1
    dc exec -T app sh -c 'npm ci --no-audit --no-fund >/dev/null 2>&1 && npm run build >/dev/null 2>&1'
    artisan migrate --force
    artisan optimize >/dev/null
    dc restart app horizon scheduler >/dev/null 2>&1
    wait_healthy nginx
    artisan up >/dev/null
    ok "MemoryLane est à jour : $(git log --oneline -1)"
}

# ---- Programme principal ------------------------------------------------------------
case "$CMD" in
    status) dc ps; exit 0 ;;
    update) update; exit 0 ;;
    finish) finish; exit 0 ;;
esac

if [ -f "$ROOT_ENV" ]; then
    grep -q '^MEMORYLANE_SELFHOST=1' "$ROOT_ENV" \
        || fail "Un fichier .env existe déjà et ne vient pas de cet installateur : rien n'a été modifié."
    if [ -f "$APP_ENV_FILE" ] && [ ! -f "$INSTALL_DIR/token" ]; then
        fail "MemoryLane est déjà installé ici. Mise à jour : ./install.sh update"
    fi
    # Installation commencée : on reprend avec la configuration existante.
    PORT=$(env_get "$ROOT_ENV" APP_PORT); BIND=$(env_get "$ROOT_ENV" APP_BIND)
    step "Reprise de l'installation commencée"
else
    printf '%sInstallation de MemoryLane%s\n' "$B" "$N"
    info "Albums de famille, arbre généalogique, visages, carte… sur votre propre serveur."
    check_requirements
    write_config
fi

build_and_start
create_token
wait_for_wizard "$(cat "$INSTALL_DIR/token")"
