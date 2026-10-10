<?php

namespace Tests\Feature;

use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Compte invité temporaire (guest_expires_at + guest_person_id) : lien signé
 * vers une fiche, lecture seule des fiches et de l'arbre, médias limités à
 * ceux de la fiche invitée.
 */
class GuestAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Person $person;
    protected User $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'user']);
        $this->person = Person::factory()->create(['user_id' => $this->owner->id]);
        $this->guest = User::factory()->create([
            'role' => 'user',
            'guest_expires_at' => now()->addDays(7),
            'guest_person_id' => $this->person->id,
        ]);
    }

    private function inviteUrl(User $guest, Person $person, $expires = null): string
    {
        return URL::temporarySignedRoute('invite.guest', $expires ?? now()->addDay(), [
            'user' => $guest->id, 'person' => $person->id,
        ]);
    }

    public function test_signed_invite_link_logs_guest_in_and_opens_person(): void
    {
        $this->get($this->inviteUrl($this->guest, $this->person))
            ->assertRedirect(route('people.show', $this->person));

        $this->assertAuthenticatedAs($this->guest);
    }

    public function test_invite_link_is_refused_for_a_regular_account(): void
    {
        $this->get($this->inviteUrl($this->owner, $this->person))->assertForbidden();
        $this->assertGuest();
    }

    public function test_unsigned_invite_link_is_refused(): void
    {
        $this->get(route('invite.guest', ['user' => $this->guest->id, 'person' => $this->person->id]))
            ->assertForbidden();
    }

    public function test_guest_can_read_people_and_tree_only(): void
    {
        $this->actingAs($this->guest);

        $this->get(route('people.show', $this->person))->assertOk();
        $this->get(route('people.index'))->assertOk();
        $this->get(route('family-tree.index'))->assertOk();
        $this->get(route('family-tree.data'))->assertOk();

        $this->get(route('dashboard'))->assertRedirect(route('family-tree.index'));
        $this->get(route('media.index'))->assertRedirect(route('family-tree.index'));
        $this->get(route('albums.index'))->assertRedirect(route('family-tree.index'));
    }

    public function test_guest_cannot_write(): void
    {
        $this->actingAs($this->guest);

        $this->putJson(route('people.update', $this->person), ['first_name' => 'X'])->assertForbidden();
        $this->postJson(route('people.events.store', $this->person), ['title' => 'x'])->assertForbidden();
        $this->assertNotSame('X', $this->person->fresh()->first_name);
    }

    public function test_guest_sees_only_media_of_the_invited_person(): void
    {
        $tagged = Media::factory()->create(['user_id' => $this->owner->id, 'type' => 'photo']);
        $this->person->media()->attach($tagged->id);

        $event = LifeEvent::create([
            'person_id' => $this->person->id, 'user_id' => $this->owner->id,
            'type' => 'moment', 'title' => 'Voyage', 'event_date' => '1950-01-01',
        ]);
        $inEvent = Media::factory()->create(['user_id' => $this->owner->id, 'type' => 'photo']);
        $event->photos()->attach($inEvent->id, ['order' => 0]);

        $other = Media::factory()->create(['user_id' => $this->owner->id, 'type' => 'photo']);

        $this->assertTrue($this->guest->can('view', $tagged));
        $this->assertTrue($this->guest->can('view', $inEvent));
        $this->assertFalse($this->guest->can('view', $other));

        $this->actingAs($this->guest);
        $this->get(route('media.file', $other))->assertForbidden();

        $photos = collect($this->getJson(route('people.timeline', $this->person))->json())
            ->flatMap(fn ($item) => $item['photos'] ?? []);
        $this->assertSame([$inEvent->id], $photos->pluck('id')->all());
    }

    public function test_expired_guest_is_logged_out(): void
    {
        $this->guest->update(['guest_expires_at' => now()->subMinute()]);

        $this->actingAs($this->guest)
            ->get(route('people.show', $this->person))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        // Le lien lui-même ne fonctionne plus, même signé et non échu.
        $this->get($this->inviteUrl($this->guest, $this->person))->assertForbidden();
    }
}
