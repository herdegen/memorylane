# Architecture MemoryLane

## Vue d'ensemble

MemoryLane est construit sur une architecture moderne et scalable basée sur Docker, Laravel, Vue.js et PostgreSQL.

## Diagramme d'architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Client (Browser)                      │
│                 Vue 3 + Inertia.js                      │
└──────────────────┬──────────────────────────────────────┘
                   │ HTTP/HTTPS
                   ▼
┌─────────────────────────────────────────────────────────┐
│                   Nginx (Reverse Proxy)                  │
│            Static assets + PHP-FPM routing               │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│               Laravel 11 (PHP-FPM)                       │
│  ┌────────────┬──────────────┬───────────────────┐     │
│  │Controllers │   Services   │   Jobs/Queues     │     │
│  └────────────┴──────────────┴───────────────────┘     │
│  ┌────────────┬──────────────┬───────────────────┐     │
│  │  Models    │  Resources   │   Middleware      │     │
│  └────────────┴──────────────┴───────────────────┘     │
└──────────┬──────────────┬─────────────┬────────────────┘
           │              │             │
           ▼              ▼             ▼
    ┌──────────┐   ┌──────────┐  ┌──────────┐
    │PostgreSQL│   │  Redis   │  │ S3 Storage│
    │    16    │   │    7     │  │ Scaleway  │
    └──────────┘   └──────────┘  └──────────┘
```

## Stack Technologique

### Backend Layer
- **Framework** : Laravel 11.x
- **Langage** : PHP 8.3
- **Database** : PostgreSQL 16 (avec UUID)
- **Cache/Queue** : Redis 7
- **Storage** : S3-compatible (Scaleway)

### Frontend Layer
- **Framework** : Vue 3 (Composition API)
- **Build Tool** : Vite 5
- **Bridge** : Inertia.js
- **Styling** : Tailwind CSS 3
- **State** : Pinia

### Infrastructure
- **Conteneurisation** : Docker & Docker Compose
- **Web Server** : Nginx
- **Admin Panel** : Filament v3.3
- **Queue Monitoring** : Laravel Horizon

## Architecture des Composants

### Backend Architecture

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/        # API & Page Controllers
│   │   ├── Middleware/         # Custom middleware
│   │   └── Requests/           # Form requests
│   ├── Models/                 # Eloquent models
│   ├── Services/               # Business logic
│   │   ├── MediaService.php    # Media processing
│   │   ├── S3Service.php       # S3 operations
│   │   └── FaceRecognition.php # AI services
│   ├── Jobs/                   # Async jobs
│   │   ├── GenerateMediaConversions.php
│   │   └── ExtractMediaMetadata.php
│   ├── Filament/               # Admin panel
│   │   ├── Resources/
│   │   ├── Pages/
│   │   └── Widgets/
│   └── Providers/
├── database/
│   ├── migrations/             # Database migrations
│   └── factories/              # Model factories
├── resources/
│   ├── js/                     # Vue.js application
│   │   ├── Components/         # Reusable components
│   │   ├── Pages/              # Inertia pages
│   │   ├── Layouts/            # App layouts
│   │   └── Stores/             # Pinia stores
│   └── views/                  # Blade templates
├── routes/
│   └── web.php                 # Web routes
└── tests/
    └── Feature/                # Feature tests
```

### Frontend Architecture

```
resources/js/
├── Components/
│   ├── MediaCard.vue           # Media display card
│   ├── TagInput.vue            # Tag management
│   ├── GeolocationEditor.vue   # GPS editor
│   └── UploadDropzone.vue      # File upload
├── Pages/
│   ├── Dashboard.vue           # Home dashboard
│   ├── Media/
│   │   ├── Index.vue           # Media gallery
│   │   ├── Show.vue            # Media details
│   │   └── Create.vue          # Upload page
│   └── Map/
│       └── Index.vue           # Map view
├── Layouts/
│   └── AppLayout.vue           # Main layout
└── Stores/
    └── mediaStore.js           # Media state
```

## Patterns et Principes

### Backend Patterns

1. **Repository Pattern**
   - Services encapsulent la logique métier
   - Séparation contrôleurs/services
   - Réutilisabilité du code

2. **Job Queue Pattern**
   - Traitement asynchrone des médias
   - Génération de thumbnails en arrière-plan
   - Extraction métadonnées EXIF

3. **Service Provider Pattern**
   - Configuration centralisée
   - Dependency injection
   - Filament panel provider

4. **Policy Pattern** (à venir)
   - Authorization basée sur les rôles
   - Permissions granulaires

### Frontend Patterns

1. **Component Composition**
   - Composants réutilisables
   - Props et événements
   - Slots pour flexibilité

2. **Reactive State**
   - Pinia stores pour état global
   - Computed properties
   - Watchers pour side effects

3. **Server-Driven UI**
   - Inertia.js pour navigation
   - SSR pour SEO
   - Hydratation côté client

## Flux de Données

### Upload de Média

```
1. User drags file → UploadDropzone.vue
2. Vue emits upload event
3. MediaController@store receives request
4. MediaService validates file
5. S3Service uploads to storage
6. Media model created in DB
7. Job dispatched: GenerateMediaConversions
8. Job dispatched: ExtractMediaMetadata
9. Response sent to client
10. Client updates UI
```

### Affichage Galerie

```
1. User visits /media → Inertia request
2. MediaController@index queries DB
3. Eager loading: user, tags, metadata
4. Pagination (24 per page)
5. Inertia renders Media/Index.vue
6. Vue mounts, displays MediaCards
7. LazyLoad images as user scrolls
```

### Tagging de Média

```
1. User types in TagInput → autocomplete
2. TagController@index provides suggestions
3. User selects/creates tag
4. TagController@attach called
5. Pivot table media_tag updated
6. UI updates reactively
```

## Scalabilité

### Horizontal Scaling
- **App containers** : Peut être dupliqué derrière load balancer
- **Queue workers** : Scalable indépendamment
- **Storage** : S3 géré par Scaleway (illimité)

### Vertical Scaling
- **PostgreSQL** : Support jusqu'à 1To+ de data
- **Redis** : Cache partagé entre instances
- **CDN** : Pour distribution assets statiques (futur)

## Sécurité

### Backend
- CSRF protection (VerifyCsrfToken)
- XSS prevention (Blade escaping)
- SQL Injection (Eloquent ORM)
- File upload validation (MIME types)
- Rate limiting (à implémenter)

### Frontend
- Input sanitization
- Secure cookies
- HTTPS only (production)

### Infrastructure
- Environment variables (.env)
- Secrets management (Docker secrets)
- Network isolation (Docker networks)

## Performance

### Optimisations Backend
- Eloquent eager loading (N+1 queries)
- Query caching avec Redis
- Asset compilation (Vite)
- Lazy loading des relations

### Optimisations Frontend
- Code splitting (Vite)
- Lazy loading images
- Virtual scrolling (galerie)
- Debouncing (recherche)
- Progressive Web App (PWA - futur)

## Monitoring & Observabilité

### Logs
- Laravel logs : `storage/logs/laravel.log`
- Nginx logs : `docker/nginx/logs/`
- Docker logs : `docker-compose logs`

### Queues
- Laravel Horizon : http://localhost:8000/horizon
- Real-time monitoring
- Failed job tracking

### Métriques (à venir)
- Prometheus
- Grafana dashboards
- Alert manager

## Annexes

### Conventions de Code
- PSR-12 pour PHP
- ESLint + Prettier pour JavaScript
- Tailwind pour CSS

### Documentation Code
- PHPDoc pour classes/méthodes
- JSDoc pour fonctions complexes
- README dans chaque module

### Versioning
- Semantic Versioning (SemVer)
- Git Flow workflow
- Feature branches
