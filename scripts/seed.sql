-- ============================================
-- Script de jeu d'essai ObsiLock - VERSION SIMPLIFIÉE
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE file_versions;
TRUNCATE TABLE shares;
TRUNCATE TABLE files;
TRUNCATE TABLE folders;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- UTILISATEURS
-- ============================================
-- Password pour tous: Alice123!
-- Hash bcrypt: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi

INSERT INTO users (id, email, password, quota_total, quota_used) VALUES
(1, 'alice@obsilock.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1073741824, 524288),
(2, 'bob@obsilock.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 10485760, 2097152),
(3, 'charlie@obsilock.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 5368709120, 1048576);

-- ============================================
-- DOSSIERS
-- ============================================

INSERT INTO folders (id, user_id, parent_id, name) VALUES
(1, 1, NULL, 'Documents'),
(2, 1, NULL, 'Photos'),
(3, 1, 1, 'Travail'),
(4, 2, NULL, 'Projets'),
(5, 3, NULL, 'Admin');

-- ============================================
-- FICHIERS (métadonnées fictives)
-- ============================================

INSERT INTO files (id, user_id, folder_id, filename, stored_name, size, mime_type, checksum, current_version) VALUES
(1, 1, 1, 'rapport_mensuel.pdf', 'demo_file_1.enc', 245760, 'application/pdf', 'abc123def456', 1),
(2, 1, 2, 'vacances_2024.jpg', 'demo_file_2.enc', 524288, 'image/jpeg', 'def456ghi789', 1),
(3, 1, 3, 'presentation.pptx', 'demo_file_3.enc', 1048576, 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'ghi789jkl012', 2),
(4, 2, 4, 'projet_web.zip', 'demo_file_4.enc', 2097152, 'application/zip', 'jkl012mno345', 1),
(5, 3, 5, 'logs_systeme.txt', 'demo_file_5.enc', 8192, 'text/plain', 'mno345pqr678', 1);

-- ============================================
-- VERSIONS DE FICHIERS
-- ============================================

INSERT INTO file_versions (file_id, version, stored_name, size, checksum, mime_type, nonce, key_envelope, key_nonce) VALUES
(1, 1, 'demo_file_1.enc', 245760, 'abc123def456', 'application/pdf', 'nonce_demo_1', 'key_envelope_demo_1', 'key_nonce_demo_1'),
(2, 1, 'demo_file_2.enc', 524288, 'def456ghi789', 'image/jpeg', 'nonce_demo_2', 'key_envelope_demo_2', 'key_nonce_demo_2'),
(3, 1, 'demo_file_3_v1.enc', 786432, 'ghi789jkl012_v1', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'nonce_demo_3_v1', 'key_envelope_demo_3_v1', 'key_nonce_demo_3_v1'),
(3, 2, 'demo_file_3.enc', 1048576, 'ghi789jkl012', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'nonce_demo_3_v2', 'key_envelope_demo_3_v2', 'key_nonce_demo_3_v2'),
(4, 1, 'demo_file_4.enc', 2097152, 'jkl012mno345', 'application/zip', 'nonce_demo_4', 'key_envelope_demo_4', 'key_nonce_demo_4'),
(5, 1, 'demo_file_5.enc', 8192, 'mno345pqr678', 'text/plain', 'nonce_demo_5', 'key_envelope_demo_5', 'key_nonce_demo_5');

-- ============================================
-- PARTAGES
-- ============================================

-- Partage actif avec expiration
INSERT INTO shares (user_id, kind, target_id, label, token, token_signature, expires_at, max_uses, remaining_uses, is_revoked) VALUES
(1, 'file', 1, 'Rapport pour équipe', 'demo_token_abc123xyz', 'demo_signature_1', DATE_ADD(NOW(), INTERVAL 7 DAY), 10, 8, 0);

-- Partage actif sans limite
INSERT INTO shares (user_id, kind, target_id, label, token, token_signature, expires_at, max_uses, remaining_uses, is_revoked) VALUES
(1, 'folder', 2, 'Album photos vacances', 'demo_token_def456uvw', 'demo_signature_2', NULL, NULL, NULL, 0);

-- Partage expiré
INSERT INTO shares (user_id, kind, target_id, label, token, token_signature, expires_at, max_uses, remaining_uses, is_revoked) VALUES
(1, 'file', 3, 'Présentation ancienne', 'demo_token_ghi789rst', 'demo_signature_3', DATE_SUB(NOW(), INTERVAL 2 DAY), NULL, NULL, 0);

-- Partage avec usages limités
INSERT INTO shares (user_id, kind, target_id, label, token, token_signature, expires_at, max_uses, remaining_uses, is_revoked) VALUES
(2, 'file', 4, 'Code source projet', 'demo_token_jkl012opq', 'demo_signature_4', DATE_ADD(NOW(), INTERVAL 30 DAY), 5, 3, 0);

-- Partage révoqué
INSERT INTO shares (user_id, kind, target_id, label, token, token_signature, expires_at, max_uses, remaining_uses, is_revoked) VALUES
(2, 'folder', 4, 'Ancien partage', 'demo_token_mno345lmn', 'demo_signature_5', NULL, NULL, NULL, 1);

-- ============================================
-- STATISTIQUES
-- ============================================

SELECT 
    '✓ SEED TERMINÉ' as status,
    (SELECT COUNT(*) FROM users) as users,
    (SELECT COUNT(*) FROM folders) as folders,
    (SELECT COUNT(*) FROM files) as files,
    (SELECT COUNT(*) FROM file_versions) as versions,
    (SELECT COUNT(*) FROM shares) as shares;

SELECT '
╔════════════════════════════════════════════╗
║     COMPTES DE DÉMONSTRATION CRÉÉS         ║
╚════════════════════════════════════════════╝

👤 User 1: Alice
   Email: alice@obsilock.fr
   Password: Alice123!
   Quota: 1 GB (512 KB utilisés)
   Fichiers: 3 (dont 1 avec 2 versions)
   Partages: 3

👤 User 2: Bob
   Email: bob@obsilock.fr
   Password: Alice123!
   Quota: 10 MB (2 MB utilisés)
   Fichiers: 1
   Partages: 2

👤 User 3: Charlie
   Email: charlie@obsilock.fr
   Password: Alice123!
   Quota: 5 GB (1 MB utilisé)
   Fichiers: 1
   Partages: 0

╔════════════════════════════════════════════╗
║           TESTS RECOMMANDÉS                ║
╚════════════════════════════════════════════╝

1. POST /auth/login avec alice@obsilock.fr / Alice123!
2. GET /files (voir les 3 fichiers)
3. GET /files/3/versions (voir les 2 versions)
4. GET /shares (voir les 3 partages)
5. GET /s/demo_token_abc123xyz (partage public actif)
6. GET /me/quota (vérifier quota)

' as INFO;