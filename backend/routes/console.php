<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Les albums intelligents restent à jour même sans visite
Schedule::command('memorylane:refresh-smart-albums')->dailyAt('04:00');

// Le dimanche en fin d'après-midi (heure de Paris — l'app tourne en UTC) :
// résumé de la semaine à toute la famille, par mail et en push pour ceux qui
// l'ont activé.
Schedule::command('memorylane:weekly-digest')->weeklyOn(0, '18:30')->timezone('Europe/Paris');

// Le matin : push « anniversaires du jour » (cercle proche), une seule
// notification par membre, seulement s'il y en a.
Schedule::command('memorylane:birthday-push')->dailyAt('09:00')->timezone('Europe/Paris');

// Nettoyage des uploads multipart abandonnés (parts orphelines S3, issue #23)
Schedule::command('memorylane:prune-upload-sessions')->dailyAt('03:30');
// Départs de l'application arrivés au bout des 30 jours de grâce.
Schedule::command('memorylane:process-departures')->dailyAt('03:45');

// Fêtes des prénoms : précharge le cache Nominis pour les prochains jours.
Schedule::command('memorylane:refresh-namedays')->dailyAt('00:10');

// Sauvegarde de la base chaque nuit : dump local + copie sur Scaleway
// (_backups/db/), rotation 7 jours / 4 dimanches / 6 mois.
Schedule::command('memorylane:backup-db')->dailyAt('02:45')->timezone('Europe/Paris')->withoutOverlapping();

// Test de restauration de la dernière sauvegarde, le 2 de chaque mois.
Schedule::command('memorylane:backup-db --check')->monthlyOn(2, '05:15')->timezone('Europe/Paris');

// Supervision : sauvegardes, files d'attente, disque, erreurs — mail aux
// administrateurs en cas de problème, widget « État du système » dans l'admin.
Schedule::command('memorylane:health-check')->hourlyAt(17)->withoutOverlapping();

// Les échecs de tâches de plus d'un mois n'apprennent plus rien.
Schedule::command('queue:prune-failed --hours=720')->weeklyOn(1, '04:30');
