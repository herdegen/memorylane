<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Invitations : création par l'admin ou un membre, mail, page d'arrivée
 * /bienvenue/{jeton}, création du compte relié à la fiche + foyer d'accueil.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected Person $person;
    protected Household $family;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Paul Martin']);
        config(['mail.invitations.admin_from_name' => 'Paul Martin']);
        $this->member = User::factory()->create(['role' => 'user', 'name' => 'Bastien Morel']);
        $this->person = Person::factory()->create([
            'user_id' => $this->admin->id,
            'first_name' => 'Élodie',
            'last_name' => 'DURAND',
            'gender' => 'F',
            'birth_date' => '1990-03-12',
            'death_date' => null,
        ]);
        $this->family = Household::factory()->create(['created_by' => $this->admin->id, 'name' => 'Famille']);
        $this->family->makeWelcome();
    }

    private function invite(?User $by = null, array $options = []): Invitation
    {
        return app(InvitationService::class)->invite($by ?? $this->admin, 'Elodie@Example.fr', $this->person, $options);
    }

    private function tokenOf(Invitation $invitation): string
    {
        return $invitation->fresh()->token;
    }

    public function test_admin_invitation_sends_mail_with_usable_link(): void
    {
        $invitation = $this->invite();

        $this->assertSame('elodie@example.fr', $invitation->email);
        $this->assertNotNull($invitation->fresh()->sent_at);
        $this->assertSame($this->family->id, $invitation->household_id);
        $this->assertTrue($invitation->fresh()->isUsable());
        $this->assertTrue($invitation->fresh()->expires_at->between(now()->addDays(29), now()->addDays(31)));

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('elodie@example.fr')
            && str_contains($mail->url, '/bienvenue/'));
    }

    public function test_mail_renders_personal_letter_in_tu_and_vous(): void
    {
        $tu = (new InvitationMail($this->invite(), 'https://example.test/bienvenue/x'))->render();
        $this->assertStringContainsString('Salut Élodie', $tu);
        $this->assertStringContainsString('Créer mon accès', $tu);
        $this->assertStringContainsString('Pourquoi j’ai fait ça', $tu);
        $this->assertStringContainsString('Née le 12 mars 1990', $tu);
        $this->assertStringContainsString('Élodie Durand', $tu);

        $other = Person::factory()->create(['user_id' => $this->admin->id, 'first_name' => 'Jean', 'last_name' => 'DUPONT', 'gender' => 'M', 'death_date' => null, 'birth_date' => '1950-01-01']);
        $vous = app(InvitationService::class)->invite($this->member, 'jean@example.fr', $other, ['formal' => true]);
        $html = (new InvitationMail($vous, 'https://example.test/bienvenue/y'))->render();
        $this->assertStringContainsString('Bonjour Jean', $html);
        $this->assertStringContainsString('Bastien vous invite', $html);
        $this->assertStringContainsString('Pourquoi Paul a créé ce site', $html);
        $this->assertStringNotContainsString('Salut', $html);
    }

    public function test_deceased_or_already_linked_person_cannot_be_invited(): void
    {
        $dead = Person::factory()->create(['user_id' => $this->admin->id, 'death_date' => '2001-01-01']);
        try {
            app(InvitationService::class)->invite($this->admin, 'x@example.fr', $dead);
            $this->fail('Une fiche décédée ne doit pas pouvoir être invitée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('person_id', $e->errors());
        }

        $this->member->update(['person_id' => $this->person->id]);
        $this->expectException(ValidationException::class);
        $this->invite();
    }

    public function test_existing_account_email_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        app(InvitationService::class)->invite($this->admin, strtoupper($this->member->email), $this->person);
    }

    public function test_welcome_page_shows_the_person(): void
    {
        $token = $this->tokenOf($this->invite());

        $this->get("/bienvenue/{$token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Welcome', false)
                ->where('state', 'ok')
                ->where('person.first_name', 'Élodie')
                ->where('suggestedName', 'Élodie Durand')
                ->where('email', 'elodie@example.fr'));
    }

    public function test_accepting_creates_linked_account_in_welcome_household_and_logs_in(): void
    {
        $invitation = $this->invite();
        $token = $this->tokenOf($invitation);

        $this->post("/bienvenue/{$token}", [
            'name' => 'Élodie Durand',
            'password' => 'un-mot-de-passe',
            'password_confirmation' => 'un-mot-de-passe',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'elodie@example.fr')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($this->person->id, $user->person_id);
        $this->assertSame('user', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($this->family->isMember($user));

        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($user->id, $invitation->user_id);
        $this->assertNull($invitation->token_hash);

        // Lien à usage unique.
        auth()->logout();
        $this->get("/bienvenue/{$token}")
            ->assertInertia(fn ($page) => $page->where('state', 'invalid'));
        $this->post("/bienvenue/{$token}", [
            'name' => 'X', 'password' => 'autre-secret', 'password_confirmation' => 'autre-secret',
        ])->assertStatus(410);
    }

    public function test_password_rules_are_enforced(): void
    {
        $token = $this->tokenOf($this->invite());

        $this->post("/bienvenue/{$token}", [
            'name' => 'Élodie',
            'password' => 'court',
            'password_confirmation' => 'autre',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'elodie@example.fr']);
    }

    public function test_expired_and_revoked_links_are_refused(): void
    {
        $invitation = $this->invite();
        $token = $this->tokenOf($invitation);

        $invitation->forceFill(['expires_at' => now()->subDay()])->save();
        $this->get("/bienvenue/{$token}")->assertInertia(fn ($page) => $page->where('state', 'expired'));
        $this->post("/bienvenue/{$token}", [
            'name' => 'Élodie', 'password' => 'un-mot-de-passe', 'password_confirmation' => 'un-mot-de-passe',
        ])->assertStatus(410);

        app(InvitationService::class)->send($invitation->fresh());
        $fresh = $this->tokenOf($invitation);
        $this->assertNotSame($token, $fresh);
        app(InvitationService::class)->revoke($invitation->fresh());
        $this->get("/bienvenue/{$fresh}")->assertInertia(fn ($page) => $page->where('state', 'invalid'));
    }

    public function test_resend_rotates_the_token(): void
    {
        $invitation = $this->invite();
        $old = $this->tokenOf($invitation);

        app(InvitationService::class)->send($invitation->fresh());

        $this->get("/bienvenue/{$old}")->assertInertia(fn ($page) => $page->where('state', 'invalid'));
        $this->get('/bienvenue/'.$this->tokenOf($invitation))->assertInertia(fn ($page) => $page->where('state', 'ok'));
        Mail::assertSent(InvitationMail::class, 2);
    }

    public function test_member_can_invite_from_a_person_page(): void
    {
        $this->actingAs($this->member)
            ->postJson("/people/{$this->person->id}/invite", ['email' => 'elodie@example.fr', 'message' => 'Coucou !'])
            ->assertCreated();

        $invitation = Invitation::firstOrFail();
        $this->assertSame($this->member->id, $invitation->invited_by);
        $this->assertSame($this->family->id, $invitation->household_id);
        $this->assertSame('Coucou !', $invitation->message);

        // Le mail d'un membre part de l'adresse MemoryLane, réponse au membre.
        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) {
            $envelope = $mail->envelope();

            return $envelope->from->name === 'Bastien via MemoryLane'
                && $envelope->replyTo[0]->address === $this->member->email;
        });

        // Une seule invitation en cours par fiche.
        $this->actingAs($this->member)
            ->postJson("/people/{$this->person->id}/invite", ['email' => 'autre@example.fr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('person_id');
    }

    public function test_admin_invitation_uses_gmail_when_configured(): void
    {
        config([
            'mail.invitations.admin_mailer' => 'gmail',
            'mail.invitations.admin_from_address' => 'paul.martin@example.com',
            'mail.invitations.admin_from_name' => 'Paul Martin',
        ]);

        $mail = new InvitationMail($this->invite(), 'https://example.test/bienvenue/x');

        $this->assertSame('paul.martin@example.com', $mail->envelope()->from->address);
        $this->assertSame('Paul t\'invite sur MemoryLane, notre album de famille', $mail->envelope()->subject);
        Mail::assertSent(InvitationMail::class, fn (InvitationMail $m) => $m->mailer === 'gmail');
    }

    public function test_guest_and_tablet_accounts_cannot_invite(): void
    {
        $guest = User::factory()->create(['guest_expires_at' => now()->addDay(), 'guest_person_id' => $this->person->id]);
        $this->actingAs($guest)
            ->postJson("/people/{$this->person->id}/invite", ['email' => 'elodie@example.fr'])
            ->assertForbidden();

        $tablet = User::factory()->create(['simple_mode' => true]);
        $this->actingAs($tablet)
            ->postJson("/people/{$this->person->id}/invite", ['email' => 'elodie@example.fr'])
            ->assertForbidden();

        $this->assertSame(0, Invitation::count());
    }

    public function test_person_page_exposes_invitation_state(): void
    {
        $this->actingAs($this->member)
            ->get("/people/{$this->person->id}")
            ->assertInertia(fn ($page) => $page->where('account.canInvite', true)->where('account.hasAccount', false));

        $this->invite();

        $this->actingAs($this->member)
            ->get("/people/{$this->person->id}")
            ->assertInertia(fn ($page) => $page->where('account.canInvite', false)
                ->where('account.pendingInvitation.inviter', 'Paul Martin'));
    }

    public function test_old_ancestor_without_dates_is_not_invitable(): void
    {
        $ancestorFather = Person::factory()->create(['user_id' => $this->admin->id, 'birth_date' => '1820-01-01']);
        $ancestor = Person::factory()->create([
            'user_id' => $this->admin->id, 'birth_date' => null, 'death_date' => null, 'father_id' => $ancestorFather->id,
        ]);

        $this->assertNotNull(app(InvitationService::class)->personIneligibility($ancestor));
    }

    public function test_friend_invitation_joins_the_inviters_circle_not_the_family(): void
    {
        $friends = Household::factory()->create(['created_by' => $this->admin->id, 'name' => 'Les copains', 'kind' => Household::KIND_FRIENDS]);
        $friend = User::factory()->create(['role' => 'user']);
        $friends->members()->attach($friend->id);
        $buddy = Person::factory()->create(['user_id' => $friend->id, 'death_date' => null, 'birth_date' => '1991-01-01']);

        $invitation = app(InvitationService::class)->invite($friend, 'buddy@example.fr', $buddy);

        $this->assertSame($friends->id, $invitation->household_id);
    }

    public function test_cercle_reserve_a_l_admin_refuse_l_invitation_d_un_membre(): void
    {
        $friends = Household::factory()->friends()->create(['created_by' => $this->admin->id]);
        $friend = User::factory()->create(['role' => 'user']);
        $friends->members()->attach($friend->id);
        $buddy = Person::factory()->create(['user_id' => $friend->id, 'death_date' => null, 'birth_date' => '1991-01-01', 'in_family_directory' => false]);
        $buddy->circles()->attach($friends->id);

        $this->actingAs($friend)
            ->postJson("/people/{$buddy->id}/invite", ['email' => 'buddy@example.fr'])
            ->assertUnprocessable()->assertJsonValidationErrors('person_id');
        $this->assertFalse(app(InvitationService::class)->canInvite($friend, $buddy));

        // L'admin, lui, invite ; et un cercle ouvert laisse le membre inviter.
        $this->assertTrue(app(InvitationService::class)->canInvite($this->admin, $buddy));
        $friends->update(['invite_policy' => 'everyone']);
        $this->assertTrue(app(InvitationService::class)->canInvite($friend->fresh(), $buddy));
    }

    public function test_invitation_dans_un_cercle_d_amis_utilise_le_mail_amis(): void
    {
        $friends = Household::factory()->friends()->create(['created_by' => $this->admin->id, 'name' => 'Les Ponzes']);
        $friend = Person::factory()->create(['user_id' => $this->admin->id, 'first_name' => 'Laura', 'last_name' => 'Gaugler', 'in_family_directory' => false, 'death_date' => null, 'birth_date' => '1990-01-01']);
        $friend->circles()->attach($friends->id);

        $invitation = app(InvitationService::class)->create($this->admin, 'laura@example.fr', $friend, ['household_id' => $friends->id]);
        $mail = new \App\Mail\InvitationMail($invitation, 'https://example.test/bienvenue/x');

        $this->assertStringContainsString('« Les Ponzes »', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('propres groupes d’amis', $html);
        $this->assertStringContainsString('Et la famille ?', $html);
        $this->assertStringNotContainsString('arbre de la famille', $html);
    }
}
