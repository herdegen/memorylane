{{ __('Bonjour :name,', ['name' => $firstName]) }}

@foreach ($issue['sections'] as $s)
@if ($s['key'] === 'new_photos')
{{ $s['uploaders'] ? trans_choice(':count nouvelle photo cette semaine (ajoutées par :names).|:count nouvelles photos cette semaine (ajoutées par :names).', $s['count'], ['names' => implode(', ', $s['uploaders'])]) : trans_choice(':count nouvelle photo cette semaine.|:count nouvelles photos cette semaine.', $s['count']) }}
{{ $s['url'] }}
@elseif ($s['key'] === 'tagged_you')
{{ trans_choice('On vous a reconnu sur :count photo : :url|On vous a reconnu sur :count photos : :url', $s['count'], ['url' => $s['url']]) }}
@elseif ($s['key'] === 'upcoming')
{{ __('Fêtes et anniversaires des 7 prochains jours :') }}
@foreach ($s['items'] as $item)
- {{ $item['name'] }} ({{ $item['text'] }}), {{ $item['when'] }}
@endforeach
@elseif ($s['key'] === 'family_activity')
{{ __('La famille cette semaine :') }}
@foreach ($s['lines'] as $line)
- {{ $line['count'] }} {{ $line['label'] }}{{ $line['detail'] ? ' '.$line['detail'] : '' }}
@endforeach
@elseif ($s['key'] === 'person_of_week')
@php $values = ['name' => $s['name'], 'relation' => $s['relation'] ?? '', 'life' => $s['life'] ?? '']; @endphp
@if ($s['relation'] && $s['life'])
{{ __('La personne de la semaine : :name, :relation (:life).', $values) }}
@elseif ($s['relation'])
{{ __('La personne de la semaine : :name, :relation.', $values) }}
@elseif ($s['life'])
{{ __('La personne de la semaine : :name (:life).', $values) }}
@else
{{ __('La personne de la semaine : :name.', $values) }}
@endif
{{ $s['url'] }}
@elseif ($s['key'] === 'timeline')
{{ $s['self'] ? __('Et si vous racontiez votre propre parcours ?') : __('Que savez-vous de la vie de :name ?', ['name' => $s['name']]) }} {{ $s['url'] }}
@elseif ($s['key'] === 'tree_links')
{!! __('Votre place dans l\'arbre : :url', ['url' => e($s['url'])]) !!}
@elseif ($s['key'] === 'own_circle')
{!! __('Créez votre propre cercle d\'amis : :url', ['url' => e($s['url'])]) !!}
@elseif ($s['key'] === 'years_ago')
{{ trans_choice('Il y a :count an cette semaine : :url|Il y a :count ans cette semaine : :url', $s['years'], ['url' => $s['url']]) }}
@elseif ($s['key'] === 'quests')
{{ trans_choice(':count petite question attend votre réponse : :url|:count petites questions attendent votre réponse : :url', $s['count'], ['url' => $s['url']]) }}
@elseif ($s['key'] === 'numbers')
{!! __('Les chiffres de la famille : :photos photos, :people personnes dans l\'arbre.', ['photos' => e($s['photos']), 'people' => e($s['people'])]) !!}
@elseif ($s['key'] === 'spotlight')
{{ __('À découvrir — :title : :text', ['title' => $s['title'], 'text' => $s['text']]) }} {{ $s['url_abs'] }}
@endif

@endforeach
--
{{ __('Mes préférences : :url', ['url' => $settingsUrl]) }}
{{ __('Ne plus recevoir cette lettre : :url', ['url' => $unsubscribeUrl]) }}
