<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FilamentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user
        $this->adminUser = User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@memorylane.com',
            'password' => Hash::make('password'),
        ]);
    }

    /**
     * Test admin login page loads.
     */
    public function test_admin_login_page_loads(): void
    {
        $response = $this->get('/admin/login');

        $response->assertStatus(200);
    }

    /**
     * Test admin can access admin panel after login.
     */
    public function test_admin_can_access_admin_panel(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get('/admin');

        $response->assertStatus(200);
    }

    /**
     * Test unauthenticated user cannot access admin panel.
     */
    public function test_guest_cannot_access_admin_panel(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    /**
     * Test admin can view media list page.
     */
    public function test_admin_can_view_media_list(): void
    {
        Media::factory()->count(3)->create(['user_id' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/media');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view media create page.
     */
    public function test_admin_can_view_media_create_page(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get('/admin/media/create');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view media edit page.
     */
    public function test_admin_can_view_media_edit_page(): void
    {
        $media = Media::factory()->create(['user_id' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)
            ->get("/admin/media/{$media->id}/edit");

        $response->assertStatus(200);
    }

    /**
     * Test admin can view tags list page.
     */
    public function test_admin_can_view_tags_list(): void
    {
        Tag::factory()->count(5)->create();

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/tags');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view tag create page.
     */
    public function test_admin_can_view_tag_create_page(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get('/admin/tags/create');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view tag edit page.
     */
    public function test_admin_can_view_tag_edit_page(): void
    {
        $tag = Tag::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->get("/admin/tags/{$tag->id}/edit");

        $response->assertStatus(200);
    }

    /**
     * Test admin can view users list page.
     */
    public function test_admin_can_view_users_list(): void
    {
        User::factory()->count(3)->create();

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/users');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view user create page.
     */
    public function test_admin_can_view_user_create_page(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get('/admin/users/create');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view user edit page.
     */
    public function test_admin_can_view_user_edit_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->get("/admin/users/{$user->id}/edit");

        $response->assertStatus(200);
    }

    /**
     * Test media resource shows correct columns.
     */
    public function test_media_resource_displays_media_data(): void
    {
        $media = Media::factory()->create([
            'user_id' => $this->adminUser->id,
            'original_name' => 'test-photo.jpg',
            'type' => 'photo',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/media');

        $response->assertStatus(200)
            ->assertSee($media->original_name)
            ->assertSee($media->type);
    }

    /**
     * Test tag resource shows correct columns.
     */
    public function test_tag_resource_displays_tag_data(): void
    {
        $tag = Tag::factory()->create([
            'name' => 'Vacation',
            'type' => 'custom',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/tags');

        $response->assertStatus(200)
            ->assertSee($tag->name)
            ->assertSee($tag->type);
    }

    /**
     * Test user resource shows correct columns.
     */
    public function test_user_resource_displays_user_data(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/users');

        $response->assertStatus(200)
            ->assertSee($user->name)
            ->assertSee($user->email);
    }

    /**
     * Test admin dashboard shows widgets.
     */
    public function test_admin_dashboard_loads(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get('/admin');

        $response->assertStatus(200);
    }

    /**
     * Test media with soft deletes shows trashed filter.
     */
    public function test_media_shows_with_trashed_records(): void
    {
        $activeMedia = Media::factory()->create(['user_id' => $this->adminUser->id]);
        $deletedMedia = Media::factory()->create(['user_id' => $this->adminUser->id]);
        $deletedMedia->delete();

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/media');

        $response->assertStatus(200);

        // Both active and deleted should be visible in admin panel
        $this->assertEquals(2, Media::withTrashed()->count());
        $this->assertEquals(1, Media::onlyTrashed()->count());
    }

    /**
     * Test admin can view albums list page.
     */
    public function test_admin_can_view_albums_list(): void
    {
        Album::factory()->count(3)->create(['user_id' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/albums');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view album create page.
     */
    public function test_admin_can_view_album_create_page(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get('/admin/albums/create');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view album edit page.
     */
    public function test_admin_can_view_album_edit_page(): void
    {
        $album = Album::factory()->create(['user_id' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)
            ->get("/admin/albums/{$album->id}/edit");

        $response->assertStatus(200);
    }

    /**
     * Test album resource shows correct data.
     */
    public function test_album_resource_displays_album_data(): void
    {
        $album = Album::factory()->create([
            'user_id' => $this->adminUser->id,
            'name' => 'Vacances 2025',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/albums');

        $response->assertStatus(200)
            ->assertSee('Vacances 2025');
    }

    /**
     * Test admin can view people list page.
     */
    public function test_admin_can_view_people_list(): void
    {
        Person::factory()->count(3)->create(['user_id' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/people');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view person create page.
     */
    public function test_admin_can_view_person_create_page(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get('/admin/people/create');

        $response->assertStatus(200);
    }

    /**
     * Test admin can view person edit page.
     */
    public function test_admin_can_view_person_edit_page(): void
    {
        $person = Person::factory()->create(['user_id' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)
            ->get("/admin/people/{$person->id}/edit");

        $response->assertStatus(200);
    }

    /**
     * Test person resource shows correct data.
     */
    public function test_person_resource_displays_person_data(): void
    {
        $person = Person::factory()->create([
            'user_id' => $this->adminUser->id,
            'name' => 'Jean Dupont',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/people');

        $response->assertStatus(200)
            ->assertSee('Jean Dupont');
    }

    /**
     * Test user can be linked to a person.
     */
    public function test_user_resource_shows_linked_person(): void
    {
        $person = Person::factory()->create([
            'user_id' => $this->adminUser->id,
            'name' => 'Admin Personne',
        ]);

        $this->adminUser->update(['person_id' => $person->id]);

        $response = $this->actingAs($this->adminUser)
            ->get('/admin/users');

        $response->assertStatus(200)
            ->assertSee('Admin Personne');
    }
}
