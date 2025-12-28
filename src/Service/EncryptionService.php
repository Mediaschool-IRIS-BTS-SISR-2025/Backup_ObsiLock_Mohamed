<?php

namespace App\Service;

class EncryptionService
{
    private string $masterKey;

    public function __construct()
    {
        $key = getenv('ENCRYPTION_KEY');
        if (!$key) {
            throw new \RuntimeException('ENCRYPTION_KEY non définie dans .env');
        }
        $this->masterKey = base64_decode($key);
        
        if (strlen($this->masterKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('ENCRYPTION_KEY invalide (doit être 32 octets en base64)');
        }
    }

    /**
     * Chiffre un fichier
     * 
     * @param string $inputPath Chemin fichier source
     * @param string $outputPath Chemin fichier chiffré
     * @return array ['key_envelope', 'nonce', 'chunk_nonce_start']
     */
    public function encryptFile(string $inputPath, string $outputPath): array
    {
        // 1. Génération clé de contenu aléatoire
        $contentKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES); // 32 octets
        
        // 2. Génération nonce pour chunks
        $chunkNonceStart = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); // 24 octets
        $chunkNonce = $chunkNonceStart;
        
        // 3. Chiffrement par blocs de 8KB
        $inputHandle = fopen($inputPath, 'rb');
        $outputHandle = fopen($outputPath, 'wb');
        
        if (!$inputHandle || !$outputHandle) {
            throw new \RuntimeException('Impossible d\'ouvrir les fichiers');
        }
        
        while (!feof($inputHandle)) {
            $chunk = fread($inputHandle, 8192);
            if ($chunk === false) break;
            
            $encryptedChunk = sodium_crypto_secretbox($chunk, $chunkNonce, $contentKey);
            fwrite($outputHandle, $encryptedChunk);
            
            // Incrémenter nonce pour chaque chunk
            sodium_increment($chunkNonce);
        }
        
        fclose($inputHandle);
        fclose($outputHandle);
        
        // 4. Chiffrement de la clé de contenu avec clé maître
        $keyNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encryptedKey = sodium_crypto_secretbox($contentKey, $keyNonce, $this->masterKey);
        
        // 5. Nettoyage mémoire sensible
        sodium_memzero($contentKey);
        sodium_memzero($chunkNonce);
        
        return [
            'key_envelope' => base64_encode($encryptedKey),
            'nonce' => base64_encode($keyNonce),
            'chunk_nonce_start' => base64_encode($chunkNonceStart)
        ];
    }

    /**
     * Déchiffre un fichier
     * 
     * @param string $inputPath Chemin fichier chiffré
     * @param string $outputPath Chemin fichier déchiffré
     * @param string $keyEnvelope Clé chiffrée (base64)
     * @param string $keyNonce Nonce clé (base64)
     * @param string $chunkNonceStart Nonce chunks (base64)
     */
    public function decryptFile(
        string $inputPath, 
        string $outputPath, 
        string $keyEnvelope, 
        string $keyNonce,
        string $chunkNonceStart
    ): void {
        // 1. Déchiffrement clé de contenu
        $encryptedKey = base64_decode($keyEnvelope);
        $nonce = base64_decode($keyNonce);
        
        $contentKey = sodium_crypto_secretbox_open($encryptedKey, $nonce, $this->masterKey);
        if ($contentKey === false) {
            throw new \RuntimeException('Impossible de déchiffrer la clé');
        }
        
        // 2. Déchiffrement chunks
        $chunkNonce = base64_decode($chunkNonceStart);
        $inputHandle = fopen($inputPath, 'rb');
        $outputHandle = fopen($outputPath, 'wb');
        
        if (!$inputHandle || !$outputHandle) {
            throw new \RuntimeException('Impossible d\'ouvrir les fichiers');
        }
        
        // Taille chunk chiffré = taille originale + MAC (16 octets)
        $encryptedChunkSize = 8192 + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        
        while (!feof($inputHandle)) {
            $encryptedChunk = fread($inputHandle, $encryptedChunkSize);
            if ($encryptedChunk === false || $encryptedChunk === '') break;
            
            $decryptedChunk = sodium_crypto_secretbox_open($encryptedChunk, $chunkNonce, $contentKey);
            if ($decryptedChunk === false) {
                throw new \RuntimeException('Échec déchiffrement (fichier corrompu ou clé invalide)');
            }
            
            fwrite($outputHandle, $decryptedChunk);
            sodium_increment($chunkNonce);
        }
        
        fclose($inputHandle);
        fclose($outputHandle);
        
        // Nettoyage
        sodium_memzero($contentKey);
        sodium_memzero($chunkNonce);
    }

    /**
     * Chiffre des données en mémoire
     */
    public function encryptData(string $data): array
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        $encrypted = sodium_crypto_secretbox($data, $nonce, $key);
        
        // Chiffrer la clé avec clé maître
        $keyNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encryptedKey = sodium_crypto_secretbox($key, $keyNonce, $this->masterKey);
        
        sodium_memzero($key);
        
        return [
            'data' => base64_encode($encrypted),
            'nonce' => base64_encode($nonce),
            'key_envelope' => base64_encode($encryptedKey),
            'key_nonce' => base64_encode($keyNonce)
        ];
    }

    /**
     * Déchiffre des données en mémoire
     */
    public function decryptData(string $encrypted, string $keyEnvelope, string $nonce, string $keyNonce): string
    {
        $encryptedKey = base64_decode($keyEnvelope);
        $keyNonceDecoded = base64_decode($keyNonce);
        
        $key = sodium_crypto_secretbox_open($encryptedKey, $keyNonceDecoded, $this->masterKey);
        if ($key === false) {
            throw new \RuntimeException('Impossible de déchiffrer la clé');
        }
        
        $nonceDecoded = base64_decode($nonce);
        $encryptedDecoded = base64_decode($encrypted);
        
        $decrypted = sodium_crypto_secretbox_open($encryptedDecoded, $nonceDecoded, $key);
        if ($decrypted === false) {
            throw new \RuntimeException('Impossible de déchiffrer les données');
        }
        
        sodium_memzero($key);
        
        return $decrypted;
    }

    /**
     * Génère une clé maître aléatoire (à mettre dans .env)
     */
    public static function generateMasterKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}