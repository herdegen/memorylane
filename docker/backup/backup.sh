#!/bin/sh
# Sauvegarde des fichiers MemoryLane : bucket Scaleway → OVH Object Storage
# (Gravelines), chiffrée côté serveur par rclone crypt (noms et contenus).
#
# Copie SANS suppression : un média effacé dans l'appli reste dans la
# sauvegarde. Le bucket OVH verrouille chaque version 30 jours (Object Lock,
# mode Gouvernance) : même avec les clés du serveur, rien ne peut y être
# effacé ni écrasé pendant ce délai.
#
#   backup.sh copy    copie nocturne de media/ et _backups/ (dumps de la base)
#   backup.sh verify  test de restauration : fichiers manquants + 5 fichiers
#                     au hasard relus depuis la sauvegarde, comparés à l'original
#
# Configuration lue dans le .env de l'appli (monté en lecture seule) :
# SCALEWAY_* (source), BACKUP_S3_* (destination), BACKUP_CRYPT_PASSWORD/SALT.
# ⚠️ Sans BACKUP_CRYPT_PASSWORD et BACKUP_CRYPT_SALT, la sauvegarde est illisible.
set -eu

ENV_FILE=${ENV_FILE:-/app/.env}
LOG=${BACKUP_LOG:-/logs/backup-media.log}

get() {
    grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

export RCLONE_CONFIG_SRC_TYPE=s3 RCLONE_CONFIG_SRC_PROVIDER=Scaleway
RCLONE_CONFIG_SRC_ACCESS_KEY_ID=$(get SCALEWAY_ACCESS_KEY)
RCLONE_CONFIG_SRC_SECRET_ACCESS_KEY=$(get SCALEWAY_SECRET_KEY)
RCLONE_CONFIG_SRC_REGION=$(get SCALEWAY_REGION)
RCLONE_CONFIG_SRC_ENDPOINT=$(get SCALEWAY_ENDPOINT)
export RCLONE_CONFIG_SRC_ACCESS_KEY_ID RCLONE_CONFIG_SRC_SECRET_ACCESS_KEY
export RCLONE_CONFIG_SRC_REGION="${RCLONE_CONFIG_SRC_REGION:-fr-par}"
export RCLONE_CONFIG_SRC_ENDPOINT="${RCLONE_CONFIG_SRC_ENDPOINT:-https://s3.fr-par.scw.cloud}"

export RCLONE_CONFIG_DST_TYPE=s3 RCLONE_CONFIG_DST_PROVIDER=Other RCLONE_CONFIG_DST_NO_CHECK_BUCKET=true
RCLONE_CONFIG_DST_ACCESS_KEY_ID=$(get BACKUP_S3_ACCESS_KEY)
RCLONE_CONFIG_DST_SECRET_ACCESS_KEY=$(get BACKUP_S3_SECRET_KEY)
RCLONE_CONFIG_DST_ENDPOINT=$(get BACKUP_S3_ENDPOINT)
RCLONE_CONFIG_DST_REGION=$(get BACKUP_S3_REGION)
export RCLONE_CONFIG_DST_ACCESS_KEY_ID RCLONE_CONFIG_DST_SECRET_ACCESS_KEY RCLONE_CONFIG_DST_ENDPOINT RCLONE_CONFIG_DST_REGION

export RCLONE_CONFIG_CRYPT_TYPE=crypt
RCLONE_CONFIG_CRYPT_REMOTE="dst:$(get BACKUP_S3_BUCKET)"
RCLONE_CONFIG_CRYPT_PASSWORD=$(get BACKUP_CRYPT_PASSWORD | rclone obscure -)
RCLONE_CONFIG_CRYPT_PASSWORD2=$(get BACKUP_CRYPT_SALT | rclone obscure -)
export RCLONE_CONFIG_CRYPT_REMOTE RCLONE_CONFIG_CRYPT_PASSWORD RCLONE_CONFIG_CRYPT_PASSWORD2

SRC="src:$(get SCALEWAY_BUCKET)"
PREFIXES="media _backups"

log() { echo "$(date -u '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; echo "$*"; }

case "${1:-}" in
copy)
    log "copie : début"
    status=0
    for p in $PREFIXES; do
        # Fichiers immuables (clé unique) : la taille suffit, pas de lecture
        # des dates une à une sur la source.
        rclone copy "$SRC/$p" "crypt:$p" --size-only --fast-list --transfers 8 --checkers 16 \
            --log-file "$LOG" --log-level INFO --stats 0 || status=$?
    done
    [ "$status" -eq 0 ] && log "copie : TERMINÉE OK" || log "copie : ÉCHEC (code $status)"
    exit "$status"
    ;;
verify)
    log "vérification : début"
    status=0
    for p in $PREFIXES; do
        if ! rclone check "$SRC/$p" "crypt:$p" --one-way --size-only --fast-list --log-file "$LOG" --log-level NOTICE; then
            log "vérification : fichiers manquants ou différents dans $p"; status=1
        fi
    done
    for f in $(rclone lsf -R --files-only --fast-list crypt:media | shuf -n 5); do
        a=$(rclone cat "crypt:media/$f" | sha256sum | cut -d' ' -f1)
        b=$(rclone cat "$SRC/media/$f" | sha256sum | cut -d' ' -f1)
        if [ "$a" = "$b" ]; then log "restauration OK : media/$f"; else log "restauration DIFFÉRENTE : media/$f"; status=1; fi
    done
    [ "$status" -eq 0 ] && log "vérification : TERMINÉE OK" || log "vérification : ÉCHEC"
    exit "$status"
    ;;
*)
    echo "usage : backup.sh copy|verify" >&2
    exit 2
    ;;
esac
