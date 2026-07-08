#!/usr/bin/env bash
#
# Sauvegarde backend/.env (secrets prod) dans Bitwarden Secrets Manager.
# Cf. docs/BACKUP_SECRETS.md. Ne PAS committer le .env ni le token (dépôt public).
#
# Prérequis :
#   - CLI `bws` installée (Bitwarden Secrets Manager) et `jq`
#   - export BWS_ACCESS_TOKEN="<access-token du compte machine>"
#   - export BWS_PROJECT_ID="<id du projet>"   (requis à la création du secret)
#
# Le .env complet est stocké comme UN secret nommé "memorylane-prod-env".
# Restauration : cf. docs/BACKUP_SECRETS.md (bws secret get <id> | jq -r .value).

set -euo pipefail

ENV_FILE="$(cd "$(dirname "$0")/.." && pwd)/.env"
SECRET_KEY="memorylane-prod-env"

[ -f "$ENV_FILE" ] || { echo "Introuvable : $ENV_FILE"; exit 1; }

# Fallback si la CLI Secrets Manager n'est pas là.
if ! command -v bws >/dev/null 2>&1; then
  echo "CLI Bitwarden Secrets Manager (bws) absente."
  echo "→ Fallback MANUEL : copiez le contenu de $ENV_FILE dans une note"
  echo "  sécurisée Bitwarden « MemoryLane prod .env » (cf. docs/BACKUP_SECRETS.md)."
  exit 1
fi
command -v jq >/dev/null 2>&1 || { echo "jq requis."; exit 1; }
: "${BWS_ACCESS_TOKEN:?Exportez BWS_ACCESS_TOKEN (compte machine Secrets Manager)}"

content="$(cat "$ENV_FILE")"
existing_id="$(bws secret list 2>/dev/null | jq -r --arg k "$SECRET_KEY" '.[] | select(.key==$k) | .id' | head -1)"

if [ -n "$existing_id" ]; then
  bws secret edit "$existing_id" --value "$content" >/dev/null
  echo "Secret « $SECRET_KEY » mis à jour dans Bitwarden Secrets Manager."
else
  : "${BWS_PROJECT_ID:?Exportez BWS_PROJECT_ID (id du projet) pour créer le secret}"
  bws secret create "$SECRET_KEY" "$content" "$BWS_PROJECT_ID" >/dev/null
  echo "Secret « $SECRET_KEY » créé dans Bitwarden Secrets Manager."
fi
