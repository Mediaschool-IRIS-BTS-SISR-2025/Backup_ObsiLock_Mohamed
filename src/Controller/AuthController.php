<?php
namespace App\Controller;

use App\Model\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    private UserRepository $users;
    private string $jwtSecret;

    public function __construct(UserRepository $users, string $jwtSecret)
    {
        $this->users = $users;
        $this->jwtSecret = $jwtSecret;
    }

    // POST /auth/register
    public function register(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (empty($data['email']) || empty($data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Email et password requis']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Vérifie si l'email existe déjà
        if ($this->users->findByEmail($data['email'])) {
            $response->getBody()->write(json_encode(['error' => 'Email déjà utilisé']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }

        // Hash du mot de passe
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);

        $userId = $this->users->create([
            'email' => $data['email'],
            'password' => $hash
        ]);

        $response->getBody()->write(json_encode([
            'message' => 'Utilisateur créé',
            'user_id' => $userId
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    // POST /auth/login
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data['email']) || empty($data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Email et password requis']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $user = $this->users->findByEmail($data['email']);

        if (!$user || !password_verify($data['password'], $user['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Identifiants invalides']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Génère le JWT simple
        $token = $this->generateJWT([
            'user_id' => $user['id'],
            'email' => $user['email']
        ]);

        $response->getBody()->write(json_encode([
            'message' => 'Connexion réussie',
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email']
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    // Fonction simple pour générer un JWT
    private function generateJWT(array $payload): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['exp'] = time() + 3600; // expire dans 1h
        $payload = json_encode($payload);

        $base64Header = $this->base64url($header);
        $base64Payload = $this->base64url($payload);
        
        $signature = hash_hmac('sha256', "$base64Header.$base64Payload", $this->jwtSecret, true);
        $base64Signature = $this->base64url($signature);

        return "$base64Header.$base64Payload.$base64Signature";
    }

    private function base64url($data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}