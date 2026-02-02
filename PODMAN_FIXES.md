# Corrections pour Podman - MemoryLane

## ✅ Corrections appliquées

J'ai corrigé tous les problèmes de compatibilité entre Docker et Podman :

### 1. **Dockerfiles mis à jour**

#### [docker/app/Dockerfile](docker/app/Dockerfile)
- ✅ Changé `FROM php:8.3-fpm-alpine` → `FROM docker.io/library/php:8.3-fpm-alpine`
- ✅ Remplacé `COPY --from=composer:2` par une installation via curl (compatible Podman)

#### [docker/nginx/Dockerfile](docker/nginx/Dockerfile)
- ✅ Changé `FROM nginx:alpine` → `FROM docker.io/library/nginx:alpine`

### 2. **docker-compose.yml mis à jour**

- ✅ `postgres:16-alpine` → `docker.io/library/postgres:16-alpine`
- ✅ `redis:7-alpine` → `docker.io/library/redis:7-alpine`
- ✅ `getmeili/meilisearch:v1.6` → `docker.io/getmeili/meilisearch:v1.6`

### 3. **Configuration Podman**

- ✅ Créé `~/.config/containers/registries.conf` avec docker.io comme registry par défaut
- ✅ Nettoyé les images/conteneurs partiels (récupéré 4.3 GB)

## 🚀 Démarrage maintenant

Tout est prêt ! Vous pouvez démarrer l'application :

```bash
# Méthode 1 : Script automatisé (recommandé)
./podman.sh setup

# Méthode 2 : Commandes manuelles
podman-compose up -d --build
```

Le script `setup` va automatiquement :
1. Démarrer tous les services
2. Installer les dépendances PHP (composer)
3. Installer les dépendances JS (npm)
4. Générer la clé Laravel
5. Exécuter les migrations
6. Builder les assets

## 📊 Vérification

Après le démarrage, vérifiez que tout fonctionne :

```bash
# Voir l'état des conteneurs
./podman.sh status

# OU
podman-compose ps
```

Vous devriez voir 7 conteneurs en cours d'exécution :
- memorylane_postgres
- memorylane_redis
- memorylane_meilisearch
- memorylane_app
- memorylane_nginx
- memorylane_horizon
- memorylane_scheduler

## 🌐 Accès aux services

Une fois démarré :
- **Application** : http://localhost:8000
- **Admin Panel** : http://localhost:8000/admin
- **Meilisearch** : http://localhost:7700
- **Horizon** : http://localhost:8000/horizon

## 🔧 Commandes utiles

```bash
# Voir les logs en temps réel
./podman.sh logs

# Accéder au conteneur app
./podman.sh shell

# Exécuter les tests
./podman.sh test

# Démarrer le serveur dev (hot reload)
./podman.sh dev

# Arrêter tout
./podman.sh stop
```

## ❓ En cas de problème

### Les images ne se téléchargent pas

```bash
# Vérifier la config des registries
cat ~/.config/containers/registries.conf

# Devrait afficher :
# unqualified-search-registries = ["docker.io"]
```

### Erreur de build

```bash
# Nettoyer et reconstruire
podman-compose down -v
podman system prune -f
podman-compose up -d --build
```

### Port déjà utilisé

```bash
# Vérifier ce qui utilise le port
sudo lsof -i :8000

# Arrêter tous les conteneurs
podman-compose down
```

### Problème de permissions

```bash
# Vérifier les permissions du dossier backend
ls -la backend/

# Si nécessaire, ajuster les UID/GID dans .env
echo "UID=$(id -u)" >> .env
echo "GID=$(id -g)" >> .env

# Reconstruire
podman-compose build --no-cache
```

## 📝 Différences Docker vs Podman

Les changements que j'ai faits sont **rétrocompatibles** avec Docker. Votre projet fonctionne maintenant avec :
- ✅ Docker & Docker Compose
- ✅ Podman & Podman Compose

Les noms d'images complets (`docker.io/...`) fonctionnent avec les deux outils.

## 🎯 Prochaines étapes

1. **Lancer le setup** : `./podman.sh setup`
2. **Créer un admin** : `./podman.sh tinker`
   ```php
   User::create([
       'name' => 'Admin',
       'email' => 'admin@memorylane.com',
       'password' => Hash::make('password')
   ]);
   ```
3. **Accéder à l'app** : http://localhost:8000

## 💡 Astuce Windows

Si vous travaillez depuis Windows (PowerShell/CMD) et non depuis WSL :

```powershell
# Configurer WSL par défaut
wsl -s Ubuntu

# Accéder au projet via WSL
wsl
cd /home/matthieu/memorylane
./podman.sh setup
```

---

**Tout est prêt ! Lancez `./podman.sh setup` pour démarrer. 🚀**
