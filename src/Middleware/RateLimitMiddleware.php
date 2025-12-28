<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpTooManyRequestsException;

class RateLimitMiddleware
{
    private int $maxRequests;
    private int $timeWindow;
    private string $storageDir;

    /**
     * @param int $maxRequests Nombre max de requêtes (défaut: 100)
     * @param int $timeWindow Fenêtre de temps en secondes (défaut: 3600 = 1h)
     */
    public function __construct(int $maxRequests = 100, int $timeWindow = 3600)
    {
        $this->maxRequests = $maxRequests;
        $this->timeWindow = $timeWindow;
        $this->storageDir = sys_get_temp_dir() . '/obsilock_rate_limit';
        
        // Créer le dossier si nécessaire
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $ip = $this->getClientIp($request);
        $key = md5($ip);
        $logFile = $this->storageDir . '/' . $key . '.json';
        
        // Charger l'historique des requêtes
        $requests = $this->loadRequests($logFile);
        
        // Nettoyer les requêtes expirées
        $now = time();
        $requests = array_filter($requests, function($timestamp) use ($now) {
            return $timestamp > ($now - $this->timeWindow);
        });
        
        // Vérifier la limite
        if (count($requests) >= $this->maxRequests) {
            // Calculer le temps restant avant reset
            $oldestRequest = min($requests);
            $retryAfter = ($oldestRequest + $this->timeWindow) - $now;
            
            throw new HttpTooManyRequestsException(
                $request,
                "Rate limit exceeded. Try again in {$retryAfter} seconds."
            );
        }
        
        // Ajouter la requête actuelle
        $requests[] = $now;
        
        // Sauvegarder
        $this->saveRequests($logFile, $requests);
        
        // Ajouter headers informatifs
        $response = $handler->handle($request);
        
        return $response
            ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string)($this->maxRequests - count($requests)))
            ->withHeader('X-RateLimit-Reset', (string)($now + $this->timeWindow));
    }

    /**
     * Récupère l'IP du client (même derrière proxy)
     */
    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        
        // Vérifier les headers de proxy
        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $serverParams['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        
        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            return $serverParams['HTTP_X_REAL_IP'];
        }
        
        return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Charge l'historique des requêtes
     */
    private function loadRequests(string $logFile): array
    {
        if (!file_exists($logFile)) {
            return [];
        }
        
        $content = file_get_contents($logFile);
        $data = json_decode($content, true);
        
        return is_array($data) ? $data : [];
    }

    /**
     * Sauvegarde l'historique des requêtes
     */
    private function saveRequests(string $logFile, array $requests): void
    {
        file_put_contents($logFile, json_encode($requests));
    }
}