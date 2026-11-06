<?php

namespace App\Services\Demo;

use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * La démo a toujours l'air vivante : chaque nuit, après la remise à zéro,
 * certaines dates sont recalées sur aujourd'hui (anniversaires et souvenirs
 * des prochains jours, photos « de cette semaine », « il y a N ans ce
 * jour-là »), d'après la section « freshen » de chaque famille. Les années
 * restent celles de l'histoire de la famille.
 */
class DemoFreshener
{
    public function freshen(): void
    {
        DemoBuilder::assertDemoDatabase();
        $today = today();

        foreach (DemoBuilder::families() as $fam => $data) {
            $f = $data['freshen'] ?? [];
            $person = fn (string $key) => Person::where('gedcom_id', "demo:{$fam}:{$key}")->first();

            foreach ($f['birthdays'] ?? [] as $key => $days) {
                $this->recalDay($person($key), 'birth_date', $today->copy()->addDays($days));
            }
            foreach ($f['memorials'] ?? [] as $key => $days) {
                $this->recalDay($person($key), 'death_date', $today->copy()->addDays($days));
            }
            foreach ($f['weddings'] ?? [] as [$a, $b, $days]) {
                $ids = array_filter([$person($a)?->id, $person($b)?->id]);
                $rel = PersonRelationship::whereIn('person1_id', $ids)->whereIn('person2_id', $ids)->first();
                if ($rel?->start_date) {
                    $date = $today->copy()->addDays($days)->setYear(Carbon::parse($rel->start_date)->year);
                    $rel->forceFill(['start_date' => $date->toDateString()])->save();
                }
            }

            $albumNames = array_map(fn ($k) => $data['albums'][$k]['name'], $f['recent_albums'] ?? []);
            $recent = Media::whereHas('albums', fn ($a) => $a->whereIn('name', $albumNames))->get();
            foreach ($recent as $i => $m) {
                $m->forceFill(['uploaded_at' => now()->subDays(1 + $i % 5)->subMinutes($i * 7)])->saveQuietly();
            }

            $titles = array_map(fn ($k) => $data['photos'][$k]['title'], $f['on_this_day'] ?? []);
            foreach (Media::whereIn('title', $titles)->get() as $m) {
                if ($m->taken_at) {
                    $m->forceFill(['taken_at' => $m->taken_at->copy()->setDate($m->taken_at->year, $today->month, $today->day)])->saveQuietly();
                }
            }
        }

        // Les caches de lots (questions, réseau…) repartent de ces dates ; les
        // lieux, eux, sont aussitôt reposés (carte « Dans le temps »).
        Cache::flush();
        DemoBuilder::seedPlaces();
    }

    private function recalDay(?Person $person, string $field, Carbon $target): void
    {
        if (! $person?->{$field}) {
            return;
        }
        $date = $target->copy()->setYear($person->{$field}->year);
        $person->forceFill([$field => $date->toDateString()])->saveQuietly();
    }
}
