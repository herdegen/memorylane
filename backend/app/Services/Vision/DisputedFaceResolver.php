<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use Illuminate\Support\Facades\Cache;

/**
 * Revue des identifications existantes à la lumière d'InsightFace : un
 * visage identifié (non confirmé par un humain) est « à vérifier » quand
 * son empreinte ressemble nettement plus à une AUTRE personne, ou à
 * personne, ou quand l'âge estimé contredit franchement l'âge réel.
 *
 * Comparaison aux empreintes MOYENNES de chaque personne (calculées sans le
 * visage lui-même) : rapide, et robuste aux quelques erreurs déjà présentes.
 */
class DisputedFaceResolver
{
    /** Avance minimale d'une autre personne pour signaler (distance). */
    public const OTHER_MARGIN = 0.15;

    /** Au-delà, le visage ne ressemble pas à la personne associée. */
    public const UNLIKE_DISTANCE = 1.30;

    /** Écart d'âge signalé (adolescents et adultes seulement). */
    public const AGE_GAP = 30;

    /** @var array<string, array{reason: string, assigned: float, best_person: ?string, best: ?float, age_gap: ?int}>|null */
    private ?array $analysis = null;

    public function analysis(): array
    {
        $version = (int) Cache::get('face-refs-version', 0);

        return $this->analysis ??= Cache::remember("face-disputed:v{$version}", now()->addHour(), fn () => $this->compute());
    }

    private function compute(): array
    {
        $faces = DetectedFace::query()->where('status', 'matched')->whereNotNull('person_id')->whereNotNull('embedding_v2')
            ->with(['person:id,birth_date', 'media:id,taken_at'])
            ->get(['id', 'person_id', 'media_id', 'embedding_v2', 'age_estimate', 'verified_at'])
            ->filter(fn ($f) => is_array($f->embedding_v2) && count($f->embedding_v2) === 512)->values();

        if ($faces->count() < 2) {
            return [];
        }

        $sums = [];
        $counts = [];
        foreach ($faces as $f) {
            $sums[$f->person_id] = isset($sums[$f->person_id]) ? $this->add($sums[$f->person_id], $f->embedding_v2) : $f->embedding_v2;
            $counts[$f->person_id] = ($counts[$f->person_id] ?? 0) + 1;
        }
        $centroids = [];
        foreach ($sums as $pid => $sum) {
            $centroids[$pid] = $this->normalize($sum);
        }

        $out = [];
        foreach ($faces as $f) {
            if ($f->verified_at) {
                continue; // confirmé par un humain : on ne le remet pas en cause
            }
            // Moyenne de SA personne sans lui-même.
            $own = $counts[$f->person_id] > 1 ? $this->normalize($this->sub($sums[$f->person_id], $f->embedding_v2)) : null;
            $assigned = $own ? $this->distance($f->embedding_v2, $own) : null;

            $bestPerson = null;
            $best = INF;
            foreach ($centroids as $pid => $c) {
                if ($pid === $f->person_id) {
                    continue;
                }
                $d = $this->distance($f->embedding_v2, $c);
                if ($d < $best) {
                    $best = $d;
                    $bestPerson = $pid;
                }
            }

            $ageGap = null;
            if ($f->age_estimate !== null && $f->person?->birth_date && $f->media?->taken_at && $f->person->birth_date->lte($f->media->taken_at)) {
                $real = (int) $f->person->birth_date->diffInYears($f->media->taken_at);
                if ($real >= 15) {
                    $ageGap = $f->age_estimate - $real;
                }
            }

            $reason = match (true) {
                $assigned !== null && $bestPerson && $best + self::OTHER_MARGIN <= $assigned => 'other',
                $assigned !== null && $assigned > self::UNLIKE_DISTANCE => 'unlike',
                $ageGap !== null && abs($ageGap) >= self::AGE_GAP => 'age',
                default => null,
            };

            if ($reason) {
                $out[$f->id] = [
                    'reason' => $reason,
                    // Force du désaccord (tri : les cas les plus francs d'abord).
                    'strength' => round(match ($reason) {
                        'other' => $assigned - $best,
                        'unlike' => $assigned - self::UNLIKE_DISTANCE + 0.1,
                        default => abs($ageGap) / 100,
                    }, 3),
                    'assigned' => round($assigned ?? 0, 3),
                    'best_person' => $bestPerson,
                    'best' => is_finite($best) ? round($best, 3) : null,
                    'age_gap' => $ageGap,
                ];
            }
        }

        return $out;
    }

    private function add(array $a, array $b): array
    {
        foreach ($b as $i => $v) {
            $a[$i] += $v;
        }

        return $a;
    }

    private function sub(array $a, array $b): array
    {
        foreach ($b as $i => $v) {
            $a[$i] -= $v;
        }

        return $a;
    }

    private function normalize(array $v): array
    {
        $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v))) ?: 1.0;

        return array_map(fn ($x) => $x / $n, $v);
    }

    private function distance(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
        }

        return sqrt(max(0, 2 - 2 * $dot));
    }
}
