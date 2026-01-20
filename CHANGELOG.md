# Changelog - MemoryLane

## [1.0.0-alpha] - 2025-01-17

### Module 1 : Fondations - COMPLÉTÉ ✅

#### Infrastructure Docker
- ✅ Configuration `docker-compose.yml` avec 7 services :
  - PHP 8.3-FPM (app)
  - Nginx (serveur web)
  - PostgreSQL 16 (base de données)
  - Redis 7 (cache + queues)
  - Meilisearch (moteur de recherche)
  - Laravel Horizon (monitoring queues)
  - Scheduler (tâches planifiées)
- ✅ Dockerfiles optimisés pour développement
- ✅ Configuration PHP personnalisée (php.ini)
- ✅ Configuration Nginx optimisée pour upload 2GB

#### Backend Laravel
- ✅ Laravel 11 initialisé dans `/backend`
- ✅ Packages installés :
  - inertiajs/inertia-laravel
  - spatie/laravel-medialibrary
  - spatie/laravel-permission
  - intervention/image
  - pbmedia/laravel-ffmpeg
  - google/cloud-vision
  - laravel/horizon
  - meilisearch/meilisearch-php
- ✅ Configuration S3 Scaleway dans `filesystems.php`
- ✅ Configuration Google Vision API dans `services.php`
- ✅ Middleware Inertia.js configuré
- ✅ Route dashboard + health check

#### Frontend Vue.js
- ✅ Vue 3 avec Composition API
- ✅ Inertia.js configuré
- ✅ Vite 5 avec plugin Vue
- ✅ Tailwind CSS 3 avec couleurs personnalisées
- ✅ Pinia pour state management
- ✅ Packages installés :
  - @inertiajs/vue3
  - photoswipe
  - @uppy/core & @uppy/aws-s3
  - vue-virtual-scroller
  - leaflet
  - blurhash

#### Structure de fichiers créée
- ✅ `resources/js/Pages/Dashboard.vue` - Page d'accueil
- ✅ `resources/js/Layouts/AppLayout.vue` - Layout principal
- ✅ `resources/js/Components/NavLink.vue` - Composant navigation
- ✅ `resources/views/app.blade.php` - Template Blade Inertia

#### Migrations Base de Données
- ✅ **users** : Utilisateurs avec UUID, PIN code, avatar, person_id
- ✅ **media** : Photos/vidéos/documents avec UUID, soft deletes
- ✅ **media_metadata** : EXIF détaillé (géolocalisation, caméra)
- ✅ **media_conversions** : Thumbnails et versions optimisées
- ✅ **tags** : Système de tags avec pivot polymorphique
- ✅ **albums** : Albums avec pivot album_media
- ✅ **locations** : Lieux hiérarchiques
- ✅ **media_locations** : Association médias-lieux

#### Modèles Eloquent
- ✅ **User** : Modèle avec UUID, casts JSON pour preferences
- ✅ **Media** : Modèle avec UUID, relations (user, metadata, conversions, tags, albums)

#### Documentation
- ✅ README.md complet avec instructions
- ✅ QUICKSTART.md pour démarrage rapide
- ✅ CHANGELOG.md (ce fichier)
- ✅ .env.example avec toutes les variables

### À venir - Module 2 : Upload Basique Médias

#### Fonctionnalités prévues
- [ ] Contrôleur MediaController
- [ ] Service MediaService
- [ ] Upload direct vers S3 (pre-signed URLs)
- [ ] Validation fichiers (MIME type, taille)
- [ ] Job ProcessUploadedMedia
- [ ] Extraction EXIF basique
- [ ] Génération thumbnail
- [ ] Interface upload Vue.js

#### Services à créer
- [ ] `app/Services/MediaService.php`
- [ ] `app/Services/S3Service.php`
- [ ] `app/Services/ExifExtractor.php`
- [ ] `app/Jobs/ProcessUploadedMedia.php`
- [ ] `app/Jobs/GenerateMediaConversions.php`

#### Composants Vue à créer
- [ ] `Components/Media/MediaUploader.vue`
- [ ] `Components/Media/MediaGrid.vue`
- [ ] `Components/Media/MediaCard.vue`
- [ ] `Pages/Media/Upload.vue`
- [ ] `Pages/Media/Index.vue`

### Configuration requise avant utilisation

1. **Docker Desktop** : Installer et configurer WSL2 integration
2. **Scaleway S3** :
   - Créer un bucket
   - Générer clés API
   - Remplir SCALEWAY_* dans .env
3. **Google Vision API** :
   - Créer projet Google Cloud
   - Activer API Vision
   - Télécharger credentials JSON
   - Configurer GOOGLE_* dans .env

### Commandes de démarrage

```bash
# Build initial
docker-compose up -d --build

# Installation dépendances
docker-compose exec app composer install
docker-compose exec app npm install

# Configuration Laravel
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate

# Build assets
docker-compose exec app npm run build
```

### URLs de l'application

- Application : http://localhost:8000
- Meilisearch : http://localhost:7700
- Horizon : http://localhost:8000/horizon

---

**Prochaine étape** : Module 2 - Upload et traitement de médias
