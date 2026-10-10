<?php

namespace Tests\Unit;

use App\Services\PushNotifier;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class PushNotifierQuietHoursTest extends TestCase
{
    private function at(string $parisTime): Carbon
    {
        return Carbon::parse($parisTime, 'Europe/Paris');
    }

    public function test_en_journee_pas_de_report(): void
    {
        $this->assertNull(PushNotifier::quietHoursDelay($this->at('2026-10-07 12:00')));
        $this->assertNull(PushNotifier::quietHoursDelay($this->at('2026-10-07 08:00')));
        $this->assertNull(PushNotifier::quietHoursDelay($this->at('2026-10-07 20:59')));
    }

    public function test_le_soir_report_au_lendemain_8h(): void
    {
        foreach (['2026-10-07 21:00', '2026-10-07 22:30', '2026-10-07 23:59'] as $time) {
            $delay = PushNotifier::quietHoursDelay($this->at($time));
            $this->assertSame('2026-10-08 08:00', $delay->setTimezone('Europe/Paris')->format('Y-m-d H:i'), $time);
        }
    }

    public function test_tot_le_matin_report_au_8h_du_jour_meme(): void
    {
        $delay = PushNotifier::quietHoursDelay($this->at('2026-10-08 07:15'));
        $this->assertSame('2026-10-08 08:00', $delay->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
    }

    public function test_l_heure_de_reference_est_paris_meme_si_l_app_est_en_utc(): void
    {
        // 20:30 UTC = 22:30 Paris en octobre (heure d'été) → heures calmes.
        $delay = PushNotifier::quietHoursDelay(Carbon::parse('2026-10-07 20:30', 'UTC'));
        $this->assertNotNull($delay);
        $this->assertSame('2026-10-08 08:00', $delay->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
    }
}
