<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Model\Share;
use Medoo\Medoo;

class ShareTest extends TestCase
{
    private Share $shareModel;
    private Medoo $db;

    protected function setUp(): void
    {
        // Définir le secret HMAC pour les tests
        putenv('HMAC_SECRET=test_hmac_secret_for_phpunit_32chars');
        
        // BDD SQLite en mémoire
        $this->db = new Medoo([
            'type' => 'sqlite',
            'database' => ':memory:'
        ]);

        // Créer les tables
        $this->db->query("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL
            )
        ");

        $this->db->query("
            CREATE TABLE shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                kind VARCHAR(10) NOT NULL,
                target_id INTEGER NOT NULL,
                token VARCHAR(64) UNIQUE NOT NULL,
                token_signature VARCHAR(64) NOT NULL,
                label VARCHAR(255),
                expires_at DATETIME,
                max_uses INTEGER,
                remaining_uses INTEGER,
                is_revoked BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        // Créer un utilisateur test
        $this->db->insert('users', [
            'email' => 'test@obsilock.fr',
            'password' => 'hash'
        ]);

        $this->shareModel = new Share($this->db);
    }

    public function testCreateShare(): void
    {
        $share = $this->shareModel->create(1, 'file', 123, 'Test Share', null, 10);

        $this->assertIsArray($share);
        $this->assertArrayHasKey('id', $share);
        $this->assertArrayHasKey('token', $share);
        
        // Token base64 URL-safe (~43 chars)
        $this->assertGreaterThanOrEqual(40, strlen($share['token']));
        $this->assertLessThanOrEqual(45, strlen($share['token']));
        
        $this->assertEquals(10, $share['max_uses']);
        $this->assertEquals(10, $share['remaining_uses']);
    }

    public function testTokenIsUnique(): void
    {
        $share1 = $this->shareModel->create(1, 'file', 1, 'Share 1');
        $share2 = $this->shareModel->create(1, 'file', 2, 'Share 2');

        $this->assertNotEquals($share1['token'], $share2['token']);
    }

    public function testHmacSignature(): void
    {
        $share = $this->shareModel->create(1, 'file', 123, 'Test', null, null);

        // Vérifier que la signature existe
        $this->assertArrayHasKey('token_signature', $share);
        $this->assertNotEmpty($share['token_signature']);
        
        // Le token est en base64 URL-safe (~43 chars)
        $this->assertGreaterThanOrEqual(40, strlen($share['token']));
        $this->assertLessThanOrEqual(45, strlen($share['token']));
    }

    public function testGetByToken(): void
    {
        $share = $this->shareModel->create(1, 'file', 456, 'My Share');

        // Récupérer par token
        $found = $this->shareModel->getByToken($share['token']);

        $this->assertIsArray($found);
        $this->assertEquals($share['id'], $found['id']);
        $this->assertEquals('My Share', $found['label']);
    }

    public function testIsValidExpired(): void
    {
        // Créer un partage expiré
        $share = $this->shareModel->create(
            1, 
            'file', 
            789, 
            'Expired', 
            date('Y-m-d H:i:s', strtotime('-1 day')) // Hier
        );

        $validation = $this->shareModel->isValid($share);

        $this->assertFalse($validation['valid']);
        $this->assertEquals('expired', $validation['reason']);
    }

    public function testIsValidRevoked(): void
    {
        $share = $this->shareModel->create(1, 'file', 999, 'Test');

        // Révoquer
        $this->shareModel->revoke($share['id'], 1);

        // Récupérer à nouveau
        $revokedShare = $this->shareModel->getByToken($share['token']);

        $validation = $this->shareModel->isValid($revokedShare);

        $this->assertFalse($validation['valid']);
        $this->assertEquals('revoked', $validation['reason']);
    }

    public function testIsValidNoUsesLeft(): void
    {
        $share = $this->shareModel->create(1, 'file', 111, 'Limited', null, 2);

        // Décrémenter 2 fois
        $this->shareModel->decrementUses($share['id']);
        $this->shareModel->decrementUses($share['id']);

        // Récupérer
        $usedShare = $this->shareModel->getByToken($share['token']);

        $validation = $this->shareModel->isValid($usedShare);

        $this->assertFalse($validation['valid']);
        $this->assertEquals('no_uses_left', $validation['reason']);
    }

    public function testDecrementUses(): void
    {
        $share = $this->shareModel->create(1, 'file', 222, 'Test', null, 5);

        // Décrémenter
        $success = $this->shareModel->decrementUses($share['id']);

        $this->assertTrue($success);

        // Vérifier remaining_uses
        $updated = $this->shareModel->getByToken($share['token']);
        $this->assertEquals(4, $updated['remaining_uses']);
    }

    public function testDecrementUsesAtomic(): void
    {
        $share = $this->shareModel->create(1, 'file', 333, 'Test', null, 1);

        // Premier décrémente : OK
        $success1 = $this->shareModel->decrementUses($share['id']);
        $this->assertTrue($success1);

        // Deuxième décrémente : Devrait échouer (remaining = 0)
        $success2 = $this->shareModel->decrementUses($share['id']);
        $this->assertFalse($success2);
    }
}