<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Premiers pas : le mini-tuto d'envoi et la visite guidée ne s'ouvrent
 * d'office qu'une fois (préférences « onboarding.* » partagées au front).
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_steps_are_remembered_and_shared_with_the_front(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.onboarding', [])->where('auth.user.is_guest', false));

        $this->actingAs($user)->postJson('/onboarding/upload_tutorial')->assertOk();
        $this->actingAs($user)->postJson('/onboarding/tour')->assertOk();

        $prefs = $user->fresh()->preferences['onboarding'];
        $this->assertArrayHasKey('upload_tutorial', $prefs);
        $this->assertArrayHasKey('tour', $prefs);

        $this->actingAs($user->fresh())->get('/media/upload')
            ->assertInertia(fn ($page) => $page->component('Media/Upload', false)
                ->has('auth.user.onboarding.upload_tutorial')
                ->has('auth.user.onboarding.tour'));
    }

    public function test_unknown_step_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/onboarding/autre')->assertNotFound();
    }

    public function test_guest_and_tablet_accounts_cannot_write_onboarding(): void
    {
        $person = Person::factory()->create();
        $guest = User::factory()->create(['guest_expires_at' => now()->addDay(), 'guest_person_id' => $person->id]);
        $this->actingAs($guest)->postJson('/onboarding/tour')->assertForbidden();

        $tablet = User::factory()->create(['simple_mode' => true]);
        $this->actingAs($tablet)->postJson('/onboarding/tour')->assertForbidden();
    }

    public function test_installed_app_is_recorded_and_shown_to_the_admin(): void
    {
        $member = User::factory()->create(['name' => 'Léa Martin']);
        $this->actingAs($member)->postJson('/onboarding/pwa')->assertOk();
        $this->assertNotNull(data_get($member->fresh()->preferences, 'onboarding.pwa'));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertSee('App installée')
            ->assertSee(now()->format('d/m/Y'));
    }
}
