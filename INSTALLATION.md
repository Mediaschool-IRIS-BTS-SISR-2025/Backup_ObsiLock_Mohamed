# Guide d'Installation - ObsiLock API

## 📋 Prérequis

- **Docker** 20.10+ et **Docker Compose** 2.0+
- **Git** 2.30+

Vérification :
```bash
docker --version
docker compose version
git --version
```

---

## 🚀 Installation rapide (5 minutes)

### 1. Cloner le projet
```bash
git clone https://github.com/Momjax/ObsiLock.git
cd ObsiLock
```

### 2. Configurer l'environnement

Copier le fichier d'exemple :
```bash
cp .env.example .env
nano .env
```

**Modifier ces valeurs OBLIGATOIRES :**

```ini
# Base de données
DB_HOST=mysql
DB_NAME=coffre_fort
DB_USER=obsilock_user
DB_PASS=CHANGEZ_MOI_PRODUCTION_SECURE_123!

# Sécurité JWT (générer avec: openssl rand -base64 32)
JWT_SECRET=CHANGEZ_MOI_32_CARACTERES_MINIMUM_POUR_JWT

# Chiffrement des fichiers (générer avec: php -r "echo base64_encode(random_bytes(32));")
ENCRYPTION_KEY=CHANGEZ_MOI_BASE64_32_BYTES

# Signature HMAC des tokens de partage
HMAC_SECRET=CHANGEZ_MOI_HMAC_SECRET_32_CARACTERES

# Configuration uploads
UPLOAD_DIR=/var/www/html/storage/uploads
MAX_FILE_SIZE=104857600  # 100 MB

# Quota par défaut par utilisateur (en octets)
DEFAULT_QUOTA=1073741824  # 1 GB

# URL de l'application (pour les liens de partage)
APP_URL=http://api.obsilock.iris.a3n.fr:8080
```

### 3. Générer les secrets de sécurité

**JWT Secret (32+ caractères) :**
```bash
openssl rand -base64 32
```

**Clé de chiffrement (32 octets en base64) :**
```bash
# Avec PHP local
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"

# Ou avec Docker
docker run --rm php:8.2-cli php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
```

Copier ces valeurs dans `.env`

⚠️ **IMPORTANT : Ne JAMAIS committer `.env` sur Git !**

### 4. Lancer les services Docker

```bash
# Construire et démarrer tous les services
docker compose up -d

# Vérifier que les conteneurs tournent
docker ps
```

**Vous devriez voir 3 conteneurs :**
- `obsilock_api` → API REST (port 8080)
- `obsilock_db` → MySQL 8.0
- `obsilock_phpmyadmin` → Interface BDD (port 8081)

### 5. Exécuter les migrations SQL

```bash
# Récupérer le mot de passe root MySQL automatiquement
DB_ROOT_PASS=$(docker exec obsilock_db printenv MYSQL_ROOT_PASSWORD)

# Exécuter toutes les migrations dans l'ordre
docker exec -i obsilock_db mysql -u root -p"${DB_ROOT_PASS}" coffre_fort < migrations/001_create_users.sql
docker exec -i obsilock_db mysql -u root -p"${DB_ROOT_PASS}" coffre_fort < migrations/002_create_folders.sql
docker exec -i obsilock_db mysql -u root -p"${DB_ROOT_PASS}" coffre_fort < migrations/003_create_files.sql
docker exec -i obsilock_db mysql -u root -p"${DB_ROOT_PASS}" coffre_fort < migrations/004_create_shares.sql
docker exec -i obsilock_db mysql -u root -p"${DB_ROOT_PASS}" coffre_fort < migrations/005_upload_logs.sql
```

**Alternative (boucle automatique) :**
```bash
for file in migrations/*.sql; do
    docker exec -i obsilock_db mysql -u root -p"${DB_ROOT_PASS}" coffre_fort < "$file"
    echo "✓ Migration $file exécutée"
done
```

### 6. Vérifier l'installation

**Test API :**
```bash
curl http://localhost:8080/
```

**Résultat attendu (JSON) :**
```json
{
  "message": "File Vault API - Jours 1-5",
  "version": "1.0.0",
  "security": {
    "jwt_auth": true,
    "rate_limiting": "100 req/hour",
    "headers": "enabled"
  },
  "endpoints": [...]
}
```

**Test phpMyAdmin :**
- URL : http://localhost:8081
- Utilisateur : `obsilock_user`
- Mot de passe : (celui dans `.env`)

---

## 🗂️ Structure des dossiers

```
ObsiLock/
├── public/              # Point d'entrée web
│   └── index.php
├── src/                 # Code source
│   ├── Controller/      # Contrôleurs (AuthController, FileController...)
│   ├── Model/           # Repositories (accès BDD)
│   ├── Service/         # Services (EncryptionService)
│   └── Middleware/      # Middlewares (SecurityHeaders, RateLimit)
├── storage/
│   └── uploads/         # Fichiers chiffrés (créé automatiquement)
├── migrations/          # Scripts SQL de création BDD
├── tests/               # Tests PHPUnit
│   ├── Unit/            # Tests unitaires
│   └── Integration/     # Tests d'intégration
├── docs/                # Documentation
├── vendor/              # Dépendances Composer (auto-généré)
├── .env                 # Configuration (NE PAS COMMITTER)
├── .env.example         # Exemple de configuration
├── docker-compose.yml   # Configuration Docker
├── composer.json        # Dépendances PHP
├── phpunit.xml          # Configuration tests
├── openapi.yaml         # Documentation API (Swagger)
├── backup.sh            # Script de sauvegarde
└── restore.sh           # Script de restauration
```

---

## 🔐 Permissions des fichiers

```bash
# Dossier uploads (lecture/écriture pour www-data dans Docker)
chmod -R 775 storage/uploads

# Fichier .env (lecture seule pour sécurité)
chmod 600 .env

# Scripts backup/restore (exécutables)
chmod +x backup.sh restore.sh
```

---

## 🌐 Configuration Nginx (Production)

Si vous utilisez Nginx en reverse proxy :

```nginx
server {
    listen 80;
    server_name api.obsilock.example.com;

    # Redirection HTTPS (recommandé en production)
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.obsilock.example.com;

    # Certificats SSL (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/api.obsilock.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.obsilock.example.com/privkey.pem;

    # Sécurité SSL
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Proxy vers Docker
    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Timeouts pour gros uploads
        proxy_read_timeout 300;
        proxy_connect_timeout 300;
        proxy_send_timeout 300;
    }

    # Limite taille upload (100 MB)
    client_max_body_size 100M;
}
```

**Activer la configuration :**
```bash
sudo ln -s /etc/nginx/sites-available/obsilock /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 🔧 Configuration Apache (Alternative)

```apache
<VirtualHost *:80>
    ServerName api.obsilock.example.com
    
    ProxyPreserveHost On
    ProxyPass / http://localhost:8080/
    ProxyPassReverse / http://localhost:8080/
    
    # Logs
    ErrorLog ${APACHE_LOG_DIR}/obsilock-error.log
    CustomLog ${APACHE_LOG_DIR}/obsilock-access.log combined
</VirtualHost>
```

**Activer les modules :**
```bash
sudo a2enmod proxy proxy_http
sudo systemctl restart apache2
```

---

## 🧪 Installation des dépendances de développement

### PHPUnit (tests)
```bash
# Via Docker Composer
docker run --rm -v $(pwd):/app composer require --dev phpunit/phpunit:^10.0

# Lancer les tests
docker exec obsilock_api vendor/bin/phpunit
```

### PHP-CS-Fixer (linting)
```bash
docker run --rm -v $(pwd):/app composer require --dev friendsofphp/php-cs-fixer

# Vérifier le code
docker exec obsilock_api vendor/bin/php-cs-fixer fix --dry-run
```

---

## ✅ Installation terminée !

**Accès :**
- **API REST** : http://localhost:8080
- **phpMyAdmin** : http://localhost:8081
- **Documentation API** : https://editor.swagger.io/ (importer `openapi.yaml`)

**Prochaines étapes :**
1. Consulter `EXPLOITATION.md` pour la gestion quotidienne
2. Consulter `TROUBLESHOOTING.md` en cas de problème
3. Tester l'API avec Postman (collection disponible dans le repo)

**Endpoints principaux :**
- `POST /auth/register` - Créer un compte
- `POST /auth/login` - Se connecter (obtenir JWT)
- `POST /files` - Upload un fichier chiffré
- `GET /files/{id}/download` - Télécharger un fichier
- `POST /shares` - Créer un lien de partage public

**Support :**
- Documentation complète : `docs/`
- Issues GitHub : https://github.com/Momjax/ObsiLock/issues
