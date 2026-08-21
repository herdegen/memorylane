<?php

namespace App\Exceptions;

/**
 * La question n'a plus lieu d'être au moment d'appliquer la réponse : la
 * donnée a été remplie entre-temps (autre utilisateur, édition manuelle).
 * Le contrôleur la traduit en 409 et enchaîne sur la question suivante.
 */
class StaleQuestException extends \RuntimeException
{
}
