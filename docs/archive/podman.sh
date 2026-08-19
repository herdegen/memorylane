#!/bin/bash

# Script de gestion MemoryLane avec Podman
# Usage: ./podman.sh [commande]

set -e

COMPOSE_CMD="podman-compose"
PROJECT_NAME="memorylane"

# Couleurs pour l'output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Fonction d'affichage
print_info() {
    echo -e "${BLUE}ℹ ${NC} $1"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

# Vérifier que podman-compose est installé
check_requirements() {
    if ! command -v podman &> /dev/null; then
        print_error "Podman n'est pas installé. Consultez PODMAN_SETUP.md"
        exit 1
    fi

    if ! command -v podman-compose &> /dev/null; then
        print_error "podman-compose n'est pas installé."
        print_info "Installez-le avec: pip install podman-compose"
        exit 1
    fi
}

# Afficher l'aide
show_help() {
    cat << EOF
${BLUE}MemoryLane - Gestion Podman${NC}

${GREEN}Commandes disponibles :${NC}

  ${YELLOW}start${NC}           Démarrer tous les services
  ${YELLOW}stop${NC}            Arrêter tous les services
  ${YELLOW}restart${NC}         Redémarrer tous les services
  ${YELLOW}status${NC}          Afficher le statut des services
  ${YELLOW}logs${NC}            Afficher les logs (Ctrl+C pour quitter)
  ${YELLOW}build${NC}           Reconstruire les images

  ${YELLOW}setup${NC}           Installation initiale complète
  ${YELLOW}reset${NC}           Réinitialiser (supprime les volumes!)

  ${YELLOW}shell${NC}           Accéder au conteneur app (bash)
  ${YELLOW}tinker${NC}          Ouvrir Laravel Tinker
  ${YELLOW}artisan${NC} [cmd]   Exécuter une commande artisan
  ${YELLOW}composer${NC} [cmd]  Exécuter une commande composer
  ${YELLOW}npm${NC} [cmd]       Exécuter une commande npm

  ${YELLOW}test${NC}            Exécuter tous les tests
  ${YELLOW}migrate${NC}         Exécuter les migrations
  ${YELLOW}fresh${NC}           Migrations fresh (recrée la DB)

  ${YELLOW}dev${NC}             Démarrer le serveur dev (Vite)
  ${YELLOW}prod${NC}            Builder pour la production

  ${YELLOW}ps${NC}              Liste des conteneurs
  ${YELLOW}clean${NC}           Nettoyer les ressources inutilisées

${GREEN}Exemples :${NC}
  ./podman.sh start
  ./podman.sh artisan migrate
  ./podman.sh composer install
  ./podman.sh test

EOF
}

# Démarrer les services
start_services() {
    print_info "Démarrage des services MemoryLane..."
    $COMPOSE_CMD up -d
    print_success "Services démarrés !"
    echo ""
    $COMPOSE_CMD ps
    echo ""
    print_info "Application : http://localhost:8000"
    print_info "Admin Panel : http://localhost:8000/admin"
    print_info "Meilisearch : http://localhost:7700"
}

# Arrêter les services
stop_services() {
    print_info "Arrêt des services..."
    $COMPOSE_CMD down
    print_success "Services arrêtés !"
}

# Redémarrer les services
restart_services() {
    print_info "Redémarrage des services..."
    $COMPOSE_CMD restart
    print_success "Services redémarrés !"
}

# Afficher le statut
show_status() {
    print_info "Statut des services :"
    echo ""
    $COMPOSE_CMD ps
    echo ""
    print_info "Utilisation des ressources :"
    podman stats --no-stream
}

# Afficher les logs
show_logs() {
    print_info "Logs en temps réel (Ctrl+C pour quitter)..."
    $COMPOSE_CMD logs -f
}

# Reconstruire les images
build_images() {
    print_info "Reconstruction des images..."
    $COMPOSE_CMD build --no-cache
    print_success "Images reconstruites !"
}

# Installation initiale complète
setup_project() {
    print_info "Installation complète de MemoryLane..."

    # Vérifier le fichier .env
    if [ ! -f ".env" ]; then
        print_warning "Fichier .env manquant, copie depuis .env.example..."
        cp .env.example .env
        print_info "⚠️  Éditez le fichier .env avant de continuer !"
        read -p "Appuyez sur Entrée quand c'est fait..."
    fi

    # Démarrer les services
    print_info "Démarrage des services..."
    $COMPOSE_CMD up -d --build

    # Attendre que les services soient prêts
    print_info "Attente du démarrage des services..."
    sleep 10

    # Créer les dossiers nécessaires avec bonnes permissions
    print_info "Création des dossiers nécessaires..."
    mkdir -p backend/vendor backend/node_modules backend/storage backend/bootstrap/cache
    chmod -R 775 backend/

    # Installer les dépendances (en root pour éviter les problèmes de permissions Podman)
    print_info "Installation des dépendances PHP..."
    $COMPOSE_CMD exec --user root app composer install --no-interaction

    print_info "Correction des permissions vendor..."
    $COMPOSE_CMD exec --user root app chown -R memorylane:memorylane /var/www/html/vendor

    print_info "Installation des dépendances JavaScript..."
    $COMPOSE_CMD exec --user root app npm install

    print_info "Correction des permissions node_modules..."
    $COMPOSE_CMD exec --user root app chown -R memorylane:memorylane /var/www/html/node_modules

    # Configuration Laravel
    print_info "Correction des permissions storage..."
    $COMPOSE_CMD exec --user root app chown -R memorylane:memorylane /var/www/html/storage /var/www/html/bootstrap/cache
    $COMPOSE_CMD exec --user root app chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

    print_info "Génération de la clé Laravel..."
    $COMPOSE_CMD exec --user root app php artisan key:generate

    # Migrations
    print_info "Exécution des migrations..."
    $COMPOSE_CMD exec app php artisan migrate --force

    # Build assets
    print_info "Build des assets..."
    $COMPOSE_CMD exec --user root app npm run build

    print_info "Correction des permissions build..."
    $COMPOSE_CMD exec --user root app chown -R memorylane:memorylane /var/www/html/public/build

    print_success "Installation terminée !"
    echo ""
    print_info "Créez maintenant un utilisateur admin :"
    print_info "./podman.sh tinker"
    echo ""
    echo "User::create(['name' => 'Admin', 'email' => 'admin@memorylane.com', 'password' => Hash::make('password')]);"
}

# Réinitialiser le projet
reset_project() {
    print_warning "ATTENTION : Cette action va supprimer tous les volumes et données !"
    read -p "Êtes-vous sûr ? (oui/non) : " confirm

    if [ "$confirm" = "oui" ]; then
        print_info "Réinitialisation..."
        $COMPOSE_CMD down -v
        print_success "Projet réinitialisé ! Exécutez './podman.sh setup' pour réinstaller."
    else
        print_info "Annulé."
    fi
}

# Accéder au shell du conteneur app
shell_access() {
    print_info "Accès au conteneur app (bash)..."
    $COMPOSE_CMD exec app bash
}

# Ouvrir Tinker
open_tinker() {
    print_info "Ouverture de Laravel Tinker..."
    $COMPOSE_CMD exec app php artisan tinker
}

# Exécuter une commande artisan
run_artisan() {
    $COMPOSE_CMD exec app php artisan "$@"
}

# Exécuter composer
run_composer() {
    $COMPOSE_CMD exec app composer "$@"
}

# Exécuter npm
run_npm() {
    $COMPOSE_CMD exec app npm "$@"
}

# Exécuter les tests
run_tests() {
    print_info "Exécution des tests..."
    $COMPOSE_CMD exec app php artisan test
}

# Exécuter les migrations
run_migrations() {
    print_info "Exécution des migrations..."
    $COMPOSE_CMD exec app php artisan migrate
}

# Migrations fresh
run_fresh() {
    print_warning "ATTENTION : Ceci va supprimer toutes les données de la DB !"
    read -p "Continuer ? (oui/non) : " confirm

    if [ "$confirm" = "oui" ]; then
        print_info "Migration fresh..."
        $COMPOSE_CMD exec app php artisan migrate:fresh
        print_success "Database réinitialisée !"
    else
        print_info "Annulé."
    fi
}

# Démarrer le serveur dev
start_dev() {
    print_info "Démarrage du serveur de développement (Vite)..."
    print_info "Accessible sur http://localhost:5173"
    $COMPOSE_CMD exec app npm run dev
}

# Builder pour production
build_prod() {
    print_info "Build des assets pour la production..."
    $COMPOSE_CMD exec app npm run build
    print_success "Build terminé !"
}

# Liste des conteneurs
list_containers() {
    podman ps -a
}

# Nettoyer les ressources
clean_resources() {
    print_info "Nettoyage des ressources inutilisées..."
    podman system prune -f
    print_success "Nettoyage terminé !"
}

# Vérifier les prérequis
check_requirements

# Traiter la commande
case "${1:-help}" in
    start)
        start_services
        ;;
    stop)
        stop_services
        ;;
    restart)
        restart_services
        ;;
    status)
        show_status
        ;;
    logs)
        show_logs
        ;;
    build)
        build_images
        ;;
    setup)
        setup_project
        ;;
    reset)
        reset_project
        ;;
    shell)
        shell_access
        ;;
    tinker)
        open_tinker
        ;;
    artisan)
        shift
        run_artisan "$@"
        ;;
    composer)
        shift
        run_composer "$@"
        ;;
    npm)
        shift
        run_npm "$@"
        ;;
    test)
        run_tests
        ;;
    migrate)
        run_migrations
        ;;
    fresh)
        run_fresh
        ;;
    dev)
        start_dev
        ;;
    prod)
        build_prod
        ;;
    ps)
        list_containers
        ;;
    clean)
        clean_resources
        ;;
    help|--help|-h)
        show_help
        ;;
    *)
        print_error "Commande inconnue : $1"
        echo ""
        show_help
        exit 1
        ;;
esac
