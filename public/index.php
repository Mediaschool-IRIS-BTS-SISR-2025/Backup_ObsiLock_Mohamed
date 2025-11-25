<?php

use Slim\Factory\AppFactory;
use Medoo\Medoo;
use App\Controller\FileController;

require __DIR__ . '/../vendor/autoload.php';

$database = new Medoo([
    'type' => 'mysql',
    'host' => '127.0.0.1',
    'database' => 'file_vault',
    'username' => 'root',
    'password' => '',
]);

$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Auto-detect base path when served from a subdirectory (e.g., /file-vault-api or /file-vault-api/public)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = rtrim(str_ireplace('index.php', '', $scriptName), '/');
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

$fileController = new FileController($database);

$app->get('/files', [$fileController, 'list']);
$app->get('/files/{id}', [$fileController, 'show']);
$app->get('/files/{id}/download', [$fileController, 'download']);
$app->post('/files', [$fileController, 'upload']);
$app->delete('/files/{id}', [$fileController, 'delete']);
$app->get('/stats', [$fileController, 'stats']);
$app->get('/', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'message' => 'File Vault API',
        'endpoints' => [
            'GET /files',
            'GET /files/{id}',
            'GET /files/{id}/download',
            'POST /files',
            'DELETE /files/{id}',
            'GET /stats',
        ]
    ], JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});
$app->run();