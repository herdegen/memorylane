# Guide de Test - MemoryLane

## Vue d'ensemble

MemoryLane dispose d'une suite de tests complète couvrant toutes les fonctionnalités principales avec PHPUnit et Laravel Testing.

## Statistiques de Tests

- **Total tests** : 61
- **Total assertions** : ~100
- **Couverture** : ~85% (fonctionnalités principales)

### Répartition par Suite

| Suite | Tests | Assertions | Statut |
|-------|-------|------------|--------|
| TagTest | 11 | ~25 | ✅ Passing |
| MediaTest | 11 | ~24 | ✅ Passing |
| TagAttachmentTest | 11 | ~31 | ✅ Passing |
| MapControllerTest | 11 | ~28 | ✅ Passing |
| FilamentAdminTest | 17 | ~26 | ✅ Passing |

## Configuration

### Environment de Test

Le fichier `phpunit.xml` configure l'environnement :

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="memorylane_test"/>
<env name="CACHE_DRIVER" value="array"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="QUEUE_DRIVER" value="sync"/>
```

### Base de Données de Test

Une base PostgreSQL dédiée est utilisée :

```bash
# Créer la DB de test (une seule fois)
docker-compose exec postgres createdb -U memorylane memorylane_test

# Les migrations sont exécutées automatiquement via RefreshDatabase
```

## Exécuter les Tests

### Commandes de Base

```bash
# Tous les tests
docker-compose exec app php artisan test

# Suite spécifique
docker-compose exec app php artisan test --filter=TagTest
docker-compose exec app php artisan test --filter=MediaTest
docker-compose exec app php artisan test --filter=MapControllerTest
docker-compose exec app php artisan test --filter=FilamentAdminTest

# Test spécifique
docker-compose exec app php artisan test --filter=test_can_create_tag

# Verbose mode
docker-compose exec app php artisan test -v

# Stop on failure
docker-compose exec app php artisan test --stop-on-failure
```

### Tests en Parallèle

```bash
# Plus rapide, utilise plusieurs processus
docker-compose exec app php artisan test --parallel

# Spécifier nombre de processus
docker-compose exec app php artisan test --parallel --processes=4
```

### Couverture de Code

```bash
# Coverage basique
docker-compose exec app php artisan test --coverage

# Coverage détaillée (requiert Xdebug)
docker-compose exec app php artisan test --coverage --min=80

# Export HTML
docker-compose exec app php vendor/bin/phpunit --coverage-html coverage/
```

## Suites de Tests

### 1. TagTest (11 tests)

**Fichier :** `tests/Feature/TagTest.php`

**Ce qui est testé :**
- ✅ Liste des tags (index)
- ✅ Création de tag
- ✅ Mise à jour de tag
- ✅ Suppression de tag
- ✅ Validation (nom requis, unique)
- ✅ Génération automatique du slug
- ✅ Type de tag par défaut
- ✅ Recherche de tags
- ✅ Couleur hexadécimale

**Exemple :**
```php
public function test_can_create_tag(): void
{
    $response = $this->postJson('/tags', [
        'name' => 'Vacances',
        'type' => 'general',
        'color' => '#FF5733',
    ]);

    $response->assertStatus(201)
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('tags', [
        'name' => 'Vacances',
        'slug' => 'vacances',
        'type' => 'general',
        'color' => '#FF5733',
    ]);
}
```

---

### 2. MediaTest (11 tests)

**Fichier :** `tests/Feature/MediaTest.php`

**Ce qui est testé :**
- ✅ Liste des médias (pagination)
- ✅ Upload de média
- ✅ Affichage détails média
- ✅ Suppression média (soft delete)
- ✅ Téléchargement fichier
- ✅ Validation upload (taille, type MIME)
- ✅ Extraction métadonnées EXIF
- ✅ Génération conversions (thumbnails)
- ✅ Filtrage par type
- ✅ Relations (user, tags, metadata)

**Exemple :**
```php
public function test_can_upload_media(): void
{
    Storage::fake('local');

    $file = UploadedFile::fake()->image('test.jpg', 1920, 1080);

    $response = $this->postJson('/media', [
        'file' => $file,
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('media', [
        'original_name' => 'test.jpg',
        'type' => 'photo',
        'mime_type' => 'image/jpeg',
    ]);

    Storage::disk('local')->assertExists('media/2025/01/...');
}
```

---

### 3. TagAttachmentTest (11 tests)

**Fichier :** `tests/Feature/TagAttachmentTest.php`

**Ce qui est testé :**
- ✅ Attacher tag à média
- ✅ Détacher tag de média
- ✅ Idempotence (attacher 2× = OK)
- ✅ Validation (UUIDs valides)
- ✅ Champs requis
- ✅ Récupérer tags d'un média
- ✅ Plusieurs tags par média
- ✅ Un tag sur plusieurs médias
- ✅ Relations polymorphiques

**Exemple :**
```php
public function test_can_attach_tag_to_media(): void
{
    $media = Media::factory()->create(['user_id' => $this->user->id]);
    $tag = Tag::factory()->create(['name' => 'Vacation']);

    $response = $this->postJson('/tags/attach', [
        'media_id' => $media->id,
        'tag_id' => $tag->id,
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('media_tag', [
        'media_id' => $media->id,
        'tag_id' => $tag->id,
    ]);
}
```

---

### 4. MapControllerTest (11 tests)

**Fichier :** `tests/Feature/MapControllerTest.php`

**Ce qui est testé :**
- ✅ Page carte (map index)
- ✅ Récupérer médias géolocalisés
- ✅ Filtrage par type (photo/video)
- ✅ Filtrage par tags
- ✅ Mise à jour géolocalisation
- ✅ Suppression géolocalisation
- ✅ Validation latitude (-90 à 90)
- ✅ Validation longitude (-180 à 180)
- ✅ Recherche médias proches (Haversine)
- ✅ Recherche de lieux (Nominatim)
- ✅ Calculs de distance

**Exemple :**
```php
public function test_can_get_nearby_media(): void
{
    // Paris location
    $media1 = Media::factory()->create(['user_id' => $this->user->id]);
    $media1->metadata()->create([
        'latitude' => 48.8566,
        'longitude' => 2.3522,
    ]);

    // Near Paris (within 5km)
    $media2 = Media::factory()->create(['user_id' => $this->user->id]);
    $media2->metadata()->create([
        'latitude' => 48.8606,
        'longitude' => 2.3376,
    ]);

    // Lyon (far away)
    $media3 = Media::factory()->create(['user_id' => $this->user->id]);
    $media3->metadata()->create([
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);

    $response = $this->getJson('/map/nearby?latitude=48.8566&longitude=2.3522&radius=5');

    $response->assertStatus(200);

    $json = $response->json();
    $this->assertCount(2, $json); // Only Paris and nearby, not Lyon
}
```

---

### 5. FilamentAdminTest (17 tests)

**Fichier :** `tests/Feature/FilamentAdminTest.php`

**Ce qui est testé :**
- ✅ Page login admin
- ✅ Accès panel admin (authentifié)
- ✅ Redirection guest (non authentifié)
- ✅ Liste médias admin
- ✅ Page création média
- ✅ Page édition média
- ✅ Liste tags admin
- ✅ Page création tag
- ✅ Page édition tag
- ✅ Liste utilisateurs admin
- ✅ Page création utilisateur
- ✅ Page édition utilisateur
- ✅ Affichage données (colonnes)
- ✅ Dashboard widgets
- ✅ Soft deletes (corbeille)
- ✅ Filters et recherche

**Exemple :**
```php
public function test_admin_can_view_media_list(): void
{
    Media::factory()->count(3)->create(['user_id' => $this->adminUser->id]);

    $response = $this->actingAs($this->adminUser)
        ->get('/admin/media');

    $response->assertStatus(200);
}

public function test_guest_cannot_access_admin_panel(): void
{
    $response = $this->get('/admin');

    $response->assertRedirect('/admin/login');
}
```

---

## Patterns de Test

### RefreshDatabase

Toutes les suites utilisent `RefreshDatabase` pour réinitialiser la DB :

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyTest extends TestCase
{
    use RefreshDatabase;

    // Chaque test démarre avec une DB propre
}
```

### Factories

Les factories génèrent des données de test réalistes :

```php
// User factory
$user = User::factory()->create([
    'email' => 'custom@example.com',
]);

// Media factory (photo)
$photo = Media::factory()->photo()->create([
    'user_id' => $user->id,
]);

// Media factory (video)
$video = Media::factory()->video()->create();

// Tag factory
$tag = Tag::factory()->create([
    'name' => 'Custom Tag',
    'type' => 'location',
]);
```

### Assertions Courantes

```php
// HTTP status
$response->assertStatus(200);
$response->assertOk();
$response->assertCreated(); // 201
$response->assertNoContent(); // 204

// JSON structure
$response->assertJson(['success' => true]);
$response->assertJsonStructure(['data', 'links', 'meta']);
$response->assertJsonCount(10, 'data');

// Database
$this->assertDatabaseHas('media', ['id' => $media->id]);
$this->assertDatabaseMissing('media', ['id' => $deleted->id]);
$this->assertSoftDeleted($media);

// Validation errors
$response->assertStatus(422);
$response->assertJsonValidationErrors(['field_name']);

// Redirects
$response->assertRedirect('/login');

// File system
Storage::disk('local')->assertExists('path/to/file.jpg');
Storage::disk('local')->assertMissing('path/to/deleted.jpg');
```

## Mocking & Fakes

### Storage Fake

```php
use Illuminate\Support\Facades\Storage;

public function test_file_upload()
{
    Storage::fake('local');

    // Upload file...

    Storage::disk('local')->assertExists('media/file.jpg');
}
```

### HTTP Fake (External APIs)

```php
use Illuminate\Support\Facades\Http;

public function test_nominatim_search()
{
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            ['display_name' => 'Paris', 'lat' => '48.8566', 'lon' => '2.3522']
        ], 200),
    ]);

    // Call search...
}
```

### Queue Fake

```php
use Illuminate\Support\Facades\Queue;

public function test_job_dispatched()
{
    Queue::fake();

    // Dispatch job...

    Queue::assertPushed(GenerateMediaConversions::class);
}
```

## Best Practices

### 1. Test Isolation

Chaque test doit être indépendant :

```php
✅ Good
public function test_something()
{
    $user = User::factory()->create();
    // Test avec cet utilisateur
}

❌ Bad
protected $user; // Shared state entre tests

public function test_something()
{
    // Utilise $this->user (peut créer dépendances)
}
```

### 2. Naming Conventions

```php
// Descriptif et explicite
public function test_can_create_tag_with_valid_data(): void
public function test_cannot_create_tag_without_name(): void
public function test_guest_cannot_access_admin_panel(): void
```

### 3. Arrange-Act-Assert (AAA)

```php
public function test_can_attach_tag_to_media(): void
{
    // Arrange
    $media = Media::factory()->create();
    $tag = Tag::factory()->create();

    // Act
    $response = $this->postJson('/tags/attach', [
        'media_id' => $media->id,
        'tag_id' => $tag->id,
    ]);

    // Assert
    $response->assertStatus(200);
    $this->assertDatabaseHas('media_tag', [...]);
}
```

### 4. Test Data Builders

```php
// Helper method pour données complexes
protected function createGeolocatedMedia(array $attributes = []): Media
{
    $media = Media::factory()->create($attributes);
    $media->metadata()->create([
        'latitude' => 48.8566,
        'longitude' => 2.3522,
    ]);
    return $media;
}
```

## Debugging Tests

### Dump & Die

```php
$response->dump(); // Affiche le contenu
$response->dumpHeaders(); // Affiche les headers
$response->dd(); // Dump and die
```

### Log SQL Queries

```php
\DB::enableQueryLog();

// Code testé...

dd(\DB::getQueryLog());
```

### Assert Multiple

```php
$response->assertStatus(200);
dump($response->json()); // Voir structure avant assertion
$response->assertJson([...]);
```

## CI/CD Integration

### GitHub Actions (exemple)

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Run tests
        run: docker-compose exec -T app php artisan test
```

### Pre-commit Hook

```bash
# .git/hooks/pre-commit
#!/bin/sh
docker-compose exec -T app php artisan test
```

## Performance

### Temps d'exécution

```
Suite           | Tests | Time
----------------|-------|-------
TagTest         | 11    | 1.2s
MediaTest       | 11    | 1.8s
TagAttachment   | 11    | 1.3s
MapController   | 11    | 2.0s
FilamentAdmin   | 17    | 2.6s
----------------|-------|-------
Total           | 61    | ~9s
```

### Optimisations

- Utiliser `--parallel` pour diviser par 2-3
- Database in-memory (SQLite) pour tests rapides
- Limiter `RefreshDatabase` si possible
- Cacher les factories courantes

## Annexes

### Commandes Utiles

```bash
# Créer nouveau test
php artisan make:test MyFeatureTest

# Test unitaire
php artisan make:test MyUnitTest --unit

# Lister tous les tests
php artisan test --list-tests

# PHPUnit directement
vendor/bin/phpunit

# Code style (PHP CS Fixer)
vendor/bin/php-cs-fixer fix
```

### Ressources

- [Laravel Testing Docs](https://laravel.com/docs/testing)
- [PHPUnit Manual](https://phpunit.de/manual/current/en/index.html)
- [Filament Testing](https://filamentphp.com/docs/panels/testing)

## Vérification Automatique (CI Local)

Pour détecter rapidement les erreurs de build (comme une dépendance manquante ou une erreur de syntaxe JS) avant de commit, un script d'automatisation est disponible.

### Script `verify.sh`

Ce script exécute la chaîne complète de validation :
1. **Build Frontend** (`npm run build`) : Vérifie la compilation des assets, le SCSS, et les dépendances JS.
2. **Tests Backend** (`php artisan test`) : Vérifie la logique métier PHP.

```bash
# Rendre le script exécutable
chmod +x verify.sh

# Lancer la vérification
./verify.sh
```
