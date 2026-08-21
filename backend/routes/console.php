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

// Nettoyage des uploads multipart abandonnés (parts orphelines S3, issue #23)
Schedule::command('memorylane:prune-upload-sessions')->dailyAt('03:30');

// Fêtes des prénoms : précharge le cache Nominis pour les prochains jours.
Schedule::command('memorylane:refresh-namedays')->dailyAt('00:10');
