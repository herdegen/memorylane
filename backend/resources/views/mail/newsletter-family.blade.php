@php
    $serif = "Georgia, 'Times New Roman', serif";
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $card = 'background:#ffffff; border-radius:18px; padding:26px 28px; border:1px solid #ede7dc;';
    $kicker = 'font-size:11px; letter-spacing:1.6px; text-transform:uppercase; color:#b45309; font-weight:bold; margin:0 0 8px;';
    $h2 = "font-family:{$serif}; font-size:22px; line-height:1.3; color:#1c1917; margin:0 0 10px;";
    $p = 'font-size:15px; line-height:1.6; color:#44403c; margin:0 0 14px;';
    $btn = 'display:inline-block; background:#b45309; color:#ffffff; text-decoration:none; font-weight:bold; font-size:14px; padding:11px 20px; border-radius:10px;';
    $link = 'color:#b45309; font-weight:bold; text-decoration:none;';
    $plural = fn (int $n, string $one, string $many) => $n === 1 ? $one : $many;
    $treeTexts = [
        'link_self' => ['Reliez votre compte à votre fiche', 'Trouvez-vous dans la liste des personnes et cliquez « C\'est moi » : l\'arbre, les liens de parenté et vos photos se mettent à votre nom.'],
        'parents' => ['Vos parents ne sont pas encore dans l\'arbre', 'Ajoutez-les depuis votre fiche : c\'est le point de départ de toutes les branches.'],
        'siblings' => ['Et vos frères et sœurs ?', 'Ils n\'apparaissent pas encore à côté de vous dans l\'arbre. Ajoutez-les depuis votre fiche, en quelques secondes.'],
        'partner' => ['Votre conjoint dans l\'arbre ?', 'Ajoutez votre compagne ou votre compagnon depuis votre fiche, avec la date de votre union si vous le souhaitez.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $issue['subject'] }}</title>
</head>
<body style="margin:0; padding:0; background:#f6f1e9; -webkit-text-size-adjust:100%;">
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{{ $issue['preheader'] }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f1e9;">
<tr><td align="center" style="padding:28px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; font-family:{{ $sans }}; color:#44403c;">

    {{-- En-tête --}}
    <tr><td align="center" style="padding:0 0 18px;">
        <div style="font-family:{{ $serif }}; font-size:26px; color:#1c1917;">
            <span style="display:inline-block; width:28px; height:28px; line-height:28px; border-radius:8px; background:#fde68a; color:#b45309; font-size:16px; vertical-align:3px; margin-right:6px;">✦</span>MemoryLane
        </div>
        <div style="font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#a8a29e; margin-top:6px;">La semaine en famille</div>
    </td></tr>

    <tr><td style="padding:0 4px 16px;">
        <p style="font-family:{{ $serif }}; font-size:22px; color:#1c1917; margin:0;">Bonjour {{ $firstName }},</p>
    </td></tr>

@foreach ($issue['sections'] as $s)
    <tr><td style="padding:0 0 14px;"><div style="{{ $card }}">
    @if ($s['key'] === 'new_photos')
            <p style="{{ $kicker }}">Nouveautés</p>
            <p style="{{ $h2 }}">{{ $s['count'] }} {{ $plural($s['count'], 'nouvelle photo', 'nouvelles photos') }} cette semaine</p>
            @if ($s['uploaders'])
                <p style="{{ $p }}">Ajoutées par {{ implode(', ', $s['uploaders']) }}.</p>
            @endif
            @include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])
            <a href="{{ $s['url'] }}" style="{{ $btn }}">Voir les nouveautés</a>

        @elseif ($s['key'] === 'tagged_you')
            <p style="{{ $kicker }}">Vous êtes dessus</p>
            <p style="{{ $h2 }}">On vous a reconnu sur {{ $s['count'] }} {{ $plural($s['count'], 'photo', 'photos') }}</p>
            @include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])
            <a href="{{ $s['url'] }}" style="{{ $link }}">Voir mes photos →</a>

        @elseif ($s['key'] === 'upcoming')
            <p style="{{ $kicker }}">Les 7 prochains jours</p>
            <p style="{{ $h2 }}">Fêtes et anniversaires</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            @foreach ($s['items'] as $item)
                <tr>
                    <td style="padding:6px 0; font-size:15px; width:28px;">{{ ['birthday' => '🎂', 'nameday' => '🥳', 'memorial' => '🕯️'][$item['kind']] }}</td>
                    <td style="padding:6px 0; font-size:15px;"><a href="{{ $item['url'] }}" style="color:#1c1917; font-weight:bold; text-decoration:none;">{{ $item['name'] }}</a> <span style="color:#78716c;">· {{ $item['text'] }}</span></td>
                    <td align="right" style="padding:6px 0; font-size:13px; color:#78716c; white-space:nowrap;">{{ $item['when'] }}</td>
                </tr>
            @endforeach
            </table>

        @elseif ($s['key'] === 'family_activity')
            <p style="{{ $kicker }}">La famille cette semaine</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            @foreach ($s['lines'] as $line)
                <tr>
                    <td style="padding:5px 12px 5px 0; font-family:{{ $serif }}; font-size:24px; color:#b45309; width:72px; text-align:right; vertical-align:top;">{{ number_format($line['count'], 0, ',', ' ') }}</td>
                    <td style="padding:9px 0 5px; font-size:15px; vertical-align:top;">{{ $line['label'] }}@if ($line['detail']) <span style="color:#78716c;">{{ $line['detail'] }}</span>@endif</td>
                </tr>
            @endforeach
            </table>

        @elseif ($s['key'] === 'person_of_week')
            <p style="{{ $kicker }}">La personne de la semaine</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                @if ($s['portrait'])
                    <td width="120" style="vertical-align:top; padding-right:18px;"><img src="{{ $s['portrait'] }}" width="120" height="160" alt="" style="display:block; border-radius:14px; object-fit:cover;"></td>
                @endif
                <td style="vertical-align:top;">
                    <p style="{{ $h2 }} margin-bottom:4px;">{{ $s['name'] }}</p>
                    @if ($s['relation'])<p style="font-size:14px; color:#b45309; margin:0 0 6px;">{{ ucfirst($s['relation']) }}</p>@endif
                    @if ($s['life'])<p style="font-size:14px; color:#78716c; margin:0 0 10px;">{{ ucfirst($s['life']) }}</p>@endif
                    <p style="{{ $p }}">{{ $s['photos_count'] }} photos dans vos souvenirs. Prenez un moment pour parcourir sa fiche, sa frise de vie et son récit en diaporama.</p>
                    <a href="{{ $s['url'] }}" style="{{ $btn }}">Voir sa fiche</a>
                </td>
            </tr></table>
            @if ($s['thumbs'])<div style="margin-top:16px;">@include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])</div>@endif

        @elseif ($s['key'] === 'timeline')
            <p style="{{ $kicker }}">Un coup de pouce pour sa frise</p>
            @if ($s['self'])
                <p style="{{ $h2 }}">Et si vous racontiez votre propre parcours ?</p>
                <p style="{{ $p }}">Votre frise de vie est encore presque vide. Ajoutez {{ implode(', ', $s['prompts']) }} : vos proches le découvriront sur votre fiche.</p>
            @else
                <p style="{{ $h2 }}">Que savez-vous de la vie de {{ $s['name'] }} ?</p>
                <p style="{{ $p }}">{{ $s['relation'] ? ucfirst($s['relation']).' : sa' : 'Sa' }} frise de vie est presque vide. {{ ucfirst($s['prompts'][0]) }}, {{ $s['prompts'][1] }}, {{ $s['prompts'][2] }}, {{ $s['prompts'][3] }}… chaque détail compte.</p>
            @endif
            <a href="{{ $s['url'] }}" style="{{ $btn }}">Compléter la frise</a>

        @elseif ($s['key'] === 'tree_links')
            <p style="{{ $kicker }}">Votre place dans l'arbre</p>
            <p style="{{ $h2 }}">{{ $treeTexts[$s['missing']][0] }}</p>
            <p style="{{ $p }}">{{ $treeTexts[$s['missing']][1] }}</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">Ouvrir ma fiche →</a>

        @elseif ($s['key'] === 'own_circle')
            <p style="{{ $kicker }}">Vos amis aussi</p>
            <p style="{{ $h2 }}">Créez votre propre cercle</p>
            <p style="{{ $p }}">Une bande d'amis, des collègues, un club : un cercle partage ses photos et ses souvenirs entre ses seuls membres, à part de la famille.</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">Créer un cercle →</a>

        @elseif ($s['key'] === 'years_ago')
            <p style="{{ $kicker }}">Il y a {{ $s['years'] }} {{ $plural($s['years'], 'an', 'ans') }}, cette semaine</p>
            @if ($s['image'])<a href="{{ $s['url'] }}"><img src="{{ $s['image'] }}" width="544" alt="" style="display:block; width:100%; height:auto; border-radius:12px; margin:0 0 12px;"></a>@endif
            <p style="{{ $p }}">{{ ucfirst($s['date']) }}@if ($s['who']) · avec {{ $s['who'] }}@endif</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">Revoir ce souvenir →</a>

        @elseif ($s['key'] === 'quests')
            <p style="{{ $kicker }}">Petites questions</p>
            <p style="{{ $h2 }}">{{ $s['count'] }} {{ $plural($s['count'], 'question attend', 'questions attendent') }} votre réponse</p>
            <p style="{{ $p }}">Une date, un lieu, un visage : une minute suffit, et chaque réponse complète la mémoire de toute la famille.</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">Répondre →</a>

        @elseif ($s['key'] === 'numbers')
            <p style="{{ $kicker }}">Les chiffres de la famille</p>
            <p style="{{ $p }}">
                <strong style="color:#1c1917;">{{ number_format($s['photos'], 0, ',', ' ') }}</strong> photos partagées,
                <strong style="color:#1c1917;">{{ $s['people'] }}</strong> personnes dans l'arbre,
                dont <strong style="color:#1c1917;">{{ $s['with_photos'] }}</strong> avec leur portrait.
                @if ($s['oldest']) La plus ancienne photo date de <strong style="color:#1c1917;">{{ \Illuminate\Support\Carbon::parse($s['oldest'])->year }}</strong>.@endif
            </p>

        @elseif ($s['key'] === 'spotlight')
            <p style="{{ $kicker }}">À découvrir</p>
            <p style="{{ $h2 }}">{{ $s['title'] }}</p>
            <p style="{{ $p }}">{{ $s['text'] }}</p>
            <a href="{{ $s['url_abs'] }}" style="{{ $link }}">{{ $s['cta'] }} →</a>

    @endif
    </div></td></tr>
@endforeach

    {{-- Pied --}}
    <tr><td align="center" style="padding:14px 16px 0; font-size:12px; line-height:1.6; color:#a8a29e;">
        Vous recevez ce message chaque dimanche parce que vous faites partie de la famille sur MemoryLane.<br>
        <a href="{{ $settingsUrl }}" style="color:#78716c;">Mes préférences</a> ·
        <a href="{{ $unsubscribeUrl }}" style="color:#78716c;">Ne plus recevoir cette lettre</a>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
