# Changelog - MemoryLane

Toutes les modifications notables du projet sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased] - 2026-07-08

### Sécurité
- 🔒 **Galerie privée par défaut** : les listings média (galerie, carte, dashboard « ce jour-là », recherche, albums intelligents) sont scopés au propriétaire — ils exposaient auparavant les médias de tous les comptes.
- 🔒 **Fuite média corrigée** : `MediaController::show`/`download` n'avaient aucun contrôle d'accès ; désormais gardés par `MediaPolicy`.

### Ajouté
- ✨ **Partage & permissions d'albums** : album public (tous les comptes connectés) ou restreint (comptes choisis via `album_access`) ; accès automatique des personnes taguées ayant un compte lié ; **partage délégué récursif** (jamais public) ; lien de partage anonyme conservé. `AlbumPolicy`/`MediaPolicy`, scopes `accessibleBy`. Albums (mes albums + partagés) fusionnés dans une seule page ; badge Public/Partagé/Privé.
- ✨ **Arbre & tags publics** : lecture ouverte à tous les comptes connectés (écriture réservée au propriétaire) ; les médias d'une fiche restent filtrés selon l'accès du visiteur.
- ✨ **Reconnaissance de visages** (face-api.js, 100 % navigateur, aucun cloud) : détection, suggestions par plus proche voisin, **détection en tâche de fond pendant la navigation + auto-association** au-delà d'un seuil strict, **ajout manuel d'un visage** (dessin d'une zone), correction/retrait d'une association, avatar de profil auto depuis le visage tagué, avatars sur les cartes de l'arbre.
- ✨ **Import HEIC/HEIF** : conversion automatique en JPEG à l'upload (Imagick + libheif).
- ✨ **Sauvegarde des secrets `.env`** via Bitwarden Secrets Manager (`bws`) — cf. `docs/BACKUP_SECRETS.md`.

### Modifié / Corrigé
- 🐛 **Import Google Photos** : déduplication fiable par empreinte de contenu (sha256) au lieu du seul nom ; état terminal serveur (terminé/échoué) + arrêt du spinner ; **fix OOM** (memory_limit des workers 128M → 512M) ; création d'album à la volée pendant l'import ; affichage progressif.
- 🐛 **Couverture d'album** : repli sur la première photo si aucune couverture définie.
- 🐛 **Noms de famille** : dérivation du nom d'usage des femmes mariées depuis le conjoint (`people:derive-usage-names`), le GEDCOM ne contenant que le nom de naissance ; affichage « Prénom Nom (Nom de naissance) ».

## [1.0.0-alpha] - 2025-01-31

### Added

#### Fonctionnalités
- ✨ **Panel Admin Filament v3.3**
  - Interface d'administration complète
  - Gestion des médias (CRUD, soft delete, restauration)
  - Gestion des tags (création, édition, suppression)
  - Gestion des utilisateurs
  - Dashboard avec widgets
  - Thème Amber personnalisé
  - Interface responsive

- ✨ **Système de Tags Complet**
  - Création, édition, suppression de tags
  - Génération automatique de slugs
  - Types de tags (general, location, person, event)
  - Couleurs personnalisables (hex)
  - Autocomplete dans l'interface
  - Filtrage par tags dans la galerie
  - Support many-to-many avec médias

- ✨ **Géolocalisation Complète**
  - Extraction automatique GPS depuis EXIF
  - Carte interactive Leaflet.js + OpenStreetMap
  - Recherche de lieux (Nominatim API)
  - Filtrage par zone géographique (rayon)
  - Édition manuelle des coordonnées GPS
  - Affichage médias géolocalisés sur carte
  - Calcul de distance (formule de Haversine)
  - Validation latitude/longitude
  - Recherche de médias proches (nearby)

- ✨ **Gestion des Médias**
  - Upload drag-and-drop
  - Support photos (JPEG, PNG, GIF, WebP, HEIC)
  - Support vidéos (MP4, QuickTime, AVI)
  - Support documents (PDF, TXT)
  - Extraction métadonnées EXIF automatique
  - Génération thumbnails (4 tailles)
  - Conversions en arrière-plan (Jobs)
  - Soft delete avec restauration
  - Download fichier original
  - Galerie responsive avec PhotoSwipe
  - Pagination (24 items/page)

#### Tests
- 🧪 **Suite de Tests Complète (61 tests)**
  - TagControllerTest (7 tests) - Gestion des tags
  - TagAttachmentTest (11 tests) - Attachement tags-médias
  - MediaControllerTest (9 tests) - Gestion des médias
  - MapControllerTest (11 tests) - Géolocalisation
  - FilamentAdminTest (17 tests) - Panel admin
  - Couverture ~85% des fonctionnalités principales
  - Tests d'intégration complets
  - Validation des données
  - Tests de relations Eloquent
  - Tests d'autorisation Filament

#### Documentation
- 📚 **Documentation Technique Complète**
  - Guide d'architecture système
  - Documentation base de données (schéma ERD)
  - Documentation API (tous les endpoints)
  - Guide de test complet
  - README mis à jour avec nouvelles fonctionnalités
  - Index de documentation
  - Exemples de code
  - Best practices

#### Infrastructure
- 🐳 **Configuration Docker**
  - 7 services (app, nginx, postgres, redis, meilisearch, etc.)
  - PHP 8.3 + PHP-FPM
  - PostgreSQL 16 avec UUID
  - Redis 7 pour cache/queues
  - Nginx avec configuration optimisée
  - Support Livewire (routes dynamiques)
  - Volumes persistants
  - Network isolation

### Fixed

- 🐛 **Filament Login Error**
  - Correction erreur POST method not allowed sur /admin/login
  - Ajout configuration Livewire dans nginx
  - Publication assets Livewire
  - Implémentation FilamentUser contract sur User model
  - Méthode canAccessPanel() pour autorisation panel

- 🐛 **Nginx Livewire Routes**
  - Ajout location block pour routes Livewire
  - Support /livewire/livewire.js
  - Support /livewire/update (Livewire wire:)

### Changed

- ♻️ **User Model**
  - Implémentation FilamentUser contract
  - Méthode canAccessPanel() pour accès admin
  - Support autorisation Filament panel

- ♻️ **README.md**
  - Ajout section Panel Admin Filament
  - Ajout section Tests (statistiques détaillées)
  - Mise à jour technologies (Filament v3.3)
  - Ajout instructions création admin user
  - Mise à jour roadmap (Phase 2 complète)
  - Ajout URL admin panel

### Technical Details

#### Base de Données
- Tables : users, media, media_metadata, media_conversions, tags, media_tag
- Indexes optimisés pour requêtes fréquentes
- Constraints (latitude/longitude validation)
- Soft deletes sur media
- UUID primary keys
- JSON pour preferences utilisateur

#### API Endpoints
- `GET/POST /media` - Liste et upload médias
- `GET/DELETE /media/{id}` - Détails et suppression
- `GET/POST/PUT/DELETE /tags` - CRUD tags
- `POST /tags/attach` - Attacher tag à média
- `POST /tags/detach` - Détacher tag de média
- `GET /tags/media/{id}` - Tags d'un média
- `GET /map` - Page carte
- `GET /map/media` - Médias géolocalisés
- `POST/DELETE /map/media/{id}/geolocation` - CRUD géolocalisation
- `GET /map/nearby` - Médias proches (Haversine)
- `GET /map/search` - Recherche de lieux (Nominatim)

#### Composants Vue
- MediaCard.vue - Affichage carte média
- TagInput.vue - Input tags avec autocomplete
- GeolocationEditor.vue - Éditeur GPS
- UploadDropzone.vue - Zone upload drag-and-drop
- AppLayout.vue - Layout principal
- Navigation responsive

#### Services
- MediaService - Logique métier médias
- S3Service - Operations S3 (upload/delete)
- FaceRecognitionService - Google Vision API (à venir)

#### Jobs Asynchrones
- GenerateMediaConversions - Génération thumbnails
- ExtractMediaMetadata - Extraction EXIF

#### Filament Resources
- MediaResource - Gestion médias admin
- TagResource - Gestion tags admin
- UserResource - Gestion utilisateurs admin

### Performance

- ⚡ Eager loading relations (N+1 queries)
- ⚡ Pagination optimisée (24 items/page)
- ⚡ Cache Redis pour queries fréquentes
- ⚡ Lazy loading images (galerie)
- ⚡ Code splitting Vite
- ⚡ Gzip compression nginx
- ⚡ Asset caching (1 year)
- ⚡ Jobs asynchrones (conversions médias)

### Security

- 🔒 CSRF protection
- 🔒 XSS prevention (Blade escaping)
- 🔒 SQL Injection protection (Eloquent ORM)
- 🔒 File upload validation (MIME types, taille)
- 🔒 Latitude/longitude constraints (DB level)
- 🔒 UUID validation sur endpoints
- 🔒 Environment variables sécurisées
- 🔒 Password hashing (bcrypt)

### Dependencies

#### Backend
- laravel/framework: ^11.0
- filament/filament: ^3.3
- inertiajs/inertia-laravel: ^1.0
- intervention/image: ^3.0
- spatie/laravel-permission: ^6.0
- google/cloud-vision: ^1.0 (prévu)

#### Frontend
- vue: ^3.4
- @inertiajs/vue3: ^1.0
- vite: ^5.0
- tailwindcss: ^3.4
- leaflet: ^1.9
- photoswipe: ^5.4
- pinia: ^2.1

### Known Issues

- ⚠️ Upload max size: 2GB (nginx limit)
- ⚠️ Nominatim API rate limit: 1 req/sec
- ⚠️ Face recognition non implémentée
- ⚠️ Albums non implémentés
- ⚠️ Rate limiting API non configuré
- ⚠️ CI/CD non configuré
- ⚠️ Backup automatique non configuré

### Migration Guide

Pour migrer depuis une version antérieure :

```bash
# 1. Backup base de données
docker-compose exec postgres pg_dump -U memorylane memorylane > backup.sql

# 2. Pull dernières modifications
git pull origin main

# 3. Installer dépendances
docker-compose exec app composer install
docker-compose exec app npm install

# 4. Exécuter migrations
docker-compose exec app php artisan migrate

# 5. Publier assets Filament/Livewire
docker-compose exec app php artisan filament:assets
docker-compose exec app php artisan livewire:publish --assets

# 6. Clear caches
docker-compose exec app php artisan optimize:clear

# 7. Rebuild assets
docker-compose exec app npm run build

# 8. Restart containers
docker-compose restart
```

### Contributors

- Claude (AI Assistant) - Development, Testing, Documentation
- Matthieu - Project Owner, Requirements, Testing

---

## [Unreleased]

### Planned

- 🔮 Albums & Collections
- 🔮 Face Recognition (Google Vision)
- 🔮 Arbre Généalogique (GEDCOM import)
- 🔮 Partage familial
- 🔮 Timeline view
- 🔮 Memories (souvenirs automatiques)
- 🔮 PWA (Progressive Web App)
- 🔮 Mobile apps (iOS/Android)
- 🔮 API authentication (Laravel Sanctum)
- 🔮 Rate limiting
- 🔮 CI/CD pipeline
- 🔮 Automated backups
- 🔮 CDN integration
- 🔮 Multi-language support
- 🔮 Dark mode

---

## Versions Précédentes

### [0.9.0] - 2025-01-15
- Initial project setup
- Docker environment
- Laravel 11 + Vue 3 + Inertia.js
- Basic media upload
- Database migrations

### [0.5.0] - 2025-01-01
- Project inception
- Requirements gathering
- Technology stack selection

---

**Note :** Ce fichier est maintenu manuellement. Pour l'historique complet, voir `git log`.
