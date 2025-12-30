<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Medoo\Medoo;
use App\Controller\FileController;
use App\Model\FileRepository;
use App\Model\UserRepository;

class FileUploadIntegrationTest extends TestCase
{
    private $app;
    private Medoo $db;
    private string $uploadDir;
    private string $testFilePath;

    protected function setUp(): void
    {
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
                password VARCHAR(255) NOT NULL,
                quota_total BIGINT DEFAULT 1073741824,
                quota_used BIGINT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->query("
            CREATE TABLE files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                folder_id INTEGER,
                filename VARCHAR(255) NOT NULL,
                stored_name VARCHAR(255) UNIQUE NOT NULL,
                size BIGINT NOT NULL,
                mime_type VARCHAR(100),
                checksum VARCHAR(64),
                current_version INTEGER DEFAULT 1,
                uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $this->db->query("
            CREATE TABLE file_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL,
                version INTEGER NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                size BIGINT NOT NULL,
                checksum VARCHAR(64),
                mime_type VARCHAR(100),
                nonce VARCHAR(64),
                key_envelope TEXT,
                key_nonce VARCHAR(64),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (file_id) REFERENCES files(id),
                UNIQUE(file_id, version)
            )
        ");

        $this->db->query("
            CREATE TABLE upload_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                file_id INTEGER,
                filename VARCHAR(255) NOT NULL,
                size BIGINT NOT NULL,
                mime_type VARCHAR(100),
                checksum VARCHAR(64),
                ip_address VARCHAR(45),
                user_agent TEXT,
                success BOOLEAN DEFAULT 1,
                error_message TEXT,
                uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (file_id) REFERENCES files(id)
            )
        ");

        // Créer un utilisateur test
        $this->db->insert('users', [
            'email' => 'test@obsilock.fr',
            'password' => password_hash('password', PASSWORD_BCRYPT),
            'quota_total' => 10485760, // 10 MB
            'quota_used' => 0
        ]);

        // Dossier upload temporaire
        $this->uploadDir = sys_get_temp_dir() . '/obsilock_test_' . uniqid();
        mkdir($this->uploadDir, 0777, true);

        // Définir la clé de chiffrement
        putenv('ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));

        // Créer l'application Slim
        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->addRoutingMiddleware();
        $this->app->addErrorMiddleware(false, true, true);

        // Repositories et Controller
        $fileRepo = new FileRepository($this->db);
        $userRepo = new UserRepository($this->db);
        $fileController = new FileController($fileRepo, $userRepo, $this->uploadDir, $this->db);

        // Mock du middleware auth
        $authMiddleware = function ($request, $handler) {
            $request = $request->withAttribute('user', [
                'user_id' => 1,
                'email' => 'test@obsilock.fr'
            ]);
            return $handler->handle($request);
        };

        // Routes
        $this->app->post('/files', [$fileController, 'upload'])->add($authMiddleware);

        // Créer un fichier test
        $this->testFilePath = sys_get_temp_dir() . '/test_upload_' . uniqid() . '.txt';
        file_put_contents($this->testFilePath, 'Hello ObsiLock Test!');
    }

    protected function tearDown(): void
    {
        // Nettoyer
        if (file_exists($this->testFilePath)) {
            unlink($this->testFilePath);
        }

        if (is_dir($this->uploadDir)) {
            $this->deleteDirectory($this->uploadDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testUploadFileSuccess(): void
    {
        // Créer l'UploadedFile
        $uploadedFile = new UploadedFile(
            $this->testFilePath,
            'test.txt',
            'text/plain',
            filesize($this->testFilePath),
            UPLOAD_ERR_OK
        );

        // Créer la requête multipart
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/files')
            ->withUploadedFiles(['file' => $uploadedFile]);

        // Exécuter
        $response = $this->app->handle($request);

        // Assertions
        $this->assertEquals(201, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('encrypted', $data);
        $this->assertTrue($data['encrypted']);
    }

    public function testUploadQuotaExceeded(): void
    {
        // Mettre le quota à 100 octets seulement
        $this->db->update('users', ['quota_total' => 10], ['id' => 1]);

        $uploadedFile = new UploadedFile(
            $this->testFilePath,
            'test.txt',
            'text/plain',
            filesize($this->testFilePath),
            UPLOAD_ERR_OK
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/files')
            ->withUploadedFiles(['file' => $uploadedFile]);

        $response = $this->app->handle($request);

        // Doit retourner 413 Payload Too Large
        $this->assertEquals(413, $response->getStatusCode());
    }

    public function testUploadNoFile(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/files');

        $response = $this->app->handle($request);

        // Doit retourner 400 Bad Request
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testQuotaUpdatedAfterUpload(): void
    {
        $uploadedFile = new UploadedFile(
            $this->testFilePath,
            'test.txt',
            'text/plain',
            filesize($this->testFilePath),
            UPLOAD_ERR_OK
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/files')
            ->withUploadedFiles(['file' => $uploadedFile]);

        $this->app->handle($request);

        // Vérifier que le quota a été mis à jour
        $user = $this->db->get('users', '*', ['id' => 1]);
        
        $this->assertGreaterThan(0, $user['quota_used']);
        // Le quota doit être > 0 et proche de la taille du fichier
        $this->assertGreaterThan(15, $user['quota_used']);
        $this->assertLessThanOrEqual(25, $user['quota_used']);
    }
}