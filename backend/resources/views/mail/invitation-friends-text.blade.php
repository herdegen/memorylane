{{ $tv(__('Salut :name,', ['name' => $firstName]), __('Bonjour :name,', ['name' => $firstName])) }}

@if ($byAuthor)
{{ __('C’est :name.', ['name' => $inviterFirstName]) }} {{ __('J’ai construit moi-même, sur mon temps libre, MemoryLane : un site privé pour rassembler nos photos et les histoires qui vont avec.') }} {{ $tv(__('J’aimerais beaucoup que tu rejoignes notre groupe, « :circle ».', ['circle' => $circleName]), __('J’aimerais beaucoup que vous rejoigniez notre groupe, « :circle ».', ['circle' => $circleName])) }}
@else
{{ $tv(__(':name t’invite à rejoindre « :circle » sur MemoryLane, un site privé de souvenirs que :author a construit lui-même, sur son temps libre.', ['name' => $inviterFirstName, 'circle' => $circleName, 'author' => $authorFirstName]), __(':name vous invite à rejoindre « :circle » sur MemoryLane, un site privé de souvenirs que :author a construit lui-même, sur son temps libre.', ['name' => $inviterFirstName, 'circle' => $circleName, 'author' => $authorFirstName])) }}
@endif
@if ($personalMessage)

{{ __('Le mot de :name :', ['name' => $inviterFirstName]) }}
{{ __('« :name »', ['name' => $personalMessage]) }}
@endif

{{ mb_strtoupper($tv(__('Ta place est déjà là'), __('Votre place est déjà là'))) }}
{{ $personName }} — {{ __('« :name »', ['name' => $circleName]) }}
@if ($circlePhotoCount > 0)
{{ trans_choice('Déjà :count photo partagée dans le groupe.|Déjà :count photos partagées dans le groupe.', $circlePhotoCount) }}
@endif

{{ mb_strtoupper($tv(__('Ce que tu y trouveras'), __('Ce que vous y trouverez'))) }}
- {{ __('Le réseau de la bande : qui apparaît avec qui, tissé par vos photos.') }}
- {{ __('Vos souvenirs ensemble : sur la fiche de chacun, vos photos communes.') }}
- {{ __('Des albums partagés au groupe, avec les visages reconnus automatiquement.') }}
- {{ __('« Comment vous êtes-vous connus ? » : chacun raconte sa version.') }}
- {{ __('Une page d’accueil vivante : souvenirs du jour, anniversaires des amis.') }}

{{ mb_strtoupper($tv(__('Tes propres groupes d’amis'), __('Vos propres groupes d’amis'))) }}
{{ $tv(__('Tu peux aussi créer tes propres groupes — les copains du lycée, le club de sport, les voisins — et y inviter qui tu veux.'), __('Vous pouvez aussi créer vos propres groupes — les copains du lycée, le club de sport, les voisins — et y inviter qui vous voulez.')) }} {{ __('Chaque groupe est étanche : ce qui y est partagé reste dans le groupe.') }}

{{ mb_strtoupper(__('Et la famille ?')) }}
{{ __('MemoryLane a aussi un mode famille : un arbre généalogique et la mémoire de toute une famille.') }} {{ $tv(__('Famille et amis sont deux mondes séparés, sauf pour un album que tu choisis de partager avec les deux, comme celui d’un mariage.'), __('Famille et amis sont deux mondes séparés, sauf pour un album que vous choisissez de partager avec les deux, comme celui d’un mariage.')) }}

{{ $tv(__('CRÉER MON ACCÈS (une minute, tu choisis un mot de passe) :'), __('CRÉER MON ACCÈS (une minute, vous choisissez un mot de passe) :')) }}
{{ $url }}
@if ($expiresAt)
{{ __('Lien personnel, valable jusqu’au :date.', ['date' => $expiresAt]) }}
@endif

{{ __('Pas un réseau social : ni fil d’actualité, ni « j’aime », ni publicité.') }} {{ __('Rien n’est public.') }}

{{ mb_strtoupper(__('Une question, un bug, une idée ?')) }}
@if ($byAuthor && $repliesToAuthor)
{{ $contactPhone ? __('Réponds simplement à ce mail, ou appelle-moi au :phone.', ['phone' => $contactPhone]) : __('Réponds simplement à ce mail.') }}
@else
{{ __('Écrire à :name : :email', ['name' => $authorFirstName, 'email' => $authorEmail]) }}@if ($contactPhone) — {{ $contactPhone }}@endif
@endif

{{ __('À très vite sur MemoryLane,') }}
{{ $inviterFirstName }}

--
MemoryLane · {{ __('nos souvenirs entre amis') }} · {{ $appHost }}
