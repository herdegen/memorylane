<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Services\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_connects_as_a_member_and_comes_back(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)
            ->callTableAction('impersonate', $member)
            ->assertRedirect('/dashboard');

        $this->assertTrue(auth()->user()->is($member));
        $this->assertSame($admin->id, session(Impersonation::SESSION_KEY));
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('impersonating.name', $member->name));
        $this->get('/admin/users')->assertForbidden();

        $this->post('/impersonate/leave')->assertRedirect('/admin/users');
        $this->assertTrue(auth()->user()->is($admin));
        $this->assertNull(session(Impersonation::SESSION_KEY));
        // Retour dans l'admin sans être déconnecté par le contrôle de session.
        $this->get('/admin/users')->assertOk();
        $this->assertTrue(auth()->user()->is($admin));
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('impersonating', null));
    }

    public function test_a_member_cannot_impersonate(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(Impersonation::class)->start($member, $other);
    }

    public function test_leaving_without_impersonation_logs_out(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->post('/impersonate/leave')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_banner_route_works_from_tablet_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $tablet = User::factory()->create(['simple_mode' => true]);

        $this->actingAs($admin);
        app(Impersonation::class)->start($admin, $tablet);

        $this->post('/impersonate/leave')->assertRedirect('/admin/users');
        $this->assertTrue(auth()->user()->is($admin));
    }
}
