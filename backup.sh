#!/bin/bash

################################################################################
# Script de Backup Automatisé pour ObsiLock
# Sauvegarde: Base MySQL + Fichiers uploads + Configuration
################################################################################

set -euo pipefail  # Arrêt en cas d'erreur

# Configuration
BACKUP_DIR="/home/mohamed/backup/slam/obsilock"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_NAME="obsilock_backup_${TIMESTAMP}"
BACKUP_PATH="${BACKUP_DIR}/${BACKUP_NAME}"
LOG_FILE="${BACKUP_DIR}/backup_${TIMESTAMP}.log"

# Nombre de jours de rétention des backups
RETENTION_DAYS=7

# Projet ObsiLock
PROJECT_DIR="/home/iris/slam/ObsiLock"

# Créer le répertoire de backup
mkdir -p "${BACKUP_DIR}"

# Couleurs pour les logs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

################################################################################
# Fonction de logging
################################################################################
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "${LOG_FILE}"
}

log_error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" | tee -a "${LOG_FILE}"
}

log_warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1" | tee -a "${LOG_FILE}"
}

log_info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] INFO:${NC} $1" | tee -a "${LOG_FILE}"
}

################################################################################
# Fonction de nettoyage en cas d'erreur
################################################################################
cleanup_on_error() {
    log_error "Une erreur s'est produite. Nettoyage..."
    if [ -d "${BACKUP_PATH}" ]; then
        rm -rf "${BACKUP_PATH}"
        log "Répertoire de backup temporaire supprimé"
    fi
    exit 1
}

trap cleanup_on_error ERR

################################################################################
# Vérifications préliminaires
################################################################################
check_prerequisites() {
    log "=== Vérification des prérequis ==="
    
    # Vérifier que Docker est en cours d'exécution
    if ! docker info > /dev/null 2>&1; then
        log_error "Docker n'est pas en cours d'exécution"
        exit 1
    fi
    
    # Vérifier que le conteneur MySQL existe
    if ! docker ps -a --format '{{.Names}}' | grep -q "obsilock_db"; then
        log_error "Le conteneur 'obsilock_db' n'existe pas"
        exit 1
    fi
    
    # Vérifier que le projet existe
    if [ ! -d "${PROJECT_DIR}" ]; then
        log_error "Le projet ObsiLock n'existe pas dans ${PROJECT_DIR}"
        exit 1
    fi
    
    # Créer le répertoire de backup s'il n'existe pas
    mkdir -p "${BACKUP_DIR}"
    mkdir -p "${BACKUP_PATH}"
    
    log "✓ Prérequis validés"
}

################################################################################
# Backup de la base de données MySQL
################################################################################
backup_database() {
    log "=== Début du backup de la base de données MySQL ==="
    
    mkdir -p "${BACKUP_PATH}/database"
    
    # Récupérer les variables d'environnement depuis le conteneur
    DB_NAME=$(docker exec obsilock_db printenv MYSQL_DATABASE 2>/dev/null || echo "coffre_fort")
    DB_USER=$(docker exec obsilock_db printenv MYSQL_USER 2>/dev/null || echo "obsilock_user")
    DB_PASSWORD=$(docker exec obsilock_db printenv MYSQL_ROOT_PASSWORD)
    
    log_info "Base de données: ${DB_NAME}"
    
    # Backup complet avec structure + données
    log "Backup de la base de données ${DB_NAME}..."
    docker exec obsilock_db mysqldump \
        -u root \
        -p"${DB_PASSWORD}" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        "${DB_NAME}" | gzip > "${BACKUP_PATH}/database/${DB_NAME}.sql.gz"
    
    if [ -s "${BACKUP_PATH}/database/${DB_NAME}.sql.gz" ]; then
        SIZE=$(du -h "${BACKUP_PATH}/database/${DB_NAME}.sql.gz" | cut -f1)
        log "✓ Backup de ${DB_NAME} réussi (${SIZE})"
    else
        log_error "Le fichier dump est vide"
        exit 1
    fi
    
    # Backup de toutes les bases (sécurité supplémentaire)
    log "Backup global de MySQL..."
    docker exec obsilock_db mysqldump \
        -u root \
        -p"${DB_PASSWORD}" \
        --all-databases \
        --single-transaction \
        --routines \
        --triggers \
        --events | gzip > "${BACKUP_PATH}/database/all_databases.sql.gz"
    
    if [ -s "${BACKUP_PATH}/database/all_databases.sql.gz" ]; then
        SIZE=$(du -h "${BACKUP_PATH}/database/all_databases.sql.gz" | cut -f1)
        log "✓ Backup global réussi (${SIZE})"
    fi
    
    log "=== Backup de la base de données terminé ✓ ==="
}

################################################################################
# Backup des fichiers uploadés
################################################################################
backup_uploads() {
    log "=== Début du backup des fichiers uploadés ==="
    
    mkdir -p "${BACKUP_PATH}/uploads"
    
    UPLOAD_DIR="${PROJECT_DIR}/storage/uploads"
    
    if [ -d "${UPLOAD_DIR}" ]; then
        log "Backup des fichiers dans ${UPLOAD_DIR}..."
        
        # Compter les fichiers
        FILE_COUNT=$(find "${UPLOAD_DIR}" -type f | wc -l)
        log_info "Nombre de fichiers à sauvegarder: ${FILE_COUNT}"
        
        # Créer une archive tar.gz
        tar czf "${BACKUP_PATH}/uploads/uploads.tar.gz" -C "${PROJECT_DIR}/storage" uploads
        
        if [ -s "${BACKUP_PATH}/uploads/uploads.tar.gz" ]; then
            SIZE=$(du -h "${BACKUP_PATH}/uploads/uploads.tar.gz" | cut -f1)
            log "✓ Fichiers uploadés sauvegardés (${SIZE})"
        else
            log_warning "Aucun fichier uploadé trouvé"
        fi
    else
        log_warning "Dossier uploads non trouvé: ${UPLOAD_DIR}"
    fi
    
    log "=== Backup des uploads terminé ✓ ==="
}

################################################################################
# Backup des fichiers de configuration
################################################################################
backup_config() {
    log "=== Début du backup des fichiers de configuration ==="
    
    mkdir -p "${BACKUP_PATH}/config"
    
    cd "${PROJECT_DIR}"
    
    # Fichiers de configuration à sauvegarder
    declare -a CONFIG_FILES=(
        "docker-compose.yml"
        ".env"
        "composer.json"
        "composer.lock"
    )
    
    for file in "${CONFIG_FILES[@]}"; do
        if [ -f "${file}" ]; then
            cp "${file}" "${BACKUP_PATH}/config/"
            log "✓ ${file} sauvegardé"
        else
            log_warning "${file} non trouvé, ignoré"
        fi
    done
    
    # Backup du code source (src/)
    if [ -d "src" ]; then
        log "Backup du code source..."
        tar czf "${BACKUP_PATH}/config/src.tar.gz" src/
        SIZE=$(du -h "${BACKUP_PATH}/config/src.tar.gz" | cut -f1)
        log "✓ Code source sauvegardé (${SIZE})"
    fi
    
    # Backup des migrations SQL
    if [ -d "migrations" ]; then
        log "Backup des migrations SQL..."
        tar czf "${BACKUP_PATH}/config/migrations.tar.gz" migrations/
        log "✓ Migrations SQL sauvegardées"
    fi
    
    # Backup de public/
    if [ -d "public" ]; then
        log "Backup du dossier public..."
        tar czf "${BACKUP_PATH}/config/public.tar.gz" public/
        log "✓ Dossier public sauvegardé"
    fi
    
    log "=== Backup de la configuration terminé ✓ ==="
}

################################################################################
# Création de l'archive finale
################################################################################
create_archive() {
    log "=== Création de l'archive finale ==="
    
    cd "${BACKUP_DIR}"
    
    # Créer l'archive tar.gz
    tar czf "${BACKUP_NAME}.tar.gz" "${BACKUP_NAME}"
    
    if [ -s "${BACKUP_NAME}.tar.gz" ]; then
        SIZE=$(du -h "${BACKUP_NAME}.tar.gz" | cut -f1)
        log "✓ Archive finale créée: ${BACKUP_NAME}.tar.gz (${SIZE})"
    else
        log_error "Échec de la création de l'archive"
        exit 1
    fi
    
    # Supprimer le dossier temporaire
    rm -rf "${BACKUP_NAME}"
    log "✓ Dossier temporaire supprimé"
    
    log "=== Archive finale créée ✓ ==="
}

################################################################################
# Nettoyage des anciens backups
################################################################################
cleanup_old_backups() {
    log "=== Nettoyage des anciens backups (>${RETENTION_DAYS} jours) ==="
    
    cd "${BACKUP_DIR}"
    
    # Supprimer les backups de plus de RETENTION_DAYS jours
    DELETED=0
    for backup in obsilock_backup_*.tar.gz; do
        if [ -f "${backup}" ]; then
            AGE_SECONDS=$(($(date +%s) - $(stat -c %Y "${backup}")))
            AGE_DAYS=$((AGE_SECONDS / 86400))
            
            if [ ${AGE_DAYS} -gt ${RETENTION_DAYS} ]; then
                rm -f "${backup}"
                log "✓ Supprimé: ${backup} (${AGE_DAYS} jours)"
                DELETED=$((DELETED + 1))
            fi
        fi
    done
    
    # Supprimer les anciens logs
    for log_file in backup_*.log; do
        if [ -f "${log_file}" ]; then
            AGE_SECONDS=$(($(date +%s) - $(stat -c %Y "${log_file}")))
            AGE_DAYS=$((AGE_SECONDS / 86400))
            
            if [ ${AGE_DAYS} -gt ${RETENTION_DAYS} ]; then
                rm -f "${log_file}"
                DELETED=$((DELETED + 1))
            fi
        fi
    done
    
    if [ ${DELETED} -eq 0 ]; then
        log "Aucun ancien backup à supprimer"
    else
        log "✓ ${DELETED} ancien(s) fichier(s) supprimé(s)"
    fi
    
    log "=== Nettoyage terminé ✓ ==="
}

################################################################################
# Fonction principale
################################################################################
main() {
    local START_TIME=$(date +%s)
    
    log "╔═══════════════════════════════════════════════════════════╗"
    log "║         BACKUP OBSILOCK - $(date +'%Y-%m-%d %H:%M:%S')         ║"
    log "╚═══════════════════════════════════════════════════════════╝"
    
    check_prerequisites
    backup_database
    backup_uploads
    backup_config
    create_archive
    cleanup_old_backups
    
    local END_TIME=$(date +%s)
    local DURATION=$((END_TIME - START_TIME))
    
    log ""
    log "╔═══════════════════════════════════════════════════════════╗"
    log "║                  BACKUP TERMINÉ AVEC SUCCÈS               ║"
    log "╠═══════════════════════════════════════════════════════════╣"
    log "║  Archive: ${BACKUP_NAME}.tar.gz"
    log "║  Durée: ${DURATION} secondes"
    log "║  Log: backup_${TIMESTAMP}.log"
    log "╚═══════════════════════════════════════════════════════════╝"
}

# Exécution
main