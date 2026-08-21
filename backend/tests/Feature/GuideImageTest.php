<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Captures du guide (GET /guide/images/{name}) : elles contiennent des photos
 * de famille → servies uniquement aux comptes connectés, noms strictement
 * contraints (pas de traversée), 404 si la vignette n'a pas été générée.
 */
class GuideImageTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('app/guide/test-vignette.webp');
        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, 'fake-webp-bytes');
    }

    protected function tearDown(): void
    {
        File::delete($this->file);

        parent::tearDown();
    }

    public function test_une_capture_exige_une_authentification(): void
    {
        $this->get('/guide/images/test-vignette')->assertRedirect('/login');
    }

    public function test_une_capture_existante_est_servie_en_prive(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/guide/images/test-vignette');

        $response->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_les_noms_hors_contrainte_sont_refuses(): void
    {
        $user = User::factory()->create();

        // Traversée, point et casse interdits par la contrainte de route
        // (pas d'extension : nginx servirait les *.webp en statique).
        $this->actingAs($user)->get('/guide/images/../secret')->assertNotFound();
        $this->actingAs($user)->get('/guide/images/x.php')->assertNotFound();
        $this->actingAs($user)->get('/guide/images/Dash')->assertNotFound();
    }

    public function test_une_capture_absente_renvoie_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/guide/images/inexistante')
            ->assertNotFound();
    }
}
