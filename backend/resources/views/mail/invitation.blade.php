@php
    $serif = "Georgia, 'Times New Roman', serif";
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $features = [
        'accueil' => [
            'title' => 'Chaque jour, une page d’accueil vivante',
            'text' => 'Les souvenirs du même jour il y a 5, 10 ou 20 ans, la personne du jour, les anniversaires et les fêtes de la famille.',
        ],
        'arbre' => [
            'title' => 'L’arbre de toute la famille',
            'text' => "Plus de {$peopleCount} personnes, de génération en génération. ".$tv('Touche', 'Touchez').' une carte pour ouvrir la fiche de chacun et voir le lien de parenté qui vous relie.',
        ],
        'fiche' => [
            'title' => 'La vie de chacun, racontée',
            'text' => 'Sur chaque fiche : ses photos, sa frise de vie, ses grands moments, et un diaporama animé qui voyage de lieu en lieu.',
        ],
        'albums' => [
            'title' => 'Nos photos, enfin réunies',
            'text' => "Plus de {$photoCount} photos et vidéos, classées par date, par lieu et par personne. Les visages sont reconnus automatiquement, et les albums se partagent entre nous en un clic.",
        ],
        'carte' => [
            'title' => 'La carte de nos souvenirs',
            'text' => 'Chaque photo à sa place sur la carte : les vacances, les maisons de famille, les cousinades.',
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>MemoryLane</title>
</head>
<body style="margin:0; padding:0; background:#f6f1e9; -webkit-text-size-adjust:100%;">
{{-- Aperçu dans la boîte de réception --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">
    {{ $byAuthor ? $tv('J’ai construit un site pour notre famille, et j’aimerais que tu en fasses partie.', 'J’ai construit un site pour notre famille, et j’aimerais que vous en fassiez partie.') : $tv("Ta place est déjà dans l'arbre de la famille.", "Votre place est déjà dans l'arbre de la famille.") }}
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f1e9;">
<tr><td align="center" style="padding:28px 12px;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; font-family:{{ $sans }}; color:#44403c;">

    {{-- En-tête --}}
    <tr><td align="center" style="padding:0 0 20px;">
        <div style="font-family:{{ $serif }}; font-size:28px; color:#1c1917; letter-spacing:-0.3px;">
            <span style="display:inline-block; width:30px; height:30px; line-height:30px; border-radius:8px; background:#fde68a; color:#b45309; font-size:17px; vertical-align:3px; margin-right:6px;">✦</span>MemoryLane
        </div>
        <div style="font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#a8a29e; margin-top:6px;">La mémoire de notre famille</div>
    </td></tr>

    <tr><td style="background:#ffffff; border-radius:18px; padding:36px 32px 32px; border:1px solid #ede7dc;">

        {{-- Introduction --}}
        <p style="font-family:{{ $serif }}; font-size:24px; line-height:1.3; color:#1c1917; margin:0 0 18px;">
            {{ $tv('Salut', 'Bonjour') }} {{ $firstName }},
        </p>

        @if ($byAuthor)
            <p style="font-size:16px; line-height:1.65; margin:0 0 16px;">
                C’est {{ $inviterFirstName }}.
                @if ($repliesToAuthor)
                    {{ $tv('Je t’écris depuis mon adresse perso', 'Je vous écris depuis mon adresse personnelle') }}, parce que ce message est un peu particulier :
                @else
                    Ce message est un peu particulier :
                @endif
                depuis plusieurs mois, je construis moi-même, sur mon temps libre, un site pour notre famille.
                Il s’appelle <strong>MemoryLane</strong>, et j’aimerais beaucoup que {{ $tv('tu en fasses partie', 'vous en fassiez partie') }}.
            </p>
        @else
            <p style="font-size:16px; line-height:1.65; margin:0 0 16px;">
                {{ $inviterFirstName }} {{ $tv('t’invite', 'vous invite') }} à rejoindre <strong>MemoryLane</strong>, le site de notre famille.
                C’est un projet que {{ $authorFirstName }} a construit lui-même, sur son temps libre, pour rassembler nos photos et notre histoire —
                et il ne prend tout son sens que si chacun y a sa place.
            </p>
        @endif

        @if ($personalMessage)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 22px;">
                <tr><td style="border-left:3px solid #f59e0b; background:#fffbeb; border-radius:0 10px 10px 0; padding:14px 18px;">
                    <div style="font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#b45309; margin-bottom:6px;">Le mot de {{ $inviterFirstName }}</div>
                    <div style="font-family:{{ $serif }}; font-size:16px; line-height:1.6; font-style:italic; color:#44403c;">{!! nl2br(e($personalMessage)) !!}</div>
                </td></tr>
            </table>
        @endif

        {{-- Sa fiche dans l'arbre --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 28px; background:#fef7e6; border:1px solid #fde68a; border-radius:16px;">
            <tr><td style="padding:20px 22px 6px;">
                <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#b45309; font-weight:bold;">
                    {{ $tv('Ta place est déjà là', 'Votre place est déjà là') }}
                </div>
            </td></tr>
            <tr><td style="padding:10px 22px 20px;">
                <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                    <td valign="middle" style="padding-right:16px;">
                        @if ($avatar)
                            <img src="{{ $message->embedData($avatar, 'portrait.jpg', 'image/jpeg') }}" width="84" height="84" alt="{{ $personName }}"
                                 style="display:block; width:84px; height:84px; border-radius:42px; border:3px solid #ffffff; object-fit:cover;">
                        @else
                            <div style="width:84px; height:84px; line-height:84px; border-radius:42px; background:#fde68a; color:#b45309; text-align:center; font-family:{{ $serif }}; font-size:36px; border:3px solid #ffffff;">{{ $initial }}</div>
                        @endif
                    </td>
                    <td valign="middle">
                        <div style="font-family:{{ $serif }}; font-size:21px; line-height:1.25; color:#1c1917;">{{ $personName }}</div>
                        @if ($birthLine)
                            <div style="font-size:14px; color:#78716c; margin-top:4px;">{{ $birthLine }}</div>
                        @endif
                    </td>
                </tr></table>
                <p style="font-size:15px; line-height:1.6; margin:14px 0 0; color:#57534e;">
                    @if ($parentsLine){{ $parentsLine }} @endif
                    {{ $tv('Tu fais partie', 'Vous faites partie') }} des plus de {{ $peopleCount }} personnes de notre arbre :
                    {{ $tv('ta fiche t’attend', 'votre fiche vous attend') }}.
                </p>
                <p style="margin:16px 0 0;">
                    <a href="{{ $url }}" target="_blank"
                       style="display:inline-block; background:#d97706; color:#ffffff; text-decoration:none; font-size:15px; font-weight:bold; padding:10px 20px; border-radius:10px;">
                        Créer mon accès →
                    </a>
                </p>
            </td></tr>
        </table>

        {{-- Pourquoi --}}
        <h2 style="font-family:{{ $serif }}; font-size:21px; font-weight:normal; color:#1c1917; margin:0 0 12px;">
            {{ $byAuthor ? 'Pourquoi j’ai fait ça' : 'Pourquoi '.$authorFirstName.' a créé ce site' }}
        </h2>
        @if (! $byAuthor)<div style="border-left:3px solid #e7e5e4; padding-left:16px; font-style:italic;">@endif
        <p style="font-size:16px; line-height:1.65; margin:0 0 14px;">
            Nos photos de famille sont éparpillées : dans nos téléphones, dans des albums papier chez les uns et les autres,
            sur des disques durs qu’on n’ouvre jamais. Et les histoires qui vont avec — qui est sur la photo, où c’était,
            ce qui s’est passé ce jour-là — ne vivent souvent que dans la mémoire de quelques-uns.
        </p>
        <p style="font-size:16px; line-height:1.65; margin:0 0 {{ $byAuthor ? '28' : '6' }}px;">
            J’ai voulu un endroit à nous : privé, sans publicité, sans réseau social, où tout ça est rassemblé, rangé
            et transmis — aux plus jeunes comme à ceux qui viendront après.
        </p>
        @if (! $byAuthor)
            <p style="font-size:14px; color:#78716c; margin:0 0 28px; font-style:normal;">— {{ $authorFirstName }}</p>
            </div>
        @endif

        {{-- Fonctionnalités --}}
        <h2 style="font-family:{{ $serif }}; font-size:21px; font-weight:normal; color:#1c1917; margin:0 0 6px;">
            Ce que {{ $tv('tu y trouveras', 'vous y trouverez') }}
        </h2>
        @foreach ($features as $key => $feature)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 0;">
                @if (isset($screenshots[$key]))
                    <tr><td style="padding-bottom:12px;">
                        <img src="{{ $message->embed($screenshots[$key]) }}" width="536" alt="{{ $feature['title'] }}"
                             style="display:block; width:100%; max-width:536px; height:auto; border-radius:12px; border:1px solid #e7e5e4;">
                    </td></tr>
                @endif
                <tr><td>
                    <div style="font-size:16px; font-weight:bold; color:#1c1917; margin-bottom:4px;">{{ $feature['title'] }}</div>
                    <div style="font-size:15px; line-height:1.6; color:#57534e;">{{ $feature['text'] }}</div>
                </td></tr>
            </table>
        @endforeach
        <p style="font-size:15px; line-height:1.6; color:#57534e; margin:18px 0 28px;">
            <strong style="color:#1c1917;">Et aussi :</strong>
            {{ $tv('enregistrer ta voix', 'enregistrer votre voix') }} pour raconter une photo, pour que les histoires ne se perdent pas ;
            un petit résumé de la semaine le dimanche ; et une application à installer sur {{ $tv('ton', 'votre') }} téléphone.
        </p>

        {{-- Ça ne marchera qu'avec vous --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf7f2; border-radius:14px; margin:0 0 30px;">
            <tr><td style="padding:22px 22px;">
                <h2 style="font-family:{{ $serif }}; font-size:20px; font-weight:normal; color:#1c1917; margin:0 0 10px;">
                    Ça ne marchera qu’avec vous
                </h2>
                <p style="font-size:15px; line-height:1.65; margin:0;">
                    Le site est prêt, mais un album de famille ne se remplit pas tout seul. Il ne prendra vie que si chacun y dépose
                    ses photos, met des noms sur les visages et raconte ce qu’il sait. Même cinq minutes de temps en temps, ça compte :
                    l’accueil {{ $tv('te posera', 'vous posera') }} d’ailleurs quelques petites questions faciles
                    (« Qui est sur cette photo ? », « En quelle année est-elle née ? »).
                </p>
            </td></tr>
        </table>

        {{-- Bouton --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 14px;">
                <a href="{{ $url }}" target="_blank"
                   style="display:inline-block; background:#d97706; color:#ffffff; text-decoration:none; font-size:17px; font-weight:bold; padding:15px 34px; border-radius:12px;">
                    Créer mon accès
                </a>
            </td></tr>
            <tr><td align="center" style="font-size:14px; line-height:1.6; color:#78716c; padding:0 10px 6px;">
                Ça prend une minute : {{ $tv('tu choisis', 'vous choisissez') }} simplement un mot de passe.
                @if ($expiresAt)<br>Ce lien est personnel et valable jusqu’au {{ $expiresAt }}.@endif
            </td></tr>
            <tr><td align="center" style="font-size:12px; line-height:1.5; color:#a8a29e; padding:4px 10px 28px; word-break:break-all;">
                Si le bouton ne fonctionne pas, {{ $tv('copie', 'copiez') }} ce lien dans {{ $tv('ton', 'votre') }} navigateur :<br>
                <a href="{{ $url }}" style="color:#b45309;">{{ $url }}</a>
            </td></tr>
        </table>
        <p style="font-size:15px; line-height:1.65; margin:0 0 28px;">
            Ensuite, {{ $tv('tu te connectes', 'vous vous connectez') }} simplement sur
            <a href="{{ $appUrl }}" style="color:#b45309;">{{ $appHost }}</a>,
            et {{ $tv('tu peux', 'vous pouvez') }} l’installer sur {{ $tv('ton', 'votre') }} téléphone comme une vraie application.
        </p>

        {{-- À ton tour --}}
        <h2 style="font-family:{{ $serif }}; font-size:21px; font-weight:normal; color:#1c1917; margin:0 0 10px;">
            À {{ $tv('ton', 'votre') }} tour
        </h2>
        <p style="font-size:16px; line-height:1.65; margin:0 0 28px;">
            Une fois sur le site, {{ $tv('tu pourras', 'vous pourrez') }} inviter {{ $tv('toi-même', 'vous-même') }} les proches qui sont dans l’arbre
            (bouton « Inviter sur MemoryLane » sur leur fiche), et ajouter ceux qui manquent : un conjoint, un nouveau-né,
            un cousin oublié… Chaque modification est gardée dans un historique : impossible de casser quoi que ce soit.
        </p>

        {{-- Contact --}}
        <h2 style="font-family:{{ $serif }}; font-size:21px; font-weight:normal; color:#1c1917; margin:0 0 10px;">
            Une question, un bug, une idée ?
        </h2>
        <p style="font-size:16px; line-height:1.65; margin:0 0 22px;">
            C’est un projet fait maison : il y aura forcément quelques petits défauts.
            @if ($byAuthor)
                {{ $tv('N’hésite surtout pas', 'N’hésitez surtout pas') }} à me le dire —
                @if ($repliesToAuthor)
                    il suffit de répondre à ce mail{{ $contactPhone ? ', ou de m’appeler au '.$contactPhone : '' }}.
                @else
                    {{ $tv('écris-moi', 'écrivez-moi') }} à <a href="mailto:{{ $authorEmail }}" style="color:#b45309;">{{ $authorEmail }}</a>@if ($contactPhone) ou {{ $tv('appelle-moi', 'appelez-moi') }} au {{ $contactPhone }}@endif.
                @endif
                Tous les retours m’intéressent.
            @else
                {{ $tv('N’hésite pas', 'N’hésitez pas') }} à écrire à {{ $authorFirstName }}
                @if ($authorEmail)
                    (<a href="mailto:{{ $authorEmail }}" style="color:#b45309;">{{ $authorEmail }}</a>)
                @endif
                {{ $contactPhone ? 'ou à l’appeler au '.$contactPhone : '' }} : tous les retours l’intéressent.
            @endif
        </p>

        <p style="font-size:14px; line-height:1.6; color:#78716c; margin:0 0 26px;">
            Rien n’est public : seuls les membres de la famille invités ont accès au site, et {{ $tv('tes', 'vos') }} photos restent privées
            tant que {{ $tv('tu ne choisis pas', 'vous ne choisissez pas') }} de les partager.
        </p>

        {{-- Signature --}}
        <p style="font-family:{{ $serif }}; font-size:18px; line-height:1.5; color:#1c1917; margin:0;">
            @if ($byAuthor)
                {{ $tv('Je t’embrasse', 'Bien affectueusement') }},<br>{{ $inviterFirstName }}
            @else
                À très vite sur MemoryLane,<br>{{ $inviterFirstName }}
            @endif
        </p>
    </td></tr>

    {{-- Pied de page --}}
    <tr><td align="center" style="padding:22px 20px 0; font-size:12px; line-height:1.6; color:#a8a29e;">
        MemoryLane · le site privé de notre famille · <a href="{{ $appUrl }}" style="color:#a8a29e;">{{ $appHost }}</a><br>
        {{ $tv('Tu reçois', 'Vous recevez') }} ce mail parce que {{ $inviterFirstName }} a pensé à {{ $tv('toi', 'vous') }}.
        Si ce message ne {{ $tv('t’était', 'vous était') }} pas destiné, {{ $tv('ignore-le', 'ignorez-le') }} simplement.
    </td></tr>
</table>

</td></tr>
</table>
</body>
</html>
