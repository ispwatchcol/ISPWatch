<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * `$request->realIp()` — la IP del visitante real, no la del borde de Cloudflare.
 *
 * El problema: el sitio está detrás de Cloudflare. `$request->ip()` —incluso
 * con `trustProxies(at: '*')` puesto en `bootstrap/app.php`— devuelve la IP del
 * BORDE de Cloudflare (rango `104.16.0.0/13` y similares), no la del cliente.
 * DigitalOcean App Platform no conserva la cadena `X-Forwarded-For` que
 * Cloudflare arma; genera la suya propia a partir de con quién habló
 * directamente, que es Cloudflare. El dato original se pierde antes de que
 * Laravel vea la petición, así que ninguna configuración de proxies de
 * confianza puede recuperarlo desde `X-Forwarded-For`.
 *
 * Se descubrió el 2026-08-21 al emitir la primera llave real de la API partner
 * (para Colombia Net de Occidente) y comparar la IP pública del ISP contra la
 * que quedó en `api_key_request_logs`: siempre `104.22.86.188`, sin importar
 * la IP real ni el día. `api_key_request_logs` tenía CERO peticiones antes de
 * esa prueba, así que nunca antes se había validado de punta a punta si el
 * allowlist funcionaba contra tráfico externo real — sólo contra la suite de
 * tests, que fija `allowed_ips = ['127.0.0.1']` y jamás pasa por un proxy.
 *
 * El alcance no es sólo la API partner. Toda comprobación de IP de la
 * aplicación —el allowlist de llaves, el límite de intentos de login y de
 * registro, el límite de firma remota de contratos (5 intentos por IP), y la
 * bitácora de auditoría de dinero— usaba `$request->ip()` directo, así que
 * TODAS estaban registrando o limitando por el borde de Cloudflare, no por
 * quien realmente llamaba.
 *
 * SOLUCIÓN: Cloudflare pone el visitante real en la cabecera `CF-Connecting-IP`
 * —un solo valor, no una cadena que haya que recorrer—. Se usa cuando está
 * presente y es una IP válida; si no, se cae a `$request->ip()` (petición
 * local, entorno de pruebas, o cualquier despliegue sin Cloudflare delante).
 *
 * RIESGO QUE QUEDA ABIERTO A PROPÓSITO, Y NO SE CIERRA AQUÍ: cualquiera que
 * alcance el origen de DigitalOcean SIN pasar por Cloudflare puede mandar su
 * propia cabecera `CF-Connecting-IP` y suplantar cualquier IP —incluida una
 * que sí esté en un allowlist—. La corrección completa es que el origen sólo
 * acepte conexiones desde los rangos publicados de Cloudflare (o desde el
 * balanceador de DO configurado para eso), y eso es un cambio de
 * infraestructura, no de este archivo. Anotado como P-38 en
 * `MEJORAS_RECOMENDADAS.md`; hasta que se cierre, esta macro es estrictamente
 * mejor que el estado anterior —el allowlist ya no falla contra tráfico
 * real—, pero no es una defensa contra quien conozca y alcance el origen.
 */
class RequestMacrosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Request::macro('realIp', function () {
            /** @var Request $this */
            $cf = $this->header('CF-Connecting-IP');

            if ($cf && filter_var($cf, FILTER_VALIDATE_IP)) {
                return $cf;
            }

            return $this->ip();
        });
    }
}
