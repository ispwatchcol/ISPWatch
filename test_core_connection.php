<?php
/**
 * Test de conexión al MikroTik CORE
 * Ahora prueba tanto API como SSH
 * 
 * Ejecutar: php test_core_connection.php
 */

require __DIR__ . '/vendor/autoload.php';

// Cargar Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "========================================\n";
echo "  TEST DE CONEXIÓN AL MIKROTIK CORE\n";
echo "========================================\n\n";

// Mostrar configuración actual
echo "Configuración API:\n";
echo "  Host: " . env('MIKROTIK_CORE_API_HOST', '(default)') . "\n";
echo "  Port: " . env('MIKROTIK_CORE_API_PORT', 8728) . "\n";
echo "  User: " . env('MIKROTIK_CORE_API_USER', 'admin') . "\n";
echo "  Pass: " . (env('MIKROTIK_CORE_API_PASS') ? '***SET***' : '(not set)') . "\n";
echo "\n";

echo "Configuración SSH:\n";
echo "  Host: " . env('MIKROTIK_CORE_SSH_HOST', '(default)') . "\n";
echo "  Port: " . env('MIKROTIK_CORE_SSH_PORT', 22) . "\n";
echo "  User: " . env('MIKROTIK_CORE_SSH_USER', 'admin') . "\n";
echo "  Key Path: " . storage_path('keys/mikrotik_core_id_ed25519') . "\n";
echo "  Key Exists: " . (file_exists(storage_path('keys/mikrotik_core_id_ed25519')) ? 'YES' : 'NO') . "\n";
echo "\n";

// Test de conexión
$service = app(App\Services\MikroTikSshService::class);

echo "Probando conexiones...\n\n";

// Test API
echo "--- TEST API ---\n";
$apiResult = $service->testApiConnection();
echo "Resultado: " . ($apiResult['success'] ? '✅ EXITOSO' : '❌ FALLIDO') . "\n";
echo "Mensaje: " . ($apiResult['message'] ?? 'N/A') . "\n";
if (isset($apiResult['identity'])) {
    echo "Identity: " . $apiResult['identity'] . "\n";
}
echo "\n";

// Test SSH
echo "--- TEST SSH ---\n";
$sshResult = $service->testSshConnection();
echo "Resultado: " . ($sshResult['success'] ? '✅ EXITOSO' : '❌ FALLIDO') . "\n";
echo "Mensaje: " . ($sshResult['message'] ?? 'N/A') . "\n";
if (isset($sshResult['identity'])) {
    echo "Identity: " . $sshResult['identity'] . "\n";
}
echo "\n";

// Test PPP Active (uses preferred method automatically)
echo "--- TEST PPP ACTIVE (VPN Connections) ---\n";
$pppResult = $service->getPppActive();
echo "Resultado: " . ($pppResult['success'] ? '✅ EXITOSO' : '❌ FALLIDO') . "\n";
echo "Método usado: " . ($pppResult['method'] ?? 'N/A') . "\n";
if (!empty($pppResult['connections'])) {
    echo "Conexiones activas: " . count($pppResult['connections']) . "\n";
    foreach ($pppResult['connections'] as $conn) {
        echo "  - {$conn['name']} ({$conn['service']}) IP: {$conn['address']} Uptime: {$conn['uptime']}\n";
    }
} else {
    echo "No hay conexiones VPN activas\n";
}
echo "\n";

echo "========================================\n";
echo "  RESUMEN\n";
echo "========================================\n";
echo "API: " . ($apiResult['success'] ? '✅ FUNCIONA' : '❌ FALLA') . "\n";
echo "SSH: " . ($sshResult['success'] ? '✅ FUNCIONA' : '❌ FALLA') . "\n";
echo "Método preferido: " . ($apiResult['success'] ? 'API' : ($sshResult['success'] ? 'SSH' : 'NINGUNO')) . "\n";
echo "\n";

if ($apiResult['success'] || $sshResult['success']) {
    echo "✅ Al menos un método de conexión funciona!\n";
    echo "El sistema usará automáticamente el método disponible.\n";
} else {
    echo "❌ Ningún método de conexión funciona.\n";
    echo "Verifica la configuración y los puertos del firewall.\n";
}
