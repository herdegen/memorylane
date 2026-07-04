# Roadmap — rendre MemoryLane simple pour toute la famille

> Objectif : que chacun — des enfants aux grands-parents — puisse retrouver,
> regarder et partager les souvenirs **sans mode d'emploi**. Chaque idée est
> évaluée sur un seul critère : est-ce que ça enlève un obstacle pour
> quelqu'un de la famille ?

État au 4 juillet 2026 : Phases 1 à 5 terminées (upload, galerie, tags, géo,
albums, personnes, Vision AI, arbre généalogique, vidéo complète avec
transcodage web). 168 tests.

---

## Priorité 1 — Lever les obstacles à l'entrée

### 1.1 Connexion par lien magique
**Pour qui :** les grands-parents (et tous ceux qui oublient les mots de passe).
Un e-mail « Clique ici pour ouvrir MemoryLane », session longue durée (6 mois).
Le mot de passe devient optionnel.
- *Briques :* Laravel signed URLs + notification mail. Petit chantier (~1 jour).

### 1.2 « Ce jour-là » sur l'accueil
**Pour qui :** tout le monde — la raison de revenir chaque semaine.
Le Dashboard montre les photos prises un même jour les années précédentes
(« Il y a 5 ans aujourd'hui »). C'est la fonctionnalité la plus aimée de
Google Photos, et on a déjà `taken_at`.
- *Briques :* une requête sur `taken_at`, une section Dashboard. (~½ jour)

### 1.3 Une seule barre de recherche
**Pour qui :** ceux qui ne comprennent pas la différence tag/album/personne/label.
Une barre unique qui cherche partout : noms de personnes, tags, albums, lieux,
labels IA, noms de fichiers. Taper « Marie plage 2019 » doit suffire.
- *Briques :* laravel/scout + Meilisearch sont **déjà installés** mais
  inutilisés. Indexer media/people/albums/tags. (~2 jours)

### 1.4 Upload depuis le téléphone sans friction
**Pour qui :** les parents — 95 % des photos naissent sur un téléphone.
PWA installable (icône sur l'écran d'accueil) + Web Share Target : depuis la
galerie du téléphone, « Partager → MemoryLane » envoie directement les photos.
- *Briques :* manifest.json + service worker + route share-target vers
  l'upload existant. (~2 jours)

### 1.5 Diaporama plein écran
**Pour qui :** les grands-parents, la tablette du salon, la TV.
Un bouton « Diaporama » sur chaque album : plein écran, défilement automatique,
zéro interaction nécessaire. Les vidéos se jouent puis on passe à la suite.
- *Briques :* PhotoSwipe est déjà là ; ajouter autoplay + plein écran. (~1 jour)

---

## Priorité 2 — Le confort qui fidélise

### 2.0 Import Google Photos ciblé *(demandé)*
**Pour qui :** ceux dont les photos vivent déjà dans Google Photos.
Un bouton « Importer depuis Google Photos » : l'utilisateur choisit ses photos
dans l'interface Google (le **Picker API** — on peut y chercher par personne,
lieu, date comme dans Google Photos), puis avant l'import il choisit à quelle
**personne** et/ou quel **album** MemoryLane les rattacher. Les photos arrivent
pré-taguées.
- *Pourquoi le Picker :* depuis 2025, l'API Library de Google ne permet plus de
  lister la bibliothèque d'un utilisateur ; le Picker est la voie officielle,
  et son UI de sélection sait filtrer par visage — exactement le besoin.
- *Briques :* OAuth Google (le projet Cloud existe déjà pour Vision), session
  Picker + polling, téléchargement des items choisis, création via
  MediaService (jobs existants : conversions, EXIF, Vision), attach
  personne/album. (~2-3 jours)
- *Prérequis côté admin :* créer un OAuth Client ID (type Web) dans la console
  Google Cloud et activer le Photos Picker API.
- *Limite connue (constatée le 4 juillet 2026) :* **Google supprime les données
  GPS** des fichiers téléchargés via son API (politique de confidentialité,
  pas de contournement). Les photos importées arrivent sans géolocalisation ;
  la date de prise de vue (EXIF) est conservée. Pour un import AVEC géoloc :
  Google Takeout (3.1), dont les sidecars JSON contiennent lat/lng.

### 2.1 Albums intelligents automatiques
Des albums qui se remplissent tout seuls : « Léa » (visages détectés),
« Été 2025 » (période), « Bretagne » (géoloc). Zéro rangement manuel — le
rangement est l'obstacle n°1 des bibliothèques familiales.
- *Briques :* les données existent toutes (detected_faces, taken_at, lat/lng).
  Un modèle SmartAlbum avec des règles + un job de rafraîchissement. (~3 jours)

### 2.2 Résumé hebdomadaire par e-mail
Pour ceux qui ne se connectent jamais d'eux-mêmes : un e-mail le dimanche
« 14 nouvelles photos cette semaine — Paul a ajouté l'anniversaire de Mamie »,
avec 3-4 vignettes et un lien magique (cf. 1.1).
- *Briques :* scheduler déjà en place, Mailable + résumé des uploads. (~1 jour)

### 2.3 Coups de cœur
Un cœur sur chaque média, un album « Coups de cœur » par personne et un
« Préférés de la famille » (les plus aimés). Simple, universel, compréhensible
par un enfant de 5 ans.
- *Briques :* table pivot favorites + bouton. (~1 jour)

### 2.4 Détection de doublons à l'upload
Éviter que la bibliothèque devienne un bazar quand deux personnes uploadent
les mêmes photos de vacances.
- *Briques :* hash SHA-256 à l'upload (colonne + index), avertissement doux
  « déjà présent ». (~1 jour)

### 2.5 L'histoire derrière la photo
Un champ commentaire par média — mais surtout un bouton **enregistrer une
anecdote audio** : la voix de Mamie qui raconte la photo de 1962 vaut plus
que la photo. C'est le cœur du produit « mémoire familiale ».
- *Briques :* MediaRecorder API côté client, stockage S3 comme les médias,
  lecteur audio discret sur la page du média. (~3 jours)

---

## Priorité 3 — Les gros chantiers (quand le reste tourne)

### 3.1 Import en masse
Google Takeout / export WhatsApp / dossier ZIP : un écran « Importer une
archive » qui digère tout en arrière-plan (les jobs et Horizon sont prêts pour ça).

### 3.2 La vie d'une personne
Sur la fiche d'une personne : une frise chronologique de sa vie en photos,
reliée à l'arbre généalogique (naissance → aujourd'hui). L'arbre devient
vivant au lieu d'être un schéma.

### 3.3 Rôles simples
Trois niveaux, pas plus : « regarde » (enfants, invités), « ajoute » (famille),
« organise » (1-2 admins). spatie/laravel-permission est déjà installé.

### 3.4 Export / sauvegarde familiale
Un bouton « Tout télécharger » (ZIP par album ou complet). La confiance vient
de la certitude qu'on peut repartir avec ses souvenirs.

---

## Ce qu'on ne fera PAS (volontairement)

- **Pas de flux social** (likes publics, stories) — c'est un coffre à souvenirs,
  pas un réseau.
- **Pas de retouche photo** — les apps natives le font mieux.
- **Pas de HLS multi-qualités** tant que le MP4 1080p suffit sur la connexion
  familiale.
- **Pas de multi-famille / multi-tenant** — une instance = une famille, c'est
  ce qui garde le produit simple.

## Ordre suggéré

1. **1.2 Ce jour-là** (½ jour, effet immédiat)
2. **1.1 Lien magique** (débloque 2.2)
3. **1.5 Diaporama** (les grands-parents en profitent tout de suite)
4. **1.3 Recherche unifiée** (Meilisearch dort dans composer.json)
5. **1.4 PWA + share target**
6. Puis Priorité 2 dans l'ordre, en commençant par **2.5** (anecdotes audio)
   si l'émotion prime, ou **2.1** (albums intelligents) si c'est le rangement.
