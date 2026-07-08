#!/usr/bin/env bash
#
# Sauvegarde backend/.env (secrets prod) dans Bitwarden. Cf. docs/BACKUP_SECRETS.md.
# Ne PAS committer le .env ni les tokens (dépôt public).
#
# Deux modes, selon la CLI disponible :
#   A) Secrets Manager — CLI `bws` (binaire) + BWS_ACCESS_TOKEN [+ BWS_PROJECT_ID]
#   B) Coffre — CLI `bw` (npm i -g @bitwarden/cli) + session déverrouillée
#      (bw login && export BW_SESSION="$(bw unlock --raw)") → note sécurisée
# Nécessite `jq` dans les deux cas.

set -euo pipefail

ENV_FILE="$(cd "$(dirname "$0")/.." && pwd)/.env"
NAME="memorylane-prod-env"          # clé de secret (bws) / nom de note (bw)
NOTE_NAME="MemoryLane prod .env"    # nom de note lisible (bw)

[ -f "$ENV_FILE" ] || { echo "Introuvable : $ENV_FILE"; exit 1; }
command -v jq >/dev/null 2>&1 || { echo "jq requis."; exit 1; }
content="$(cat "$ENV_FILE")"

# --- Mode A : Bitwarden Secrets Manager (bws) ---
if command -v bws >/dev/null 2>&1; then
  : "${BWS_ACCESS_TOKEN:?Exportez BWS_ACCESS_TOKEN (compte machine Secrets Manager)}"
  id="$(bws secret list 2>/dev/null | jq -r --arg k "$NAME" '.[] | select(.key==$k) | .id' | head -1)"
  if [ -n "$id" ]; then
    bws secret edit "$id" --value "$content" >/dev/null
    echo "Secrets Manager : « $NAME » mis à jour."
  else
    : "${BWS_PROJECT_ID:?Exportez BWS_PROJECT_ID (id du projet) pour créer le secret}"
    bws secret create "$NAME" "$content" "$BWS_PROJECT_ID" >/dev/null
    echo "Secrets Manager : « $NAME » créé."
  fi
  exit 0
fi

# --- Mode B : coffre Bitwarden (bw, npm) → note sécurisée ---
if command -v bw >/dev/null 2>&1; then
  : "${BW_SESSION:?Exportez BW_SESSION (bw unlock --raw)}"
  id="$(bw list items --search "$NOTE_NAME" 2>/dev/null | jq -r --arg n "$NOTE_NAME" '.[] | select(.name==$n) | .id' | head -1)"
  if [ -n "$id" ]; then
    bw get item "$id" | jq --arg n "$content" '.notes=$n' | bw encode | bw edit item "$id" >/dev/null
    echo "Coffre : note « $NOTE_NAME » mise à jour."
  else
    jq -n --arg name "$NOTE_NAME" --arg notes "$content" \
      '{type:2,name:$name,secureNote:{type:0},notes:$notes}' | bw encode | bw create item >/dev/null
    echo "Coffre : note « $NOTE_NAME » créée."
  fi
  exit 0
fi

echo "Aucune CLI Bitwarden trouvée."
echo "  • Secrets Manager : installer le binaire bws (cf. docs/BACKUP_SECRETS.md)"
echo "  • Coffre : npm i -g @bitwarden/cli"
echo "  • Sinon, sauvegarde MANUELLE : copier $ENV_FILE dans une note sécurisée « $NOTE_NAME »."
exit 1
