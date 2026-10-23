<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\User;
use App\Services\Demo\DemoBuilder;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site de démonstration : entrée en un clic dans le compte partagé, actions
 * bloquées (envois, imports, e-mails, suppressions lourdes), garde-fous.
 */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    private User $visitor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['demo.enabled' => true, 'demo.user_email' => 'demo@memory-lane.fr']);
        $this->visitor = User::factory()->create(['email' => 'demo@memory-lane.fr', 'role' => 'user']);
    }

    public function test_entrer_dans_la_demo_connecte_le_compte_partage(): void
    {
        $this->post('/demo/enter')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->visitor);
    }

    public function test_hors_demo_pas_d_entree_ni_de_blocage(): void
    {
        config(['demo.enabled' => false]);

        $this->post('/demo/enter')->assertNotFound();
        $this->assertGuest();

        $album = Album::factory()->create(['user_id' => $this->visitor->id]);
        $this->actingAs($this->visitor)->deleteJson("/albums/{$album->id}")->assertSuccessful();
    }

    public function test_envois_imports_et_suppressions_refuses(): void
    {
        $this->actingAs($this->visitor);

        $this->postJson('/media/uploads/initiate', [])->assertForbidden()->assertJsonPath('demo', true);
        $this->postJson('/media', [])->assertForbidden();
        $this->postJson('/google-drive/import', [])->assertForbidden();
        $this->putJson('/profile', ['name' => 'Pirate', 'email' => 'x@y.z'])->assertForbidden();

        $album = Album::factory()->create(['user_id' => $this->visitor->id]);
        $this->deleteJson("/albums/{$album->id}")->assertForbidden();
        $this->assertNotNull($album->fresh(), 'l\'album n\'a pas été supprimé');

        // Page (non JSON) : retour avec un message, pas une page d'erreur.
        $this->from('/media/upload')->get('/google-photos/connect')
            ->assertRedirect('/media/upload')
            ->assertSessionHas('error');
    }

    public function test_toutes_les_routes_bloquees_existent(): void
    {
        $known = collect(app('router')->getRoutes()->getRoutesByName())->keys();
        $unknown = collect(config('demo.blocked_routes'))->diff($known)->values()->all();

        $this->assertSame([], $unknown, 'noms de routes inconnus dans config/demo.php');
    }

    public function test_la_page_sait_qu_elle_est_en_demo(): void
    {
        $this->actingAs($this->visitor)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('demo', true));
    }

    public function test_construction_refusee_hors_base_de_demo(): void
    {
        // La base de test ne s'appelle pas « …demo… » : refus, rien n'est effacé.
        $this->expectException(\RuntimeException::class);
        DemoBuilder::assertDemoDatabase();
    }

    public function test_cles_s3_prefixees_par_la_racine_du_disque(): void
    {
        config(['filesystems.default' => 'scaleway', 'filesystems.disks.scaleway.root' => 'demo']);
        $this->assertSame('demo/media/a.jpg', (new S3Service)->objectKey('media/a.jpg'));

        config(['filesystems.disks.scaleway.root' => '']);
        $this->assertSame('media/a.jpg', (new S3Service)->objectKey('media/a.jpg'), 'prod : inchangé');
    }
}
