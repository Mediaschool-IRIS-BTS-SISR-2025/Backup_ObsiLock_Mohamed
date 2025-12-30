<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Model\UserRepository;
use Medoo\Medoo;

class UserRepositoryTest extends TestCase
{
    private UserRepository $userRepo;
    private Medoo $db;

    protected function setUp(): void
    {
        // BDD SQLite en mémoire pour les tests
        $this->db = new Medoo([
            'type' => 'sqlite',
            'database' => ':memory:'
        ]);

        // Créer la table users
        $this->db->query("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                quota_total BIGINT DEFAULT 1073741824,
                quota_used BIGINT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->userRepo = new UserRepository($this->db);
    }

    public function testCreate(): void
    {
        $userId = $this->userRepo->create([
            'email' => 'test@obsilock.fr',
            'password' => password_hash('password123', PASSWORD_BCRYPT)
        ]);

        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);
    }

    public function testFindByEmail(): void
    {
        // Créer un utilisateur
        $this->userRepo->create([
            'email' => 'john@obsilock.fr',
            'password' => password_hash('pass', PASSWORD_BCRYPT)
        ]);

        // Rechercher
        $user = $this->userRepo->findByEmail('john@obsilock.fr');

        $this->assertIsArray($user);
        $this->assertEquals('john@obsilock.fr', $user['email']);
    }

    public function testFindByEmailNotFound(): void
    {
        $user = $this->userRepo->findByEmail('notfound@obsilock.fr');

        $this->assertNull($user);
    }

    public function testUpdateQuota(): void
    {
        // Créer un utilisateur
        $userId = $this->userRepo->create([
            'email' => 'quota@obsilock.fr',
            'password' => password_hash('pass', PASSWORD_BCRYPT)
        ]);

        // Mettre à jour le quota
        $this->userRepo->updateQuota($userId, 500000000);

        // Vérifier
        $user = $this->userRepo->find($userId);
        $this->assertEquals(500000000, $user['quota_used']);
    }

    public function testQuotaExceeded(): void
    {
        // Créer un utilisateur avec quota par défaut
        $userId = $this->userRepo->create([
            'email' => 'limit@obsilock.fr',
            'password' => password_hash('pass', PASSWORD_BCRYPT)
        ]);

        $user = $this->userRepo->find($userId);

        // Simuler dépassement de quota
        $newSize = 100000000; // 100 MB
        $exceeded = ($user['quota_used'] + $newSize) > $user['quota_total'];

        $this->assertFalse($exceeded); // Ne devrait pas dépasser avec 100 MB

        // Simuler vraiment gros fichier
        $hugeSize = 2000000000; // 2 GB
        $exceeded = ($user['quota_used'] + $hugeSize) > $user['quota_total'];

        $this->assertTrue($exceeded); // Devrait dépasser
    }
}