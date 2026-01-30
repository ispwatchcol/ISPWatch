<?php
/**
 * Test de conexión al MikroTik CORE
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
echo "Configuración:\n";
echo "  Host: " . env('MIKROTIK_CORE_SSH_HOST', '(default)') . "\n";
echo "  Port: " . env('MIKROTIK_CORE_SSH_PORT', 22) . "\n";
echo "  User: " . env('MIKROTIK_CORE_SSH_USER', 'admin') . "\n";
echo "  Passw: " . (env('MIKROTIK_CORE_SSH_PASS') ? '***SET***' : '(not set)') . "\n";
echo "  Key Path: " . storage_path('keys/mikrotik_core_id_ed25519') . "\n";
echo "  Key Exists: " . (file_exists(storage_path('keys/mikrotik_core_id_ed25519')) ? 'YES' : 'NO') . "\n";
echo "\n";

// Test de conexión
$service = app(App\Services\MikroTikSshService::class);

echo "Probando conexión SSH...\n";
$result = $service->testConnection();

echo "\n========================================\n";
echo "  RESULTADO\n";
echo "========================================\n";
print_r($result);

if ($result['success']) {
    echo "\n✅ CONEXIÓN EXITOSA!\n";
} else {
    echo "\n❌ CONEXIÓN FALLIDA\n";
    echo "Mensaje: " . ($result['message'] ?? 'Sin mensaje') . "\n";
}
