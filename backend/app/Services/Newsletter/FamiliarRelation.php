<?php

namespace App\Services\Newsletter;

/**
 * Lien de parenté tutoyé, pour les lettres du dimanche (écrites au « tu ») :
 * « votre grand-mère » → « ta grand-mère », « votre arrière-grand-mère » →
 * « ton arrière-grand-mère » (« ton » devant une voyelle), « votre oncle par
 * alliance » → « ton oncle par alliance ».
 *
 * GenealogyService::relationLabel reste au vouvoiement pour le reste du
 * site ; ses libellés anglais (your) et espagnols (tu) passent tels quels.
 */
final class FamiliarRelation
{
    /** @param  ?string  $gender  genre de la personne désignée (celui qui a choisi la forme du libellé) */
    public static function label(?string $label, ?string $gender): ?string
    {
        if ($label === null) {
            return null;
        }
        if (str_starts_with($label, 'vos ')) {
            return 'tes '.substr($label, 4);
        }
        if (! str_starts_with($label, 'votre ')) {
            return $label;
        }

        $noun = substr($label, 6);
        // Au féminin « ta », sauf devant une voyelle ou un h muet : « ton arrière-grand-mère ».
        $ta = $gender === 'F' && ! preg_match('/^[aeiouyàâäéèêëîïôöùûüh]/iu', $noun);

        return ($ta ? 'ta ' : 'ton ').$noun;
    }
}
