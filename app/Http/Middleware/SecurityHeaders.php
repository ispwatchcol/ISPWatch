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

            // Isolate the browsing context from cross-origin popups/openers
            // (mitigates cross-window leaks). 'allow-popups' keeps OAuth/maps
            // popups able to talk back to the opener if ever needed.
            $response->header('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

            // Block legacy Adobe cross-domain policy files
            $response->header('X-Permitted-Cross-Domain-Policies', 'none');

            // Strict Transport Security (HTTPS only) - enable in production
            if (!app()->environment('local')) {
                $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
            }

            // Content Security Policy - Allow Vite dev server in development
            // Check both environment AND if we're actually on localhost
            $host = $request->getHost();
            $isLocal = app()->environment('local') &&
                (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1'));

            // ── Content Security Policy ──────────────────────────────────────
            //
            // Endurecida el 2026-07-30. Tres cambios, cada uno verificado antes
            // de aplicarlo — no se retiró nada "por si acaso":
            //
            //  1. FUERA 'unsafe-eval' en script-src. Se compiló el bundle de
            //     producción y se comprobó que no contiene ni `eval(` ni
            //     `new Function(`: Vite emite SFC ya compilados y el runtime de
            //     Vue usado no necesita compilar plantillas en el navegador.
            //     Mantenerlo desactivaba una de las defensas más útiles contra
            //     XSS en una aplicación que, además, permite HTML editable por
            //     el usuario en las plantillas de documentos.
            //
            //  2. FUERA https://unpkg.com. Era el único CDN externo, y sólo lo
            //     usaba el mapa de clientes para cargar @googlemaps/markerclusterer
            //     en tiempo de ejecución. Ese paquete ahora se empaqueta con la
            //     aplicación (ver CustomerMap.vue), así que la excepción sobra:
            //     un CDN público comprometido inyectaba script arbitrario en una
            //     página autenticada.
            //
            //  3. FUERA 'unsafe-inline' en SCRIPT-src. Los dos únicos scripts en
            //     línea del proyecto eran `onclick="window.location.href=..."` en
            //     el portal de pago; se convirtieron en enlaces <a>. Laravel emite
            //     los assets de Vite como <script src>, sin código en línea.
            //
            // style-src CONSERVA 'unsafe-inline' a propósito: Vue inyecta estilos
            // en línea para los componentes con <style scoped> y quitarlo rompe
            // el render. Es la excepción habitual y de riesgo mucho menor que la
            // de script-src.
            if ($isLocal) {
                // Desarrollo: además hay que permitir el dev server de Vite, que
                // sí inyecta un preámbulo en línea para el HMR (de ahí que aquí
                // 'unsafe-inline' siga presente en script-src).
                $response->header(
                    'Content-Security-Policy',
                    "default-src 'self' http://localhost:5173 ws://localhost:5173; " .
                    "script-src 'self' 'unsafe-inline' http://localhost:5173 ws://localhost:5173 https://maps.googleapis.com; " .
                    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://maps.googleapis.com http://localhost:5173; " .
                    "font-src 'self' https://fonts.gstatic.com data:; " .
                    "img-src 'self' data: https: blob: http://localhost:5173; " .
                    "connect-src 'self' http://localhost:* ws://localhost:*; " .
                    "frame-src 'self' blob:; " .
                    "object-src 'none'; " .
                    "base-uri 'self'; " .
                    "form-action 'self'; " .
                    "frame-ancestors 'self';"
                );
            } else {
                // Production: Allow 'self' and dynamically include the current origin
                $scheme = $request->getScheme(); // http or https
                $currentOrigin = $scheme . '://' . $host;

                $response->header(
                    'Content-Security-Policy',
                    "default-src 'self' {$currentOrigin}; " .
                    "script-src 'self' {$currentOrigin} https://maps.googleapis.com; " .
                    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://maps.googleapis.com {$currentOrigin}; " .
                    "font-src 'self' https://fonts.gstatic.com data:; " .
                    "img-src 'self' data: https: http: blob:; " .
                    "connect-src 'self' https: wss:; " .
                    // frame-src NO se hereda de default-src por accidente: sin
                    // declararlo, caía en "default-src 'self'" y el navegador
                    // RECHAZABA cualquier <iframe src="blob:...">. Eso rompía la
                    // vista previa en PDF de la hoja de instalación (el visor
                    // salía en gris con el icono de documento roto). 'blob:' sólo
                    // habilita documentos generados por la propia página —quien
                    // pudiera crear uno ya ejecuta script— y el blob hereda la
                    // CSP del contexto que lo creó, así que no abre una vía de
                    // escape. NO se añade 'data:', que sí es un vector clásico.
                    "frame-src 'self' blob:; " .
                    // object-src 'none' bloquea <object>/<embed>, vectores clásicos
                    // de XSS. base-uri 'self' impide que una inyección reescriba
                    // la base de las URLs relativas. form-action 'self' evita que
                    // un formulario inyectado envíe credenciales a otro dominio.
                    "object-src 'none'; " .
                    "base-uri 'self'; " .
                    "form-action 'self'; " .
                    "frame-ancestors 'self';"
                );
            }
        }

        return $response;
    }
}
