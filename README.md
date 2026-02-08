# MemoryLane - Hub Familial Multimédia

MemoryLane est une plateforme de gestion de médias familiaux permettant de stocker, organiser et partager photos, vidéos et documents pour votre famille.

## Technologies

### Backend
- **Laravel 11** - Framework PHP
- **PostgreSQL 16** - Base de données (UUID primary keys)
- **Redis 7** - Cache & Queues
- **Inertia.js** - Bridge Laravel-Vue (SPA sans API séparée)
- **Filament v3.3** - Panel d'administration

### Frontend
- **Vue 3** (Composition API + `<script setup>`)
- **Vite 5** - Build tool
- **Tailwind CSS 3** - Styling
- **PhotoSwipe** - Carousel lightbox (albums)

### Infrastructure
- **Podman** / Docker Compose
- **Nginx** - Serveur web
- **S3-compatible** - Stockage médias (Scaleway ou disque local)

## Fonctionnalités

### Authentification
- Login/logout avec validation Inertia
- Gestion des sessions (UUID)
- Panel admin protégé (Filament)

### Galerie de médias
- Upload drag-and-drop (photos, vidéos, documents)
- Galerie responsive avec filtres (type, recherche, tags)
- Extraction automatique des métadonnées EXIF
- Génération de thumbnails (4 tailles)
- Clic sur un média ouvre l'éditeur (titre, description, tags, personnes, géolocalisation)

### Albums
- Création, édition, suppression d'albums
- Ajout/retrait de médias dans un album via un picker
- Réordonnement des médias
- Carousel PhotoSwipe pour visualiser les photos d'un album
- Partage d'albums :
  - Visibilité publique/privée
  - Lien partageable avec token unique
  - Révocation du lien de partage
- Couverture automatique (premier média ajouté)

### Système de personnes
- CRUD complet des personnes (nom, date de naissance/décès, notes, avatar)
- Slug auto-généré et unique
- Tagging de personnes sur les médias (relation many-to-many via `media_person`)
- Autocomplete avec création rapide depuis l'éditeur de média
- Page profil d'une personne avec ses médias associés
- Page listing de toutes les personnes

### Tags
- Création, édition, suppression de tags
- Tagging de médias avec autocomplete
- Filtrage par tags dans la galerie
- Tags colorés affichés sur les cartes médias

### Géolocalisation
- Extraction GPS automatique depuis EXIF
- Carte interactive avec Leaflet.js + OpenStreetMap
- Recherche de lieux avec Nominatim
- Édition manuelle des coordonnées GPS
- Filtrage par zone géographique

### Panel Admin (Filament)
- Gestion des médias, tags, utilisateurs
- Dashboard avec statistiques
- Soft delete avec corbeille

## Installation

### Prérequis

- Podman ou Docker avec Compose
- WSL2 (Windows) ou Linux/Mac
- Git

### Démarrage rapide

```bash
# Cloner le projet
git clone <repository-url> memorylane
cd memorylane

# Copier et configurer l'environnement
cp backend/.env.example backend/.env

# Démarrer les conteneurs
podman-compose up -d --build
# ou: docker-compose up -d --build

# Installer les dépendances
podman-compose exec app composer install
podman-compose exec app npm install

# Générer la clé, migrer, builder
podman-compose exec app php artisan key:generate
podman-compose exec app php artisan migrate
podman-compose exec app npm run build
```

### Créer un utilisateur

```bash
podman-compose exec app php artisan tinker

# Dans Tinker :
User::create([
    'name' => 'Admin',
    'email' => 'admin@memorylane.com',
    'password' => Hash::make('password'),
    'role' => 'admin',
]);
```

### Accès

| Service | URL |
|---------|-----|
| Application | http://localhost:8000 |
| Admin Panel | http://localhost:8000/admin |

> Pour la configuration Podman détaillée, voir [PODMAN_SETUP.md](PODMAN_SETUP.md)

## Structure du projet

```
memorylane/
├── docker/                          # Configurations Docker/Podman
│   ├── app/                         # PHP-FPM
│   ├── nginx/                       # Nginx
│   └── postgres/                    # PostgreSQL
├── backend/                         # Application Laravel
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── AlbumController.php  # CRUD albums + partage + médias
│   │   │   ├── AuthController.php   # Login/logout
│   │   │   ├── MediaController.php  # CRUD médias + upload
│   │   │   ├── PersonController.php # CRUD personnes + attach/detach
│   │   │   ├── TagController.php    # CRUD tags + attach/detach
│   │   │   ├── MapController.php    # Géolocalisation
│   │   │   └── ProfileController.php
│   │   ├── Models/
│   │   │   ├── Album.php            # Albums (share token, slug, soft delete)
│   │   │   ├── Media.php            # Médias (titre, description, personnes)
│   │   │   ├── Person.php           # Personnes (slug unique, dates)
│   │   │   ├── Tag.php
│   │   │   └── User.php
│   │   └── Services/
│   │       └── MediaService.php     # Upload, URLs signées, conversions
│   ├── database/
│   │   ├── migrations/              # Toutes les migrations (UUID)
│   │   └── factories/               # Factories pour tests
│   ├── resources/js/
│   │   ├── Components/
│   │   │   ├── AlbumCard.vue        # Carte album
│   │   │   ├── AlbumFormModal.vue   # Modal création/édition album
│   │   │   ├── MediaCard.vue        # Carte média
│   │   │   ├── MediaGrid.vue        # Grille responsive
│   │   │   ├── MediaInfoEditor.vue  # Éditeur titre/description
│   │   │   ├── MediaPickerModal.vue # Sélecteur médias pour albums
│   │   │   ├── PersonInput.vue      # Autocomplete personnes
│   │   │   ├── PersonFormModal.vue  # Modal création personne
│   │   │   ├── SharePanel.vue       # Contrôles de partage
│   │   │   ├── TagInput.vue         # Autocomplete tags
│   │   │   └── GeolocationEditor.vue
│   │   ├── Pages/
│   │   │   ├── Albums/              # Index, Show, Shared
│   │   │   ├── Auth/                # Login
│   │   │   ├── Media/               # Index (galerie), Show (éditeur), Upload
│   │   │   ├── People/              # Index, Show
│   │   │   ├── Map/                 # Carte interactive
│   │   │   └── Tags/                # Index
│   │   └── Layouts/
│   │       └── AppLayout.vue        # Navigation principale
│   ├── routes/web.php               # Toutes les routes
│   └── tests/Feature/               # Tests fonctionnels
├── podman-compose.yml
└── README.md
```

## Base de données

### Tables principales

| Table | Description |
|-------|-------------|
| `users` | Utilisateurs (UUID) |
| `media` | Photos/vidéos/documents (titre, description, type, dimensions) |
| `media_metadata` | Métadonnées EXIF (GPS, appareil, date) |
| `media_conversions` | Thumbnails et versions optimisées |
| `tags` | Tags avec couleur et slug |
| `taggables` | Pivot polymorphique tags-médias |
| `albums` | Albums (slug, share_token, is_public, soft delete) |
| `album_media` | Pivot album-média avec ordre |
| `people` | Personnes (nom, slug, dates, notes, avatar) |
| `media_person` | Pivot média-personne (avec face_coordinates) |

### Commandes migrations

```bash
podman-compose exec app php artisan migrate
podman-compose exec app php artisan migrate:rollback
podman-compose exec app php artisan migrate:fresh
```

## Tests

### Suites de tests

| Suite | Tests | Description |
|-------|-------|-------------|
| `AlbumControllerTest` | 18 | CRUD albums, ajout/retrait médias, partage, autorisations |
| `PersonControllerTest` | 16 | CRUD personnes, attach/detach médias, slug unique, autorisations |
| `MediaUpdateTest` | 8 | Édition titre/description, validation, autorisations |
| `MediaControllerTest` | 10 | Upload, listing, filtres, suppression |
| `TagControllerTest` | 7 | CRUD tags, validation |
| `TagAttachmentTest` | 11 | Attachement/détachement tags-médias |
| `MapControllerTest` | 11 | Géolocalisation, recherche, carte |
| `FilamentAdminTest` | 17 | Panel admin Filament |
| `LoginTest` | 4 | Authentification |

**Total : ~100 tests**

### Lancer les tests

```bash
# Tous les tests
podman-compose exec app php artisan test

# Une suite spécifique
podman-compose exec app php artisan test --filter=AlbumControllerTest
podman-compose exec app php artisan test --filter=PersonControllerTest

# Avec couverture
podman-compose exec app php artisan test --coverage
```

## Routes API

### Médias (`/media`)
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/media` | Liste (Inertia ou JSON) |
| POST | `/media` | Upload |
| GET | `/media/{id}` | Détail + éditeur |
| PUT | `/media/{id}` | Mise à jour titre/description |
| DELETE | `/media/{id}` | Suppression |

### Albums (`/albums`)
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/albums` | Liste des albums |
| POST | `/albums` | Créer un album |
| GET | `/albums/{id}` | Détail + carousel |
| PUT | `/albums/{id}` | Modifier |
| DELETE | `/albums/{id}` | Supprimer |
| POST | `/albums/{id}/media` | Ajouter des médias |
| DELETE | `/albums/{id}/media` | Retirer des médias |
| PUT | `/albums/{id}/media/reorder` | Réordonner |
| POST | `/albums/{id}/share` | Générer lien de partage |
| DELETE | `/albums/{id}/share` | Révoquer le partage |
| GET | `/albums/shared/{token}` | Vue publique partagée |

### Personnes (`/people`)
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/people` | Liste (Inertia ou JSON) |
| POST | `/people` | Créer |
| GET | `/people/{id}` | Profil + médias |
| PUT | `/people/{id}` | Modifier |
| DELETE | `/people/{id}` | Supprimer |
| POST | `/people/attach` | Associer personne à un média |
| POST | `/people/detach` | Dissocier personne d'un média |

### Tags (`/tags`)
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/tags` | Liste |
| POST | `/tags` | Créer |
| PUT | `/tags/{id}` | Modifier |
| DELETE | `/tags/{id}` | Supprimer |
| POST | `/tags/attach` | Attacher à un média |
| POST | `/tags/detach` | Détacher d'un média |

## Développement

### Commandes utiles

```bash
# Hot reload frontend
podman-compose exec app npm run dev

# Accéder au conteneur
podman-compose exec app bash

# Logs
podman-compose logs -f app

# Redémarrer
podman-compose restart
```

## Roadmap

### Phase 1 : Fondations
- [x] Environnement Docker/Podman
- [x] Laravel 11 + Vue 3 + Inertia.js
- [x] Migrations base de données (UUID, soft deletes)
- [x] Upload médias (drag-and-drop)
- [x] Galerie photos responsive
- [x] Extraction EXIF automatique
- [x] Génération thumbnails

### Phase 2 : Fonctionnalités Core
- [x] Système de tags complet
- [x] Géolocalisation + carte interactive
- [x] Panel Admin Filament
- [x] Albums avec partage (public + lien token)
- [x] Édition titre/description des médias
- [x] Système de personnes (CRUD + tagging sur médias)
- [x] Suite de tests (~100 tests)

### Phase 3 : IA & Reconnaissance
- [ ] Intégration Google Vision API
- [ ] Détection et clustering de visages
- [ ] Suggestion automatique de personnes

### Phase 4 : Arbre Généalogique
- [ ] Import GEDCOM (Généanet)
- [ ] Visualisation arbre
- [ ] Liaison personnes-photos

---

**Version** : 1.1.0 | **Dernière mise à jour** : Février 2026
