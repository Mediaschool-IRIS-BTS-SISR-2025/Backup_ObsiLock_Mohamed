<?php
// src/Controller/FileController.php

namespace App\Controller;

use App\Model\FileRepository;
use Medoo\Medoo;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FileController
{
    private FileRepository $files;
    private string $uploadDir;

    public function __construct(Medoo $db)
    {
        $this->files = new FileRepository($db);
        $this->uploadDir = __DIR__ . '/../../storage/uploads';
    }

    // GET /files
    public function list(Request $request, Response $response): Response
    {
        $data = $this->files->listFiles();

        $payload = json_encode($data, JSON_PRETTY_PRINT);
        $response->getBody()->write($payload);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    // GET /files/{id}
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $file = $this->files->find($id);

        if (!$file) {
            $response->getBody()->write(json_encode(['error' => 'File not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode($file, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    // POST /files  (upload via form-data)
    public function upload(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();

        if (!isset($uploadedFiles['file'])) {
            $response->getBody()->write(json_encode(['error' => 'No file uploaded']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $file = $uploadedFiles['file'];

        if ($file->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode(['error' => 'Upload error']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $size = $file->getSize();
        $totalSize = $this->files->totalSize();
        $quota = $this->files->quotaBytes();

        if ($quota > 0 && ($totalSize + $size) > $quota) {
            $response->getBody()->write(json_encode(['error' => 'Quota exceeded']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(413);
        }

        $originalName = $file->getClientFilename();
        $mimeType = $file->getClientMediaType();
        $storedName = uniqid('f_', true) . '_' . $originalName;

        $file->moveTo($this->uploadDir . DIRECTORY_SEPARATOR . $storedName);

        $id = $this->files->create([
            'filename'    => $originalName,
            'stored_name' => $storedName,
            'size'        => $size,
            'mime_type'   => $mimeType,
            'uploaded_at' => date('Y-m-d H:i:s')
        ]);

        $response->getBody()->write(json_encode([
            'message' => 'File uploaded',
            'id'      => $id
        ], JSON_PRETTY_PRINT));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    // GET /files/{id}/download
    public function download(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $file = $this->files->find($id);

        if (!$file) {
            $response->getBody()->write('File not found');
            return $response->withStatus(404);
        }

        $path = $this->uploadDir . DIRECTORY_SEPARATOR . $file['stored_name'];

        if (!file_exists($path)) {
            $response->getBody()->write('File missing on disk');
            return $response->withStatus(500);
        }

        $stream = fopen($path, 'rb');
        $response->getBody()->write(stream_get_contents($stream));
        fclose($stream);

        return $response
            ->withHeader('Content-Type', $file['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withStatus(200);
    }

    // DELETE /files/{id}
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $file = $this->files->find($id);

        if (!$file) {
            $response->getBody()->write(json_encode(['error' => 'File not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Supprimer le fichier sur le disque
        $path = $this->uploadDir . DIRECTORY_SEPARATOR . $file['stored_name'];
        if (file_exists($path)) {
            unlink($path);
        }

        // Supprimer en base
        $this->files->delete($id);

        $response->getBody()->write(json_encode(['message' => 'File deleted']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    // GET /stats
    public function stats(Request $request, Response $response): Response
    {
        $totalSize = $this->files->totalSize();
        $quota = $this->files->quotaBytes();

        // Exercice 1: utiliser countFiles() ici si l'étudiant l’a codée
        // $count = $this->files->countFiles();

        $data = [
            'total_size_bytes' => $totalSize,
            'quota_bytes'      => $quota,
            // 'file_count'        => $count,
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
}