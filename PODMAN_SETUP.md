# Guide d'installation Podman pour MemoryLane sur Windows

Ce guide vous explique comment faire tourner MemoryLane avec Podman au lieu de Docker Desktop sur Windows.

## 🐳 Pourquoi Podman ?

- **Gratuit** : Pas de licence payante comme Docker Desktop
- **Plus sécurisé** : Rootless par défaut
- **Compatible** : Fonctionne avec les fichiers Docker existants
- **Pas de daemon** : Plus léger et plus sécurisé

## 📋 Prérequis

- Windows 10/11 avec WSL2 activé
- WSL2 avec une distribution Linux (Ubuntu recommandé)

## 🛠️ Installation

### 1. Installer Podman sur Windows

Podman fournit maintenant un installeur Windows qui configure automatiquement WSL2 :

1. Télécharger le [Podman Desktop](https://podman-desktop.io/) ou l'installeur CLI depuis [GitHub](https://github.com/containers/podman/releases)
2. Exécuter l'installeur
3. Podman va configurer automatiquement une machine WSL2

**OU** via winget :
```powershell
winget install -e --id RedHat.Podman
```

### 2. Configurer les registries Docker (Important pour WSL)

Dans WSL, configurez Podman pour utiliser Docker Hub par défaut :

```bash
# Créer le dossier de config si nécessaire
mkdir -p ~/.config/containers

# Configurer les registries
cat > ~/.config/containers/registries.conf << 'EOF'
# Configuration des registries pour Podman
unqualified-search-registries = ["docker.io"]

[[registry]]
prefix = "docker.io"
location = "docker.io"
EOF
```

**OU** si vous préférez modifier la config globale (nécessite sudo) :

```bash
sudo nano /etc/containers/registries.conf

# Ajoutez cette ligne dans le fichier :
unqualified-search-registries = ["docker.io"]
```

### 3. Vérifier l'installation

```bash
# Vérifier que tout fonctionne
podman version
podman info

# Tester en tirant une image
podman pull hello-world
podman run hello-world
```

### 4. Installer Podman Compose

Podman Compose est l'équivalent de Docker Compose pour Podman.

**Option A : Via pip (recommandé)**
```powershell
# Installer Python si nécessaire
winget install Python.Python.3.12

# Installer podman-compose
pip install podman-compose
```

**Option B : Via WSL**
```bash
# Dans votre terminal WSL
pip3 install podman-compose
```

### 5. Créer un alias (optionnel)

Pour faciliter la transition, vous pouvez créer des alias qui remplacent les commandes Docker :

**Dans PowerShell** (fichier `$PROFILE`) :
```powershell
Set-Alias -Name docker -Value podman
function docker-compose { podman-compose $args }
```

**Dans WSL** (fichier `~/.bashrc` ou `~/.zshrc`) :
```bash
alias docker='podman'
alias docker-compose='podman-compose'
```

## 🚀 Démarrer MemoryLane avec Podman

### Configuration initiale

Vous êtes actuellement dans WSL, donc utilisez directement les commandes Podman :

```bash
# Se placer dans le projet
cd /home/matthieu/memorylane

# Construire et démarrer tous les services
podman-compose up -d --build

# Vérifier que tous les conteneurs tournent
podman-compose ps
```

### Installation des dépendances

```bash
# Installer les dépendances PHP
podman-compose exec app composer install

# Installer les dépendances JavaScript
podman-compose exec app npm install

# Générer la clé d'application Laravel
podman-compose exec app php artisan key:generate

# Exécuter les migrations
podman-compose exec app php artisan migrate

# Builder les assets frontend
podman-compose exec app npm run build
```

### Créer un utilisateur admin

```bash
# Ouvrir Tinker
podman-compose exec app php artisan tinker

# Dans Tinker, créer un admin :
User::create([
    'name' => 'Admin',
    'email' => 'admin@memorylane.com',
    'password' => Hash::make('password')
]);
```

## 📝 Commandes Podman équivalentes

Voici la correspondance entre Docker et Podman :

| Docker                              | Podman                              |
|-------------------------------------|-------------------------------------|
| `docker ps`                         | `podman ps`                         |
| `docker images`                     | `podman images`                     |
| `docker-compose up -d`              | `podman-compose up -d`              |
| `docker-compose down`               | `podman-compose down`               |
| `docker-compose logs -f`            | `podman-compose logs -f`            |
| `docker-compose exec app bash`      | `podman-compose exec app bash`      |
| `docker-compose build`              | `podman-compose build`              |
| `docker-compose restart`            | `podman-compose restart`            |

## 🔧 Commandes utiles pour MemoryLane

```bash
# Démarrer l'environnement
podman-compose up -d

# Voir les logs en temps réel
podman-compose logs -f

# Voir les logs d'un service spécifique
podman-compose logs -f app
podman-compose logs -f nginx
podman-compose logs -f postgres

# Accéder au conteneur app
podman-compose exec app bash

# Démarrer le serveur de dev avec hot reload
podman-compose exec app npm run dev

# Exécuter les tests
podman-compose exec app php artisan test

# Arrêter tous les services
podman-compose down

# Arrêter et supprimer les volumes (ATTENTION : supprime les données)
podman-compose down -v

# Reconstruire les images
podman-compose build --no-cache

# Redémarrer un service spécifique
podman-compose restart app
```

## 🌐 Accès aux services

Une fois démarré, vous pouvez accéder aux services :

- **Application** : http://localhost:8000
- **Admin Panel (Filament)** : http://localhost:8000/admin
- **Meilisearch** : http://localhost:7700
- **Horizon (queues)** : http://localhost:8000/horizon

## 🐛 Résolution de problèmes

### La machine Podman ne démarre pas

```powershell
# Réinitialiser la machine
podman machine stop
podman machine rm
podman machine init
podman machine start
```

### Erreur de permissions

Si vous avez des erreurs de permissions sur les volumes, ajoutez `:Z` à la fin des volumes dans le docker-compose.yml :

```yaml
volumes:
  - ./backend:/var/www/html:Z
```

### Les ports sont déjà utilisés

Vérifiez qu'aucun conteneur Docker ne tourne en parallèle :

```bash
# Voir tous les conteneurs Podman
podman ps -a

# Nettoyer les conteneurs arrêtés
podman container prune

# Libérer les ports
podman-compose down
```

### Podman-compose ne trouve pas podman

Sur Windows, assurez-vous que Podman est dans le PATH :

```powershell
# Vérifier
podman version

# Si ça ne marche pas, redémarrer le terminal ou ajouter au PATH
```

### Les conteneurs ne communiquent pas entre eux

Podman utilise un réseau par défaut. Si vous avez des problèmes, recréez le réseau :

```bash
podman network rm memorylane
podman-compose up -d
```

## 🔄 Migration depuis Docker

Si vous utilisiez Docker avant :

```bash
# Arrêter Docker
docker-compose down

# Nettoyer Docker (optionnel)
docker system prune -a

# Démarrer avec Podman
podman-compose up -d
```

**Note** : Les volumes Docker et Podman sont séparés. Vous devrez refaire les migrations si vous migrez.

## 📊 Commandes de monitoring

```bash
# Voir l'utilisation des ressources
podman stats

# Voir les ressources d'un conteneur spécifique
podman stats memorylane_app

# Inspecter un conteneur
podman inspect memorylane_app

# Voir les réseaux
podman network ls

# Voir les volumes
podman volume ls
```

## 💡 Astuces

### Script de démarrage rapide

Créez un fichier `start-podman.sh` à la racine du projet :

```bash
#!/bin/bash
echo "🚀 Démarrage de MemoryLane avec Podman..."
podman-compose up -d
echo "✅ Services démarrés !"
echo ""
podman-compose ps
echo ""
echo "📱 Application : http://localhost:8000"
echo "⚙️  Admin Panel : http://localhost:8000/admin"
```

Rendez-le exécutable :
```bash
chmod +x start-podman.sh
./start-podman.sh
```

### Auto-start de la machine Podman

Pour démarrer automatiquement la machine Podman au démarrage de Windows :

```powershell
# Créer une tâche planifiée (exécuter en tant qu'admin)
$action = New-ScheduledTaskAction -Execute "podman" -Argument "machine start"
$trigger = New-ScheduledTaskTrigger -AtStartup
Register-ScheduledTask -TaskName "PodmanMachineStart" -Action $action -Trigger $trigger -RunLevel Highest
```

## 📚 Ressources

- [Documentation Podman](https://docs.podman.io/)
- [Podman Desktop](https://podman-desktop.io/)
- [Podman Compose](https://github.com/containers/podman-compose)
- [Migration Docker vers Podman](https://docs.podman.io/en/latest/markdown/podman-docker.1.html)

## ⚠️ Limitations connues

1. **Podman Desktop** : Interface graphique disponible mais moins mature que Docker Desktop
2. **Compatibilité** : Certains fichiers docker-compose très complexes peuvent nécessiter des ajustements
3. **Performance** : Sur Windows, les performances peuvent varier selon la configuration WSL2

---

**Conseil** : Si vous rencontrez des problèmes, les logs sont votre ami :
```bash
podman-compose logs -f
```
