# MemoryLane - Hub Familial Multimédia

MemoryLane est une plateforme de gestion de médias familiaux permettant de stocker, organiser et partager photos, vidéos et documents pour votre famille.

## Technologies

### Backend
- **Laravel 11** - Framework PHP
- **PostgreSQL 16** - Base de données (UUID primary keys)
- **Redis 7** - Cache & Queues
- **Inertia.js** - Bridge Laravel-Vue (SPA sans API séparée)
- **Filament v3.3** - Panel d'administration
- **Google Cloud Vision API** - Détection de visages et labels IA (provider swappable)

### Frontend
- **Vue 3** (Composition API + `<script setup>`)
- **Vite 5** - Build tool
- **Tailwind CSS 3** - Styling
- **PhotoSwipe** - Carousel lightbox (albums)
- **family-chart** - Visualisation arbre généalogique (D3-based)

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
- CRUD complet des personnes (nom, genre, date de naissance/décès, lieu, notes, avatar)
- Slug auto-généré et unique
- Tagging de personnes sur les médias (relation many-to-many via `media_person`)
- Autocomplete avec création rapide depuis l'éditeur de média
- **Relations familiales** : père, mère, conjoints, enfants
- Panel famille interactif sur la fiche personne (RelationshipPicker)
- Page profil d'une personne avec ses médias associés
- Page listing de toutes les personnes

### Tags
- Création, édition, suppression de tags
- Tagging de médias avec autocomplete
- Filtrage par tags dans la galerie
- Tags colorés affichés sur les cartes médias

### IA & Reconnaissance visuelle (Vision AI)
- **Architecture provider-swappable** : interface unique (`VisionServiceInterface`) avec implémentation Google Cloud Vision et possibilité de basculer vers une alternative locale gratuite (DeepFace/InsightFace)
- **Détection de visages** : bounding boxes positionnées en % sur l'image, overlay interactif
- **Matching de visages** : associer un visage détecté à une personne existante ou en créer une nouvelle
- **Détection de labels** : catégorisation automatique des photos (Nature, Famille, Animaux, etc.)
- **Auto-tagging** : les labels IA créent automatiquement des tags de type `ai` (violet)
- **Traitement asynchrone** : job en queue déclenché automatiquement après upload
- **Suivi du statut** : badge temps réel (pending → processing → completed/failed) avec polling
- **Re-analyse** : possibilité de relancer l'analyse IA sur une photo

### Arbre Généalogique
- Import GEDCOM (format standard Geneanet/MyHeritage/Ancestry)
- Parseur GEDCOM custom (INDI + FAM, gestion des dates, encodage)
- Matching intelligent à l'import : score 0-100 (nom, dates) avec auto-match >= 80%
- Import en 3 étapes : upload → review matching → confirmation
- Visualisation interactive de l'arbre (SVG, pan/zoom, clic sur nœud)
- Relations familiales : père, mère, conjoints (avec dates mariage/divorce)
- Panel famille interactif sur la fiche personne (RelationshipPicker)
- Sous-arbre centré sur une personne (3 générations haut/bas)

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
│   │   ├── Contracts/
│   │   │   └── VisionServiceInterface.php  # Interface provider-swappable
│   │   ├── Http/Controllers/
│   │   │   ├── AlbumController.php  # CRUD albums + partage + médias
│   │   │   ├── AuthController.php   # Login/logout
│   │   │   ├── FamilyTreeController.php  # Arbre généalogique + données JSON
│   │   │   ├── GedcomImportController.php # Import GEDCOM (upload, matching, confirm)
│   │   │   ├── MediaController.php  # CRUD médias + upload
│   │   │   ├── PersonController.php # CRUD personnes + relations familiales
│   │   │   ├── TagController.php    # CRUD tags + attach/detach
│   │   │   ├── MapController.php    # Géolocalisation
│   │   │   ├── VisionController.php # Visages, labels, analyse IA
│   │   │   └── ProfileController.php
│   │   ├── Jobs/
│   │   │   ├── AnalyzeMediaWithVision.php    # Job analyse IA async
│   │   │   ├── ProcessUploadedMedia.php      # Extraction EXIF
│   │   │   ├── GenerateMediaConversions.php  # Thumbnails
│   │   │   └── Concerns/
│   │   │       └── DownloadsMediaToTemp.php  # Trait partagé download
│   │   ├── Models/
│   │   │   ├── Album.php            # Albums (share token, slug, soft delete)
│   │   │   ├── DetectedFace.php     # Visages détectés par l'IA
│   │   │   ├── Media.php            # Médias (titre, description, personnes)
│   │   │   ├── MediaMetadata.php    # Métadonnées EXIF + vision
│   │   │   ├── Person.php           # Personnes (slug, dates, relations familiales)
│   │   │   ├── PersonRelationship.php # Relations conjoints/partenaires
│   │   │   ├── GedcomImport.php     # Sessions d'import GEDCOM
│   │   │   ├── Tag.php              # Tags (type: general|ai)
│   │   │   └── User.php
│   │   └── Services/
│   │       ├── MediaService.php     # Upload, URLs signées, conversions
│   │       ├── GedcomParserService.php  # Parseur GEDCOM custom (INDI + FAM)
│   │       ├── GedcomImportService.php  # Matching + import GEDCOM
│   │       └── Vision/
│   │           ├── GoogleVisionService.php  # Implémentation Google
│   │           └── NullVisionService.php    # No-op (vision désactivée)
│   ├── database/
│   │   ├── migrations/              # Toutes les migrations (UUID)
│   │   └── factories/               # Factories pour tests
│   ├── resources/js/
│   │   ├── Components/
│   │   │   ├── AlbumCard.vue        # Carte album
│   │   │   ├── AlbumFormModal.vue   # Modal création/édition album
│   │   │   ├── FaceDetectionOverlay.vue  # Overlay visages sur image
│   │   │   ├── FaceMatchPanel.vue   # Panel matching visage → personne
│   │   │   ├── MediaCard.vue        # Carte média
│   │   │   ├── MediaGrid.vue        # Grille responsive
│   │   │   ├── MediaInfoEditor.vue  # Éditeur titre/description
│   │   │   ├── MediaPickerModal.vue # Sélecteur médias pour albums
│   │   │   ├── PersonInput.vue      # Autocomplete personnes
│   │   │   ├── PersonFormModal.vue  # Modal création/édition personne (+ genre)
│   │   │   ├── RelationshipPicker.vue # Sélecteur de relation familiale
│   │   │   ├── FamilyPanel.vue      # Panel famille (parents, conjoints, enfants)
│   │   │   ├── SharePanel.vue       # Contrôles de partage
│   │   │   ├── TagInput.vue         # Autocomplete tags
│   │   │   ├── VisionLabels.vue     # Labels IA (chips violet)
│   │   │   ├── VisionStatusBadge.vue # Badge statut analyse IA
│   │   │   └── GeolocationEditor.vue
│   │   ├── Pages/
│   │   │   ├── Albums/              # Index, Show, Shared
│   │   │   ├── Auth/                # Login
│   │   │   ├── Media/               # Index (galerie), Show (éditeur), Upload
│   │   │   ├── People/              # Index, Show (+ FamilyPanel)
│   │   │   ├── FamilyTree/          # Index (arbre), Import (GEDCOM)
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
| `media_metadata` | Métadonnées EXIF (GPS, appareil, date) + champs vision IA |
| `media_conversions` | Thumbnails et versions optimisées |
| `tags` | Tags avec couleur, slug et type (general/ai) |
| `taggables` | Pivot polymorphique tags-médias |
| `albums` | Albums (slug, share_token, is_public, soft delete) |
| `album_media` | Pivot album-média avec ordre |
| `people` | Personnes (nom, slug, genre, dates, father_id, mother_id, avatar) |
| `person_relationships` | Relations conjoints/partenaires (dates mariage/divorce) |
| `media_person` | Pivot média-personne (avec face_coordinates) |
| `detected_faces` | Visages détectés par l'IA (bounding box, confiance, émotions, statut) |
| `gedcom_imports` | Sessions d'import GEDCOM (parsed_data JSON, status, matching) |

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
| `VisionControllerTest` | 12 | Détection visages, matching, labels, re-analyse, autorisations |
| `FilamentAdminTest` | 17 | Panel admin Filament |
| `FamilyRelationshipTest` | 11 | Relations familiales, arbre, autorisations |
| `GedcomImportTest` | 8 | Parseur GEDCOM, upload, matching, import |
| `LoginTest` | 4 | Authentification |

**Total : 136 tests**

### Lancer les tests

```bash
# Tous les tests
podman-compose exec app php artisan test

# Une suite spécifique
podman-compose exec app php artisan test --filter=AlbumControllerTest
podman-compose exec app php artisan test --filter=VisionControllerTest

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
| POST | `/people/{id}/parent` | Définir père/mère |
| DELETE | `/people/{id}/parent` | Retirer père/mère |
| POST | `/people/{id}/spouse` | Ajouter un conjoint |
| DELETE | `/people/{id}/spouse` | Retirer un conjoint |

### Arbre Généalogique (`/family-tree`)
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/family-tree` | Page arbre (Inertia) |
| GET | `/family-tree/data` | Données JSON de l'arbre complet |
| GET | `/family-tree/data/{person}` | Sous-arbre centré sur une personne |
| GET | `/family-tree/import` | Page import GEDCOM |
| POST | `/family-tree/import/upload` | Upload + parse GEDCOM |
| GET | `/family-tree/import/{id}/review` | Suggestions de matching |
| POST | `/family-tree/import/{id}/confirm` | Confirmer l'import |

### Tags (`/tags`)
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/tags` | Liste |
| POST | `/tags` | Créer |
| PUT | `/tags/{id}` | Modifier |
| DELETE | `/tags/{id}` | Supprimer |
| POST | `/tags/attach` | Attacher à un média |
| POST | `/tags/detach` | Détacher d'un média |

### Vision IA (`/vision`)
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/vision/media/{id}/faces` | Visages détectés (hors dismissed) |
| POST | `/vision/faces/{id}/match` | Matcher un visage à une personne |
| POST | `/vision/faces/{id}/dismiss` | Ignorer un visage détecté |
| GET | `/vision/media/{id}/labels` | Labels IA détectés |
| POST | `/vision/media/{id}/analyze` | (Re)lancer l'analyse IA |
| GET | `/vision/media/{id}/status` | Statut du traitement IA |

## Configuration Vision IA

L'analyse IA est optionnelle et désactivée par défaut. L'architecture utilise un pattern **provider-swappable** : une interface unique `VisionServiceInterface` avec des implémentations interchangeables.

### Providers disponibles

| Provider | Env `VISION_PROVIDER` | Description |
|----------|----------------------|-------------|
| Google Cloud Vision | `google` | API cloud payante, haute précision |
| Null (désactivé) | — | No-op, retourne des résultats vides |
| Local *(futur)* | `local` | Alternative gratuite via DeepFace/InsightFace |

### Variables d'environnement

```env
# Activer/désactiver l'analyse IA
VISION_ENABLED=false

# Provider : google (par défaut)
VISION_PROVIDER=google

# Google Cloud Vision
GOOGLE_CLOUD_PROJECT=your-project-id
GOOGLE_APPLICATION_CREDENTIALS=/path/to/credentials.json

# Seuils de confiance
VISION_FACE_CONFIDENCE=0.75
VISION_LABEL_CONFIDENCE=0.70
VISION_LABEL_MAX=15

# Auto-tagging : crée des tags automatiquement depuis les labels IA
VISION_AUTO_TAG=true
```

### Ajouter un nouveau provider

1. Créer une classe implémentant `VisionServiceInterface` dans `app/Services/Vision/`
2. Ajouter le case dans le `match` de `AppServiceProvider`
3. Configurer `VISION_PROVIDER=nom_du_provider` dans `.env`

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
- [x] Architecture provider-swappable (VisionServiceInterface)
- [x] Intégration Google Cloud Vision API (visages + labels)
- [x] Overlay interactif des visages détectés sur les photos
- [x] Workflow de matching : visage → personne (existante ou nouvelle)
- [x] Auto-tagging depuis les labels IA (tags type `ai`)
- [x] Traitement asynchrone (job en queue) + suivi statut temps réel
- [x] Re-analyse IA sur demande
- [x] Tests VisionController (12 tests) — total : 117 tests

### Phase 4 : Arbre Généalogique
- [x] Relations familiales (père, mère, conjoints) avec panel interactif
- [x] Import GEDCOM custom (parseur INDI + FAM, gestion dates/encodage)
- [x] Matching intelligent à l'import (score 0-100, auto-match >= 80%)
- [x] Visualisation arbre interactive (SVG, pan/zoom, recherche)
- [x] Sous-arbre centré sur une personne (3 générations)
- [x] Tests FamilyRelationship (11) + GedcomImport (8) — total : 136 tests

---

**Version** : 1.3.0 | **Dernière mise à jour** : Février 2026
