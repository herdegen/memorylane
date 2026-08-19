#!/bin/bash

# Arrêter le script dès qu'une commande échoue
set -e

echo "🔍 Démarrage de la vérification du projet..."

# 1. Vérification du Build Frontend
# C'est ici que l'erreur 'sass-embedded not found' aurait été détectée immédiatement
echo "🎨 1. Vérification du build Frontend (Vite)..."
docker-compose exec -T app npm run build
echo "✅ Build Frontend OK"

# 2. Vérification des Migrations
echo "🗄️ 2. Vérification des migrations..."
docker-compose exec -T app php artisan migrate --pretend
echo "✅ Migrations OK"

# 3. Vérification des Tests Backend
echo "🐘 3. Exécution des tests Backend (PHPUnit)..."
docker-compose exec -T app php artisan test
echo "✅ Tests Backend OK"

echo "-----------------------------------------------------------"
echo "🎉 Tout est vert ! Le code est stable et prêt à être commit."
echo "-----------------------------------------------------------"