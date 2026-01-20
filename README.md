# MemoryLane - Hub Familial Multimédia

MemoryLane est une plateforme de gestion de médias familiaux permettant de stocker, organiser et partager photos, vidéos et documents pour votre famille. L'application intègre la reconnaissance faciale par IA, la géolocalisation et un arbre généalogique.

## 🚀 Technologies

### Backend
- **Laravel 11** - Framework PHP
- **PostgreSQL 16** - Base de données
- **Redis 7** - Cache & Queues
- **Inertia.js** - Bridge Laravel-Vue

### Frontend
- **Vue 3** (Composition API)
- **Vite 5** - Build tool
- **Tailwind CSS 3** - Styling
- **Pinia** - State management

### Services Externes
- **Scaleway S3** - Stockage médias
- **Google Vision API** - Reconnaissance faciale
- **Meilisearch** - Moteur de recherche

### Infrastructure
- **Docker** & Docker Compose
- **Nginx** - Serveur web
- **Laravel Horizon** - Monitoring queues

## 📋 Prérequis

- Docker Desktop avec WSL2 (Windows) ou Docker (Linux/Mac)
- Node.js 20+ (pour développement local)
- Composer 2+ (pour développement local)
- Git

## 🛠️ Installation

### 1. Cloner le projet

```bash
git clone <repository-url> memorylane
cd memorylane
```

### 2. Configuration de l'environnement

```bash
# Copier le fichier d'environnement
cp .env.example .env

# Éditer .env et configurer :
# - Les identifiants base de données
# - Les clés Scaleway S3
# - Les clés Google Cloud Vision API
# - La clé Meilisearch
```

### 3. Configuration Docker Desktop (Windows WSL2)

1. Installer [Docker Desktop](https://www.docker.com/products/docker-desktop)
2. Activer l'intégration WSL2 dans Docker Desktop :
   - Settings → Resources → WSL Integration
   - Activer votre distribution WSL2

### 4. Démarrer l'environnement

```bash
# Construire et démarrer les conteneurs
docker-compose up -d --build

# Installer les dépendances PHP
docker-compose exec app composer install

# Installer les dépendances JavaScript
docker-compose exec app npm install

# Générer la clé d'application
docker-compose exec app php artisan key:generate

# Exécuter les migrations
docker-compose exec app php artisan migrate

# Builder les assets
docker-compose exec app npm run build
```

### 5. Accéder à l'application

- **Application** : http://localhost:8000
- **Meilisearch** : http://localhost:7700
- **Horizon** (queues) : http://localhost:8000/horizon

## 🔧 Développement

### Commandes utiles

```bash
# Démarrer le serveur de développement (hot reload)
docker-compose exec app npm run dev

# Accéder au conteneur app
docker-compose exec app bash

# Voir les logs
docker-compose logs -f app
docker-compose logs -f nginx
docker-compose logs -f postgres

# Arrêter les conteneurs
docker-compose down

# Arrêter et supprimer les volumes
docker-compose down -v

# Reconstruire les images
docker-compose build --no-cache
```

### Structure du projet

```
memorylane/
├── docker/                      # Configurations Docker
│   ├── app/                     # PHP-FPM
│   ├── nginx/                   # Nginx
│   └── postgres/                # PostgreSQL
├── backend/                     # Application Laravel
│   ├── app/
│   │   ├── Http/Controllers/    # Contrôleurs
│   │   ├── Models/              # Modèles Eloquent
│   │   ├── Services/            # Logique métier
│   │   └── Jobs/                # Jobs asynchrones
│   ├── database/migrations/     # Migrations DB
│   ├── resources/
│   │   ├── js/                  # Code Vue.js
│   │   │   ├── Components/      # Composants réutilisables
│   │   │   ├── Pages/           # Pages Inertia
│   │   │   ├── Layouts/         # Layouts
│   │   │   └── Stores/          # Stores Pinia
│   │   └── views/               # Templates Blade
│   └── routes/                  # Fichiers de routes
├── docker-compose.yml
├── .env.example
└── README.md
```

## 📊 Base de données

### Migrations créées

- **users** : Utilisateurs (avec code PIN)
- **media** : Photos/vidéos/documents
- **media_metadata** : Métadonnées EXIF
- **media_conversions** : Thumbnails & versions optimisées
- **tags** : Système de tags
- **albums** : Albums de médias
- **locations** : Lieux (hiérarchiques)

### Exécuter les migrations

```bash
docker-compose exec app php artisan migrate

# Rollback
docker-compose exec app php artisan migrate:rollback

# Refresh (drop all + migrate)
docker-compose exec app php artisan migrate:fresh
```

## 🔐 Configuration Services Externes

### Scaleway S3

1. Créer un compte [Scaleway](https://www.scaleway.com/)
2. Créer un bucket S3 dans la région `fr-par`
3. Générer des clés d'accès API
4. Configurer dans `.env` :

```env
SCALEWAY_ACCESS_KEY=your-access-key
SCALEWAY_SECRET_KEY=your-secret-key
SCALEWAY_REGION=fr-par
SCALEWAY_BUCKET=memorylane
SCALEWAY_ENDPOINT=https://s3.fr-par.scw.cloud
FILESYSTEM_DISK=scaleway
```

### Google Vision API

1. Créer un projet [Google Cloud](https://console.cloud.google.com/)
2. Activer l'API Cloud Vision
3. Créer une clé de compte de service (JSON)
4. Télécharger le fichier JSON et le placer dans `backend/storage/`
5. Configurer dans `.env` :

```env
GOOGLE_CLOUD_PROJECT=your-project-id
GOOGLE_APPLICATION_CREDENTIALS=/var/www/html/storage/google-credentials.json
```

### Meilisearch

Meilisearch est déjà configuré dans Docker. La clé par défaut est dans `.env` :

```env
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=masterKey
```

## 🧪 Tests

```bash
# Exécuter les tests
docker-compose exec app php artisan test

# Avec couverture
docker-compose exec app php artisan test --coverage
```

## 📦 Packages principaux

### Laravel
- `inertiajs/inertia-laravel` - Bridge Inertia.js
- `spatie/laravel-medialibrary` - Gestion médias
- `spatie/laravel-permission` - Permissions
- `intervention/image` - Manipulation images
- `pbmedia/laravel-ffmpeg` - Traitement vidéo
- `google/cloud-vision` - Reconnaissance faciale
- `laravel/horizon` - Queues monitoring

### Vue.js
- `@inertiajs/vue3` - Inertia Vue 3
- `photoswipe` - Galerie lightbox
- `@uppy/core` - Upload fichiers
- `leaflet` - Cartes
- `pinia` - State management

## 🗺️ Roadmap

### Phase 1 : Fondations ✅ (En cours)
- [x] Environnement Docker
- [x] Laravel 11 + Vue 3 + Inertia.js
- [x] Migrations base de données
- [x] Configuration S3 Scaleway
- [ ] Upload basique médias
- [ ] Galerie photos

### Phase 2 : Fonctionnalités Core
- [ ] Extraction EXIF automatique
- [ ] Génération thumbnails
- [ ] Système de tags
- [ ] Albums
- [ ] Géolocalisation

### Phase 3 : IA & Reconnaissance Faciale
- [ ] Intégration Google Vision API
- [ ] Détection visages
- [ ] Clustering automatique
- [ ] Assignment manuel personnes

### Phase 4 : Arbre Généalogique
- [ ] Import GEDCOM (Généanet)
- [ ] Visualisation arbre
- [ ] Liaison personnes-photos

## 🤝 Contribution

Ce projet est personnel/familial. Les contributions externes ne sont pas acceptées pour le moment.

## 📝 License

Propriétaire - Usage familial uniquement

## 🆘 Support

Pour toute question ou problème :
- Vérifier les logs : `docker-compose logs -f`
- Redémarrer les conteneurs : `docker-compose restart`
- Reconstruire : `docker-compose up -d --build`

---

**Version actuelle** : 1.0.0-alpha
**Dernière mise à jour** : Janvier 2025
