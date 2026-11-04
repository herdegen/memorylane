{{-- Texte brut : rien n'y est interprété comme du HTML, d'où {!! !!} (avec {{ }}, une apostrophe d'un nom de cercle ou de personne deviendrait « &#039; »). --}}{!! __('Bonjour :name,', ['name' => $firstName]) !!}

{!! __('Voici ta semaine avec :circle.', ['circle' => $issue['circle']]) !!}

@foreach ($issue['sections'] as $s)
@if ($s['key'] === 'new_photos')
{!! $s['uploaders'] ? trans_choice(':count nouvelle photo cette semaine (ajoutées par :names).|:count nouvelles photos cette semaine (ajoutées par :names).', $s['count'], ['names' => implode(', ', $s['uploaders'])]) : trans_choice(':count nouvelle photo cette semaine.|:count nouvelles photos cette semaine.', $s['count']) !!}
{!! $s['url'] !!}
@elseif ($s['key'] === 'tagged_you')
{!! trans_choice('Te voilà sur :count photo du cercle : :url|Te voilà sur :count photos du cercle : :url', $s['count'], ['url' => $s['url']]) !!}
@elseif ($s['key'] === 'birthdays')
{!! __('Anniversaires des 7 prochains jours :') !!}
@foreach ($s['items'] as $item)
- {!! $item['name'] !!}, {!! $item['when'] !!}
@endforeach
@elseif ($s['key'] === 'years_ago')
{!! trans_choice('Il y a :count an cette semaine : :url|Il y a :count ans cette semaine : :url', $s['years'], ['url' => $s['url']]) !!}
@elseif ($s['key'] === 'how_met')
{!! __('Comment as-tu connu :name ? Raconte votre rencontre : :url', ['name' => $s['name'], 'url' => $s['url']]) !!}
@elseif ($s['key'] === 'faces')
{!! trans_choice(':count visage attend un nom sur les photos du cercle : :url|:count visages attendent un nom sur les photos du cercle : :url', $s['count'], array_filter(['url' => $s['url'], 'count' => $s['more'] ? $s['count'].'+' : null])) !!}
@elseif ($s['key'] === 'faces_album')
{!! trans_choice('Il y a :count visage non identifié dans l’album « :album », où tu apparais.|Il y a :count visages non identifiés dans l’album « :album », où tu apparais.', $s['count'], ['album' => $s['album']]) !!} {!! __('Aide-nous à les identifier :') !!}
{!! $s['url'] !!}
@elseif ($s['key'] === 'spotlight')
{!! __('À découvrir — :title : :text', ['title' => $s['title'], 'text' => $s['text']]) !!} {!! $s['url_abs'] !!}
@endif

@endforeach
--
{!! __('Tu reçois ce message le dimanche, quand il y a du nouveau dans tes cercles d’amis sur MemoryLane.') !!}
{!! __('Mes préférences : :url', ['url' => $settingsUrl]) !!}
{!! __('Ne plus recevoir cette lettre : :url', ['url' => $unsubscribeUrl]) !!}
