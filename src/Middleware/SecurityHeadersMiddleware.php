<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class SecurityHeadersMiddleware
{
    /**
     * Ajoute les headers de sécurité HTTP
     */
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);
        
        return $response
            // Empêche l'affichage dans une iframe (clickjacking)
            ->withHeader('X-Frame-Options', 'DENY')
            
            // Empêche le navigateur de "deviner" le MIME type
            ->withHeader('X-Content-Type-Options', 'nosniff')
            
            // Active la protection XSS du navigateur
            ->withHeader('X-XSS-Protection', '1; mode=block')
            
            // Force HTTPS (strict transport security)
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            
            // Content Security Policy (sécurité renforcée)
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'")
            
            // Politique de référent (ne pas envoyer l'URL complète)
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            
            // Permissions Policy (anciennement Feature Policy)
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            
            // Retire le header Server pour ne pas exposer Apache
            ->withoutHeader('Server')
            ->withoutHeader('X-Powered-By');
    }
}