<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use App\Services\GedcomParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class GedcomImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_parser_extracts_individuals(): void
    {
        $parser = new GedcomParserService();
        $gedcom = "0 HEAD\n0 @I001@ INDI\n1 NAME Jean /Dupont/\n1 SEX M\n1 BIRT\n2 DATE 15 MAR 1950\n2 PLAC Paris, France\n0 TRLR";

        $result = $parser->parse($gedcom);

        $this->assertCount(1, $result['individuals']);
        $individual = $result['individuals']['@I001@'];
        $this->assertEquals('Jean Dupont', $individual['name']);
        $this->assertEquals('Dupont', $individual['surname']);
        $this->assertEquals('M', $individual['sex']);
        $this->assertEquals('1950-03-15', $individual['birth_date']);
        $this->assertEquals('Paris, France', $individual['birth_place']);
    }

    public function test_parser_extracts_families(): void
    {
        $parser = new GedcomParserService();
        $gedcom = "0 HEAD\n0 @I001@ INDI\n1 NAME Jean /Dupont/\n0 @I002@ INDI\n1 NAME Marie /Martin/\n0 @I003@ INDI\n1 NAME Pierre /Dupont/\n0 @F001@ FAM\n1 HUSB @I001@\n1 WIFE @I002@\n1 CHIL @I003@\n1 MARR\n2 DATE 20 JUN 1975\n2 PLAC Lyon\n0 TRLR";

        $result = $parser->parse($gedcom);

        $this->assertCount(1, $result['families']);
        $family = $result['families']['@F001@'];
        $this->assertEquals('@I001@', $family['husband_id']);
        $this->assertEquals('@I002@', $family['wife_id']);
        $this->assertContains('@I003@', $family['children_ids']);
        $this->assertEquals('1975-06-20', $family['marriage_date']);
        $this->assertEquals('Lyon', $family['marriage_place']);
    }

    public function test_parser_handles_date_formats(): void
    {
        $parser = new GedcomParserService();

        $this->assertEquals('1950-03-15', $parser->parseGedcomDate('15 MAR 1950'));
        $this->assertEquals('1950-03-01', $parser->parseGedcomDate('MAR 1950'));
        $this->assertEquals('1950-01-01', $parser->parseGedcomDate('1950'));
        $this->assertEquals('1950-03-15', $parser->parseGedcomDate('ABT 15 MAR 1950'));
        $this->assertNull($parser->parseGedcomDate(''));
    }

    public function test_can_upload_gedcom_file(): void
    {
        $gedcomContent = "0 HEAD\n0 @I001@ INDI\n1 NAME Jean /Dupont/\n1 SEX M\n0 TRLR";
        $file = UploadedFile::fake()->createWithContent('test.ged', $gedcomContent);

        $response = $this->actingAs($this->user)->postJson('/family-tree/import/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('individuals_count', 1)
            ->assertJsonStructure(['import_id', 'suggestions']);
    }

    public function test_matching_finds_existing_people(): void
    {
        Person::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Jean Dupont',
            'birth_date' => '1950-03-15',
        ]);

        $gedcomContent = "0 HEAD\n0 @I001@ INDI\n1 NAME Jean /Dupont/\n1 SEX M\n1 BIRT\n2 DATE 15 MAR 1950\n0 TRLR";
        $file = UploadedFile::fake()->createWithContent('test.ged', $gedcomContent);

        $response = $this->actingAs($this->user)->postJson('/family-tree/import/upload', [
            'file' => $file,
        ]);

        $suggestions = $response->json('suggestions');
        $this->assertNotEmpty($suggestions[0]['matches']);
        $this->assertGreaterThan(50, $suggestions[0]['matches'][0]['score']);
    }

    public function test_import_creates_people_and_relationships(): void
    {
        $gedcomContent = "0 HEAD\n0 @I001@ INDI\n1 NAME Jean /Dupont/\n1 SEX M\n0 @I002@ INDI\n1 NAME Marie /Martin/\n1 SEX F\n0 @I003@ INDI\n1 NAME Pierre /Dupont/\n1 SEX M\n0 @F001@ FAM\n1 HUSB @I001@\n1 WIFE @I002@\n1 CHIL @I003@\n0 TRLR";
        $file = UploadedFile::fake()->createWithContent('test.ged', $gedcomContent);

        // Upload
        $uploadResponse = $this->actingAs($this->user)->postJson('/family-tree/import/upload', [
            'file' => $file,
        ]);

        $importId = $uploadResponse->json('import_id');

        // Confirm with all 'create'
        $response = $this->actingAs($this->user)->postJson("/family-tree/import/{$importId}/confirm", [
            'decisions' => [
                '@I001@' => 'create',
                '@I002@' => 'create',
                '@I003@' => 'create',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('stats.created', 3);

        // Verify relationships
        $pierre = Person::where('name', 'Pierre Dupont')
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($pierre);
        $this->assertNotNull($pierre->father_id);
        $this->assertNotNull($pierre->mother_id);

        // Verify spouse relationship
        $this->assertDatabaseHas('person_relationships', [
            'type' => 'spouse',
        ]);
    }

    public function test_cannot_import_without_auth(): void
    {
        $file = UploadedFile::fake()->createWithContent('test.ged', '0 HEAD');

        $response = $this->postJson('/family-tree/import/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_import_page_loads(): void
    {
        $response = $this->actingAs($this->user)->get('/family-tree/import');
        $response->assertStatus(200);
    }
}
