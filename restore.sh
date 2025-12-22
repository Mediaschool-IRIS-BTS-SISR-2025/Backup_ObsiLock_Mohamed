#!/bin/bash

################################################################################
# Script de Restauration pour ObsiLock
# Restaure: Base MySQL + Fichiers uploads + Configuration
################################################################################

set -euo pipefail

# Configuration
BACKUP_DIR="/home/mohamed/backup/slam/obsilock"
PROJECT_DIR="/home/iris/slam/ObsiLock"
RESTORE_DIR="/tmp/obsilock_restore_$$"

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

################################################################################
# Fonctions de logging
################################################################################
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

log_error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

log_info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] INFO:${NC} $1"
}

################################################################################
# Nettoyage à la sortie
################################################################################
cleanup() {
    if [ -d "${RESTORE_DIR}" ]; then
        log "Nettoyage du répertoire temporaire..."
        rm -rf "${RESTORE_DIR}"
    fi
}

trap cleanup EXIT

################################################################################
# Lister les backups disponibles
################################################################################
list_backups() {
    log "=== Backups disponibles ==="
    
    if [ ! -d "${BACKUP_DIR}" ]; then
        log_error "Dossier de backups non trouvé: ${BACKUP_DIR}"
        exit 1
    fi
    
    cd "${BACKUP_DIR}"
    
    # Créer un tableau avec les backups
    mapfile -t BACKUPS < <(ls -t obsilock_backup_*.tar.gz 2>/dev/null || true)
    
    if [ ${#BACKUPS[@]} -eq 0 ]; then
        log_error "Aucun backup trouvé dans ${BACKUP_DIR}"
        exit 1
    fi
    
    echo ""
    echo "┌─────┬────────────────────────────────────┬──────────┬──────────────────────┐"
    echo "│  #  │ Nom du backup                      │ Taille   │ Date                 │"
    echo "├─────┼────────────────────────────────────┼──────────┼──────────────────────┤"
    
    local INDEX=1
    for backup in "${BACKUPS[@]}"; do
        SIZE=$(du -h "${backup}" | cut -f1)
        DATE=$(stat -c %y "${backup}" | cut -d' ' -f1,2 | cut -d'.' -f1)
        printf "│ %3d │ %-34s │ %8s │ %20s │\n" "${INDEX}" "${backup}" "${SIZE}" "${DATE}"
        INDEX=$((INDEX + 1))
    done
    
    echo "└─────┴────────────────────────────────────┴──────────┴──────────────────────┘"
    echo ""
}

################################################################################
# Sélection du backup
################################################################################
select_backup() {
    list_backups
    
    local TOTAL=${#BACKUPS[@]}
    
    while true; do
        read -p "Sélectionnez le numéro du backup à restaurer (1-${TOTAL}) ou 'q' pour quitter: " CHOICE
        
        if [[ "${CHOICE}" == "q" ]]; then
            log "Annulation de la restauration"
            exit 0
        fi
        
        if [[ "${CHOICE}" =~ ^[0-9]+$ ]] && [ "${CHOICE}" -ge 1 ] && [ "${CHOICE}" -le "${TOTAL}" ]; then
            SELECTED_BACKUP="${BACKUPS[$((CHOICE - 1))]}"
            log_info "Backup sélectionné: ${SELECTED_BACKUP}"
            break
        else
            log_error "Choix invalide. Veuillez entrer un nombre entre 1 et ${TOTAL}"
        fi
    done
}

################################################################################
# Confirmation de restauration
################################################################################
confirm_restore() {
    log_warning "⚠️  ATTENTION ⚠️"
    log_warning "Cette opération va ÉCRASER les données actuelles d'ObsiLock !"
    log_warning "Backup à restaurer: ${SELECTED_BACKUP}"
    echo ""
    
    read -p "Êtes-vous sûr de vouloir continuer ? (tapez 'OUI' en majuscules): " CONFIRM
    
    if [ "${CONFIRM}" != "OUI" ]; then
        log "Restauration annulée"
        exit 0
    fi
    
    log "Confirmation reçue. Début de la restauration..."
}

################################################################################
# Extraction du backup
################################################################################
extract_backup() {
    log "=== Extraction du backup ==="
    
    mkdir -p "${RESTORE_DIR}"
    cd "${BACKUP_DIR}"
    
    log "Extraction de ${SELECTED_BACKUP}..."
    tar xzf "${SELECTED_BACKUP}" -C "${RESTORE_DIR}"
    
    # Trouver le dossier extrait
    BACKUP_FOLDER=$(ls -d ${RESTORE_DIR}/obsilock_backup_* | head -n1)
    
    if [ ! -d "${BACKUP_FOLDER}" ]; then
        log_error "Impossible de trouver le dossier extrait"
        exit 1
    fi
    
    log "✓ Backup extrait dans ${BACKUP_FOLDER}"
}

################################################################################
# Arrêt des services Docker
################################################################################
stop_services() {
    log "=== Arrêt des services Docker ==="
    
    cd "${PROJECT_DIR}"
    
    if docker compose ps 2>/dev/null | grep -q "obsilock"; then
        log "Arrêt des conteneurs..."
        docker compose down
        log "✓ Services arrêtés"
    else
        log_info "Services déjà arrêtés"
    fi
}

################################################################################
# Restauration de la base de données
################################################################################
restore_database() {
    log "=== Restauration de la base de données ==="
    
    cd "${PROJECT_DIR}"
    
    # Démarrer uniquement MySQL
    log "Démarrage du conteneur MySQL..."
    docker compose up -d mysql
    
    # Attendre que MySQL soit prêt
    log "Attente du démarrage de MySQL..."
    sleep 10
    
    local MAX_TRIES=30
    local TRY=0
    while ! docker exec obsilock_mysql mysqladmin ping -h localhost --silent 2>/dev/null; do
        TRY=$((TRY + 1))
        if [ ${TRY} -ge ${MAX_TRIES} ]; then
            log_error "MySQL n'a pas démarré après ${MAX_TRIES} tentatives"
            exit 1
        fi
        log_info "Attente de MySQL... (${TRY}/${MAX_TRIES})"
        sleep 2
    done
    
    log "✓ MySQL est prêt"
    
    # Récupérer le mot de passe root
    DB_PASSWORD=$(docker exec obsilock_mysql printenv MYSQL_ROOT_PASSWORD)
    
    # Restaurer la base de données principale
    if [ -f "${BACKUP_FOLDER}/database/coffre_fort.sql.gz" ]; then
        log "Restauration de la base coffre_fort..."
        
        gunzip -c "${BACKUP_FOLDER}/database/coffre_fort.sql.gz" | \
            docker exec -i obsilock_mysql mysql -u root -p"${DB_PASSWORD}"
        
        log "✓ Base de données restaurée"
    else
        log_error "Fichier de backup de la base non trouvé"
        exit 1
    fi
}

################################################################################
# Restauration des fichiers uploadés
################################################################################
restore_uploads() {
    log "=== Restauration des fichiers uploadés ==="
    
    if [ -f "${BACKUP_FOLDER}/uploads/uploads.tar.gz" ]; then
        log "Sauvegarde des fichiers actuels..."
        if [ -d "${PROJECT_DIR}/storage/uploads" ]; then
            mv "${PROJECT_DIR}/storage/uploads" "${PROJECT_DIR}/storage/uploads.backup_$(date +%s)" 2>/dev/null || true
            log "✓ Fichiers actuels sauvegardés"
        fi
        
        log "Restauration des fichiers uploadés..."
        mkdir -p "${PROJECT_DIR}/storage"
        tar xzf "${BACKUP_FOLDER}/uploads/uploads.tar.gz" -C "${PROJECT_DIR}/storage/"
        
        # Permissions
        chmod -R 777 "${PROJECT_DIR}/storage/uploads" 2>/dev/null || true
        
        log "✓ Fichiers uploadés restaurés"
    else
        log_warning "Aucun fichier uploadé à restaurer"
    fi
}

################################################################################
# Restauration de la configuration
################################################################################
restore_config() {
    log "=== Restauration de la configuration ==="
    
    if [ ! -d "${BACKUP_FOLDER}/config" ]; then
        log_warning "Dossier config non trouvé dans le backup"
        return
    fi
    
    cd "${BACKUP_FOLDER}/config"
    
    # .env
    if [ -f ".env" ]; then
        log "Restauration du fichier .env..."
        cp .env "${PROJECT_DIR}/.env"
        log "✓ .env restauré"
    fi
    
    # docker-compose.yml
    if [ -f "docker-compose.yml" ]; then
        log "Restauration de docker-compose.yml..."
        cp docker-compose.yml "${PROJECT_DIR}/docker-compose.yml"
        log "✓ docker-compose.yml restauré"
    fi
    
    # Code source
    if [ -f "src.tar.gz" ]; then
        log "Restauration du code source..."
        tar xzf src.tar.gz -C "${PROJECT_DIR}/"
        log "✓ Code source restauré"
    fi
    
    # Migrations
    if [ -f "migrations.tar.gz" ]; then
        log "Restauration des migrations..."
        tar xzf migrations.tar.gz -C "${PROJECT_DIR}/"
        log "✓ Migrations restaurées"
    fi
    
    # Public
    if [ -f "public.tar.gz" ]; then
        log "Restauration du dossier public..."
        tar xzf public.tar.gz -C "${PROJECT_DIR}/"
        log "✓ Dossier public restauré"
    fi
    
    log "=== Configuration restaurée ✓ ==="
}

################################################################################
# Redémarrage des services
################################################################################
restart_services() {
    log "=== Redémarrage des services ==="
    
    cd "${PROJECT_DIR}"
    
    log "Arrêt de tous les services..."
    docker compose down
    
    log "Démarrage de tous les services..."
    docker compose up -d
    
    log "✓ Services redémarrés"
    
    # Afficher le statut
    sleep 3
    docker compose ps
}

################################################################################
# Vérification finale
################################################################################
verify_restore() {
    log "=== Vérification de la restauration ==="
    
    # Vérifier que l'API répond
    log "Test de l'API..."
    sleep 5
    
    if curl -f http://localhost:8080/ > /dev/null 2>&1; then
        log "✓ API accessible"
    else
        log_warning "L'API ne répond pas (normal si démarrage en cours)"
    fi
    
    # Afficher les logs
    log_info "Derniers logs de l'API:"
    docker logs obsilock_api --tail 10 2>/dev/null || true
}

################################################################################
# Fonction principale
################################################################################
main() {
    log "╔═══════════════════════════════════════════════════════════╗"
    log "║      RESTAURATION OBSILOCK - $(date +'%Y-%m-%d %H:%M:%S')      ║"
    log "╚═══════════════════════════════════════════════════════════╝"
    
    select_backup
    confirm_restore
    extract_backup
    stop_services
    restore_database
    restore_uploads
    restore_config
    restart_services
    verify_restore
    
    log ""
    log "╔═══════════════════════════════════════════════════════════╗"
    log "║            RESTAURATION TERMINÉE AVEC SUCCÈS              ║"
    log "╠═══════════════════════════════════════════════════════════╣"
    log "║  API: http://api.obsilock.iris.a3n.fr:8080"
    log "║  Logs: docker logs obsilock_api --tail 50"
    log "╚═══════════════════════════════════════════════════════════╝"
}

# Exécution
main