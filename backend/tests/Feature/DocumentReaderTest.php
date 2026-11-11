<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\DocumentReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Documents : lecture automatique du texte (Tesseract), classement dans
 * « Documents », classement et texte saisis à la main.
 */
class DocumentReaderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_lecture_du_tsv_de_tesseract(): void
    {
        $tsv = implode("\n", [
            "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext",
            "1\t1\t0\t0\t0\t0\t0\t0\t100\t100\t-1\t",
            "5\t1\t1\t1\t1\t1\t0\t0\t10\t10\t96\tLe",
            "5\t1\t1\t1\t1\t2\t0\t0\t10\t10\t95\tcrime",
            "5\t1\t1\t1\t2\t1\t0\t0\t10\t10\t91\tFolie-Regnault",
            "5\t1\t2\t1\t1\t1\t0\t0\t10\t10\t30\tbrouillé",
            "5\t1\t2\t1\t1\t2\t0\t0\t10\t10\t88\t26",
        ]);

        $read = DocumentReader::parse($tsv);

        $this->assertSame(2, $read['words'], 'mots fiables de 3 lettres ou plus');
        $this->assertSame("Le crime\nFolie-Regnault\n\nbrouillé 26", $read['text']);
    }

    private function readerReading(int $words, string $text = 'Monsieur le Directeur'): DocumentReader
    {
        $reader = Mockery::mock(DocumentReader::class)->makePartial();
        $reader->shouldReceive('read')->andReturn(['words' => $words, 'text' => $text]);

        return $reader;
    }

    public function test_assez_de_texte_c_est_un_document_et_son_texte_est_propose(): void
    {
        $letter = Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo']);
        $this->assertTrue($this->readerReading(120)->classify($letter));
        $letter->refresh();
        $this->assertTrue($letter->is_document);
        $this->assertSame('ocr', $letter->document_source);
        $this->assertStringStartsWith('Monsieur le Directeur', $letter->transcription);
        $this->assertSame('ocr', $letter->transcription_source);
        $this->assertNotNull($letter->text_checked_at);

        $holiday = Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo']);
        $this->assertFalse($this->readerReading(4, 'PLAGE')->classify($holiday));
        $this->assertFalse($holiday->refresh()->is_document);
        $this->assertNull($holiday->transcription);
        $this->assertNotNull($holiday->text_checked_at, 'lue une fois, pas relue chaque nuit');
    }

    public function test_jamais_de_retour_sur_ce_qui_a_ete_fait_a_la_main(): void
    {
        $media = Media::factory()->create([
            'user_id' => $this->user->id, 'type' => 'photo',
            'is_document' => false, 'document_source' => 'manual', 'transcription' => 'Texte relu',
        ]);

        $this->readerReading(200)->classify($media);

        $media->refresh();
        $this->assertFalse($media->is_document);
        $this->assertSame('Texte relu', $media->transcription);
    }

    public function test_onglets_photos_et_documents(): void
    {
        $photo = Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo']);
        $scan = Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo', 'is_document' => true]);

        $ids = fn (string $type) => collect($this->actingAs($this->user)->getJson("/media?type={$type}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$scan->id], $ids('document'));
        $this->assertSame([$photo->id], $ids('photo'));
    }

    public function test_classer_et_recopier_a_la_main(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo', 'is_document' => true, 'document_source' => 'ocr', 'transcription' => 'lu', 'transcription_source' => 'ocr']);

        $this->actingAs($this->user)->putJson("/media/{$media->id}", ['is_document' => false])->assertOk();
        $this->assertFalse($media->refresh()->is_document);
        $this->assertSame('manual', $media->document_source);

        $this->actingAs($this->user)->putJson("/media/{$media->id}", ['transcription' => 'Texte relu et corrigé'])->assertOk();
        $this->assertSame('manual', $media->refresh()->transcription_source);
    }

    public function test_commande_sans_tesseract(): void
    {
        config(['memorylane.documents.tesseract' => '/introuvable/tesseract']);
        $this->artisan('media:read-documents')->assertFailed();
    }
}
