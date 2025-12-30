# Guide de Dépannage - ObsiLock API

## 🔴 Problèmes fréquents et solutions

### 1. Port 8080 déjà utilisé

**Erreur :**
```
Error response from daemon: Bind for 0.0.0.0:8080 failed: port is already allocated
```

**Diagnostic :**
```bash
# Trouver le processus qui utilise le port
sudo lsof -i :8080
# OU
sudo netstat -tulpn | grep 8080
```

**Solutions :**

**A. Tuer le processus :**
```bash
sudo kill -9 PID_DU_PROCESSUS
docker compose up -d
```

**B. Arrêter tous les conteneurs Docker :**
```bash
docker stop $(docker ps -aq)
docker compose up -d
```

**C. Changer le port dans docker-compose.yml :**
```bash
nano docker-compose.yml

# Modifier la ligne (section api -> ports) :
ports:
  - "8081:80"  # Au lieu de "8080:80"

docker compose up -d
```

Nouvelle URL : http://localhost:8081

---

### 2. Conteneur ne démarre pas

**Symptôme :**
```
Error: Cannot start service api...
```

**Diagnostic :**
```bash
# Voir les logs d'erreur
docker compose logs api
docker compose logs db

# Vérifier l'état
docker ps -a | grep obsilock
```

**Solutions :**

**A. Redémarrage forcé :**
```bash
docker compose down
docker compose up -d --force-recreate
```

**B. Reconstruire les images :**
```bash
docker compose down
docker compose build --no-cache
docker compose up -d
```

**C. Vérifier le fichier docker-compose.yml :**
```bash
# Valider la syntaxe YAML
docker compose config
```

---

### 3. MySQL : "Connection refused"

**Symptôme :**
```
SQLSTATE[HY000] [2002] Connection refused
```

**Cause :** MySQL n'est pas encore prêt (démarrage lent)

**Solution :**
```bash
# Attendre que MySQL soit prêt
sleep 30

# Vérifier l'état MySQL
docker exec obsilock_db mysqladmin ping -u root -p$(docker exec obsilock_db printenv MYSQL_ROOT_PASSWORD)

# Résultat attendu : "mysqld is alive"
```

**Si le problème persiste :**
```bash
# Redémarrer MySQL
docker compose restart db

# Vérifier les logs
docker compose logs db | grep -i error
```

---

### 4. Upload de fichier échoue (400/500)

**Erreur 400 - "Aucun fichier" :**

**Cause :** Mauvaise configuration Postman

**Solution :**
- Body → form-data
- Key : `file` (type **File**, pas Text)
- Sélectionner un fichier

**Erreur 413 - "Quota dépassé" :**

**Solution :**
```sql
-- Via phpMyAdmin ou MySQL CLI
UPDATE users SET quota_total = 2147483648 WHERE email = 'user@example.com';
```

**Erreur 500 - "Erreur: SQLSTATE..." :**

**Vérifications :**
```bash
# 1. Permissions dossier uploads
ls -la storage/uploads/
chmod -R 777 storage/uploads/

# 2. Variable ENCRYPTION_KEY présente
docker exec obsilock_api printenv | grep ENCRYPTION_KEY

# 3. Logs API
docker compose logs api | tail -50
```

**Erreur "folder_id" NULL :**

**Solution :**
```bash
# Dans Postman, ajouter dans Body (form-data) :
# Key: folder_id | Value: 1 (ou null si pas de dossier)
```

---

### 5. Token JWT invalide (401)

**Erreur :**
```json
{
  "error": "Non autorisé"
}
```

**Causes possibles :**

1. **Token expiré** (durée de vie : 1h)
   ```bash
   # Se reconnecter pour obtenir un nouveau token
   curl -X POST http://localhost:8080/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email":"user@example.com","password":"password"}'
   ```

2. **Token mal formaté** (manque "Bearer ")
   ```
   # Mauvais :
   Authorization: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...

   # Bon :
   Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
   ```

3. **JWT_SECRET changé** depuis la génération du token
   ```bash
   # Vérifier le secret actuel
   docker exec obsilock_api printenv | grep JWT_SECRET
   
   # Si changé, se reconnecter
   ```

---

### 6. Chiffrement échoue

**Erreur :**
```
RuntimeException: ENCRYPTION_KEY non définie dans .env
```

**Solution :**
```bash
# Vérifier que la clé existe
cat .env | grep ENCRYPTION_KEY

# Si absente, en générer une
docker run --rm php:8.2-cli php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"

# Ajouter dans .env
nano .env
# ENCRYPTION_KEY=LA_CLE_GENEREE

# Redémarrer l'API
docker compose restart api
```

**Erreur :**
```
Impossible de déchiffrer la clé
```

**Cause :** Fichier chiffré avec une autre clé

**Solution :** Impossible de déchiffrer. Utiliser le bon `ENCRYPTION_KEY` ou supprimer le fichier.

---

### 7. Rate Limiting (429 Too Many Requests)

**Erreur :**
```
HTTP 429 - Rate limit exceeded. Try again in 3456 seconds.
```

**Cause :** Dépassement de 100 requêtes/heure

**Solutions :**

**A. Attendre la fin de la fenêtre :**
```bash
# La fenêtre se réinitialise toutes les heures
```

**B. Modifier la limite (développement) :**
```bash
nano public/index.php

# Ligne ~57, modifier :
$app->add(new \App\Middleware\RateLimitMiddleware(1000, 3600)); # 1000 req/h au lieu de 100

docker compose restart api
```

**C. Nettoyer les fichiers de rate limit :**
```bash
docker exec obsilock_api rm -rf /tmp/obsilock_rate_limit/*
```

---

### 8. Backup/Restore échoue

**Erreur :**
```
./backup.sh: Permission denied
```

**Solution :**
```bash
chmod +x backup.sh restore.sh
./backup.sh
```

**Erreur :**
```
Le conteneur 'obsilock_mysql' n'existe pas
```

**Solution :**
```bash
# Vérifier le nom réel du conteneur
docker ps | grep mysql

# Modifier le script si nécessaire
nano backup.sh
# Remplacer 'obsilock_mysql' par 'obsilock_db'
```

**Erreur :**
```
Permission denied: /home/iris/backup/
```

**Solution :**
```bash
# Changer le chemin dans backup.sh
nano backup.sh
# Ligne 11 : BACKUP_DIR="/home/mohamed/backup/slam/obsilock"
```

---

### 9. Tests PHPUnit échouent

**Erreur :**
```
/usr/bin/env: 'php': No such file or directory
```

**Solution :**
```bash
# Lancer les tests depuis le conteneur
docker exec obsilock_api vendor/bin/phpunit
```

**Erreur :**
```
This version of PHPUnit requires PHP >= 8.3
```

**Solution :**
```bash
# Installer PHPUnit 10 (compatible PHP 8.2)
docker run --rm -v $(pwd):/app composer remove --dev phpunit/phpunit
docker run --rm -v $(pwd):/app composer require --dev phpunit/phpunit:^10.0
```

---

### 10. phpMyAdmin inaccessible

**Symptôme :** http://localhost:8081 ne répond pas

**Diagnostic :**
```bash
# Vérifier que le conteneur tourne
docker ps | grep phpmyadmin

# Voir les logs
docker compose logs phpmyadmin
```

**Solutions :**

**A. Redémarrer phpMyAdmin :**
```bash
docker compose restart phpmyadmin
```

**B. Port déjà utilisé :**
```bash
# Changer le port dans docker-compose.yml
nano docker-compose.yml
# Section phpmyadmin -> ports : "8082:80"

docker compose up -d
```

Nouvelle URL : http://localhost:8082

---

### 11. Espace disque saturé

**Symptôme :**
```
No space left on device
```

**Diagnostic :**
```bash
# Espace disque global
df -h

# Taille des uploads
du -sh storage/uploads/

# Taille des backups
du -sh /home/mohamed/backup/

# Taille des logs Docker
du -sh /var/lib/docker/containers/
```

**Solutions :**

**A. Nettoyer les anciens backups :**
```bash
# Supprimer backups de +30 jours
find /home/mohamed/backup/slam/obsilock/ -name "*.tar.gz" -mtime +30 -delete
```

**B. Nettoyer Docker :**
```bash
docker system prune -a --volumes
```

**C. Optimiser la BDD :**
```bash
# Supprimer anciens logs
docker exec -i obsilock_db mysql -u root -p"$(docker exec obsilock_db printenv MYSQL_ROOT_PASSWORD)" coffre_fort -e "
DELETE FROM downloads_log WHERE downloaded_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);
DELETE FROM upload_logs WHERE uploaded_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
"
```

---

### 12. Composer : "command not found"

**Erreur :**
```bash
composer: command not found
```

**Solution :**
```bash
# Utiliser Composer via Docker
docker run --rm -v $(pwd):/app composer install
docker run --rm -v $(pwd):/app composer require package/name
```

---

### 13. Partage public ne fonctionne pas

**Erreur 404 :** Token invalide

**Vérifications :**
```bash
# 1. Vérifier que le token existe en BDD
docker exec -i obsilock_db mysql -u obsilock_user -p coffre_fort -e "
SELECT * FROM shares WHERE token = 'VOTRE_TOKEN';
"

# 2. Vérifier que le partage n'est pas révoqué
# is_revoked doit être 0

# 3. Vérifier l'expiration
# expires_at doit être NULL ou dans le futur
```

**Erreur 410 :** Partage expiré/révoqué/épuisé

**Solution :** Créer un nouveau partage

---

### 14. CORS bloque les requêtes

**Erreur dans le navigateur :**
```
Access to XMLHttpRequest has been blocked by CORS policy
```

**Solution :**
```bash
nano public/index.php

# Vérifier que le middleware CORS est présent (ligne ~60) :
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

docker compose restart api
```

---

## 🛠️ Outils de diagnostic

### Script de vérification complet

```bash
#!/bin/bash
echo "=== DIAGNOSTIC OBSILOCK ==="
echo ""

echo "1. Docker containers:"
docker ps | grep obsilock

echo ""
echo "2. API accessible?"
curl -s http://localhost:8080/ | head -1

echo ""
echo "3. MySQL accessible?"
docker exec obsilock_db mysqladmin ping -u root -p$(docker exec obsilock_db printenv MYSQL_ROOT_PASSWORD) 2>/dev/null && echo "OK" || echo "FAILED"

echo ""
echo "4. Espace disque:"
df -h | grep -E "Filesystem|/$"

echo ""
echo "5. Variables d'environnement critiques:"
docker exec obsilock_api printenv | grep -E "DB_|JWT_|ENCRYPTION_"

echo ""
echo "6. Permissions uploads:"
ls -ld storage/uploads/

echo ""
echo "7. Dernières erreurs API:"
docker compose logs --tail 5 api | grep -i error

echo ""
echo "=== FIN DIAGNOSTIC ==="
```

**Sauvegarder en `diagnostic.sh` et lancer :**
```bash
chmod +x diagnostic.sh
./diagnostic.sh
```

---

## 📞 Obtenir de l'aide

### Informations à fournir en cas de problème

1. **Version du projet :**
   ```bash
   git log -1 --oneline
   ```

2. **Logs complets :**
   ```bash
   docker compose logs > logs_complets.txt
   ```

3. **Configuration (sans secrets) :**
   ```bash
   docker compose config
   ```

4. **État des conteneurs :**
   ```bash
   docker ps -a
   docker stats --no-stream
   ```

### Ressources

- **Documentation** : `docs/`
- **Tests** : `vendor/bin/phpunit`
- **API Swagger** : `openapi.yaml`
- **GitHub Issues** : https://github.com/Momjax/ObsiLock/issues

---

## ⚠️ Procédure d'urgence (dernière solution)

**Si rien ne fonctionne, reset complet :**

```bash
# 1. SAUVEGARDER D'ABORD
./backup.sh

# 2. Tout supprimer
docker compose down -v
rm -rf storage/uploads/*
rm -rf vendor/

# 3. Réinstaller
docker compose up -d

# 4. Réinstaller dépendances
docker run --rm -v $(pwd):/app composer install

# 5. Migrations
DB_ROOT_PASS=$(docker exec obsilock_db printenv MYSQL_ROOT_PASSWORD)
for file in migrations/*.sql; do
    docker exec -i obsilock_db mysql -u root -p"${DB_ROOT_PASS}" coffre_fort < "$file"
done

# 6. Restaurer les données (optionnel)
./restore.sh
```
