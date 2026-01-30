<?php

// Simular que somos producción (sin acceso directo a 10.x.x.x)
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MikroTikSshService;

// Datos del router cliente
$clientIp = '10.10.10.250';
$clientUser = 'ispwatch';
$clientPass = 'iZ8vC3g4H6gyvt9A';
$wanInterface = 'ether1';
$portalIp = env('PORTAL_IP', '138.197.30.155');

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  PRUEBA SSH-EXEC DIRECTO (SIMULANDO PRODUCCIÓN)            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "Cliente: $clientIp\n";
echo "Usuario: $clientUser\n\n";

$service = new MikroTikSshService();

// Simular que API directa no funciona
echo "=== Conectando al CORE via SSH ===\n";

$startTime = microtime(true);

// Llamar directamente applyBlockRulesViaSshTunnel via Reflection
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('applyBlockRulesViaSshTunnel');
$method->setAccessible(true);

$result = $method->invoke($service, $clientIp, $clientUser, $clientPass, $wanInterface, $portalIp, 8728);

$endTime = microtime(true);

echo "\n=== RESULTADO ===\n";
echo "Tiempo: " . round($endTime - $startTime, 2) . " segundos\n\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
