@php
    $serif = "Georgia, 'Times New Roman', serif";
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $card = 'background:#ffffff; border-radius:18px; padding:26px 28px; border:1px solid #ede7dc;';
    $kicker = 'font-size:11px; letter-spacing:1.6px; text-transform:uppercase; color:#b45309; font-weight:bold; margin:0 0 8px;';
    $h2 = "font-family:{$serif}; font-size:22px; line-height:1.3; color:#1c1917; margin:0 0 10px;";
    $p = 'font-size:15px; line-height:1.6; color:#44403c; margin:0 0 14px;';
    $btn = 'display:inline-block; background:#b45309; color:#ffffff; text-decoration:none; font-weight:bold; font-size:14px; padding:11px 20px; border-radius:10px;';
    $link = 'color:#b45309; font-weight:bold; text-decoration:none;';
    $treeTexts = [
        'link_self' => [__('Relie ton compte à ta fiche'), __('Trouve-toi dans la liste des personnes et clique « C’est moi » : l’arbre, les liens de parenté et tes photos se mettent à ton nom.')],
        'parents' => [__('Tes parents ne sont pas encore dans l’arbre'), __('Ajoute-les depuis ta fiche : c’est le point de départ de toutes les branches.')],
        'siblings' => [__('Et tes frères et sœurs ?'), __('Ils n’apparaissent pas encore à côté de toi dans l’arbre. Ajoute-les depuis ta fiche, en quelques secondes.')],
        'partner' => [__('Ton conjoint ou ta conjointe dans l’arbre ?'), __('Ajoute ta compagne ou ton compagnon depuis ta fiche, avec la date de votre union si tu le souhaites.')],
    ];
    // Nombres : séparateur des milliers selon la langue ; mis en gras dans les phrases traduites.
    $thousands = app()->getLocale() === 'fr' ? ' ' : ',';
    $strong = fn ($value) => '<strong style="color:#1c1917;">'.e($value).'</strong>';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
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
        <div style="font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#a8a29e; margin-top:6px;">{{ __('La semaine en famille') }}</div>
    </td></tr>

    <tr><td style="padding:0 4px 16px;">
        <p style="font-family:{{ $serif }}; font-size:22px; color:#1c1917; margin:0;">{{ __('Bonjour :name,', ['name' => $firstName]) }}</p>
    </td></tr>

@foreach ($issue['sections'] as $s)
    {{-- L'encart « Côté amis » se distingue des rubriques de la famille par un fond plus doux. --}}
    <tr><td style="padding:0 0 14px;"><div style="{{ $s['key'] === 'friends' ? $card.' background:#fbf6ee;' : $card }}">
    @if ($s['key'] === 'new_photos')
            <p style="{{ $kicker }}">{{ __('Nouveautés') }}</p>
            <p style="{{ $h2 }}">{{ trans_choice(':count nouvelle photo cette semaine|:count nouvelles photos cette semaine', $s['count']) }}</p>
            @if ($s['uploaders'])
                <p style="{{ $p }}">{{ __('Ajoutées par :names.', ['names' => implode(', ', $s['uploaders'])]) }}</p>
            @endif
            @include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])
            <a href="{{ $s['url'] }}" style="{{ $btn }}">{{ __('Voir les nouveautés') }}</a>

        @elseif ($s['key'] === 'tagged_you')
            <p style="{{ $kicker }}">{{ __('Tu es dessus') }}</p>
            <p style="{{ $h2 }}">{{ trans_choice('Te voilà sur :count photo|Te voilà sur :count photos', $s['count']) }}</p>
            @include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])
            <a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Voir mes photos') }} →</a>

        @elseif ($s['key'] === 'upcoming')
            <p style="{{ $kicker }}">{{ __('Les 7 prochains jours') }}</p>
            <p style="{{ $h2 }}">{{ __('Fêtes et anniversaires') }}</p>
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
            <p style="{{ $kicker }}">{{ __('La famille cette semaine') }}</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            @foreach ($s['lines'] as $line)
                <tr>
                    <td style="padding:5px 12px 5px 0; font-family:{{ $serif }}; font-size:24px; color:#b45309; width:72px; text-align:right; vertical-align:top;">{{ number_format($line['count'], 0, ',', $thousands) }}</td>
                    <td style="padding:9px 0 5px; font-size:15px; vertical-align:top;">{{ $line['label'] }}@if ($line['detail']) <span style="color:#78716c;">{{ $line['detail'] }}</span>@endif</td>
                </tr>
            @endforeach
            </table>

        @elseif ($s['key'] === 'shared_people')
            <p style="{{ $kicker }}">{{ __('Fiches communes') }}</p>
            @if ($s['to_decide'] > 0)
                <p style="{{ $h2 }}">{{ trans_choice('Une proposition de correction attend ton avis.|:count propositions de correction attendent ton avis.', $s['to_decide']) }}</p>
            @endif
            @if ($s['changed'] > 0)
                <p style="{{ $p }}">{{ trans_choice('Cette semaine, :count fiche que ta famille partage avec une autre a été complétée ou corrigée de leur côté.|Cette semaine, :count fiches que ta famille partage avec d’autres ont été complétées ou corrigées de leur côté.', $s['changed']) }}</p>
            @endif
            <a href="{{ $s['url'] }}" style="{{ $btn }}">{{ __('Voir les fiches communes') }}</a>

        @elseif ($s['key'] === 'friends')
            <p style="{{ $kicker }}">{{ __('Côté amis') }}</p>
            <p style="{{ $h2 }} font-size:19px;">{{ \Illuminate\Support\Str::ucfirst($s['photos'] ? trans_choice(':circle : :count nouvelle photo|:circle : :count nouvelles photos', $s['photos'], ['circle' => $s['circle']]) : __('Du nouveau avec :circle', ['circle' => $s['circle']])) }}</p>
            @include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])
            @if ($s['tagged'])
                <p style="{{ $p }} margin-bottom:6px;">{{ trans_choice('Te voilà sur :count photo du cercle|Te voilà sur :count photos du cercle', $s['tagged']) }}.</p>
            @endif
            @if ($s['birthdays'])
                <p style="{{ $p }} margin-bottom:6px;">🎂 {{ trans_choice('Anniversaire à venir : :list|Anniversaires à venir : :list', count($s['birthdays']), ['list' => implode(', ', array_map(fn ($b) => $b['name'].' ('.$b['when'].')', $s['birthdays']))]) }}</p>
            @endif
            @if ($s['memory_years'])
                <p style="{{ $p }} margin-bottom:6px;">{{ trans_choice('Un souvenir d’il y a :count an, cette semaine.|Un souvenir d’il y a :count ans, cette semaine.', $s['memory_years']) }}</p>
            @endif
            <p style="margin:12px 0 0;"><a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Voir les photos du cercle') }} →</a></p>

        @elseif ($s['key'] === 'story')
            <p style="{{ $kicker }}">{{ $s['number'] === 1 ? __('Une histoire de famille') : __('La suite de l’histoire') }}</p>
            <p style="{{ $h2 }}">{{ $s['title'] }}</p>
            @if ($s['summary'])<p style="{{ $p }}">{{ $s['summary'] }}</p>@endif
            <p style="font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#b45309; margin:14px 0 4px;">{{ __('Chapitre :n sur :total', ['n' => $s['number'], 'total' => $s['total']]) }}@if ($s['when']) · {{ $s['when'] }}@endif</p>
            <p style="font-family:{{ $serif }}; font-size:19px; color:#1c1917; margin:0 0 8px;">{{ $s['chapter'] }}</p>
            @if ($s['image'])<img src="{{ $s['image'] }}" alt="" style="display:block; max-width:100%; max-height:280px; border-radius:10px; margin:6px 0 12px;">@endif
            @if ($s['excerpt'])<p style="{{ $p }}">{{ $s['excerpt'] }}</p>@endif
            <a href="{{ $s['url'] }}" style="{{ $btn }}">{{ $s['number'] < $s['total'] ? __('Lire le chapitre') : __('Lire la fin') }}</a>
            @if ($s['number'] < $s['total'])<p style="font-size:13px; color:#78716c; margin:10px 0 0;">{{ __('La suite dans une prochaine lettre, ou tout de suite en ligne.') }}</p>@endif

        @elseif ($s['key'] === 'person_of_week')
            <p style="{{ $kicker }}">{{ __('La personne de la semaine') }}</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                @if ($s['portrait'])
                    <td width="120" style="vertical-align:top; padding-right:18px;"><img src="{{ $s['portrait'] }}" width="120" height="160" alt="" style="display:block; border-radius:14px; object-fit:cover;"></td>
                @endif
                <td style="vertical-align:top;">
                    <p style="{{ $h2 }} margin-bottom:4px;">{{ $s['name'] }}</p>
                    @if ($s['relation'])<p style="font-size:14px; color:#b45309; margin:0 0 6px;">{{ ucfirst($s['relation']) }}</p>@endif
                    @if ($s['life'])<p style="font-size:14px; color:#78716c; margin:0 0 10px;">{{ ucfirst($s['life']) }}</p>@endif
                    <p style="{{ $p }}">{{ trans_choice(':count photo dans tes souvenirs. Prends un moment pour parcourir sa fiche, sa frise de vie et son récit en diaporama.|:count photos dans tes souvenirs. Prends un moment pour parcourir sa fiche, sa frise de vie et son récit en diaporama.', $s['photos_count']) }}</p>
                    <a href="{{ $s['url'] }}" style="{{ $btn }}">{{ __('Voir sa fiche') }}</a>
                </td>
            </tr></table>
            @if ($s['thumbs'])<div style="margin-top:16px;">@include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])</div>@endif

        @elseif ($s['key'] === 'timeline')
            <p style="{{ $kicker }}">{{ $s['self'] ? __('Ta frise de vie') : __('Un coup de pouce pour sa frise') }}</p>
            <p style="{{ $h2 }}">{{ $s['title'] }}</p>
            @if ($s['self'])
                <p style="{{ $p }}">{{ __('Ta frise de vie est encore presque vide. Ajoute :prompts : tes proches les découvriront sur ta fiche.', ['prompts' => $s['prompts']]) }}</p>
            @else
                <p style="{{ $p }}">{{ __('Sa frise de vie est presque vide.') }} {{ __(':prompts… chaque détail compte.', ['prompts' => $s['prompts']]) }}</p>
            @endif
            <a href="{{ $s['url'] }}" style="{{ $btn }}">{{ __('Compléter la frise') }}</a>

        @elseif ($s['key'] === 'faces_album')
            <p style="{{ $kicker }}">{{ __('Qui est-ce ?') }}</p>
            <p style="{{ $h2 }}">{{ trans_choice('Il y a :count visage non identifié dans l’album « :album », où tu apparais.|Il y a :count visages non identifiés dans l’album « :album », où tu apparais.', $s['count'], ['album' => $s['album']]) }}</p>
            @include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])
            <p style="{{ $p }}">{{ __('Aide-nous à les identifier : une carte à la fois, quelques secondes par visage.') }}</p>
            <a href="{{ $s['url'] }}" style="{{ $btn }}">{{ __('Identifier les visages') }}</a>

        @elseif ($s['key'] === 'fact_check')
            <p style="{{ $kicker }}">{{ __('Petite vérification') }}</p>
            <p style="{{ $h2 }} font-size:20px;">{{ $s['text'] }}</p>
            <p style="{{ $p }}">{{ __('Si quelque chose cloche, tu peux le corriger toi-même sur ta fiche.') }}</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Vérifier sur ma fiche') }} →</a>

        @elseif ($s['key'] === 'tree_links')
            <p style="{{ $kicker }}">{{ __('Ta place dans l’arbre') }}</p>
            <p style="{{ $h2 }}">{{ $treeTexts[$s['missing']][0] }}</p>
            <p style="{{ $p }}">{{ $treeTexts[$s['missing']][1] }}</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Ouvrir ma fiche') }} →</a>

        @elseif ($s['key'] === 'own_circle')
            <p style="{{ $kicker }}">{{ __('Tes amis aussi') }}</p>
            <p style="{{ $h2 }}">{{ __('Crée ton propre cercle') }}</p>
            <p style="{{ $p }}">{{ __('Une bande d\'amis, des collègues, un club : un cercle partage ses photos et ses souvenirs entre ses seuls membres, à part de la famille.') }}</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Créer un cercle') }} →</a>

        @elseif ($s['key'] === 'years_ago')
            <p style="{{ $kicker }}">{{ trans_choice('Il y a :count an, cette semaine|Il y a :count ans, cette semaine', $s['years']) }}</p>
            @if ($s['image'])<a href="{{ $s['url'] }}"><img src="{{ $s['image'] }}" width="544" alt="" style="display:block; width:100%; height:auto; border-radius:12px; margin:0 0 12px;"></a>@endif
            <p style="{{ $p }}">{{ ucfirst($s['date']) }}@if ($s['who']) · {{ __('avec :names', ['names' => $s['who']]) }}@endif</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Revoir ce souvenir') }} →</a>

        @elseif ($s['key'] === 'quests')
            <p style="{{ $kicker }}">{{ __('Petites questions') }}</p>
            <p style="{{ $h2 }}">{{ trans_choice(':count question attend ta réponse|:count questions attendent ta réponse', $s['count']) }}</p>
            <p style="{{ $p }}">{{ __('Une date, un lieu, un visage : une minute suffit, et chaque réponse complète la mémoire de toute la famille.') }}</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Répondre') }} →</a>

        @elseif ($s['key'] === 'numbers')
            <p style="{{ $kicker }}">{{ __('Les chiffres de la famille') }}</p>
            <p style="{{ $p }}">
                {!! __(':photos photos partagées, :people personnes dans l\'arbre, dont :portraits avec leur portrait.', ['photos' => $strong(number_format($s['photos'], 0, ',', $thousands)), 'people' => $strong($s['people']), 'portraits' => $strong($s['with_photos'])]) !!}
                @if ($s['oldest']) {!! __('La plus ancienne photo date de :year.', ['year' => $strong(\Illuminate\Support\Carbon::parse($s['oldest'])->year)]) !!}@endif
            </p>

        @elseif ($s['key'] === 'spotlight')
            <p style="{{ $kicker }}">{{ ($s['new'] ?? false) ? __('Nouveau sur MemoryLane') : __('À découvrir') }}</p>
            <p style="{{ $h2 }}">{{ $s['title'] }}</p>
            <p style="{{ $p }}">{{ $s['text'] }}</p>
            <a href="{{ $s['url_abs'] }}" style="{{ $link }}">{{ $s['cta'] }} →</a>

    @endif
    </div></td></tr>
@endforeach

    {{-- Pied --}}
    <tr><td align="center" style="padding:14px 16px 0; font-size:12px; line-height:1.6; color:#a8a29e;">
        {{ __('Tu reçois ce message chaque dimanche parce que tu fais partie de la famille sur MemoryLane.') }}<br>
        <a href="{{ $settingsUrl }}" style="color:#78716c;">{{ __('Mes préférences') }}</a> ·
        <a href="{{ $unsubscribeUrl }}" style="color:#78716c;">{{ __('Ne plus recevoir cette lettre') }}</a>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
