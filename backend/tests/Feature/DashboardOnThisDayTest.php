<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOnThisDayTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Les médias pris le même jour les années précédentes apparaissent,
     * groupés par année (plus récente d'abord).
     */
    public function test_dashboard_shows_memories_from_same_day_in_previous_years(): void
    {
        $threeYearsAgo = Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => now()->subYears(3)->setTime(14, 30),
        ]);
        $oneYearAgo = Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => now()->subYear()->setTime(10, 0),
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('onThisDay', 2)
            ->where('onThisDay.0.years_ago', 1)
            ->where('onThisDay.0.media.0.id', $oneYearAgo->id)
            ->where('onThisDay.1.years_ago', 3)
            ->where('onThisDay.1.media.0.id', $threeYearsAgo->id)
        );
    }

    /**
     * Un média pris un autre jour n'apparaît pas.
     */
    public function test_dashboard_excludes_media_from_other_days(): void
    {
        Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => now()->subYear()->subDays(10),
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('onThisDay', 0));
    }

    /**
     * Un média pris aujourd'hui (même année) n'apparaît pas : ce n'est pas
     * encore un souvenir.
     */
    public function test_dashboard_excludes_media_taken_today(): void
    {
        Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('onThisDay', 0));
    }

    /**
     * Les médias sans taken_at sont ignorés.
     */
    public function test_dashboard_ignores_media_without_taken_at(): void
    {
        Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => null,
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('onThisDay', 0));
    }
}
