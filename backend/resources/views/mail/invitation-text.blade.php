{{ $tv(__('Salut :name,', ['name' => $firstName]), __('Bonjour :name,', ['name' => $firstName])) }}

@if ($byAuthor)
{{ __('C’est :name.', ['name' => $inviterFirstName]) }} {{ __('Depuis plusieurs mois, je construis moi-même, sur mon temps libre, un site pour notre famille.') }} {{ $tv(__('Il s’appelle MemoryLane, et j’aimerais beaucoup que tu en fasses partie.'), __('Il s’appelle MemoryLane, et j’aimerais beaucoup que vous en fassiez partie.')) }}
@else
{{ $tv(__(':name t’invite à rejoindre MemoryLane, le site de notre famille.', ['name' => $inviterFirstName]), __(':name vous invite à rejoindre MemoryLane, le site de notre famille.', ['name' => $inviterFirstName])) }} {{ __('C’est un projet que :name a construit lui-même, sur son temps libre, pour rassembler nos photos et notre histoire.', ['name' => $authorFirstName]) }}
@endif
@if ($personalMessage)

{{ __('Le mot de :name :', ['name' => $inviterFirstName]) }}
{{ __('« :name »', ['name' => $personalMessage]) }}
@endif

{{ mb_strtoupper($tv(__('Ta place est déjà là'), __('Votre place est déjà là'))) }}
{{ $personName }}@if ($birthLine) — {{ $birthLine }}@endif

@if ($parentsLine){{ $parentsLine }}
@endif
{{ $tv(__('Tu fais partie des plus de :count personnes de notre arbre.', ['count' => $peopleCount]), __('Vous faites partie des plus de :count personnes de notre arbre.', ['count' => $peopleCount])) }}

{{ mb_strtoupper($byAuthor ? __('Pourquoi j’ai fait ça') : __('Pourquoi :name a créé ce site', ['name' => $authorFirstName])) }}
{{ __('Nos photos de famille sont éparpillées : dans nos téléphones, dans des albums papier, sur des disques durs qu’on n’ouvre jamais.') }} {{ __('Et les histoires qui vont avec ne vivent souvent que dans la mémoire de quelques-uns.') }} {{ __('J’ai voulu un endroit à nous : privé, sans publicité, sans réseau social, où tout ça est rassemblé, rangé et transmis.') }}

{{ mb_strtoupper($tv(__('Ce que tu y trouveras'), __('Ce que vous y trouverez'))) }}
- {{ __('L’arbre de toute la famille (plus de :count personnes) et la fiche de chacun, avec sa frise de vie.', ['count' => $peopleCount]) }}
- {{ __('Plus de :count photos et vidéos, classées par date, lieu et personne ; des albums à partager.', ['count' => $photoCount]) }}
- {{ __('Une page d’accueil vivante : souvenirs du jour, anniversaires, fêtes.') }}
- {{ __('La carte de nos souvenirs.') }}
- {{ $tv(__('La possibilité d’enregistrer ta voix pour raconter une photo.'), __('La possibilité d’enregistrer votre voix pour raconter une photo.')) }}

{{ mb_strtoupper(__('Ça ne marchera qu’avec vous')) }}
{{ __('Le site ne prendra vie que si chacun y dépose ses photos, met des noms sur les visages et raconte ce qu’il sait.') }} {{ __('Même cinq minutes de temps en temps, ça compte.') }}

{{ $tv(__('CRÉER MON ACCÈS (une minute, tu choisis un mot de passe) :'), __('CRÉER MON ACCÈS (une minute, vous choisissez un mot de passe) :')) }}
{{ $url }}
@if ($expiresAt)
{{ __('Lien personnel, valable jusqu’au :date.', ['date' => $expiresAt]) }}
@endif

{{ mb_strtoupper($tv(__('À ton tour'), __('À votre tour'))) }}
{{ $tv(__('Une fois sur le site, tu pourras inviter les proches qui sont dans l’arbre et ajouter ceux qui manquent.'), __('Une fois sur le site, vous pourrez inviter les proches qui sont dans l’arbre et ajouter ceux qui manquent.')) }} {{ __('Chaque modification est gardée dans un historique.') }}

{{ mb_strtoupper(__('Une question, un bug, une idée ?')) }}
@if ($byAuthor && $repliesToAuthor)
{{ $contactPhone ? __('Réponds simplement à ce mail, ou appelle-moi au :phone.', ['phone' => $contactPhone]) : __('Réponds simplement à ce mail.') }}
@else
{{ __('Écrire à :name : :email', ['name' => $authorFirstName, 'email' => $authorEmail]) }}@if ($contactPhone) — {{ $contactPhone }}@endif
@endif

@if ($byAuthor)
{{ $tv(__('Je t’embrasse'), __('Bien affectueusement')) }},
{{ $inviterFirstName }}
@else
{{ __('À très vite sur MemoryLane,') }}
{{ $inviterFirstName }}
@endif

--
MemoryLane · {{ __('le site privé de notre famille') }} · {{ $appHost }}
