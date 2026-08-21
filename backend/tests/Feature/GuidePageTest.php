<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuidePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_guide_exige_une_authentification(): void
    {
        $this->get('/guide')->assertRedirect('/login');
    }

    public function test_le_guide_se_rend_pour_un_compte_connecte(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/guide')
            ->assertOk();
    }
}
