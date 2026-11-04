{{-- Texte brut : rien n'y est interprété comme du HTML, d'où {!! !!} (avec {{ }}, « l'arbre » deviendrait « l&#039;arbre »). --}}{!! __('Bonjour :name,', ['name' => $firstName]) !!}

@foreach ($issue['sections'] as $s)
@if ($s['key'] === 'new_photos')
{!! $s['uploaders'] ? trans_choice(':count nouvelle photo cette semaine (ajoutées par :names).|:count nouvelles photos cette semaine (ajoutées par :names).', $s['count'], ['names' => implode(', ', $s['uploaders'])]) : trans_choice(':count nouvelle photo cette semaine.|:count nouvelles photos cette semaine.', $s['count']) !!}
{!! $s['url'] !!}
@elseif ($s['key'] === 'tagged_you')
{!! trans_choice('Te voilà sur :count photo : :url|Te voilà sur :count photos : :url', $s['count'], ['url' => $s['url']]) !!}
@elseif ($s['key'] === 'upcoming')
{!! __('Fêtes et anniversaires des 7 prochains jours :') !!}
@foreach ($s['items'] as $item)
- {!! $item['name'] !!} ({!! $item['text'] !!}), {!! $item['when'] !!}
@endforeach
@elseif ($s['key'] === 'family_activity')
{!! __('La famille cette semaine :') !!}
@foreach ($s['lines'] as $line)
- {!! $line['count'] !!} {!! $line['label'] !!}{!! $line['detail'] ? ' '.$line['detail'] : '' !!}
@endforeach
@elseif ($s['key'] === 'friends')
{{-- Noms de cercles et de personnes : {!! !!} (texte brut, une apostrophe ne doit pas devenir « &#039; »). --}}{!! __('Côté amis — :title', ['title' => \Illuminate\Support\Str::ucfirst($s['photos'] ? trans_choice(':circle : :count nouvelle photo|:circle : :count nouvelles photos', $s['photos'], ['circle' => $s['circle']]) : __('Du nouveau avec :circle', ['circle' => $s['circle']]))]) !!}
@if ($s['tagged'])
- {!! trans_choice('Te voilà sur :count photo du cercle|Te voilà sur :count photos du cercle', $s['tagged']) !!}
@endif
@if ($s['birthdays'])
- {!! trans_choice('Anniversaire à venir : :list|Anniversaires à venir : :list', count($s['birthdays']), ['list' => implode(', ', array_map(fn ($b) => $b['name'].' ('.$b['when'].')', $s['birthdays']))]) !!}
@endif
@if ($s['memory_years'])
- {!! trans_choice('Un souvenir d’il y a :count an, cette semaine.|Un souvenir d’il y a :count ans, cette semaine.', $s['memory_years']) !!}
@endif
{!! $s['url'] !!}
@elseif ($s['key'] === 'person_of_week')
@php $values = ['name' => $s['name'], 'relation' => $s['relation'] ?? '', 'life' => $s['life'] ?? '']; @endphp
@if ($s['relation'] && $s['life'])
{!! __('La personne de la semaine : :name, :relation (:life).', $values) !!}
@elseif ($s['relation'])
{!! __('La personne de la semaine : :name, :relation.', $values) !!}
@elseif ($s['life'])
{!! __('La personne de la semaine : :name (:life).', $values) !!}
@else
{!! __('La personne de la semaine : :name.', $values) !!}
@endif
{!! $s['url'] !!}
@elseif ($s['key'] === 'timeline')
{!! $s['title'] !!}
{!! $s['url'] !!}
@elseif ($s['key'] === 'faces_album')
{!! trans_choice('Il y a :count visage non identifié dans l’album « :album », où tu apparais.|Il y a :count visages non identifiés dans l’album « :album », où tu apparais.', $s['count'], ['album' => $s['album']]) !!} {!! __('Aide-nous à les identifier :') !!}
{!! $s['url'] !!}
@elseif ($s['key'] === 'fact_check')
{!! $s['text'] !!} {!! __('Vérifier sur ma fiche :') !!}
{!! $s['url'] !!}
@elseif ($s['key'] === 'tree_links')
{!! __('Ta place dans l’arbre : :url', ['url' => e($s['url'])]) !!}
@elseif ($s['key'] === 'own_circle')
{!! __('Crée ton propre cercle d’amis : :url', ['url' => e($s['url'])]) !!}
@elseif ($s['key'] === 'years_ago')
{!! trans_choice('Il y a :count an cette semaine : :url|Il y a :count ans cette semaine : :url', $s['years'], ['url' => $s['url']]) !!}
@elseif ($s['key'] === 'quests')
{!! trans_choice(':count petite question attend ta réponse : :url|:count petites questions attendent ta réponse : :url', $s['count'], ['url' => $s['url']]) !!}
@elseif ($s['key'] === 'numbers')
{!! __('Les chiffres de la famille : :photos photos, :people personnes dans l\'arbre.', ['photos' => e($s['photos']), 'people' => e($s['people'])]) !!}
@elseif ($s['key'] === 'spotlight')
{!! __('À découvrir — :title : :text', ['title' => $s['title'], 'text' => $s['text']]) !!} {!! $s['url_abs'] !!}
@endif

@endforeach
--
{!! __('Mes préférences : :url', ['url' => $settingsUrl]) !!}
{!! __('Ne plus recevoir cette lettre : :url', ['url' => $unsubscribeUrl]) !!}
