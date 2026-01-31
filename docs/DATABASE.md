# Base de Données - MemoryLane

## Vue d'ensemble

MemoryLane utilise PostgreSQL 16 avec des identifiants UUID pour toutes les tables principales.

## Schéma de Base de Données

### Diagramme ERD

```
┌──────────────┐         ┌──────────────┐         ┌──────────────┐
│    users     │         │    media     │         │     tags     │
├──────────────┤         ├──────────────┤         ├──────────────┤
│ id (uuid)    │────┐    │ id (uuid)    │    ┌────│ id (uuid)    │
│ name         │    │    │ user_id (fk) │────┘    │ name         │
│ email        │    └───→│ type         │         │ type         │
│ password     │         │ original_name│         │ slug         │
│ pin_code     │         │ file_path    │         │ color        │
│ avatar_id    │         │ mime_type    │         └──────────────┘
│ person_id    │         │ size         │                │
│ preferences  │         │ width        │                │
└──────────────┘         │ height       │                │
                         │ duration     │                │
                         │ uploaded_at  │                │
                         │ taken_at     │                │
                         └──────────────┘                │
                                │                        │
                                │                        │
                    ┌───────────┴───────────┐           │
                    │                       │           │
         ┌──────────▼──────────┐ ┌──────────▼───────────▼─┐
         │  media_metadata     │ │     media_tag          │
         ├─────────────────────┤ ├────────────────────────┤
         │ id (uuid)           │ │ media_id (fk)          │
         │ media_id (fk)       │ │ tag_id (fk)            │
         │ latitude            │ └────────────────────────┘
         │ longitude           │
         │ altitude            │
         │ camera_make         │
         │ camera_model        │
         │ focal_length        │
         │ aperture            │
         │ iso                 │
         │ shutter_speed       │
         │ orientation         │
         └─────────────────────┘
                    │
         ┌──────────▼──────────┐
         │  media_conversions  │
         ├─────────────────────┤
         │ id (uuid)           │
         │ media_id (fk)       │
         │ conversion_name     │
         │ file_path           │
         │ width               │
         │ height              │
         │ size                │
         └─────────────────────┘
```

## Tables Détaillées

### users

Table principale des utilisateurs de l'application.

```sql
CREATE TABLE users (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    pin_code VARCHAR(255) NULL,
    avatar_id UUID NULL,
    person_id UUID NULL,
    preferences JSON NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
```

**Champs clés :**
- `id` : UUID généré automatiquement
- `email` : Email unique pour authentification
- `pin_code` : Code PIN optionnel pour accès rapide
- `avatar_id` : Référence vers média utilisé comme avatar
- `person_id` : Lien vers entité Person (arbre généalogique)
- `preferences` : JSON pour préférences utilisateur

**Indexes :**
```sql
CREATE INDEX idx_users_email ON users(email);
```

---

### media

Table centrale stockant tous les médias (photos, vidéos, documents).

```sql
CREATE TABLE media (
    id UUID PRIMARY KEY,
    user_id UUID NOT NULL,
    type VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(255) NOT NULL,
    size BIGINT NOT NULL,
    width INTEGER NULL,
    height INTEGER NULL,
    duration DECIMAL(10,2) NULL,
    uploaded_at TIMESTAMP NOT NULL,
    taken_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**Champs clés :**
- `type` : photo | video | document
- `file_path` : Chemin dans S3 (ex: `media/2025/01/uuid.jpg`)
- `size` : Taille en octets
- `width/height` : Dimensions (photos/vidéos uniquement)
- `duration` : Durée en secondes (vidéos uniquement)
- `uploaded_at` : Date d'upload
- `taken_at` : Date de prise (depuis EXIF)
- `deleted_at` : Soft delete timestamp

**Indexes :**
```sql
CREATE INDEX idx_media_user_id ON media(user_id);
CREATE INDEX idx_media_type ON media(type);
CREATE INDEX idx_media_uploaded_at ON media(uploaded_at);
CREATE INDEX idx_media_deleted_at ON media(deleted_at);
```

---

### media_metadata

Métadonnées EXIF extraites des médias.

```sql
CREATE TABLE media_metadata (
    id UUID PRIMARY KEY,
    media_id UUID NOT NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    altitude DECIMAL(10,2) NULL,
    camera_make VARCHAR(255) NULL,
    camera_model VARCHAR(255) NULL,
    focal_length DECIMAL(8,2) NULL,
    aperture DECIMAL(5,2) NULL,
    iso INTEGER NULL,
    shutter_speed VARCHAR(50) NULL,
    orientation INTEGER NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);
```

**Champs clés :**
- `latitude/longitude` : Coordonnées GPS (WGS84)
- `altitude` : Altitude en mètres
- `camera_make/model` : Appareil photo
- `focal_length` : Longueur focale en mm
- `aperture` : Ouverture (f-number)
- `iso` : Sensibilité ISO
- `shutter_speed` : Vitesse d'obturation
- `orientation` : Orientation EXIF (1-8)

**Indexes :**
```sql
CREATE INDEX idx_metadata_media_id ON media_metadata(media_id);
CREATE INDEX idx_metadata_location ON media_metadata(latitude, longitude);
```

**Contraintes :**
```sql
ALTER TABLE media_metadata
    ADD CONSTRAINT chk_latitude CHECK (latitude >= -90 AND latitude <= 90),
    ADD CONSTRAINT chk_longitude CHECK (longitude >= -180 AND longitude <= 180);
```

---

### media_conversions

Versions converties des médias (thumbnails, optimisations).

```sql
CREATE TABLE media_conversions (
    id UUID PRIMARY KEY,
    media_id UUID NOT NULL,
    conversion_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    width INTEGER NULL,
    height INTEGER NULL,
    size BIGINT NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);
```

**Champs clés :**
- `conversion_name` : thumbnail | small | medium | large | webp
- `file_path` : Chemin dans S3
- `size` : Taille du fichier converti

**Conversions standards :**
- `thumbnail` : 150x150px (crop)
- `small` : 640px (max width)
- `medium` : 1280px (max width)
- `large` : 1920px (max width)
- `webp` : Format WebP optimisé

**Indexes :**
```sql
CREATE INDEX idx_conversions_media_id ON media_conversions(media_id);
CREATE UNIQUE INDEX idx_conversions_unique ON media_conversions(media_id, conversion_name);
```

---

### tags

Système de tags flexible pour catégoriser les médias.

```sql
CREATE TABLE tags (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(255) NOT NULL DEFAULT 'general',
    slug VARCHAR(255) NOT NULL UNIQUE,
    color VARCHAR(7) NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
```

**Champs clés :**
- `name` : Nom du tag (ex: "Vacances 2024")
- `type` : general | location | person | event
- `slug` : Identifiant URL-friendly
- `color` : Couleur hex (ex: "#FF5733")

**Types de tags :**
- `general` : Tags génériques
- `location` : Lieux spécifiques
- `person` : Personnes identifiées
- `event` : Événements (mariages, anniversaires)

**Indexes :**
```sql
CREATE INDEX idx_tags_name ON tags(name);
CREATE INDEX idx_tags_type ON tags(type);
CREATE UNIQUE INDEX idx_tags_slug ON tags(slug);
```

---

### media_tag (Pivot Table)

Table de liaison many-to-many entre médias et tags.

```sql
CREATE TABLE media_tag (
    media_id UUID NOT NULL,
    tag_id UUID NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    PRIMARY KEY (media_id, tag_id),
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);
```

**Indexes :**
```sql
CREATE INDEX idx_media_tag_media ON media_tag(media_id);
CREATE INDEX idx_media_tag_tag ON media_tag(tag_id);
```

---

## Migrations

### Ordre d'exécution

1. `create_users_table` - Utilisateurs
2. `create_media_table` - Médias
3. `create_media_metadata_table` - Métadonnées EXIF
4. `create_media_conversions_table` - Conversions
5. `create_tags_table` - Tags
6. `create_media_tag_table` - Pivot médias-tags

### Commandes

```bash
# Exécuter toutes les migrations
php artisan migrate

# Rollback dernière migration
php artisan migrate:rollback

# Reset + migrate (⚠️ PERTE DE DONNÉES)
php artisan migrate:fresh

# Reset + migrate + seed
php artisan migrate:fresh --seed
```

## Factories & Seeders

### Factories disponibles

```php
// UserFactory
User::factory()->create();
User::factory()->count(10)->create();

// MediaFactory
Media::factory()->photo()->create();
Media::factory()->video()->create();
Media::factory()->count(50)->create();

// TagFactory
Tag::factory()->create(['type' => 'location']);
Tag::factory()->count(20)->create();
```

### Seeders

```bash
# Seed database avec données de test
php artisan db:seed

# Seed classe spécifique
php artisan db:seed --class=UserSeeder
```

## Requêtes Courantes

### Médias géolocalisés

```php
$geolocatedMedia = Media::whereHas('metadata', function($query) {
    $query->whereNotNull('latitude')
          ->whereNotNull('longitude');
})->get();
```

### Médias par tag

```php
$taggedMedia = Media::whereHas('tags', function($query) use ($tagId) {
    $query->where('tags.id', $tagId);
})->get();
```

### Médias avec eager loading

```php
$media = Media::with(['user', 'tags', 'metadata', 'conversions'])
    ->orderBy('uploaded_at', 'desc')
    ->paginate(24);
```

### Recherche de médias proches (Haversine)

```php
$latitude = 48.8566;
$longitude = 2.3522;
$radius = 5; // km

$nearbyMedia = Media::selectRaw("
    media.*,
    (6371 * acos(cos(radians(?))
    * cos(radians(media_metadata.latitude))
    * cos(radians(media_metadata.longitude) - radians(?))
    + sin(radians(?))
    * sin(radians(media_metadata.latitude)))) AS distance
", [$latitude, $longitude, $latitude])
->join('media_metadata', 'media.id', '=', 'media_metadata.media_id')
->whereNotNull('media_metadata.latitude')
->whereNotNull('media_metadata.longitude')
->having('distance', '<', $radius)
->orderBy('distance')
->get();
```

## Backup & Restore

### Backup

```bash
# Backup complet
docker-compose exec postgres pg_dump -U memorylane memorylane > backup.sql

# Backup avec compression
docker-compose exec postgres pg_dump -U memorylane memorylane | gzip > backup.sql.gz
```

### Restore

```bash
# Restore depuis backup
docker-compose exec -T postgres psql -U memorylane memorylane < backup.sql

# Restore depuis backup compressé
gunzip -c backup.sql.gz | docker-compose exec -T postgres psql -U memorylane memorylane
```

## Performance

### Index recommandés

Tous les indexes sont créés automatiquement par les migrations. Pour vérifier :

```sql
-- Lister tous les indexes
SELECT tablename, indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
ORDER BY tablename, indexname;
```

### Optimisations

- **Vacuum** : Exécuté automatiquement par PostgreSQL
- **Analyze** : Mise à jour statistiques pour query planner
- **Index maintenance** : Rebuild si fragmentation

```sql
-- Analyze tables
ANALYZE media;
ANALYZE media_metadata;

-- Reindex
REINDEX TABLE media;
```

## Annexes

### Types MIME supportés

**Images :**
- image/jpeg
- image/png
- image/gif
- image/webp
- image/heic

**Vidéos :**
- video/mp4
- video/quicktime
- video/x-msvideo

**Documents :**
- application/pdf
- text/plain
