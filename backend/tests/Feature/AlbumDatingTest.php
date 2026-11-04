<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Date d'album : ses photos sans date en héritent ; une vraie date
 * (appareil photo, saisie à la main) n'est jamais touchée.
 */
class AlbumDatingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->album = Album::factory()->create(['user_id' => $this->owner->id]);
    }

    private function media(?string $takenAt = null): Media
    {
        return Media::factory()->create(['user_id' => $this->owner->id, 'taken_at' => $takenAt]);
    }

    private function setAlbumDate(?string $date)
    {
        return $this->actingAs($this->owner)->putJson("/albums/{$this->album->id}", [
            'name' => $this->album->name,
            'date' => $date,
        ]);
    }

    public function test_la_date_de_l_album_date_ses_photos_sans_date_seulement(): void
    {
        $undated = $this->media();
        $camera = $this->media('2003-05-01 10:00:00');
        $this->album->media()->attach([$undated->id, $camera->id]);

        $this->setAlbumDate('1991-07-14')->assertOk()
            ->assertJsonPath('message', 'Album mis à jour : 1 photo sans date a pris la date de l’album.');

        $this->assertSame('1991-07-14', $undated->fresh()->taken_at->toDateString());
        $this->assertSame($this->album->id, $undated->fresh()->taken_at_album_id);
        $this->assertSame('2003-05-01', $camera->fresh()->taken_at->toDateString());
        $this->assertNull($camera->fresh()->taken_at_album_id);
        $this->assertSame('1991-07-14', $this->album->fresh()->date->toDateString());
    }

    public function test_changer_ou_effacer_la_date_suit_les_dates_heritees(): void
    {
        $undated = $this->media();
        $this->album->media()->attach($undated->id);
        $this->setAlbumDate('1991-07-14');

        $this->setAlbumDate('1992-08-01');
        $this->assertSame('1992-08-01', $undated->fresh()->taken_at->toDateString());

        $this->setAlbumDate(null)->assertJsonPath('message', 'Album mis à jour : 1 photo n’a plus de date.');
        $this->assertNull($undated->fresh()->taken_at);
        $this->assertNull($undated->fresh()->taken_at_album_id);
    }

    public function test_une_photo_ajoutee_plus_tard_herite_et_la_perd_en_sortant(): void
    {
        $this->setAlbumDate('1991-07-14');
        $later = $this->media();

        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/media", ['media_ids' => [$later->id]])->assertSuccessful();
        $this->assertSame('1991-07-14', $later->fresh()->taken_at->toDateString());

        $this->actingAs($this->owner)->deleteJson("/albums/{$this->album->id}/media", ['media_ids' => [$later->id]])->assertSuccessful();
        $this->assertNull($later->fresh()->taken_at);
    }

    public function test_une_date_saisie_a_la_main_detache_la_photo_de_l_album(): void
    {
        $undated = $this->media();
        $this->album->media()->attach($undated->id);
        $this->setAlbumDate('1991-07-14');

        $this->actingAs($this->owner)->putJson("/media/{$undated->id}", ['taken_at' => '1990-12-25'])->assertSuccessful();
        $this->assertNull($undated->fresh()->taken_at_album_id);

        $this->setAlbumDate('1995-01-01');
        $this->assertSame('1990-12-25', $undated->fresh()->taken_at->toDateString());

        // Dater en masse : idem.
        $other = $this->media();
        $this->album->media()->attach($other->id);
        $this->setAlbumDate('1996-01-01');
        $this->actingAs($this->owner)->postJson('/media/bulk/taken-at', ['media_ids' => [$other->id], 'taken_at' => '1980-06-06'])->assertOk();
        $this->assertSame('1980-06-06', $other->fresh()->taken_at->toDateString());
        $this->assertNull($other->fresh()->taken_at_album_id);
    }

    public function test_un_autre_album_date_prend_le_relais(): void
    {
        $undated = $this->media();
        $older = Album::factory()->create(['user_id' => $this->owner->id, 'date' => '1985-03-03', 'created_at' => now()->subYear()]);
        $this->album->media()->attach($undated->id);
        $this->setAlbumDate('1991-07-14');
        $this->assertSame('1991-07-14', $undated->fresh()->taken_at->toDateString());

        $older->media()->attach($undated->id);
        // Déjà datée par le premier album : elle le garde tant qu'elle y est.
        app(\App\Services\AlbumDating::class)->syncMedia([$undated->id]);
        $this->assertSame('1991-07-14', $undated->fresh()->taken_at->toDateString());

        $this->actingAs($this->owner)->deleteJson("/albums/{$this->album->id}/media", ['media_ids' => [$undated->id]]);
        $this->assertSame('1985-03-03', $undated->fresh()->taken_at->toDateString());
        $this->assertSame($older->id, $undated->fresh()->taken_at_album_id);
    }

    public function test_supprimer_l_album_retire_les_dates_heritees(): void
    {
        $undated = $this->media();
        $this->album->media()->attach($undated->id);
        $this->setAlbumDate('1991-07-14');

        $this->actingAs($this->owner)->deleteJson("/albums/{$this->album->id}")->assertSuccessful();
        $this->assertNull($undated->fresh()->taken_at);
    }

    public function test_un_clip_garde_l_origine_de_la_date(): void
    {
        $undated = $this->media();
        $this->album->media()->attach($undated->id);
        $this->setAlbumDate('1991-07-14');

        $clip = Media::factory()->create([
            'user_id' => $this->owner->id,
            'taken_at' => $undated->fresh()->taken_at,
            'taken_at_album_id' => $this->album->id,
        ]);
        $this->assertSame($this->album->id, $clip->fresh()->taken_at_album_id);
    }
}
