{{ $tv('Salut', 'Bonjour') }} {{ $firstName }},

@if ($byAuthor)
C’est {{ $inviterFirstName }}. J’ai construit moi-même, sur mon temps libre, MemoryLane : un site privé pour rassembler nos photos et les histoires qui vont avec. J’aimerais beaucoup que {{ $tv('tu rejoignes', 'vous rejoigniez') }} notre groupe, « {{ $circleName }} ».
@else
{{ $inviterFirstName }} {{ $tv('t’invite', 'vous invite') }} à rejoindre « {{ $circleName }} » sur MemoryLane, un site privé de souvenirs que {{ $authorFirstName }} a construit lui-même, sur son temps libre.
@endif
@if ($personalMessage)

Le mot de {{ $inviterFirstName }} :
« {{ $personalMessage }} »
@endif

{{ $tv('TA PLACE EST DÉJÀ LÀ', 'VOTRE PLACE EST DÉJÀ LÀ') }}
{{ $personName }} — « {{ $circleName }} »
@if ($circlePhotoCount > 0)
Déjà {{ $circlePhotoCount }} photo{{ $circlePhotoCount > 1 ? 's' : '' }} partagée{{ $circlePhotoCount > 1 ? 's' : '' }} dans le groupe.
@endif

CE QUE {{ $tv('TU Y TROUVERAS', 'VOUS Y TROUVEREZ') }}
- Le réseau de la bande : qui apparaît avec qui, tissé par vos photos.
- Vos souvenirs ensemble : sur la fiche de chacun, vos photos communes.
- Des albums partagés au groupe, avec les visages reconnus automatiquement.
- « Comment vous êtes-vous connus ? » : chacun raconte sa version.
- Une page d’accueil vivante : souvenirs du jour, anniversaires des amis.

{{ $tv('TES', 'VOS') }} PROPRES GROUPES D’AMIS
{{ $tv('Tu peux', 'Vous pouvez') }} aussi créer {{ $tv('tes', 'vos') }} propres groupes — les copains du lycée, le club de sport, les voisins — et y inviter qui {{ $tv('tu veux', 'vous voulez') }}. Chaque groupe est étanche : ce qui y est partagé reste dans le groupe.

ET LA FAMILLE ?
MemoryLane a aussi un mode famille : un arbre généalogique et la mémoire de toute une famille. Famille et amis sont deux mondes séparés, sauf pour un album que {{ $tv('tu choisis', 'vous choisissez') }} de partager avec les deux, comme celui d’un mariage.

CRÉER MON ACCÈS (une minute, {{ $tv('tu choisis', 'vous choisissez') }} un mot de passe) :
{{ $url }}
@if ($expiresAt)
Lien personnel, valable jusqu’au {{ $expiresAt }}.
@endif

Pas un réseau social : ni fil d’actualité, ni « j’aime », ni publicité. Rien n’est public.

UNE QUESTION, UN BUG, UNE IDÉE ?
@if ($byAuthor && $repliesToAuthor)
Réponds simplement à ce mail{{ $contactPhone ? ', ou appelle-moi au '.$contactPhone : '' }}.
@else
Écrire à {{ $authorFirstName }} : {{ $authorEmail }}@if ($contactPhone) — {{ $contactPhone }}@endif
@endif

À très vite sur MemoryLane,
{{ $inviterFirstName }}

--
MemoryLane · nos souvenirs entre amis · {{ $appHost }}
