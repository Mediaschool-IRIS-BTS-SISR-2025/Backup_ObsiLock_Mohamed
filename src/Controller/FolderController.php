<?php
namespace App\Controller;

use App\Model\FolderRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FolderController
{
    private FolderRepository $folders;

    public function __construct(FolderRepository $folders)
    {
        $this->folders = $folders;
    }

    // GET /folders
    public function list(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $folders = $this->folders->listByUser($user['user_id']);

        $response->getBody()->write(json_encode($folders));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // POST /folders
    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        if (empty($data['name'])) {
            $response->getBody()->write(json_encode(['error' => 'Nom requis']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $folderId = $this->folders->create([
            'user_id' => $user['user_id'],
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name']
        ]);

        $response->getBody()->write(json_encode([
            'message' => 'Dossier créé',
            'id' => $folderId
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    // DELETE /folders/{id}
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $folderId = (int)$args['id'];

        $folder = $this->folders->find($folderId);

        if (!$folder || $folder['user_id'] !== $user['user_id']) {
            $response->getBody()->write(json_encode(['error' => 'Dossier introuvable']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $this->folders->delete($folderId);

        $response->getBody()->write(json_encode(['message' => 'Dossier supprimé']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}