@php
    $serif = "Georgia, 'Times New Roman', serif";
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $card = 'background:#ffffff; border-radius:18px; padding:26px 28px; border:1px solid #ede7dc;';
    $kicker = 'font-size:11px; letter-spacing:1.6px; text-transform:uppercase; color:#b45309; font-weight:bold; margin:0 0 8px;';
    $h2 = "font-family:{$serif}; font-size:22px; line-height:1.3; color:#1c1917; margin:0 0 10px;";
    $p = 'font-size:15px; line-height:1.6; color:#44403c; margin:0 0 14px;';
    $btn = 'display:inline-block; background:#b45309; color:#ffffff; text-decoration:none; font-weight:bold; font-size:14px; padding:11px 20px; border-radius:10px;';
    $link = 'color:#b45309; font-weight:bold; text-decoration:none;';
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
        <div style="font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#a8a29e; margin-top:6px;">{{ __('La semaine entre amis') }}</div>
    </td></tr>

    <tr><td style="padding:0 4px 16px;">
        <p style="font-family:{{ $serif }}; font-size:22px; color:#1c1917; margin:0 0 6px;">{{ __('Bonjour :name,', ['name' => $firstName]) }}</p>
        <p style="font-size:15px; line-height:1.6; color:#78716c; margin:0;">{{ __('Voici ta semaine avec :circle.', ['circle' => $issue['circle']]) }}</p>
    </td></tr>

@foreach ($issue['sections'] as $s)
    <tr><td style="padding:0 0 14px;"><div style="{{ $card }}">
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
            <p style="{{ $h2 }}">{{ trans_choice('Te voilà sur :count photo du cercle|Te voilà sur :count photos du cercle', $s['count']) }}</p>
            @include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])
            <a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Voir mes photos') }} →</a>

        @elseif ($s['key'] === 'birthdays')
            <p style="{{ $kicker }}">{{ __('Les 7 prochains jours') }}</p>
            <p style="{{ $h2 }}">{{ trans_choice('Un anniversaire à souhaiter|Des anniversaires à souhaiter', count($s['items'])) }}</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;">
            @foreach ($s['items'] as $item)
                <tr>
                    <td style="padding:6px 0; font-size:15px; width:28px;">🎂</td>
                    <td style="padding:6px 0; font-size:15px;"><a href="{{ $item['url'] }}" style="color:#1c1917; font-weight:bold; text-decoration:none;">{{ $item['name'] }}</a></td>
                    <td align="right" style="padding:6px 0; font-size:13px; color:#78716c; white-space:nowrap;">{{ $item['when'] }}</td>
                </tr>
            @endforeach
            </table>
            <p style="{{ $p }} margin:0; color:#78716c;">{{ __('Un petit mot fait toujours plaisir.') }}</p>

        @elseif ($s['key'] === 'years_ago')
            <p style="{{ $kicker }}">{{ trans_choice('Il y a :count an, cette semaine|Il y a :count ans, cette semaine', $s['years']) }}</p>
            @if ($s['image'])<a href="{{ $s['url'] }}"><img src="{{ $s['image'] }}" width="544" alt="" style="display:block; width:100%; height:auto; border-radius:12px; margin:0 0 12px;"></a>@endif
            <p style="{{ $p }}">{{ ucfirst($s['date']) }}@if ($s['who']) · {{ __('avec :names', ['names' => $s['who']]) }}@endif</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Revoir ce souvenir') }} →</a>

        @elseif ($s['key'] === 'how_met')
            <p style="{{ $kicker }}">{{ __('Petite question') }}</p>
            <p style="{{ $h2 }}">{{ __('Comment as-tu connu :name ?', ['name' => $s['name']]) }}</p>
            <p style="{{ $p }}">@if ($s['photos'] > 0){{ trans_choice('Vous apparaissez ensemble sur :count photo.|Vous apparaissez ensemble sur :count photos.', $s['photos']) }} @endif{{ __('Raconte votre rencontre en quelques mots : l’année, le lieu, les circonstances. Chacun garde sa propre version.') }}</p>
            <a href="{{ $s['url'] }}" style="{{ $btn }}">{{ __('Raconter notre rencontre') }}</a>

        @elseif ($s['key'] === 'faces')
            <p style="{{ $kicker }}">{{ __('Qui est-ce ?') }}</p>
            <p style="{{ $h2 }}">{{ trans_choice(':count visage attend un nom sur les photos du cercle|:count visages attendent un nom sur les photos du cercle', $s['count'], $s['more'] ? ['count' => $s['count'].'+'] : []) }}</p>
            <p style="{{ $p }}">{{ __('Une carte à la fois, au téléphone comme sur ordinateur : chaque nom retrouvé aide tout le groupe.') }}</p>
            <a href="{{ $s['url'] }}" style="{{ $link }}">{{ __('Jouer') }} →</a>

        @elseif ($s['key'] === 'faces_album')
            <p style="{{ $kicker }}">{{ __('Qui est-ce ?') }}</p>
            <p style="{{ $h2 }}">{{ trans_choice('Il y a :count visage non identifié dans l’album « :album », où tu apparais.|Il y a :count visages non identifiés dans l’album « :album », où tu apparais.', $s['count'], ['album' => $s['album']]) }}</p>
            @include('mail.partials.thumbs', ['thumbs' => $s['thumbs']])
            <p style="{{ $p }}">{{ __('Aide-nous à les identifier : une carte à la fois, quelques secondes par visage.') }}</p>
            <a href="{{ $s['url'] }}" style="{{ $btn }}">{{ __('Identifier les visages') }}</a>

        @elseif ($s['key'] === 'spotlight')
            <p style="{{ $kicker }}">{{ __('À découvrir') }}</p>
            <p style="{{ $h2 }}">{{ $s['title'] }}</p>
            <p style="{{ $p }}">{{ $s['text'] }}</p>
            <a href="{{ $s['url_abs'] }}" style="{{ $link }}">{{ $s['cta'] }} →</a>

    @endif
    </div></td></tr>
@endforeach

    {{-- Pied --}}
    <tr><td align="center" style="padding:14px 16px 0; font-size:12px; line-height:1.6; color:#a8a29e;">
        {{ __('Tu reçois ce message le dimanche, quand il y a du nouveau dans tes cercles d’amis sur MemoryLane.') }}<br>
        <a href="{{ $settingsUrl }}" style="color:#78716c;">{{ __('Mes préférences') }}</a> ·
        <a href="{{ $unsubscribeUrl }}" style="color:#78716c;">{{ __('Ne plus recevoir cette lettre') }}</a>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
