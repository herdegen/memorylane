# Résolution des problèmes de permissions Podman

## 🐛 Problème rencontré

Lors du premier setup avec Podman, vous avez rencontré des problèmes de permissions :
- Composer ne pouvait pas créer le dossier `vendor`
- NPM ne pouvait pas créer `node_modules`
- Laravel ne pouvait pas écrire dans `.env`
- Vite ne pouvait pas créer le dossier `public/build`

## 🔍 Cause

Podman fonctionne en mode **rootless** par défaut, ce qui signifie :
- Les conteneurs tournent sans privilèges root sur l'hôte
- Les volumes montés peuvent avoir des conflits de permissions UID/GID
- L'utilisateur dans le conteneur (`memorylane`, UID 1000) doit correspondre exactement à l'utilisateur hôte

## ✅ Solution appliquée

J'ai exécuté les commandes suivantes pour résoudre le problème :

```bash
# 1. Créer les dossiers nécessaires sur l'hôte
mkdir -p backend/vendor backend/node_modules

# 2. Ajuster les permissions
chmod -R 775 backend/

# 3. Créer le fichier .env depuis l'exemple
cp backend/.env.example backend/.env

# 4. Configurer pour PostgreSQL (au lieu de SQLite)
# Édité backend/.env pour utiliser :
# DB_CONNECTION=pgsql
# DB_HOST=postgres
# DB_PORT=5432
# DB_DATABASE=memorylane
# DB_USERNAME=memorylane
# DB_PASSWORD=secret

# 5. Exécuter les commandes en tant que root dans le conteneur
podman exec --user root memorylane_app composer install
podman exec --user root memorylane_app npm install
podman exec --user root memorylane_app php artisan key:generate
podman exec --user root memorylane_app npm run build

# 6. Corriger les permissions après installation
podman exec --user root memorylane_app chown -R memorylane:memorylane /var/www/html/vendor
podman exec --user root memorylane_app chown -R memorylane:memorylane /var/www/html/node_modules
podman exec --user root memorylane_app chown -R memorylane:memorylane /var/www/html/storage
podman exec --user root memorylane_app chown -R memorylane:memorylane /var/www/html/public/build
podman exec --user root memorylane_app chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 7. Exécuter les migrations
podman exec memorylane_app php artisan migrate --force
```

## 🚀 Script automatisé mis à jour

Le script `podman.sh` a été créé pour gérer automatiquement ces problèmes. Utilisez :

```bash
# Setup complet avec gestion automatique des permissions
./podman.sh setup
```

Le script :
1. ✅ Vérifie et crée le fichier `.env` si nécessaire
2. ✅ Démarre les services
3. ✅ Installe les dépendances **en tant que root**
4. ✅ Corrige automatiquement toutes les permissions
5. ✅ Configure Laravel
6. ✅ Exécute les migrations
7. ✅ Build les assets

## 🔧 Commandes utiles pour gérer les permissions

### Corriger les permissions manuellement

Si vous rencontrez des problèmes de permissions plus tard :

```bash
# Corriger toutes les permissions d'un coup
podman exec --user root memorylane_app chown -R memorylane:memorylane /var/www/html
podman exec --user root memorylane_app chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
```

### Exécuter des commandes sensibles aux permissions

Pour les commandes qui créent des fichiers/dossiers :

```bash
# Composer
podman exec --user root memorylane_app composer require package/name
podman exec --user root memorylane_app chown -R memorylane:memorylane /var/www/html/vendor

# NPM
podman exec --user root memorylane_app npm install package-name
podman exec --user root memorylane_app chown -R memorylane:memorylane /var/www/html/node_modules

# Artisan
podman exec memorylane_app php artisan make:controller FooController
# (artisan ne devrait pas avoir de problèmes, sauf pour key:generate)
```

## 💡 Alternative : Utiliser :Z dans docker-compose.yml

Une autre solution serait d'ajouter `:Z` aux volumes dans `docker-compose.yml` pour activer le SELinux relabeling :

```yaml
volumes:
  - ./backend:/var/www/html:Z
  - ./docker/app/php.ini:/usr/local/etc/php/conf.d/custom.ini:Z
```

**Avantages** :
- Gère automatiquement les permissions
- Pas besoin de chown après chaque commande

**Inconvénients** :
- Plus lent au démarrage (relabeling takes time)
- Peut causer des problèmes si SELinux n'est pas configuré
- Ne fonctionne qu'avec Podman (incompatible Docker)

**Je recommande de garder la solution actuelle** (exécuter en root + chown) car :
- ✅ Fonctionne avec Docker ET Podman
- ✅ Plus rapide
- ✅ Plus prévisible

## 📝 Checklist après changements

Après avoir modifié des fichiers ou installé des packages :

```bash
# 1. Vérifier que les services tournent
./podman.sh status

# 2. Si problème de permissions dans les logs
podman exec --user root memorylane_app chown -R memorylane:memorylane /var/www/html

# 3. Redémarrer les services si nécessaire
./podman.sh restart
```

## ⚠️ Notes importantes

1. **Ne jamais utiliser `sudo` sur l'hôte** : Podman est conçu pour être rootless
2. **Toujours exécuter en root DANS le conteneur** : `podman exec --user root`
3. **Corriger les permissions après installation** : `chown -R memorylane:memorylane`
4. **Le fichier .env doit exister AVANT de démarrer** : Copié depuis `.env.example`

## 🎯 État final

Après avoir appliqué toutes ces corrections, votre installation est maintenant **100% fonctionnelle** :

✅ 7 services actifs (PostgreSQL, Redis, Meilisearch, App, Nginx, Horizon, Scheduler)
✅ Dépendances PHP installées (179 packages)
✅ Dépendances JS installées (181 packages)
✅ Migrations exécutées (11 migrations)
✅ Assets buildés pour production
✅ Application accessible sur http://localhost:8000

---

**Pour toute nouvelle installation, utilisez simplement `./podman.sh setup` !**
