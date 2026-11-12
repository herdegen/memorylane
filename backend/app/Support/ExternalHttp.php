<?php

namespace App\Support;

/**
 * Identité de l'appli auprès des services publics qu'elle interroge
 * (Wikipédia, Commons, Nominatim, Photon, BAN). Leurs règles demandent un
 * User-Agent qui permette de joindre l'exploitant : sans adresse du site ni
 * contact, Wikipédia répond « trop de requêtes » (429) dès quelques appels.
 */
class ExternalHttp
{
    public static function userAgent(): string
    {
        $contact = array_filter([config('app.url'), config('mail.from.address')]);

        return 'MemoryLane/1.3'.($contact ? ' ('.implode('; ', $contact).')' : '');
    }
}
