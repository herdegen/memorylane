#!/usr/bin/env bash
#
# Sauvegarde backend/.env (secrets prod) dans une note sécurisée Bitwarden.
# Cf. docs/BACKUP_SECRETS.md. Ne PAS committer le .env (dépôt public).
#
# Prérequis :
#   npm i -g @bitwarden/cli
#   bw login && export BW_SESSION="$(bw unlock --raw)"
#   jq installé
#
# Restauration : récupérer la note "MemoryLane prod .env" dans Bitwarden et
# réécrire son contenu dans backend/.env, puis `php artisan optimize`.

set -euo pipefail

ENV_FILE="$(cd "$(dirname "$0")/.." && pwd)/.env"
NOTE_NAME="MemoryLane prod .env"

if ! command -v bw >/dev/null 2>&1; then
  echo "Bitwarden CLI (bw) absente."
  echo "→ Sauvegarde MANUELLE : copiez le contenu de $ENV_FILE dans une note"
  echo "  sécurisée Bitwarden nommée « $NOTE_NAME » (cf. docs/BACKUP_SECRETS.md)."
  exit 1
fi
command -v jq >/dev/null 2>&1 || { echo "jq requis."; exit 1; }
[ -f "$ENV_FILE" ] || { echo "Introuvable : $ENV_FILE"; exit 1; }
: "${BW_SESSION:?Exportez BW_SESSION (bw unlock --raw)}"

content="$(cat "$ENV_FILE")"
existing_id="$(bw list items --search "$NOTE_NAME" 2>/dev/null \
  | jq -r --arg n "$NOTE_NAME" '.[] | select(.name==$n) | .id' | head -1)"

if [ -n "$existing_id" ]; then
  bw get item "$existing_id" | jq --arg n "$content" '.notes=$n' | bw encode | bw edit item "$existing_id" >/dev/null
  echo "Note Bitwarden « $NOTE_NAME » mise à jour."
else
  jq -n --arg name "$NOTE_NAME" --arg notes "$content" \
    '{type:2,name:$name,secureNote:{type:0},notes:$notes}' | bw encode | bw create item >/dev/null
  echo "Note Bitwarden « $NOTE_NAME » créée."
fi
