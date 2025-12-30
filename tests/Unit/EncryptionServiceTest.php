<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Service\EncryptionService;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;
    private string $testFile;

    protected function setUp(): void
    {
        // Créer clé test
        putenv('ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
        
        $this->service = new EncryptionService();
        $this->testFile = sys_get_temp_dir() . '/test_' . uniqid() . '.txt';
        
        // Créer fichier test
        file_put_contents($this->testFile, 'Hello ObsiLock!');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testEncryptDecryptFile(): void
    {
        $encryptedPath = $this->testFile . '.enc';
        $decryptedPath = $this->testFile . '.dec';

        // 1. Chiffrer
        $encryptionData = $this->service->encryptFile($this->testFile, $encryptedPath);
        
        $this->assertArrayHasKey('key_envelope', $encryptionData);
        $this->assertArrayHasKey('nonce', $encryptionData);
        $this->assertArrayHasKey('chunk_nonce_start', $encryptionData);
        $this->assertFileExists($encryptedPath);

        // 2. Déchiffrer
        $this->service->decryptFile(
            $encryptedPath,
            $decryptedPath,
            $encryptionData['key_envelope'],
            $encryptionData['nonce'],
            $encryptionData['chunk_nonce_start']
        );

        $this->assertFileExists($decryptedPath);
        
        // 3. Vérifier contenu identique
        $original = file_get_contents($this->testFile);
        $decrypted = file_get_contents($decryptedPath);
        
        $this->assertEquals($original, $decrypted);

        // Nettoyage
        unlink($encryptedPath);
        unlink($decryptedPath);
    }

    public function testEncryptDecryptData(): void
    {
        $data = 'Secret message';
        
        $encrypted = $this->service->encryptData($data);
        
        $this->assertArrayHasKey('data', $encrypted);
        $this->assertArrayHasKey('nonce', $encrypted);
        $this->assertArrayHasKey('key_envelope', $encrypted);
        
        $decrypted = $this->service->decryptData(
            $encrypted['data'],
            $encrypted['key_envelope'],
            $encrypted['nonce'],
            $encrypted['key_nonce']
        );
        
        $this->assertEquals($data, $decrypted);
    }

    public function testGenerateMasterKey(): void
    {
        $key = EncryptionService::generateMasterKey();
        
        $this->assertIsString($key);
        $this->assertEquals(44, strlen($key)); // Base64 de 32 octets = 44 chars
        
        $decoded = base64_decode($key);
        $this->assertEquals(32, strlen($decoded));
    }
}