<?php

namespace Tests\Feature;

use App\Filament\Resources\HouseholdResource\Pages\CreateHousehold;
use App\Filament\Resources\HouseholdResource\Pages\EditHousehold;
use App\Filament\Resources\HouseholdResource\Pages\ListHouseholds;
use App\Filament\Resources\HouseholdResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\HouseholdResource\RelationManagers\PeopleRelationManager;
use App\Filament\Resources\PersonResource\Pages\EditPerson;
use App\Filament\Resources\PersonResource\Pages\ListPeople;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin des cercles : changer les cercles d'une fiche depuis « Personnes »,
 * et page « Cercles » (réglages, fiches rattachées, membres).
 */
class CircleAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Household $milou;
    private Household $mennecois;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);

        $this->milou = Household::create(['name' => 'La bande à milou', 'kind' => Household::KIND_FRIENDS, 'invite_policy' => Household::INVITE_ADMIN, 'created_by' => $this->admin->id]);
        $this->mennecois = Household::create(['name' => 'Les Menneçois', 'kind' => Household::KIND_FRIENDS, 'invite_policy' => Household::INVITE_ADMIN, 'created_by' => $this->admin->id]);
    }

    public function test_deplacer_une_fiche_vers_le_bon_cercle_depuis_personnes(): void
    {
        $gael = Person::factory()->create(['user_id' => $this->admin->id, 'first_name' => 'Gaël', 'in_family_directory' => false]);
        $gael->circles()->attach($this->mennecois->id, ['added_by' => $this->admin->id]);

        Livewire::test(EditPerson::class, ['record' => $gael->getRouteKey()])
            ->assertFormSet(['in_family_directory' => false, 'circles' => [$this->mennecois->id]])
            ->fillForm(['circles' => [$this->milou->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->milou->id], $gael->circles()->pluck('households.id')->all());
        $this->assertSame($this->admin->id, $gael->circles()->first()->pivot->added_by);
        $this->assertFalse($gael->fresh()->in_family_directory);
    }

    public function test_liste_des_personnes_filtrable_par_cercle(): void
    {
        $inMilou = Person::factory()->create(['user_id' => $this->admin->id, 'in_family_directory' => false]);
        $inMilou->circles()->attach($this->milou->id);
        $family = Person::factory()->create(['user_id' => $this->admin->id, 'in_family_directory' => true]);

        Livewire::test(ListPeople::class)
            ->filterTable('cercle', $this->milou->id)
            ->assertCanSeeTableRecords([$inMilou])
            ->assertCanNotSeeTableRecords([$family]);
    }

    public function test_page_cercles_liste_cree_et_edite(): void
    {
        Livewire::test(ListHouseholds::class)->assertCanSeeTableRecords([$this->milou, $this->mennecois]);

        Livewire::test(CreateHousehold::class)
            ->fillForm(['name' => 'Les voisins', 'kind' => Household::KIND_FRIENDS, 'invite_policy' => Household::INVITE_ADMIN, 'created_by' => $this->admin->id])
            ->call('create')
            ->assertHasNoFormErrors();
        $voisins = Household::where('name', 'Les voisins')->firstOrFail();
        $this->assertTrue($voisins->isMember($this->admin), 'le responsable devient membre');

        Livewire::test(EditHousehold::class, ['record' => $this->milou->getRouteKey()])
            ->fillForm(['name' => 'La bande à Milou', 'invite_policy' => Household::INVITE_EVERYONE])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('La bande à Milou', $this->milou->fresh()->name);
        $this->assertSame(Household::INVITE_EVERYONE, $this->milou->fresh()->invite_policy);
    }

    public function test_rattacher_et_retirer_des_fiches_d_un_cercle(): void
    {
        $person = Person::factory()->create(['user_id' => $this->admin->id]);

        Livewire::test(PeopleRelationManager::class, ['ownerRecord' => $this->milou, 'pageClass' => EditHousehold::class])
            ->callTableAction('attach', data: ['recordId' => [$person->id]])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($this->milou->people()->whereKey($person->id)->exists());
        $this->assertSame($this->admin->id, $this->milou->people()->first()->pivot->added_by);

        Livewire::test(PeopleRelationManager::class, ['ownerRecord' => $this->milou, 'pageClass' => EditHousehold::class])
            ->callTableAction('detach', $person);
        $this->assertFalse($this->milou->people()->whereKey($person->id)->exists());

        $this->assertFalse(PeopleRelationManager::canViewForRecord(Household::create(['name' => 'Famille', 'kind' => Household::KIND_FAMILY, 'invite_policy' => Household::INVITE_EVERYONE, 'created_by' => $this->admin->id]), EditHousehold::class));
    }

    public function test_ajouter_et_retirer_un_membre(): void
    {
        $sarahFiche = Person::factory()->create(['user_id' => $this->admin->id]);
        $sarah = User::factory()->create(['person_id' => $sarahFiche->id]);
        $this->milou->addMember($this->admin, $this->admin);

        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->milou, 'pageClass' => EditHousehold::class])
            ->callTableAction('addMember', data: ['user_id' => $sarah->id])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($this->milou->isMember($sarah));
        $this->assertTrue($this->milou->people()->whereKey($sarahFiche->id)->exists(), 'sa fiche suit dans un cercle d\'amis');

        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->milou, 'pageClass' => EditHousehold::class])
            ->callTableAction('remove', $sarah, data: ['photos' => 'keep'])
            ->assertHasNoTableActionErrors();
        $this->assertFalse($this->milou->fresh()->isMember($sarah));

        // Dernier membre : refusé, le cercle n'est pas fermé par ricochet.
        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->milou, 'pageClass' => EditHousehold::class])
            ->callTableAction('remove', $this->admin, data: ['photos' => 'keep']);
        $this->assertNotNull($this->milou->fresh());
        $this->assertTrue($this->milou->fresh()->isMember($this->admin));
    }
}
