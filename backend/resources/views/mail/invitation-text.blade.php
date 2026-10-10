{{ $tv('Salut', 'Bonjour') }} {{ $firstName }},

@if ($byAuthor)
C’est {{ $inviterFirstName }}. Depuis plusieurs mois, je construis moi-même, sur mon temps libre, un site pour notre famille. Il s’appelle MemoryLane, et j’aimerais beaucoup que {{ $tv('tu en fasses partie', 'vous en fassiez partie') }}.
@else
{{ $inviterFirstName }} {{ $tv('t’invite', 'vous invite') }} à rejoindre MemoryLane, le site de notre famille. C’est un projet que {{ $authorFirstName }} a construit lui-même, sur son temps libre, pour rassembler nos photos et notre histoire.
@endif
@if ($personalMessage)

Le mot de {{ $inviterFirstName }} :
« {{ $personalMessage }} »
@endif

{{ $tv('TA PLACE EST DÉJÀ LÀ', 'VOTRE PLACE EST DÉJÀ LÀ') }}
{{ $personName }}@if ($birthLine) — {{ $birthLine }}@endif

@if ($parentsLine){{ $parentsLine }}
@endif
{{ $tv('Tu fais partie', 'Vous faites partie') }} des plus de {{ $peopleCount }} personnes de notre arbre.

{{ $byAuthor ? 'POURQUOI J’AI FAIT ÇA' : 'POURQUOI '.mb_strtoupper($authorFirstName).' A CRÉÉ CE SITE' }}
Nos photos de famille sont éparpillées : dans nos téléphones, dans des albums papier, sur des disques durs qu’on n’ouvre jamais. Et les histoires qui vont avec ne vivent souvent que dans la mémoire de quelques-uns. J’ai voulu un endroit à nous : privé, sans publicité, sans réseau social, où tout ça est rassemblé, rangé et transmis.

CE QUE {{ $tv('TU Y TROUVERAS', 'VOUS Y TROUVEREZ') }}
- L’arbre de toute la famille (plus de {{ $peopleCount }} personnes) et la fiche de chacun, avec sa frise de vie.
- Plus de {{ $photoCount }} photos et vidéos, classées par date, lieu et personne ; des albums à partager.
- Une page d’accueil vivante : souvenirs du jour, anniversaires, fêtes.
- La carte de nos souvenirs.
- La possibilité d’enregistrer {{ $tv('ta', 'votre') }} voix pour raconter une photo.

ÇA NE MARCHERA QU’AVEC VOUS
Le site ne prendra vie que si chacun y dépose ses photos, met des noms sur les visages et raconte ce qu’il sait. Même cinq minutes de temps en temps, ça compte.

CRÉER MON ACCÈS (une minute, {{ $tv('tu choisis', 'vous choisissez') }} un mot de passe) :
{{ $url }}
@if ($expiresAt)
Lien personnel, valable jusqu’au {{ $expiresAt }}.
@endif

À {{ $tv('TON', 'VOTRE') }} TOUR
Une fois sur le site, {{ $tv('tu pourras', 'vous pourrez') }} inviter les proches qui sont dans l’arbre et ajouter ceux qui manquent. Chaque modification est gardée dans un historique.

UNE QUESTION, UN BUG, UNE IDÉE ?
@if ($byAuthor && $repliesToAuthor)
Réponds simplement à ce mail{{ $contactPhone ? ', ou appelle-moi au '.$contactPhone : '' }}.
@else
Écrire à {{ $authorFirstName }} : {{ $authorEmail }}@if ($contactPhone) — {{ $contactPhone }}@endif
@endif

@if ($byAuthor)
{{ $tv('Je t’embrasse', 'Bien affectueusement') }},
{{ $inviterFirstName }}
@else
À très vite sur MemoryLane,
{{ $inviterFirstName }}
@endif

--
MemoryLane · le site privé de notre famille · {{ $appHost }}
