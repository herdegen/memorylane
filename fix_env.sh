#!/bin/bash

# Arrêter le script dès qu'une commande échoue
set -e

echo "🔧 Correction automatique de l'environnement..."

# 1. Correction du warning Docker Compose (attribut version obsolète)
# On supprime la ligne commençant par "version:"
if [ -f docker-compose.yml ]; then
    sed -i '/^version:/d' docker-compose.yml
    echo "✅ docker-compose.yml mis à jour"
fi

# 2. Suppression de la migration conflictuelle (fichier fantôme)
rm -f backend/database/migrations/2025_02_01_100000_add_type_to_tags_table.php
echo "✅ Migration fantôme supprimée"

# 3. Nettoyage du cache et migration
echo "🐘 Nettoyage Laravel..."
docker-compose exec -T app composer dump-autoload
docker-compose exec -T app php artisan migrate

echo "-----------------------------------------------------------"
echo "🎉 Réparations terminées ! Vous pouvez relancer ./verify.sh"
echo "-----------------------------------------------------------"