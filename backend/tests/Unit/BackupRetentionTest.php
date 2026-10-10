<?php

namespace Tests\Unit;

use App\Console\Commands\BackupDatabase;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BackupRetentionTest extends TestCase
{
    private function keep(string $date): bool
    {
        // Jeudi 8 octobre 2026
        return BackupDatabase::shouldKeep("memorylane-{$date}_024500.dump", Carbon::parse('2026-10-08'));
    }

    public function test_keeps_last_seven_days(): void
    {
        $this->assertTrue($this->keep('2026-10-08'));
        $this->assertTrue($this->keep('2026-10-02'));
        $this->assertFalse($this->keep('2026-09-30')); // mercredi, 8 jours
    }

    public function test_keeps_sundays_for_four_weeks(): void
    {
        $this->assertTrue($this->keep('2026-09-13'));  // dimanche, 25 jours
        $this->assertFalse($this->keep('2026-09-06')); // dimanche, 32 jours
    }

    public function test_keeps_first_of_month_for_six_months(): void
    {
        $this->assertTrue($this->keep('2026-09-01'));
        $this->assertTrue($this->keep('2026-05-01'));
        $this->assertFalse($this->keep('2026-03-01'));
        $this->assertFalse($this->keep('2026-09-02'));
    }

    public function test_unknown_files_are_never_deleted(): void
    {
        $this->assertTrue(BackupDatabase::shouldKeep('pre-ceresa-2026-10-07.sql', Carbon::parse('2026-10-08')));
    }
}
