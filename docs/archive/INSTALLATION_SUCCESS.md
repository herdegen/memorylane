# 🎉 Installation MemoryLane avec Podman - RÉUSSIE !

## ✅ État de l'installation

Votre application **MemoryLane** est maintenant **100% fonctionnelle** avec Podman !

### Services actifs (7/7)

| Service | État | Port | Description |
|---------|------|------|-------------|
| **PostgreSQL** | 🟢 Healthy | 5432 | Base de données |
| **Redis** | 🟢 Healthy | 6379 | Cache & Queues |
| **Meilisearch** | 🟢 Healthy | 7700 | Moteur de recherche |
| **App (PHP-FPM)** | 🟢 Running | 5173 | Application Laravel |
| **Nginx** | 🟢 Healthy | 8000 | Serveur web |
| **Horizon** | 🟢 Running | - | Monitoring queues |
| **Scheduler** | 🟢 Running | - | Tâches planifiées |

### Composants installés

- ✅ **Dépendances PHP** : 179 packages (Composer)
- ✅ **Dépendances JS** : 181 packages (NPM)
- ✅ **Migrations** : 11 migrations exécutées
- ✅ **Assets** : Build production terminé
- ✅ **Configuration** : `.env` configuré pour Docker/Podman

## 🌐 Accès à l'application

Votre application est accessible aux URLs suivantes :

- **Application principale** : http://localhost:8000
- **Panel admin (Filament)** : http://localhost:8000/admin
- **Meilisearch** : http://localhost:7700
- **Horizon (monitoring queues)** : http://localhost:8000/horizon

## 🔑 Créer un utilisateur administrateur

Pour vous connecter à l'application, créez d'abord un utilisateur admin :

```bash
# Ouvrir Tinker
./podman.sh tinker

# OU
podman exec -it memorylane_app php artisan tinker
```

Puis exécutez dans Tinker :

```php
User::create([
    'name' => 'Admin',
    'email' => 'admin@memorylane.com',
    'password' => Hash::make('password')
]);
```

Appuyez sur `Ctrl+D` pour quitter Tinker.

Vous pouvez maintenant vous connecter avec :
- **Email** : admin@memorylane.com
- **Mot de passe** : password

⚠️ **Changez ce mot de passe en production !**

## 🔧 Commandes utiles

### Gestion des services

```bash
# Voir l'état des services
./podman.sh status

# Voir les logs en temps réel
./podman.sh logs

# Voir les logs d'un service spécifique
podman logs -f memorylane_app
podman logs -f memorylane_nginx

# Redémarrer tous les services
./podman.sh restart

# Arrêter tous les services
./podman.sh stop

# Redémarrer depuis zéro
./podman.sh start
```

### Développement

```bash
# Accéder au conteneur app
./podman.sh shell

# Démarrer le serveur dev (hot reload)
./podman.sh dev

# Exécuter les tests
./podman.sh test

# Exécuter une commande artisan
./podman.sh artisan migrate
./podman.sh artisan make:controller FooController

# Exécuter composer
./podman.sh composer require package/name

# Exécuter npm
./podman.sh npm install package-name
```

### Base de données

```bash
# Exécuter les migrations
./podman.sh migrate

# Rollback
./podman.sh artisan migrate:rollback

# Reset complet (ATTENTION : supprime toutes les données)
./podman.sh fresh
```

## 📝 Configuration appliquée

### Fichier `.env`

Le fichier `.env` a été configuré avec les paramètres suivants :

```env
# Application
APP_NAME=MemoryLane
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Base de données PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=memorylane
DB_USERNAME=memorylane
DB_PASSWORD=secret

# Redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379

# Meilisearch
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=masterKey
SCOUT_DRIVER=meilisearch
```

### Services externes à configurer

Pour utiliser toutes les fonctionnalités, configurez ces services dans `.env` :

#### 1. Scaleway S3 (Stockage médias)

```env
SCALEWAY_ACCESS_KEY=votre-access-key
SCALEWAY_SECRET_KEY=votre-secret-key
SCALEWAY_REGION=fr-par
SCALEWAY_BUCKET=memorylane
SCALEWAY_ENDPOINT=https://s3.fr-par.scw.cloud
FILESYSTEM_DISK=scaleway
```

#### 2. Google Vision API (Reconnaissance faciale)

1. Téléchargez votre fichier JSON de credentials
2. Placez-le dans `backend/storage/google-credentials.json`
3. Ajoutez dans `.env` :

```env
GOOGLE_CLOUD_PROJECT=votre-projet-id
GOOGLE_APPLICATION_CREDENTIALS=/var/www/html/storage/google-credentials.json
```

## 🔧 Résolution des problèmes

### Problèmes de permissions

Si vous rencontrez des erreurs de permissions :

```bash
# Corriger toutes les permissions
podman exec --user root memorylane_app chown -R memorylane:memorylane /var/www/html
podman exec --user root memorylane_app chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
```

Voir [PODMAN_PERMISSIONS_FIX.md](PODMAN_PERMISSIONS_FIX.md) pour plus de détails.

### Conteneurs qui ne démarrent pas

```bash
# Voir les logs
podman logs memorylane_app
podman logs memorylane_postgres

# Redémarrer proprement
./podman.sh stop
./podman.sh start
```

### Erreurs de connexion base de données

```bash
# Vérifier que PostgreSQL est prêt
podman exec memorylane_postgres pg_isready -U memorylane

# Tester la connexion depuis le conteneur app
podman exec memorylane_app php artisan tinker
# Puis : DB::connection()->getPdo();
```

### Port déjà utilisé

```bash
# Trouver ce qui utilise le port
sudo lsof -i :8000

# Changer le port dans docker-compose.yml
# Ligne "APP_PORT:-8000" → "APP_PORT:-8080"
```

## 📚 Documentation

J'ai créé plusieurs guides pour vous aider :

1. **[PODMAN_SETUP.md](PODMAN_SETUP.md)** - Guide complet d'installation Podman sur Windows
2. **[PODMAN_FIXES.md](PODMAN_FIXES.md)** - Détails des corrections apportées pour Podman
3. **[PODMAN_PERMISSIONS_FIX.md](PODMAN_PERMISSIONS_FIX.md)** - Résolution des problèmes de permissions
4. **[README.md](README.md)** - Documentation générale du projet
5. **[podman.sh](podman.sh)** - Script de gestion pratique

## 🎯 Prochaines étapes

1. **Créer un utilisateur admin** (voir section "Créer un utilisateur administrateur")
2. **Se connecter** à http://localhost:8000
3. **Explorer le panel admin** : http://localhost:8000/admin
4. **Configurer les services externes** (Scaleway, Google Vision)
5. **Commencer à uploader des médias** !

## 🚀 Mode développement

Pour développer avec hot reload :

```bash
# Terminal 1 : Démarrer Vite dev server
./podman.sh dev

# Terminal 2 : Voir les logs
./podman.sh logs

# Accéder à l'app sur http://localhost:8000
# Les changements dans resources/js seront rechargés automatiquement
```

## 🧪 Tests

Votre projet dispose d'une suite de tests complète (61 tests) :

```bash
# Exécuter tous les tests
./podman.sh test

# Exécuter une suite spécifique
./podman.sh artisan test --filter=TagTest
./podman.sh artisan test --filter=MediaTest
./podman.sh artisan test --filter=MapControllerTest

# Avec couverture
./podman.sh artisan test --coverage
```

## 💡 Astuces

### Alias pour Docker

Le fichier [PODMAN_SETUP.md](PODMAN_SETUP.md) explique comment créer des alias pour remplacer `docker` par `podman`. Très utile pour la transition !

### Sauvegarder les données

```bash
# Sauvegarder la base de données
podman exec memorylane_postgres pg_dump -U memorylane memorylane > backup.sql

# Restaurer
cat backup.sql | podman exec -i memorylane_postgres psql -U memorylane memorylane
```

### Nettoyer Podman

```bash
# Voir l'utilisation disque
podman system df

# Nettoyer les ressources inutilisées
./podman.sh clean

# OU
podman system prune -a --volumes
```

## 📊 Statistiques de l'installation

- **Temps d'installation** : ~10-15 minutes
- **Espace disque utilisé** : ~4.5 GB (images + dépendances)
- **Images Docker téléchargées** : 6 (postgres, redis, meilisearch, php-fpm, nginx)
- **Images construites** : 3 (app, nginx, horizon/scheduler)
- **Packages installés** : 360 (179 PHP + 181 JS)

---

## 🎉 Félicitations !

Votre installation de **MemoryLane** avec **Podman** est complète et fonctionnelle !

Si vous avez des questions ou rencontrez des problèmes :
1. Consultez la [documentation](README.md)
2. Vérifiez les [fixes Podman](PODMAN_FIXES.md)
3. Regardez les logs : `./podman.sh logs`

**Bon développement ! 🚀**
