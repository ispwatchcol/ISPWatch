<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security Headers Middleware
 * 
 * Adds security headers to all responses to protect against common web vulnerabilities.
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only add headers to HTTP responses (not redirects, etc.)
        if (method_exists($response, 'header')) {
            // Prevent clickjacking
            $response->header('X-Frame-Options', 'SAMEORIGIN');

            // Prevent MIME type sniffing
            $response->header('X-Content-Type-Options', 'nosniff');

            // Enable XSS filter in browsers
            $response->header('X-XSS-Protection', '1; mode=block');

            // Referrer policy
            $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');

            // Permissions policy (formerly Feature-Policy)
            $response->header('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

            // Strict Transport Security (HTTPS only) - uncomment in production with HTTPS
            // $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

            // Content Security Policy - Allow Vite dev server in development
            $isLocal = app()->environment('local');

            // Build CSP based on environment
            if ($isLocal) {
                // Development: Allow Vite dev server
                $response->header(
                    'Content-Security-Policy',
                    "default-src 'self' http://localhost:5173 ws://localhost:5173; " .
                    "script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 ws://localhost:5173; " .
                    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com http://localhost:5173; " .
                    "font-src 'self' https://fonts.gstatic.com data:; " .
                    "img-src 'self' data: https: blob: http://localhost:5173; " .
                    "connect-src 'self' http://localhost:* ws://localhost:* https://*.supabase.co wss://*.supabase.co; " .
                    "frame-ancestors 'self';"
                );
            } else {
                // Production: Allow 'self' which covers the current domain (including DigitalOcean)
                $response->header(
                    'Content-Security-Policy',
                    "default-src 'self'; " .
                    "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
                    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
                    "font-src 'self' https://fonts.gstatic.com data:; " .
                    "img-src 'self' data: https: blob:; " .
                    "connect-src 'self' https: wss: https://*.supabase.co wss://*.supabase.co; " .
                    "frame-ancestors 'self';"
                );
            }
        }

        return $response;
    }
}
