@php
    $serif = "Georgia, 'Times New Roman', serif";
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $features = [
        [
            'title' => 'Le réseau de la bande',
            'text' => 'Qui apparaît avec qui, tissé tout seul à partir de vos photos : plus vous êtes souvent ensemble sur les photos, plus le lien est épais.',
        ],
        [
            'title' => 'Vos souvenirs ensemble',
            'text' => 'Sur la fiche de chacun, vos photos communes, de la première à la dernière, rangées par soirée, voyage ou week-end.',
        ],
        [
            'title' => 'Des albums partagés au groupe',
            'text' => 'Les albums partagés avec « '.$circleName.' » sont visibles de tous ses membres, et les visages y sont reconnus automatiquement.',
        ],
        [
            'title' => '« Comment vous êtes-vous connus ? »',
            'text' => 'Chacun raconte sa version : l’amphi de première année, le stage de voile, la soirée chez un ami commun…',
        ],
        [
            'title' => 'Une page d’accueil vivante',
            'text' => 'Les souvenirs du même jour il y a quelques années, les anniversaires des amis, et quelques petites questions pour mettre des noms sur les visages.',
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
<body style="margin:0; padding:0; background:#f0f7f5; -webkit-text-size-adjust:100%;">
{{-- Aperçu dans la boîte de réception --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">
    {{ $inviterFirstName }} {{ $tv('t’invite', 'vous invite') }} à rejoindre « {{ $circleName }} » : vos photos et vos souvenirs entre amis, au même endroit.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f7f5;">
<tr><td align="center" style="padding:28px 12px;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; font-family:{{ $sans }}; color:#44403c;">

    {{-- En-tête --}}
    <tr><td align="center" style="padding:0 0 20px;">
        <div style="font-family:{{ $serif }}; font-size:28px; color:#1c1917; letter-spacing:-0.3px;">
            <span style="display:inline-block; width:30px; height:30px; line-height:30px; border-radius:8px; background:#ccfbf1; color:#0f766e; font-size:17px; vertical-align:3px; margin-right:6px;">✦</span>MemoryLane
        </div>
        <div style="font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#a8a29e; margin-top:6px;">Nos souvenirs entre amis</div>
    </td></tr>

    <tr><td style="background:#ffffff; border-radius:18px; padding:36px 32px 32px; border:1px solid #d9ece7;">

        {{-- Introduction --}}
        <p style="font-family:{{ $serif }}; font-size:24px; line-height:1.3; color:#1c1917; margin:0 0 18px;">
            {{ $tv('Salut', 'Bonjour') }} {{ $firstName }},
        </p>

        @if ($byAuthor)
            <p style="font-size:16px; line-height:1.65; margin:0 0 16px;">
                C’est {{ $inviterFirstName }}. J’ai construit moi-même, sur mon temps libre, <strong>MemoryLane</strong> :
                un site privé pour rassembler nos photos et les histoires qui vont avec.
                J’aimerais beaucoup que {{ $tv('tu rejoignes', 'vous rejoigniez') }} notre groupe, <strong>« {{ $circleName }} »</strong>.
            </p>
        @else
            <p style="font-size:16px; line-height:1.65; margin:0 0 16px;">
                {{ $inviterFirstName }} {{ $tv('t’invite', 'vous invite') }} à rejoindre <strong>« {{ $circleName }} »</strong> sur <strong>MemoryLane</strong>,
                un site privé de souvenirs que {{ $authorFirstName }} a construit lui-même, sur son temps libre,
                pour rassembler les photos et les histoires qui vont avec.
            </p>
        @endif

        @if ($personalMessage)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 22px;">
                <tr><td style="border-left:3px solid #14b8a6; background:#f0fdfa; border-radius:0 10px 10px 0; padding:14px 18px;">
                    <div style="font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#0f766e; margin-bottom:6px;">Le mot de {{ $inviterFirstName }}</div>
                    <div style="font-family:{{ $serif }}; font-size:16px; line-height:1.6; font-style:italic; color:#44403c;">{!! nl2br(e($personalMessage)) !!}</div>
                </td></tr>
            </table>
        @endif

        {{-- Sa place dans le groupe --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 28px; background:#f0fdfa; border:1px solid #99f6e4; border-radius:16px;">
            <tr><td style="padding:20px 22px 6px;">
                <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#0f766e; font-weight:bold;">
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
                            <div style="width:84px; height:84px; line-height:84px; border-radius:42px; background:#ccfbf1; color:#0f766e; text-align:center; font-family:{{ $serif }}; font-size:36px; border:3px solid #ffffff;">{{ $initial }}</div>
                        @endif
                    </td>
                    <td valign="middle">
                        <div style="font-family:{{ $serif }}; font-size:21px; line-height:1.25; color:#1c1917;">{{ $personName }}</div>
                        <div style="font-size:14px; color:#78716c; margin-top:4px;">« {{ $circleName }} »</div>
                    </td>
                </tr></table>
                <p style="font-size:15px; line-height:1.6; margin:14px 0 0; color:#57534e;">
                    @if ($circlePhotoCount > 0)
                        Déjà {{ $circlePhotoCount }} photo{{ $circlePhotoCount > 1 ? 's' : '' }} partagée{{ $circlePhotoCount > 1 ? 's' : '' }} dans le groupe{{ $circlePeopleCount > 1 ? ', avec '.$circlePeopleCount.' personnes' : '' }} :
                        {{ $tv('ta fiche t’attend', 'votre fiche vous attend') }}.
                    @else
                        {{ $tv('Ta fiche t’attend', 'Votre fiche vous attend') }} : il ne manque plus que vos photos.
                    @endif
                </p>
                <p style="margin:16px 0 0;">
                    <a href="{{ $url }}" target="_blank"
                       style="display:inline-block; background:#0d9488; color:#ffffff; text-decoration:none; font-size:15px; font-weight:bold; padding:10px 20px; border-radius:10px;">
                        Rejoindre le groupe →
                    </a>
                </p>
            </td></tr>
        </table>

        {{-- Fonctionnalités --}}
        <h2 style="font-family:{{ $serif }}; font-size:21px; font-weight:normal; color:#1c1917; margin:0 0 6px;">
            Ce que {{ $tv('tu y trouveras', 'vous y trouverez') }}
        </h2>
        @foreach ($features as $feature)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0 0;">
                <tr><td>
                    <div style="font-size:16px; font-weight:bold; color:#1c1917; margin-bottom:4px;">{{ $feature['title'] }}</div>
                    <div style="font-size:15px; line-height:1.6; color:#57534e;">{{ $feature['text'] }}</div>
                </td></tr>
            </table>
        @endforeach

        {{-- Ses propres groupes --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdfa; border-radius:14px; margin:28px 0 18px;">
            <tr><td style="padding:22px 22px;">
                <h2 style="font-family:{{ $serif }}; font-size:20px; font-weight:normal; color:#1c1917; margin:0 0 10px;">
                    {{ $tv('Tes', 'Vos') }} propres groupes d’amis
                </h2>
                <p style="font-size:15px; line-height:1.65; margin:0;">
                    {{ $tv('Tu peux', 'Vous pouvez') }} aussi créer {{ $tv('tes', 'vos') }} propres groupes — les copains du lycée,
                    le club de sport, les voisins — et y inviter qui {{ $tv('tu veux', 'vous voulez') }}.
                    Chaque groupe est étanche : ce qui y est partagé reste dans le groupe.
                </p>
            </td></tr>
        </table>

        {{-- Et la famille ? --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf7f2; border-radius:14px; margin:0 0 30px;">
            <tr><td style="padding:22px 22px;">
                <h2 style="font-family:{{ $serif }}; font-size:20px; font-weight:normal; color:#1c1917; margin:0 0 10px;">
                    Et la famille ?
                </h2>
                <p style="font-size:15px; line-height:1.65; margin:0;">
                    MemoryLane a aussi un mode famille : un arbre généalogique et la mémoire de toute une famille.
                    Famille et amis sont deux mondes séparés : {{ $tv('tes', 'vos') }} amis ne voient pas {{ $tv('ta', 'votre') }} famille, et inversement —
                    sauf pour un album que {{ $tv('tu choisis', 'vous choisissez') }} de partager avec les deux, comme celui d’un mariage.
                </p>
            </td></tr>
        </table>

        {{-- Bouton --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 14px;">
                <a href="{{ $url }}" target="_blank"
                   style="display:inline-block; background:#0d9488; color:#ffffff; text-decoration:none; font-size:17px; font-weight:bold; padding:15px 34px; border-radius:12px;">
                    Créer mon accès
                </a>
            </td></tr>
            <tr><td align="center" style="font-size:14px; line-height:1.6; color:#78716c; padding:0 10px 6px;">
                Ça prend une minute : {{ $tv('tu choisis', 'vous choisissez') }} simplement un mot de passe.
                @if ($expiresAt)<br>Ce lien est personnel et valable jusqu’au {{ $expiresAt }}.@endif
            </td></tr>
            <tr><td align="center" style="font-size:12px; line-height:1.5; color:#a8a29e; padding:4px 10px 28px; word-break:break-all;">
                Si le bouton ne fonctionne pas, {{ $tv('copie', 'copiez') }} ce lien dans {{ $tv('ton', 'votre') }} navigateur :<br>
                <a href="{{ $url }}" style="color:#0f766e;">{{ $url }}</a>
            </td></tr>
        </table>
        <p style="font-size:15px; line-height:1.65; margin:0 0 28px;">
            Ensuite, {{ $tv('tu te connectes', 'vous vous connectez') }} simplement sur
            <a href="{{ $appUrl }}" style="color:#0f766e;">{{ $appHost }}</a>,
            et {{ $tv('tu peux', 'vous pouvez') }} l’installer sur {{ $tv('ton', 'votre') }} téléphone comme une vraie application.
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
                    {{ $tv('écris-moi', 'écrivez-moi') }} à <a href="mailto:{{ $authorEmail }}" style="color:#0f766e;">{{ $authorEmail }}</a>@if ($contactPhone) ou {{ $tv('appelle-moi', 'appelez-moi') }} au {{ $contactPhone }}@endif.
                @endif
            @else
                {{ $tv('N’hésite pas', 'N’hésitez pas') }} à écrire à {{ $authorFirstName }}
                @if ($authorEmail)
                    (<a href="mailto:{{ $authorEmail }}" style="color:#0f766e;">{{ $authorEmail }}</a>)
                @endif
                {{ $contactPhone ? 'ou à l’appeler au '.$contactPhone : '' }} : tous les retours l’intéressent.
            @endif
        </p>

        <p style="font-size:14px; line-height:1.6; color:#78716c; margin:0 0 26px;">
            Pas un réseau social : ni fil d’actualité, ni « j’aime », ni publicité. Rien n’est public — seuls les membres invités du groupe
            ont accès à ce qui y est partagé, et {{ $tv('tes', 'vos') }} photos restent privées tant que {{ $tv('tu ne choisis pas', 'vous ne choisissez pas') }} de les partager.
        </p>

        {{-- Signature --}}
        <p style="font-family:{{ $serif }}; font-size:18px; line-height:1.5; color:#1c1917; margin:0;">
            À très vite sur MemoryLane,<br>{{ $inviterFirstName }}
        </p>
    </td></tr>

    {{-- Pied de page --}}
    <tr><td align="center" style="padding:22px 20px 0; font-size:12px; line-height:1.6; color:#a8a29e;">
        MemoryLane · nos souvenirs entre amis · <a href="{{ $appUrl }}" style="color:#a8a29e;">{{ $appHost }}</a><br>
        {{ $tv('Tu reçois', 'Vous recevez') }} ce mail parce que {{ $inviterFirstName }} a pensé à {{ $tv('toi', 'vous') }}.
        Si ce message ne {{ $tv('t’était', 'vous était') }} pas destiné, {{ $tv('ignore-le', 'ignorez-le') }} simplement.
    </td></tr>
</table>

</td></tr>
</table>
</body>
</html>
