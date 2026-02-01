# API Documentation - MemoryLane

## Vue d'ensemble

MemoryLane expose une API REST pour la gestion des médias, tags et géolocalisation. Toutes les routes sont définies dans `backend/routes/web.php`.

## Base URL

```
http://localhost:8000
```

## Authentication

L'application utilise l'authentification web standard (Session) pour le frontend et Laravel Sanctum pour l'API.
Toutes les routes nécessitent une authentification.

## Sécurité

⚠️ **Sécurité** : Toutes les routes sont désormais protégées par le middleware `auth`.
Les utilisateurs non authentifiés sont redirigés vers la page de login.

## Endpoints

### Dashboard

#### GET /

Affiche le dashboard principal.

**Response:**
- Status: `200 OK`
- Type: Inertia page render

```json
{
  "component": "Dashboard",
  "props": {}
}
```

---

## Media Endpoints

### GET /media

Liste tous les médias avec pagination.

**Query Parameters:**
- `page` (optional) : Numéro de page (default: 1)
- `per_page` (optional) : Items par page (default: 24)
- `type` (optional) : Filtrer par type (photo|video|document)
- `tags[]` (optional) : Filtrer par tags (array de UUIDs)
- `search` (optional) : Recherche texte

**Response:**
```json
{
  "data": [
    {
      "id": "9d4e2...",
      "user_id": "9d4e1...",
      "type": "photo",
      "original_name": "IMG_1234.jpg",
      "file_path": "media/2025/01/9d4e2.jpg",
      "url": "http://localhost:8000/storage/media/2025/01/9d4e2.jpg",
      "mime_type": "image/jpeg",
      "size": 2458392,
      "width": 3840,
      "height": 2160,
      "duration": null,
      "uploaded_at": "2025-01-31T10:30:00.000000Z",
      "taken_at": "2025-01-15T14:20:00.000000Z",
      "user": {
        "id": "9d4e1...",
        "name": "John Doe",
        "email": "john@example.com"
      },
      "tags": [
        {
          "id": "9d4e3...",
          "name": "Vacances",
          "type": "general",
          "color": "#FF5733"
        }
      ],
      "metadata": {
        "id": "9d4e4...",
        "latitude": 48.8566,
        "longitude": 2.3522,
        "altitude": 35.0,
        "camera_make": "Canon",
        "camera_model": "EOS R5"
      },
      "conversions": [
        {
          "conversion_name": "thumbnail",
          "file_path": "media/conversions/thumbnail.jpg",
          "url": "http://localhost:8000/storage/...",
          "width": 150,
          "height": 150,
          "size": 15234
        }
      ]
    }
  ],
  "links": {...},
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 24,
    "to": 24,
    "total": 120
  }
}
```

---

### GET /media/upload

Affiche la page d'upload de médias.

**Response:**
- Status: `200 OK`
- Type: Inertia page render

---

### POST /media

Upload un nouveau média.

**Content-Type:** `multipart/form-data`

**Body Parameters:**
- `file` (required) : Le fichier média
- `taken_at` (optional) : Date de prise (format: Y-m-d H:i:s)

**Validation:**
- Fichier requis
- Max 2GB
- Types MIME: image/*, video/*, application/pdf

**Response:**
```json
{
  "success": true,
  "message": "Média uploadé avec succès",
  "media": {
    "id": "9d4e2...",
    "original_name": "IMG_1234.jpg",
    "type": "photo",
    "url": "http://localhost:8000/storage/...",
    "size": 2458392
  }
}
```

**Errors:**
- `422 Unprocessable Entity` : Validation error
- `500 Internal Server Error` : Upload failed

---

### GET /media/{media}

Affiche les détails d'un média spécifique.

**URL Parameters:**
- `media` (UUID) : ID du média

**Response:**
```json
{
  "component": "Media/Show",
  "props": {
    "media": {
      "id": "9d4e2...",
      "original_name": "IMG_1234.jpg",
      "type": "photo",
      "url": "http://localhost:8000/storage/...",
      "user": {...},
      "tags": [...],
      "metadata": {...},
      "conversions": [...]
    }
  }
}
```

---

### DELETE /media/{media}

Supprime un média (soft delete).

**URL Parameters:**
- `media` (UUID) : ID du média

**Response:**
```json
{
  "success": true,
  "message": "Média supprimé avec succès"
}
```

---

### GET /media/{media}/download

Télécharge le fichier média original.

**URL Parameters:**
- `media` (UUID) : ID du média

**Response:**
- Status: `200 OK`
- Type: `application/octet-stream`
- Headers: `Content-Disposition: attachment; filename="original_name.jpg"`

---

## Tag Endpoints

### GET /tags

Liste tous les tags.

**Query Parameters:**
- `search` (optional) : Recherche par nom
- `type` (optional) : Filtrer par type

**Response:**
```json
{
  "data": [
    {
      "id": "9d4e3...",
      "name": "Vacances",
      "type": "general",
      "slug": "vacances",
      "color": "#FF5733",
      "created_at": "2025-01-31T10:00:00.000000Z",
      "media_count": 42
    }
  ]
}
```

---

### POST /tags

Crée un nouveau tag.

**Content-Type:** `application/json`

**Body Parameters:**
- `name` (required, string, max:255) : Nom du tag
- `type` (optional, string) : Type de tag (default: "general")
- `color` (optional, string) : Couleur hex (ex: "#FF5733")

**Response:**
```json
{
  "success": true,
  "message": "Tag créé avec succès",
  "tag": {
    "id": "9d4e3...",
    "name": "Vacances",
    "type": "general",
    "slug": "vacances",
    "color": "#FF5733"
  }
}
```

**Errors:**
- `422` : Validation error (nom requis, déjà existant, etc.)

---

### PUT /tags/{tag}

Met à jour un tag existant.

**URL Parameters:**
- `tag` (UUID) : ID du tag

**Body Parameters:**
- `name` (required, string, max:255)
- `type` (optional, string)
- `color` (optional, string)

**Response:**
```json
{
  "success": true,
  "message": "Tag mis à jour avec succès",
  "tag": {...}
}
```

---

### DELETE /tags/{tag}

Supprime un tag.

**URL Parameters:**
- `tag` (UUID) : ID du tag

**Response:**
```json
{
  "success": true,
  "message": "Tag supprimé avec succès"
}
```

**Note:** Supprime également toutes les associations media_tag.

---

### POST /tags/attach

Attache un tag à un média.

**Body Parameters:**
- `media_id` (required, UUID) : ID du média
- `tag_id` (required, UUID) : ID du tag

**Response:**
```json
{
  "success": true,
  "message": "Tag attaché au média avec succès"
}
```

**Idempotent:** Attacher un tag déjà attaché ne génère pas d'erreur.

---

### POST /tags/detach

Détache un tag d'un média.

**Body Parameters:**
- `media_id` (required, UUID) : ID du média
- `tag_id` (required, UUID) : ID du tag

**Response:**
```json
{
  "success": true,
  "message": "Tag détaché du média avec succès"
}
```

---

### GET /tags/media/{media}

Récupère tous les tags d'un média spécifique.

**URL Parameters:**
- `media` (UUID) : ID du média

**Response:**
```json
{
  "data": [
    {
      "id": "9d4e3...",
      "name": "Vacances",
      "type": "general",
      "color": "#FF5733"
    }
  ]
}
```

---

## Map Endpoints

### GET /map

Affiche la page carte interactive.

**Response:**
- Status: `200 OK`
- Type: Inertia page render

---

### GET /map/media

Récupère tous les médias géolocalisés pour affichage sur carte.

**Query Parameters:**
- `type` (optional) : Filtrer par type (photo|video)
- `tags[]` (optional) : Filtrer par tags (array de UUIDs)
- `bounds` (optional) : Bounding box (format: "sw_lat,sw_lng,ne_lat,ne_lng")

**Response:**
```json
[
  {
    "id": "9d4e2...",
    "original_name": "IMG_1234.jpg",
    "type": "photo",
    "url": "http://localhost:8000/storage/...",
    "thumbnail_url": "http://localhost:8000/storage/conversions/...",
    "latitude": 48.8566,
    "longitude": 2.3522,
    "altitude": 35.0,
    "uploaded_at": "2025-01-31T10:30:00.000000Z",
    "taken_at": "2025-01-15T14:20:00.000000Z"
  }
]
```

---

### POST /map/media/{media}/geolocation

Met à jour ou crée la géolocalisation d'un média.

**URL Parameters:**
- `media` (UUID) : ID du média

**Body Parameters:**
- `latitude` (required, numeric, -90 to 90)
- `longitude` (required, numeric, -180 to 180)
- `altitude` (optional, numeric)

**Response:**
```json
{
  "success": true,
  "message": "Geolocation updated successfully",
  "metadata": {
    "latitude": 48.8566,
    "longitude": 2.3522,
    "altitude": 35.0
  }
}
```

**Errors:**
- `422` : Validation error (coordonnées invalides)
- `404` : Média non trouvé

---

### DELETE /map/media/{media}/geolocation

Supprime la géolocalisation d'un média.

**URL Parameters:**
- `media` (UUID) : ID du média

**Response:**
```json
{
  "success": true,
  "message": "Geolocation removed successfully"
}
```

**Note:** Met les champs latitude/longitude/altitude à NULL, ne supprime pas l'enregistrement metadata.

---

### GET /map/search

Recherche de lieux via Nominatim (OpenStreetMap).

**Query Parameters:**
- `query` (required, min:3) : Terme de recherche

**Response:**
```json
{
  "data": [
    {
      "place_id": 123456,
      "display_name": "Paris, Île-de-France, France",
      "lat": "48.8566",
      "lon": "2.3522",
      "type": "city",
      "importance": 0.9
    }
  ]
}
```

**Errors:**
- `422` : Query trop courte (min 3 caractères)
- `500` : Erreur API Nominatim

---

### GET /map/nearby

Trouve les médias proches d'une position (Haversine formula).

**Query Parameters:**
- `latitude` (required, numeric)
- `longitude` (required, numeric)
- `radius` (optional, numeric, default: 10) : Rayon en kilomètres

**Response:**
```json
[
  {
    "id": "9d4e2...",
    "original_name": "IMG_1234.jpg",
    "url": "http://localhost:8000/storage/...",
    "latitude": 48.8566,
    "longitude": 2.3522,
    "distance": 2.3
  }
]
```

**Note:** Les résultats sont triés par distance croissante.

---

## Health Check

### GET /health

Endpoint de santé pour monitoring.

**Response:**
```json
{
  "status": "healthy",
  "app": "MemoryLane",
  "version": "1.0.0"
}
```

---

## Error Responses

### Format standard

Toutes les erreurs suivent ce format :

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "The field name is required."
    ]
  }
}
```

### Codes HTTP

- `200 OK` : Succès
- `201 Created` : Ressource créée
- `204 No Content` : Succès sans contenu
- `400 Bad Request` : Requête malformée
- `401 Unauthorized` : Non authentifié
- `403 Forbidden` : Accès refusé
- `404 Not Found` : Ressource introuvable
- `422 Unprocessable Entity` : Erreur de validation
- `500 Internal Server Error` : Erreur serveur

---

## Rate Limiting

**Non implémenté actuellement.**

En production, rate limiting sera configuré :
- 60 requêtes/minute pour routes standards
- 10 requêtes/minute pour uploads
- 100 requêtes/minute pour API key authentifiée

---

## Pagination

### Format

Les endpoints retournant des collections utilisent la pagination Laravel :

```json
{
  "data": [...],
  "links": {
    "first": "http://localhost:8000/media?page=1",
    "last": "http://localhost:8000/media?page=5",
    "prev": null,
    "next": "http://localhost:8000/media?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "path": "http://localhost:8000/media",
    "per_page": 24,
    "to": 24,
    "total": 120
  }
}
```

### Paramètres

- `page` : Numéro de page (commence à 1)
- `per_page` : Items par page (max: 100, default: 24)

---

## CORS

**Configuration actuelle :** Désactivé (same-origin only).

Pour API externe, configurer dans `config/cors.php` :

```php
'paths' => ['api/*'],
'allowed_origins' => ['https://example.com'],
'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
```

---

## Testing API

### cURL Examples

```bash
# List media
curl http://localhost:8000/media

# Get specific media
curl http://localhost:8000/media/{uuid}

# Upload media
curl -X POST http://localhost:8000/media \
  -F "file=@/path/to/image.jpg"

# Create tag
curl -X POST http://localhost:8000/tags \
  -H "Content-Type: application/json" \
  -d '{"name":"Vacances","type":"general","color":"#FF5733"}'

# Attach tag to media
curl -X POST http://localhost:8000/tags/attach \
  -H "Content-Type: application/json" \
  -d '{"media_id":"uuid","tag_id":"uuid"}'

# Update geolocation
curl -X POST http://localhost:8000/map/media/{uuid}/geolocation \
  -H "Content-Type: application/json" \
  -d '{"latitude":48.8566,"longitude":2.3522,"altitude":35}'
```

### Postman Collection

Une collection Postman est disponible dans `/docs/postman/` (à créer).

---

## Changelog API

### v1.0.0-alpha (Janvier 2025)
- Endpoints médias (CRUD complet)
- Endpoints tags (CRUD + attach/detach)
- Endpoints géolocalisation
- Recherche de lieux (Nominatim)
- Nearby media (Haversine)
