<?php

namespace App\Services;

use App\Models\GedcomImport;
use App\Models\Person;
use App\Models\PersonRelationship;
use Illuminate\Support\Str;

class GedcomImportService
{
    public function __construct(private GedcomParserService $parser) {}

    /**
     * Step 1: Parse GEDCOM file and create an import session.
     */
    public function parseAndCreateSession(string $content, string $filename, string $userId): GedcomImport
    {
        $parsed = $this->parser->parse($content);

        return GedcomImport::create([
            'user_id' => $userId,
            'filename' => $filename,
            'status' => 'matching',
            'parsed_data' => $parsed,
            'individuals_count' => count($parsed['individuals']),
            'families_count' => count($parsed['families']),
        ]);
    }

    /**
     * Step 2: Generate match suggestions for each GEDCOM individual.
     */
    public function generateMatchSuggestions(GedcomImport $import): array
    {
        $parsed = $import->parsed_data;
        $existingPeople = Person::where('user_id', $import->user_id)->get();
        $suggestions = [];

        foreach ($parsed['individuals'] as $gedcomId => $individual) {
            $matches = [];

            foreach ($existingPeople as $person) {
                $score = $this->calculateMatchScore($individual, $person);
                if ($score > 0) {
                    $matches[] = [
                        'person_id' => $person->id,
                        'person_name' => $person->name,
                        'person_birth_date' => $person->birth_date?->format('Y-m-d'),
                        'person_death_date' => $person->death_date?->format('Y-m-d'),
                        'score' => $score,
                    ];
                }
            }

            usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);

            $suggestions[] = [
                'gedcom_id' => $gedcomId,
                'name' => $individual['name'],
                'birth_date' => $individual['birth_date'],
                'death_date' => $individual['death_date'],
                'sex' => $individual['sex'],
                'matches' => array_slice($matches, 0, 5),
                'action' => null,
            ];
        }

        return $suggestions;
    }

    private function calculateMatchScore(array $individual, Person $person): int
    {
        $score = 0;

        $gedcomName = mb_strtolower(trim($individual['name']));
        $personName = mb_strtolower(trim($person->name));

        if ($gedcomName === $personName) {
            $score += 60;
        } elseif (str_contains($gedcomName, $personName) || str_contains($personName, $gedcomName)) {
            $score += 40;
        } else {
            $gedcomSurname = mb_strtolower($individual['surname'] ?? '');
            $personParts = explode(' ', $personName);
            $personSurname = mb_strtolower(end($personParts));

            if ($gedcomSurname && $personSurname && $gedcomSurname === $personSurname) {
                $score += 25;
            } else {
                $distance = levenshtein($gedcomName, $personName);
                $maxLen = max(mb_strlen($gedcomName), mb_strlen($personName));
                if ($maxLen > 0 && $distance / $maxLen < 0.3) {
                    $score += 20;
                }
            }
        }

        if ($individual['birth_date'] && $person->birth_date) {
            $gedcomBirth = $individual['birth_date'];
            $personBirth = $person->birth_date->format('Y-m-d');

            if ($gedcomBirth === $personBirth) {
                $score += 30;
            } elseif (substr($gedcomBirth, 0, 4) === substr($personBirth, 0, 4)) {
                $score += 15;
            }
        }

        if ($individual['death_date'] && $person->death_date) {
            if ($individual['death_date'] === $person->death_date->format('Y-m-d')) {
                $score += 10;
            }
        }

        return $score;
    }

    /**
     * Step 3: Execute the import based on user's matching decisions.
     *
     * @param  array  $decisions  ['gedcom_id' => 'create'|'skip'|'match_<uuid>']
     * @return array{created: int, matched: int, skipped: int}
     */
    public function executeImport(GedcomImport $import, array $decisions): array
    {
        $parsed = $import->parsed_data;
        $userId = $import->user_id;
        $gedcomToPersonId = [];
        $stats = ['created' => 0, 'matched' => 0, 'skipped' => 0];

        $import->update(['status' => 'importing']);

        try {
            // Phase 1: Create or match individuals
            foreach ($parsed['individuals'] as $gedcomId => $individual) {
                $decision = $decisions[$gedcomId] ?? 'skip';

                if ($decision === 'skip') {
                    $stats['skipped']++;
                    continue;
                }

                if ($decision === 'create') {
                    $person = Person::create([
                        'user_id' => $userId,
                        'name' => $individual['name'],
                        'gender' => $individual['sex'],
                        'birth_date' => $individual['birth_date'],
                        'death_date' => $individual['death_date'],
                        'birth_place' => $individual['birth_place'],
                        'death_place' => $individual['death_place'],
                        'gedcom_id' => $gedcomId,
                    ]);
                    $gedcomToPersonId[$gedcomId] = $person->id;
                    $stats['created']++;
                } elseif (str_starts_with($decision, 'match_')) {
                    $personId = Str::after($decision, 'match_');
                    $person = Person::find($personId);

                    if ($person && $person->user_id === $userId) {
                        $updates = [];
                        if (! $person->birth_date && $individual['birth_date']) {
                            $updates['birth_date'] = $individual['birth_date'];
                        }
                        if (! $person->death_date && $individual['death_date']) {
                            $updates['death_date'] = $individual['death_date'];
                        }
                        if ($person->gender === 'U' && $individual['sex'] !== 'U') {
                            $updates['gender'] = $individual['sex'];
                        }
                        if (! $person->birth_place && $individual['birth_place']) {
                            $updates['birth_place'] = $individual['birth_place'];
                        }
                        if (! $person->death_place && $individual['death_place']) {
                            $updates['death_place'] = $individual['death_place'];
                        }
                        if (! $person->gedcom_id) {
                            $updates['gedcom_id'] = $gedcomId;
                        }

                        if (! empty($updates)) {
                            $person->update($updates);
                        }

                        $gedcomToPersonId[$gedcomId] = $person->id;
                        $stats['matched']++;
                    }
                }
            }

            // Phase 2: Wire family relationships from FAM records
            foreach ($parsed['families'] as $family) {
                $husbandPersonId = $gedcomToPersonId[$family['husband_id']] ?? null;
                $wifePersonId = $gedcomToPersonId[$family['wife_id']] ?? null;

                // Set parent references on children
                foreach ($family['children_ids'] as $childGedcomId) {
                    $childPersonId = $gedcomToPersonId[$childGedcomId] ?? null;
                    if (! $childPersonId) {
                        continue;
                    }

                    $child = Person::find($childPersonId);
                    if (! $child) {
                        continue;
                    }

                    $updates = [];
                    if ($husbandPersonId && ! $child->father_id) {
                        $updates['father_id'] = $husbandPersonId;
                    }
                    if ($wifePersonId && ! $child->mother_id) {
                        $updates['mother_id'] = $wifePersonId;
                    }

                    if (! empty($updates)) {
                        $child->update($updates);
                    }
                }

                // Create spouse relationship
                if ($husbandPersonId && $wifePersonId) {
                    $ids = [$husbandPersonId, $wifePersonId];
                    sort($ids);

                    PersonRelationship::firstOrCreate(
                        [
                            'person1_id' => $ids[0],
                            'person2_id' => $ids[1],
                            'type' => 'spouse',
                        ],
                        [
                            'start_date' => $family['marriage_date'],
                            'start_place' => $family['marriage_place'],
                        ]
                    );
                }
            }

            $import->update([
                'status' => 'completed',
                'matching_decisions' => $decisions,
                'imported_count' => $stats['created'] + $stats['matched'],
            ]);
        } catch (\Exception $e) {
            $import->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $stats;
    }
}
