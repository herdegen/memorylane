<?php

namespace Tests\Feature;

use App\Filament\Resources\PersonResource\Pages\ListPeople;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use App\Support\Unaccent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Recherche sans accents ni casse partout : « gael » trouve « Gaël ».
 */
class UnaccentSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    }

    public function test_le_motif_est_normalise_et_echappe(): void
    {
        $this->assertSame('%gael%', Unaccent::pattern('  Gaël '));
        $this->assertSame('%50\\%%', Unaccent::pattern('50%'));
    }

    public function test_macro_dans_les_deux_sens(): void
    {
        $gael = Person::factory()->create(['user_id' => $this->admin->id, 'first_name' => 'Gaël', 'last_name' => 'Barbier']);
        Person::factory()->create(['user_id' => $this->admin->id, 'first_name' => 'Paul', 'last_name' => 'Martin']);

        $this->assertSame([$gael->id], Person::whereUnaccentLike(['first_name', 'last_name'], 'gael')->pluck('id')->all());
        $this->assertSame([$gael->id], Person::whereUnaccentLike('first_name', 'GAËL')->pluck('id')->all());
        $this->assertSame(0, Person::whereUnaccentLike('first_name', '50%')->count());
    }

    public function test_fiches_a_rattacher_a_un_cercle(): void
    {
        $circle = Household::create(['name' => 'Amis', 'kind' => Household::KIND_FRIENDS, 'invite_policy' => Household::INVITE_ADMIN, 'created_by' => $this->admin->id]);
        $circle->addMember($this->admin, $this->admin);
        $gael = Person::factory()->create(['user_id' => $this->admin->id, 'first_name' => 'Gaël', 'last_name' => 'Barbier']);

        $this->actingAs($this->admin)
            ->getJson("/households/{$circle->id}/people/candidates?q=gael")
            ->assertOk()
            ->assertJsonPath('0.id', $gael->id);
    }

    public function test_comptes_a_inviter_dans_un_cercle(): void
    {
        $circle = Household::create(['name' => 'Amis', 'kind' => Household::KIND_FRIENDS, 'invite_policy' => Household::INVITE_ADMIN, 'created_by' => $this->admin->id]);
        $circle->addMember($this->admin, $this->admin);
        $helene = User::factory()->create(['name' => 'Hélène Dupré']);

        $this->actingAs($this->admin)
            ->getJson("/households/{$circle->id}/members/candidates?q=helene dupre")
            ->assertOk()
            ->assertJsonFragment(['id' => $helene->id]);
    }

    public function test_photos_et_tags(): void
    {
        $media = Media::factory()->create(['user_id' => $this->admin->id, 'type' => 'photo', 'title' => 'Noël à Évian', 'original_name' => 'IMG_1.jpg', 'is_source' => false]);
        Media::factory()->create(['user_id' => $this->admin->id, 'type' => 'photo', 'title' => 'Plage', 'original_name' => 'IMG_2.jpg', 'is_source' => false]);
        $tag = Tag::factory()->create(['name' => 'Été']);

        $found = app(\App\Services\MediaService::class)->getFilteredMediaIds(['search' => 'noel evian'], $this->admin->id);
        $this->assertSame([$media->id], $found);

        $this->actingAs($this->admin)->getJson('/tags?search=ete')
            ->assertOk()
            ->assertJsonFragment(['id' => $tag->id]);
    }

    public function test_admin_personnes_et_utilisateurs(): void
    {
        $this->actingAs($this->admin);
        $gael = Person::factory()->create(['user_id' => $this->admin->id, 'first_name' => 'Gaël', 'last_name' => 'Barbier']);
        $paul = Person::factory()->create(['user_id' => $this->admin->id, 'first_name' => 'Paul', 'last_name' => 'Martin']);
        $elodie = User::factory()->create(['name' => 'Élodie']);

        Livewire::test(ListPeople::class)
            ->searchTable('gael')
            ->assertCanSeeTableRecords([$gael])
            ->assertCanNotSeeTableRecords([$paul]);

        Livewire::test(ListUsers::class)
            ->searchTable('elodie')
            ->assertCanSeeTableRecords([$elodie])
            ->assertCanNotSeeTableRecords([$this->admin]);
    }
}
