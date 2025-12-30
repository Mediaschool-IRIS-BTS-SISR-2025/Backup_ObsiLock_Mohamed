<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Medoo\Medoo;
use App\Controller\AuthController;
use App\Model\UserRepository;

class AuthIntegrationTest extends TestCase
{
    private $app;
    private Medoo $db;

    protected function setUp(): void
    {
        // BDD SQLite en mémoire
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

        // Créer l'application Slim
        $this->app = AppFactory::create();
        
        // Ajouter les middlewares
        $this->app->addBodyParsingMiddleware();
        $this->app->addRoutingMiddleware();
        $this->app->addErrorMiddleware(true, true, true);

        // Repository et Controller
        $userRepo = new UserRepository($this->db);
        $jwtSecret = 'test_jwt_secret_32_characters_min';
        $authController = new AuthController($userRepo, $jwtSecret);

        // Routes
        $this->app->post('/auth/register', [$authController, 'register']);
        $this->app->post('/auth/login', [$authController, 'login']);
    }

    public function testRegisterSuccess(): void
    {
        // Créer la requête
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/register')
            ->withParsedBody([
                'email' => 'test@obsilock.fr',
                'password' => 'Password123!'
            ]);

        // Exécuter
        $response = $this->app->handle($request);

        // Assertions
        $this->assertEquals(201, $response->getStatusCode());
    }

    public function testRegisterDuplicateEmail(): void
    {
        // Premier register
        $request1 = (new ServerRequestFactory())->createServerRequest('POST', '/auth/register')
            ->withParsedBody([
                'email' => 'duplicate@obsilock.fr',
                'password' => 'Pass123!'
            ]);
        $this->app->handle($request1);

        // Deuxième avec même email
        $request2 = (new ServerRequestFactory())->createServerRequest('POST', '/auth/register')
            ->withParsedBody([
                'email' => 'duplicate@obsilock.fr',
                'password' => 'Pass456!'
            ]);
        $response = $this->app->handle($request2);

        // Doit retourner 409 Conflict
        $this->assertEquals(409, $response->getStatusCode());
    }

    public function testLoginSuccess(): void
    {
        // Créer un user d'abord
        $registerRequest = (new ServerRequestFactory())->createServerRequest('POST', '/auth/register')
            ->withParsedBody([
                'email' => 'login@obsilock.fr',
                'password' => 'MyPassword123'
            ]);
        $this->app->handle($registerRequest);

        // Tenter le login
        $loginRequest = (new ServerRequestFactory())->createServerRequest('POST', '/auth/login')
            ->withParsedBody([
                'email' => 'login@obsilock.fr',
                'password' => 'MyPassword123'
            ]);
        $response = $this->app->handle($loginRequest);

        // Assertions
        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);
    }

    public function testLoginInvalidPassword(): void
    {
        // Créer un user
        $registerRequest = (new ServerRequestFactory())->createServerRequest('POST', '/auth/register')
            ->withParsedBody([
                'email' => 'wrongpass@obsilock.fr',
                'password' => 'CorrectPassword'
            ]);
        $this->app->handle($registerRequest);

        // Login avec mauvais password
        $loginRequest = (new ServerRequestFactory())->createServerRequest('POST', '/auth/login')
            ->withParsedBody([
                'email' => 'wrongpass@obsilock.fr',
                'password' => 'WrongPassword'
            ]);
        $response = $this->app->handle($loginRequest);

        // Doit retourner 401
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testLoginUserNotFound(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/login')
            ->withParsedBody([
                'email' => 'notfound@obsilock.fr',
                'password' => 'AnyPassword'
            ]);
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
    }
}