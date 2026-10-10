<?php

namespace Tests\Unit;

use App\Services\CelebrationService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class CelebrationServiceTest extends TestCase
{
    public function test_jours_avant_le_prochain_anniversaire(): void
    {
        $today = Carbon::parse('2026-10-07')->startOfDay();

        $this->assertSame(0, CelebrationService::daysUntilAnniversary(Carbon::parse('1990-10-07'), $today, 15));
        $this->assertSame(1, CelebrationService::daysUntilAnniversary(Carbon::parse('1990-10-08'), $today, 15));
        $this->assertNull(CelebrationService::daysUntilAnniversary(Carbon::parse('1990-10-23'), $today, 15));
        // Hier : la prochaine occurrence est l'an prochain → hors fenêtre.
        $this->assertNull(CelebrationService::daysUntilAnniversary(Carbon::parse('1990-10-06'), $today, 15));
        // Fenêtre 0 = jour J strict.
        $this->assertNull(CelebrationService::daysUntilAnniversary(Carbon::parse('1990-10-08'), $today, 0));
    }

    public function test_29_fevrier_reporte_au_1er_mars_les_annees_non_bissextiles(): void
    {
        $today = Carbon::parse('2026-03-01')->startOfDay();
        $this->assertSame(0, CelebrationService::daysUntilAnniversary(Carbon::parse('2000-02-29'), $today, 15));
    }
}
