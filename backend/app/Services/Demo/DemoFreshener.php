<?php

namespace App\Services\Demo;

use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use Illuminate\Support\Facades\Cache;

/**
 * La démo a toujours l'air vivante : chaque nuit, après la remise à zéro,
 * certaines dates sont recalées sur aujourd'hui (anniversaires et souvenirs
 * des prochains jours, photos « de cette semaine », « il y a N ans ce
 * jour-là »). Les années restent celles de l'histoire de la famille.
 */
class DemoFreshener
{
    /** fiche (prénom nom) => décalage en jours depuis aujourd'hui */
    private const BIRTHDAYS = ['Emma Bernard' => 2, 'Hugo Lambert' => 6, 'Jean Lambert' => 11];

    private const MEMORIALS = ['Pierre Lambert' => 4];

    private const WEDDING = [['Jean Lambert', 'Françoise Lambert', 9]];

    /** Albums « ajoutés cette semaine » (date d'envoi recalée). */
    private const RECENT_ALBUMS = ['L’Italie en famille, 2022', 'Ski à Chamonix, février 2019'];

    /** Photos « il y a N ans ce jour-là ». */
    private const ON_THIS_DAY = ['La maison louée cet été-là', 'Dimanche à la plage du Moulin Blanc', 'Les mariés, le lendemain'];

    public function freshen(): void
    {
        DemoBuilder::assertDemoDatabase();
        $today = today();

        foreach (self::BIRTHDAYS as $name => $days) {
            $this->recalDay(Person::where('name', $name)->first(), 'birth_date', $today->copy()->addDays($days));
        }
        foreach (self::MEMORIALS as $name => $days) {
            $this->recalDay(Person::where('name', $name)->first(), 'death_date', $today->copy()->addDays($days));
        }
        foreach (self::WEDDING as [$a, $b, $days]) {
            $ids = Person::whereIn('name', [$a, $b])->pluck('id');
            $rel = PersonRelationship::whereIn('person1_id', $ids)->whereIn('person2_id', $ids)->first();
            if ($rel?->start_date) {
                $date = $today->copy()->addDays($days)->setYear(\Carbon\Carbon::parse($rel->start_date)->year);
                $rel->forceFill(['start_date' => $date->toDateString()])->save();
            }
        }

        $recent = Media::whereHas('albums', fn ($a) => $a->whereIn('name', self::RECENT_ALBUMS))->get();
        foreach ($recent as $i => $m) {
            $m->forceFill(['uploaded_at' => now()->subDays(1 + $i % 5)->subMinutes($i * 7)])->saveQuietly();
        }

        foreach (Media::whereIn('title', self::ON_THIS_DAY)->get() as $m) {
            if ($m->taken_at) {
                $m->forceFill(['taken_at' => $m->taken_at->copy()->setDate($m->taken_at->year, $today->month, $today->day)])->saveQuietly();
            }
        }

        // Les caches de lots (questions, réseau…) repartent de ces dates.
        Cache::flush();
    }

    private function recalDay(?Person $person, string $field, \Carbon\Carbon $target): void
    {
        if (! $person?->{$field}) {
            return;
        }
        $date = $target->copy()->setYear($person->{$field}->year);
        $person->forceFill([$field => $date->toDateString()])->saveQuietly();
    }
}
