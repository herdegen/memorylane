@php
    $serif = "Georgia, 'Times New Roman', serif";
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $features = [
        [
            'title' => __('Le réseau de la bande'),
            'text' => __('Qui apparaît avec qui, tissé tout seul à partir de vos photos : plus vous êtes souvent ensemble sur les photos, plus le lien est épais.'),
        ],
        [
            'title' => __('Vos souvenirs ensemble'),
            'text' => __('Sur la fiche de chacun, vos photos communes, de la première à la dernière, rangées par soirée, voyage ou week-end.'),
        ],
        [
            'title' => __('Des albums partagés au groupe'),
            'text' => __('Les albums partagés avec « :circle » sont visibles de tous ses membres, et les visages y sont reconnus automatiquement.', ['circle' => $circleName]),
        ],
        [
            'title' => __('« Comment vous êtes-vous connus ? »'),
            'text' => __('Chacun raconte sa version : l’amphi de première année, le stage de voile, la soirée chez un ami commun…'),
        ],
        [
            'title' => __('Une page d’accueil vivante'),
            'text' => __('Les souvenirs du même jour il y a quelques années, les anniversaires des amis, et quelques petites questions pour mettre des noms sur les visages.'),
        ],
    ];
    // Liens insérés dans les phrases traduites (données échappées).
    $appLink = '<a href="'.e($appUrl).'" style="color:#0f766e;">'.e($appHost).'</a>';
    $authorMail = '<a href="mailto:'.e($authorEmail).'" style="color:#0f766e;">'.e($authorEmail).'</a>';
    // Phrase de contact : une phrase complète par cas (tu / vous, e-mail, téléphone).
    $contactValues = ['name' => e($authorFirstName), 'email' => $authorMail, 'phone' => e($contactPhone)];
    if ($byAuthor && $repliesToAuthor) {
        $contact = $contactPhone
            ? $tv(__('N’hésite surtout pas à me le dire — il suffit de répondre à ce mail, ou de m’appeler au :phone.', $contactValues), __('N’hésitez surtout pas à me le dire — il suffit de répondre à ce mail, ou de m’appeler au :phone.', $contactValues))
            : $tv(__('N’hésite surtout pas à me le dire — il suffit de répondre à ce mail.'), __('N’hésitez surtout pas à me le dire — il suffit de répondre à ce mail.'));
    } elseif ($byAuthor) {
        $contact = $contactPhone
            ? $tv(__('N’hésite surtout pas à me le dire — écris-moi à :email ou appelle-moi au :phone.', $contactValues), __('N’hésitez surtout pas à me le dire — écrivez-moi à :email ou appelez-moi au :phone.', $contactValues))
            : $tv(__('N’hésite surtout pas à me le dire — écris-moi à :email.', $contactValues), __('N’hésitez surtout pas à me le dire — écrivez-moi à :email.', $contactValues));
    } elseif ($authorEmail) {
        $contact = $contactPhone
            ? $tv(__('N’hésite pas à écrire à :name (:email) ou à l’appeler au :phone : tous les retours l’intéressent.', $contactValues), __('N’hésitez pas à écrire à :name (:email) ou à l’appeler au :phone : tous les retours l’intéressent.', $contactValues))
            : $tv(__('N’hésite pas à écrire à :name (:email) : tous les retours l’intéressent.', $contactValues), __('N’hésitez pas à écrire à :name (:email) : tous les retours l’intéressent.', $contactValues));
    } else {
        $contact = $contactPhone
            ? $tv(__('N’hésite pas à écrire à :name ou à l’appeler au :phone : tous les retours l’intéressent.', $contactValues), __('N’hésitez pas à écrire à :name ou à l’appeler au :phone : tous les retours l’intéressent.', $contactValues))
            : $tv(__('N’hésite pas à écrire à :name : tous les retours l’intéressent.', $contactValues), __('N’hésitez pas à écrire à :name : tous les retours l’intéressent.', $contactValues));
    }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>MemoryLane</title>
</head>
<body style="margin:0; padding:0; background:#f0f7f5; -webkit-text-size-adjust:100%;">
{{-- Aperçu dans la boîte de réception --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">
    {{ $tv(__(':name t’invite à rejoindre « :circle » : vos photos et vos souvenirs entre amis, au même endroit.', ['name' => $inviterFirstName, 'circle' => $circleName]), __(':name vous invite à rejoindre « :circle » : vos photos et vos souvenirs entre amis, au même endroit.', ['name' => $inviterFirstName, 'circle' => $circleName])) }}
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f7f5;">
<tr><td align="center" style="padding:28px 12px;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; font-family:{{ $sans }}; color:#44403c;">

    {{-- En-tête --}}
    <tr><td align="center" style="padding:0 0 20px;">
        <div style="font-family:{{ $serif }}; font-size:28px; color:#1c1917; letter-spacing:-0.3px;">
            <span style="display:inline-block; width:30px; height:30px; line-height:30px; border-radius:8px; background:#ccfbf1; color:#0f766e; font-size:17px; vertical-align:3px; margin-right:6px;">✦</span>MemoryLane
        </div>
        <div style="font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#a8a29e; margin-top:6px;">{{ __('Nos souvenirs entre amis') }}</div>
    </td></tr>

    <tr><td style="background:#ffffff; border-radius:18px; padding:36px 32px 32px; border:1px solid #d9ece7;">

        {{-- Introduction --}}
        <p style="font-family:{{ $serif }}; font-size:24px; line-height:1.3; color:#1c1917; margin:0 0 18px;">
            {{ $tv(__('Salut :name,', ['name' => $firstName]), __('Bonjour :name,', ['name' => $firstName])) }}
        </p>

        @if ($byAuthor)
            <p style="font-size:16px; line-height:1.65; margin:0 0 16px;">
                {{ __('C’est :name.', ['name' => $inviterFirstName]) }} {!! __('J’ai construit moi-même, sur mon temps libre, <strong>MemoryLane</strong> : un site privé pour rassembler nos photos et les histoires qui vont avec.') !!}
                {!! $tv(__('J’aimerais beaucoup que tu rejoignes notre groupe, <strong>« :circle »</strong>.', ['circle' => e($circleName)]), __('J’aimerais beaucoup que vous rejoigniez notre groupe, <strong>« :circle »</strong>.', ['circle' => e($circleName)])) !!}
            </p>
        @else
            <p style="font-size:16px; line-height:1.65; margin:0 0 16px;">
                {!! $tv(__(':name t’invite à rejoindre <strong>« :circle »</strong> sur <strong>MemoryLane</strong>, un site privé de souvenirs que :author a construit lui-même, sur son temps libre, pour rassembler les photos et les histoires qui vont avec.', ['name' => e($inviterFirstName), 'circle' => e($circleName), 'author' => e($authorFirstName)]), __(':name vous invite à rejoindre <strong>« :circle »</strong> sur <strong>MemoryLane</strong>, un site privé de souvenirs que :author a construit lui-même, sur son temps libre, pour rassembler les photos et les histoires qui vont avec.', ['name' => e($inviterFirstName), 'circle' => e($circleName), 'author' => e($authorFirstName)])) !!}
            </p>
        @endif

        @if ($personalMessage)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 22px;">
                <tr><td style="border-left:3px solid #14b8a6; background:#f0fdfa; border-radius:0 10px 10px 0; padding:14px 18px;">
                    <div style="font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#0f766e; margin-bottom:6px;">{{ __('Le mot de :name', ['name' => $inviterFirstName]) }}</div>
                    <div style="font-family:{{ $serif }}; font-size:16px; line-height:1.6; font-style:italic; color:#44403c;">{!! nl2br(e($personalMessage)) !!}</div>
                </td></tr>
            </table>
        @endif

        {{-- Sa place dans le groupe --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 28px; background:#f0fdfa; border:1px solid #99f6e4; border-radius:16px;">
            <tr><td style="padding:20px 22px 6px;">
                <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#0f766e; font-weight:bold;">
                    {{ $tv(__('Ta place est déjà là'), __('Votre place est déjà là')) }}
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
                        <div style="font-size:14px; color:#78716c; margin-top:4px;">{{ __('« :name »', ['name' => $circleName]) }}</div>
                    </td>
                </tr></table>
                <p style="font-size:15px; line-height:1.6; margin:14px 0 0; color:#57534e;">
                    @if ($circlePhotoCount > 0 && $circlePeopleCount > 1)
                        {{ $tv(trans_choice('Déjà :count photo partagée dans le groupe, avec :people personnes : ta fiche t’attend.|Déjà :count photos partagées dans le groupe, avec :people personnes : ta fiche t’attend.', $circlePhotoCount, ['people' => $circlePeopleCount]), trans_choice('Déjà :count photo partagée dans le groupe, avec :people personnes : votre fiche vous attend.|Déjà :count photos partagées dans le groupe, avec :people personnes : votre fiche vous attend.', $circlePhotoCount, ['people' => $circlePeopleCount])) }}
                    @elseif ($circlePhotoCount > 0)
                        {{ $tv(trans_choice('Déjà :count photo partagée dans le groupe : ta fiche t’attend.|Déjà :count photos partagées dans le groupe : ta fiche t’attend.', $circlePhotoCount), trans_choice('Déjà :count photo partagée dans le groupe : votre fiche vous attend.|Déjà :count photos partagées dans le groupe : votre fiche vous attend.', $circlePhotoCount)) }}
                    @else
                        {{ $tv(__('Ta fiche t’attend : il ne manque plus que vos photos.'), __('Votre fiche vous attend : il ne manque plus que vos photos.')) }}
                    @endif
                </p>
                <p style="margin:16px 0 0;">
                    <a href="{{ $url }}" target="_blank"
                       style="display:inline-block; background:#0d9488; color:#ffffff; text-decoration:none; font-size:15px; font-weight:bold; padding:10px 20px; border-radius:10px;">
                        {{ __('Rejoindre le groupe') }} →
                    </a>
                </p>
            </td></tr>
        </table>

        {{-- Fonctionnalités --}}
        <h2 style="font-family:{{ $serif }}; font-size:21px; font-weight:normal; color:#1c1917; margin:0 0 6px;">
            {{ $tv(__('Ce que tu y trouveras'), __('Ce que vous y trouverez')) }}
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
                    {{ $tv(__('Tes propres groupes d’amis'), __('Vos propres groupes d’amis')) }}
                </h2>
                <p style="font-size:15px; line-height:1.65; margin:0;">
                    {{ $tv(__('Tu peux aussi créer tes propres groupes — les copains du lycée, le club de sport, les voisins — et y inviter qui tu veux.'), __('Vous pouvez aussi créer vos propres groupes — les copains du lycée, le club de sport, les voisins — et y inviter qui vous voulez.')) }}
                    {{ __('Chaque groupe est étanche : ce qui y est partagé reste dans le groupe.') }}
                </p>
            </td></tr>
        </table>

        {{-- Et la famille ? --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf7f2; border-radius:14px; margin:0 0 30px;">
            <tr><td style="padding:22px 22px;">
                <h2 style="font-family:{{ $serif }}; font-size:20px; font-weight:normal; color:#1c1917; margin:0 0 10px;">
                    {{ __('Et la famille ?') }}
                </h2>
                <p style="font-size:15px; line-height:1.65; margin:0;">
                    {{ __('MemoryLane a aussi un mode famille : un arbre généalogique et la mémoire de toute une famille.') }}
                    {{ $tv(__('Famille et amis sont deux mondes séparés : tes amis ne voient pas ta famille, et inversement — sauf pour un album que tu choisis de partager avec les deux, comme celui d’un mariage.'), __('Famille et amis sont deux mondes séparés : vos amis ne voient pas votre famille, et inversement — sauf pour un album que vous choisissez de partager avec les deux, comme celui d’un mariage.')) }}
                </p>
            </td></tr>
        </table>

        {{-- Bouton --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 14px;">
                <a href="{{ $url }}" target="_blank"
                   style="display:inline-block; background:#0d9488; color:#ffffff; text-decoration:none; font-size:17px; font-weight:bold; padding:15px 34px; border-radius:12px;">
                    {{ __('Créer mon accès') }}
                </a>
            </td></tr>
            <tr><td align="center" style="font-size:14px; line-height:1.6; color:#78716c; padding:0 10px 6px;">
                {{ $tv(__('Ça prend une minute : tu choisis simplement un mot de passe.'), __('Ça prend une minute : vous choisissez simplement un mot de passe.')) }}
                @if ($expiresAt)<br>{{ __('Ce lien est personnel et valable jusqu’au :date.', ['date' => $expiresAt]) }}@endif
            </td></tr>
            <tr><td align="center" style="font-size:12px; line-height:1.5; color:#a8a29e; padding:4px 10px 28px; word-break:break-all;">
                {{ $tv(__('Si le bouton ne fonctionne pas, copie ce lien dans ton navigateur :'), __('Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :')) }}<br>
                <a href="{{ $url }}" style="color:#0f766e;">{{ $url }}</a>
            </td></tr>
        </table>
        <p style="font-size:15px; line-height:1.65; margin:0 0 28px;">
            {!! $tv(__('Ensuite, tu te connectes simplement sur :link, et tu peux l’installer sur ton téléphone comme une vraie application.', ['link' => $appLink]), __('Ensuite, vous vous connectez simplement sur :link, et vous pouvez l’installer sur votre téléphone comme une vraie application.', ['link' => $appLink])) !!}
        </p>

        {{-- Contact --}}
        <h2 style="font-family:{{ $serif }}; font-size:21px; font-weight:normal; color:#1c1917; margin:0 0 10px;">
            {{ __('Une question, un bug, une idée ?') }}
        </h2>
        <p style="font-size:16px; line-height:1.65; margin:0 0 22px;">
            {{ __('C’est un projet fait maison : il y aura forcément quelques petits défauts.') }}
            {!! $contact !!}
        </p>

        <p style="font-size:14px; line-height:1.6; color:#78716c; margin:0 0 26px;">
            {{ __('Pas un réseau social : ni fil d’actualité, ni « j’aime », ni publicité.') }}
            {{ $tv(__('Rien n’est public — seuls les membres invités du groupe ont accès à ce qui y est partagé, et tes photos restent privées tant que tu ne choisis pas de les partager.'), __('Rien n’est public — seuls les membres invités du groupe ont accès à ce qui y est partagé, et vos photos restent privées tant que vous ne choisissez pas de les partager.')) }}
        </p>

        {{-- Signature --}}
        <p style="font-family:{{ $serif }}; font-size:18px; line-height:1.5; color:#1c1917; margin:0;">
            {{ __('À très vite sur MemoryLane,') }}<br>{{ $inviterFirstName }}
        </p>
    </td></tr>

    {{-- Pied de page --}}
    <tr><td align="center" style="padding:22px 20px 0; font-size:12px; line-height:1.6; color:#a8a29e;">
        MemoryLane · {{ __('nos souvenirs entre amis') }} · <a href="{{ $appUrl }}" style="color:#a8a29e;">{{ $appHost }}</a><br>
        {{ $tv(__('Tu reçois ce mail parce que :name a pensé à toi.', ['name' => $inviterFirstName]), __('Vous recevez ce mail parce que :name a pensé à vous.', ['name' => $inviterFirstName])) }}
        {{ $tv(__('Si ce message ne t’était pas destiné, ignore-le simplement.'), __('Si ce message ne vous était pas destiné, ignorez-le simplement.')) }}
    </td></tr>
</table>

</td></tr>
</table>
</body>
</html>
