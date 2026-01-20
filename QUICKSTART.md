# Démarrage Rapide - MemoryLane

## Étape 1 : Configuration Docker Desktop (WSL2)

### Installer Docker Desktop

1. Télécharger [Docker Desktop pour Windows](https://www.docker.com/products/docker-desktop)
2. Installer et redémarrer votre ordinateur
3. Ouvrir Docker Desktop
4. Aller dans **Settings** → **Resources** → **WSL Integration**
5. Activer l'intégration avec votre distribution WSL2 (Ubuntu, par exemple)
6. Cliquer sur **Apply & Restart**

### Vérifier l'installation

Dans votre terminal WSL2 :

```bash
docker --version
docker-compose --version
```

## Étape 2 : Configuration du projet

### Copier le fichier d'environnement

```bash
cp .env.example .env
```

### Éditer .env (minimum requis pour démarrer)

```env
APP_NAME=MemoryLane
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=memorylane
DB_USERNAME=memorylane
DB_PASSWORD=secret

# Pour l'instant, laisser les autres valeurs par défaut
```

## Étape 3 : Démarrer l'application

### Build et démarrage des conteneurs

```bash
# Construction des images (première fois seulement, peut prendre 5-10 minutes)
docker-compose build

# Démarrage des conteneurs
docker-compose up -d

# Vérifier que tout fonctionne
docker-compose ps
```

Vous devriez voir tous les services en état `Up` :
- memorylane_app
- memorylane_nginx
- memorylane_postgres
- memorylane_redis
- memorylane_meilisearch
- memorylane_horizon
- memorylane_scheduler

### Installer les dépendances

```bash
# Installer les dépendances PHP
docker-compose exec app composer install

# Installer les dépendances JavaScript
docker-compose exec app npm install

# Générer la clé d'application Laravel
docker-compose exec app php artisan key:generate

# Exécuter les migrations
docker-compose exec app php artisan migrate

# Créer le premier utilisateur (optionnel)
docker-compose exec app php artisan tinker
# Dans tinker, taper :
# App\Models\User::create(['name' => 'Admin', 'email' => 'admin@memorylane.local', 'password' => bcrypt('password')])
# exit
```

### Builder les assets frontend

```bash
# Build pour production
docker-compose exec app npm run build

# OU pour développement avec hot reload
docker-compose exec app npm run dev
```

## Étape 4 : Accéder à l'application

Ouvrir votre navigateur et aller sur :

**http://localhost:8000**

Vous devriez voir la page d'accueil de MemoryLane !

## Commandes utiles

### Voir les logs

```bash
# Tous les logs
docker-compose logs -f

# Logs d'un service spécifique
docker-compose logs -f app
docker-compose logs -f nginx
```

### Accéder au conteneur

```bash
docker-compose exec app bash
```

### Arrêter/Redémarrer

```bash
# Arrêter
docker-compose down

# Redémarrer
docker-compose restart

# Tout reconstruire
docker-compose down -v
docker-compose up -d --build
```

## Problèmes courants

### Port 8000 déjà utilisé

Modifier dans `.env` :
```env
APP_PORT=8080
```

Puis redémarrer : `docker-compose down && docker-compose up -d`

### Les images Docker ne se construisent pas

```bash
# Nettoyer et reconstruire
docker system prune -a
docker-compose build --no-cache
```

### Permission denied dans WSL2

```bash
# Vérifier les permissions
ls -la

# Si nécessaire, fixer les permissions
sudo chown -R $USER:$USER .
```

### Base de données ne démarre pas

```bash
# Vérifier les logs
docker-compose logs postgres

# Supprimer le volume et recréer
docker-compose down -v
docker-compose up -d
```

## Prochaines étapes

Une fois l'application lancée, consulter [README.md](README.md) pour :
- Configuration Scaleway S3
- Configuration Google Vision API
- Développement des fonctionnalités

## Besoin d'aide ?

1. Vérifier les logs : `docker-compose logs -f`
2. Vérifier que Docker Desktop est lancé
3. Vérifier que tous les conteneurs tournent : `docker-compose ps`
4. Redémarrer les conteneurs : `docker-compose restart`

Bon développement ! 🚀
