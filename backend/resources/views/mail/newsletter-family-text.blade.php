@php $plural = fn (int $n, string $one, string $many) => $n === 1 ? $one : $many; @endphp
Bonjour {{ $firstName }},

@foreach ($issue['sections'] as $s)
@if ($s['key'] === 'new_photos')
{{ $s['count'] }} {{ $plural($s['count'], 'nouvelle photo', 'nouvelles photos') }} cette semaine{{ $s['uploaders'] ? ' (ajoutées par '.implode(', ', $s['uploaders']).')' : '' }}.
{{ $s['url'] }}
@elseif ($s['key'] === 'tagged_you')
On vous a reconnu sur {{ $s['count'] }} {{ $plural($s['count'], 'photo', 'photos') }} : {{ $s['url'] }}
@elseif ($s['key'] === 'upcoming')
Fêtes et anniversaires des 7 prochains jours :
@foreach ($s['items'] as $item)
- {{ $item['name'] }} ({{ $item['text'] }}), {{ $item['when'] }}
@endforeach
@elseif ($s['key'] === 'family_activity')
La famille cette semaine :
@foreach ($s['lines'] as $line)
- {{ $line['count'] }} {{ $line['label'] }}{{ $line['detail'] ? ' '.$line['detail'] : '' }}
@endforeach
@elseif ($s['key'] === 'person_of_week')
La personne de la semaine : {{ $s['name'] }}{{ $s['relation'] ? ', '.$s['relation'] : '' }}{{ $s['life'] ? ' ('.$s['life'].')' : '' }}.
{{ $s['url'] }}
@elseif ($s['key'] === 'timeline')
{{ $s['self'] ? 'Et si vous racontiez votre propre parcours ?' : 'Que savez-vous de la vie de '.$s['name'].' ?' }} {{ $s['url'] }}
@elseif ($s['key'] === 'tree_links')
Votre place dans l'arbre : {{ $s['url'] }}
@elseif ($s['key'] === 'own_circle')
Créez votre propre cercle d'amis : {{ $s['url'] }}
@elseif ($s['key'] === 'years_ago')
Il y a {{ $s['years'] }} {{ $plural($s['years'], 'an', 'ans') }} cette semaine : {{ $s['url'] }}
@elseif ($s['key'] === 'quests')
{{ $s['count'] }} petites questions attendent votre réponse : {{ $s['url'] }}
@elseif ($s['key'] === 'numbers')
Les chiffres de la famille : {{ $s['photos'] }} photos, {{ $s['people'] }} personnes dans l'arbre.
@elseif ($s['key'] === 'spotlight')
À découvrir — {{ $s['title'] }} : {{ $s['text'] }} {{ $s['url_abs'] }}
@endif

@endforeach
--
Mes préférences : {{ $settingsUrl }}
Ne plus recevoir cette lettre : {{ $unsubscribeUrl }}
