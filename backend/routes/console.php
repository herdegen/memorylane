<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Les albums intelligents restent à jour même sans visite
Schedule::command('memorylane:refresh-smart-albums')->dailyAt('04:00');

// Le dimanche en fin d'après-midi : résumé de la semaine à toute la famille
Schedule::command('memorylane:weekly-digest')->weeklyOn(0, '18:00');
