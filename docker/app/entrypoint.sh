#!/bin/sh
set -e

# Republie les assets front des paquets PHP (Livewire, Filament) au démarrage,
# pour qu'ils correspondent TOUJOURS aux versions installées dans vendor/.
# Sans ça, après un upgrade (ex. Livewire), l'admin casse en silence :
#   « Uncaught TypeError: Livewire.interceptMessage is not a function »
# et toutes les actions Filament deviennent inertes.
#
# Gardé par PUBLISH_ASSETS=1 (défini uniquement sur le service `app` dans
# docker-compose) : horizon/scheduler partagent l'image mais ne doivent pas
# republier en même temps (écriture concurrente sur public/vendor monté).
if [ "${PUBLISH_ASSETS:-0}" = "1" ]; then
  echo "[entrypoint] Republication des assets front (livewire + filament)…"
  php artisan livewire:publish --assets || true
  php artisan filament:assets || true
fi

exec "$@"
