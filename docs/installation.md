# Installer MemoryLane chez soi

MemoryLane rassemble les photos, vidéos et souvenirs d'une famille : albums, arbre généalogique, reconnaissance des visages, carte, frise de vie, jeu « Qui est-ce ? », interface pour la télévision… Ce guide explique comment l'installer sur **votre** serveur. Vos photos ne sont envoyées nulle part ailleurs que là où vous le choisissez (voir [Connexions vers l'extérieur](#connexions-vers-lextérieur)).

L'installation se fait en deux temps :

1. **Dans un terminal**, `./install.sh` prépare tout ce qui est technique : conteneurs Docker, mots de passe internes, stockage des photos. Une seule commande, aucune question.
2. **Dans le navigateur**, un assistant vous guide étape par étape, en expliquant chaque choix : adresse du site, stockage des photos, e-mails, sauvegardes, options, compte administrateur.

Comptez 15 à 20 minutes, dont la moitié à attendre les téléchargements.

---

## Ce qu'il faut

| | Minimum | Conseillé |
|---|---|---|
| Système | Linux 64 bits avec Docker | Debian 12 ou Ubuntu 24.04 |
| Mémoire | 4 Go | 8 Go, avec la reconnaissance des visages |
| Processeur | 2 cœurs | 4 cœurs |
| Disque | 20 Go, plus la place des photos | un disque séparé pour les photos |

Logiciels :

- **Docker** avec le module **Docker Compose 2.24 ou plus**,
- **git**.

Pour installer Docker sur Debian ou Ubuntu :

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER   # puis se déconnecter et se reconnecter
```

Facultatif, selon vos choix dans l'assistant :

- **Un nom de domaine**, pour accéder au site de partout, en HTTPS. Le certificat est automatique.
- **Un compte d'envoi d'e-mails** (SMTP), pour les invitations et les liens de connexion.
- **Un stockage S3**, chez Scaleway, OVHcloud, Backblaze, AWS… Il peut servir pour les photos, pour les sauvegardes, ou pour les deux.

Sans rien de tout ça, MemoryLane fonctionne à la maison, sur votre réseau.

### Connexions vers l'extérieur

Le serveur doit pouvoir joindre Internet (en sortie) pour l'installation et les mises à jour (images Docker, paquets PHP et JavaScript). Ensuite, pour enrichir les fiches, il interroge quelques services publics et gratuits, **sans jamais leur envoyer de photo** : seulement un nom de lieu, des coordonnées ou un prénom.

| Pour… | Service |
|---|---|
| Situer les lieux sur la carte | OpenStreetMap (Nominatim), Base Adresse Nationale (adresses en France) |
| Suggérer des lieux pendant la saisie | Photon (komoot) |
| Illustrer un lieu par une photo | Wikipédia, Wikidata, Wikimedia Commons |
| Fêtes des prénoms | nominis.cef.fr |

Le navigateur des visiteurs charge en plus les fonds de carte (OpenStreetMap, Esri). La lecture du texte des documents (Tesseract) et la reconnaissance des visages restent sur votre serveur.

Pour couper le géocodage automatique des lieux, réglez `GEOCODE_AUTO=false` dans `backend/.env` (voir [Changer un réglage plus tard](#changer-un-réglage-plus-tard)).

---

## Installation

```bash
git clone https://github.com/herdegen/memorylane.git
cd memorylane
./install.sh
```

Le script :

1. vérifie la machine : Docker, mémoire, disque, port libre ;
2. génère les mots de passe internes et la clé de chiffrement de l'application ;
3. construit et démarre les conteneurs : base de données, cache, moteur de recherche, stockage des photos, application ;
4. affiche une adresse et un **code d'accès** :

```
  Ouvrez dans votre navigateur :  http://192.168.1.20:8080/install
  Code d'accès à saisir       :  9LWFMU4K
```

Ouvrez cette adresse et laissez le terminal ouvert. Quand l'assistant est terminé, le script démarre les services choisis et affiche l'adresse définitive.

Si le terminal a été fermé avant la fin, relancez :

```bash
./install.sh finish
```

Si l'installation a été interrompue, relancez `./install.sh` : elle reprend là où elle s'était arrêtée.

### Options

| Option | Effet |
|---|---|
| `--port 8090` | Port du site, 8080 par défaut |
| `--bind 127.0.0.1` | N'écouter que sur cette machine, par exemple derrière votre propre proxy |
| `--project memorylane2` | Nom du projet Docker, pour avoir plusieurs installations sur une même machine |

---

## Les étapes de l'assistant

Chaque étape est expliquée en détail à l'écran. En résumé :

1. **Vérifications.** L'assistant contrôle les services, les extensions, la mémoire et le disque.
2. **Votre site.** Vous choisissez le nom du site, et comment on y accède :
   - **seulement depuis la maison** : c'est le plus simple ;
   - **de partout, avec votre nom de domaine** : le HTTPS est automatique avec Let's Encrypt. Il faut un enregistrement DNS `A` vers votre serveur, et les ports 80 et 443 ouverts ;
   - **derrière votre propre proxy** : Traefik, Nginx Proxy Manager, Synology…
3. **Photos et vidéos.** Vous choisissez où ranger les fichiers :
   - **sur ce serveur**, avec le stockage intégré (Garage) : gratuit, et vos photos restent chez vous ;
   - **chez un fournisseur S3** : vos fichiers y sont copiés plusieurs fois, l'espace est illimité, et changer de serveur ne demande pas de les déménager.

   L'assistant teste l'écriture, la lecture et la suppression d'un fichier. Pour un fournisseur S3, il règle aussi le CORS du bucket.
4. **E-mails.** Vous choisissez entre pas d'envoi pour l'instant (invitations par lien) et un serveur SMTP. Il propose des raccourcis pour Gmail, OVH, Infomaniak, Brevo et Scaleway, et un e-mail de test.
5. **Sauvegardes.** Elles sont facultatives, mais fortement conseillées : chaque nuit, une copie chiffrée part vers un stockage S3 distant. Elle contient les photos, les copies de la base et la configuration. Un test de restauration a lieu chaque mois.

   L'assistant affiche **deux phrases secrètes, à noter hors du serveur**. Sans elles, la sauvegarde est illisible.
6. **Options.** Vous pouvez activer :
   - la reconnaissance des visages sur le serveur ;
   - les notifications sur téléphone, qui demandent le HTTPS ;
   - l'import Google Photos et Google Drive ;
   - la supervision.
7. **Votre compte.** Vous créez l'administrateur et le nom de votre famille, c'est-à-dire le premier « cercle ».
8. **Installation.** L'assistant crée la base et le compte, puis passe la main au terminal.

L'assistant n'existe que pendant l'installation. Une fois terminée, l'adresse `/install` n'existe plus.

---

## Après l'installation

| Pour… | Commande, dans le dossier `memorylane` |
|---|---|
| Mettre à jour | `./install.sh update` (fait d'abord une copie de la base) |
| Voir l'état des services | `./install.sh status` |
| Lire les journaux | `docker compose logs -f app horizon` |
| Arrêter / redémarrer | `docker compose stop` puis `docker compose up -d` |

Sur téléphone, « Ajouter à l'écran d'accueil » installe MemoryLane comme une application. Cela demande le HTTPS sur Android.

### Où sont les données

| Quoi | Où |
|---|---|
| Configuration de l'application | `backend/.env` (secrets compris, droits 600) |
| Configuration Docker (services, port, domaine) | `.env` |
| Base de données | volume Docker `<projet>_postgres_data` |
| Photos et vidéos, avec le stockage intégré | volume Docker `<projet>_garage_data`, ou le dossier `GARAGE_DATA_DIR` |
| Copies de la base | chaque nuit dans le stockage des photos (`_backups/`) ; avant chaque mise à jour dans `backups/` |

Pour garder les photos sur un grand disque, indiquez son dossier **avant l'installation** : copiez `GARAGE_DATA_DIR=/srv/photos` dans le `.env` généré, puis relancez `./install.sh`. Après coup, il faut arrêter Garage, copier le contenu du volume vers ce dossier, régler la variable et redémarrer.

### Changer un réglage plus tard

Tous les réglages de l'assistant sont dans `backend/.env`, et sont commentés. Après une modification :

```bash
docker compose exec app php artisan optimize
docker compose restart app horizon scheduler
```

Les services facultatifs se règlent dans le `.env` à la racine, avec `COMPOSE_PROFILES`. Les valeurs possibles sont `local-storage`, `faces`, `backup` et `https`. Après une modification, lancez `docker compose up -d`.

Par exemple, pour passer au HTTPS avec un domaine :

1. Dans `.env`, réglez `MEMORYLANE_DOMAIN=photos.mafamille.fr` et `APP_BIND=127.0.0.1`, puis ajoutez `https` à `COMPOSE_PROFILES`.
2. Dans `backend/.env`, réglez `APP_URL=https://photos.mafamille.fr` et `SESSION_SECURE_COOKIE=true`. Avec le stockage intégré, réglez aussi `SCALEWAY_PUBLIC_URL` sur la même adresse.
3. Lancez les deux commandes ci-dessus, puis `docker compose up -d`.

### Restaurer la base de données

```bash
# copie faite avant une mise à jour :
docker compose exec -T postgres sh -c 'pg_restore --clean --if-exists -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < backups/avant-maj-AAAAMMJJ-HHMMSS.dump
```

Les copies nocturnes sont dans le stockage des photos, sous `_backups/`. Avec la sauvegarde distante, tout se relit avec `rclone` et les deux phrases secrètes. Le détail est dans `docker/backup/backup.sh`.

### Application pour la télévision (Android TV)

L'interface TV est à l'adresse `/tv/ecran`, depuis le navigateur de la télévision. L'application Android fournie est réglée pour memory-lane.fr : pour la vôtre, construisez-la sur votre serveur. Il faut Docker et le SDK Android ; la marche à suivre est en tête du script.

```bash
cd tv-app && TV_URL=https://photos.mafamille.fr/tv/ecran ./build.sh
```

La page « Relier une télévision » propose ensuite l'application à votre famille. Gardez précieusement la clé de signature créée dans `~/.memorylane-tv/` : sans elle, les mises à jour ne s'installent pas par-dessus.

---

## Dépannage

| Symptôme | Piste |
|---|---|
| « Docker ne répond pas » | Ajoutez votre compte au groupe `docker` et reconnectez-vous, ou lancez avec `sudo`. |
| « Le port 8080 est déjà utilisé » | `./install.sh --port 8090` |
| Le site en HTTPS ne répond pas | Vérifiez l'enregistrement DNS `A` et les ports 80/443 de la box. Lancez `docker compose logs caddy`. |
| Les envois échouent avec un stockage S3 externe | Le bucket doit autoriser votre site (CORS) : relancez le test du stockage, ou réglez-le chez le fournisseur. |
| Aucun e-mail n'arrive | Regardez les indésirables. Vérifiez le serveur SMTP dans `backend/.env` et lisez `docker compose logs app`. |
| Une erreur pendant une page | `docker compose exec app tail -n 50 storage/logs/laravel-$(date +%F).log` |

---

## Désinstaller

```bash
docker compose down        # arrête tout, garde les données
docker compose down -v     # ⚠️ efface aussi la base et les photos du stockage intégré
```
