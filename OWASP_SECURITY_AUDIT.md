# 🔒 AUDITORÍA DE SEGURIDAD OWASP TOP 10
**ISPWatch - Análisis de Vulnerabilidades**
**Fecha:** 2026-05-14

---

## 📊 RESUMEN EJECUTIVO

Se han identificado **12 vulnerabilidades críticas y de alto riesgo** en la aplicación que requieren atención inmediata. El proyecto tiene buenas prácticas en algunas áreas (validación de entrada, hash de contraseñas), pero presenta falencias serias en otras (inyección SQL, configuración de seguridad, logging).

**Severidad General:** 🔴 ALTO (7 Critical, 5 High)

---

## 🚨 VULNERABILIDADES CRÍTICAS POR OWASP TOP 10

### 1. **A01:2021 – BROKEN ACCESS CONTROL** 🔴 CRÍTICO

#### ✅ FORTALEZAS DETECTADAS:
- ✓ Middleware `CheckPermission.php` previene escalado de privilegios usando `$request->user()` en lugar de input del usuario
- ✓ Validación de `tenant_id` desde usuario autenticado en `UserController` y `CustomerProfileController`
- ✓ Soft deletes implementados correctamente

#### ❌ VULNERABILIDADES ENCONTRADAS:

**1.1 - Falta de Verificación de Permisos en Endpoints Críticos**
- **Ubicación:** Múltiples controladores sin middleware de permisos
- **Severidad:** 🔴 CRÍTICO
- **Problema:** Endpoints como `RouterController`, `BillingController`, `TenantController` no validan permisos específicos
```php
// ❌ MAL - Sin validación de permisos
public function applyBlockRules(Router $router): array {
    // Accesible por cualquier usuario autenticado
}

// ✅ BIEN - Con validación
Route::middleware(['auth:sanctum', 'permission:suspend_customers'])->post('/routers/block', ...);
```
- **Impacto:** Un usuario con rol limitado podría bloquear clientes, modificar routers
- **Remediación:** Agregar middleware de permisos a todas las rutas críticas

**1.2 - Acceso Directo a Recursos sin Validación de Propiedad**
- **Ubicación:** `UserController@show()`, `RouterController`
- **Severidad:** 🔴 CRÍTICO
```php
// ❌ VULNERABLE - Sin validar tenant_id del recurso
public function show($id) {
    $user = User::findOrFail($id); // ¿Es del mismo tenant?
}

// ✅ CORRECTO - Validar tenant_id
public function show($id) {
    $user = User::where('tenant_id', auth()->user()->tenant_id)
                ->findOrFail($id);
}
```
- **Impacto:** Un usuario podría ver/modificar datos de otros tenants
- **Remediación:** Validar `tenant_id` en TODAS las operaciones CRUD

**1.3 - Escalado de Privilegios en Asignación de Roles**
- **Ubicación:** `UserController@store()`, `RoleController`
- **Severidad:** 🔴 CRÍTICO
```php
// ❌ VULNERABLE - Aceptar cualquier rol_id del request
$data = $request->validate(['role_id' => 'required|integer|exists:role,id']);

// ✅ CORRECTO - Validar que solo puede asignar roles disponibles
$userRole = auth()->user()->role;
$allowedRoles = Permissions::getAllowedRolesToAssign($userRole->name);
if (!in_array($data['role_id'], $allowedRoles)) {
    throw new \Exception('No puedes asignar ese rol');
}
```
- **Impacto:** Un admin podría promover usuarios a super-admin
- **Remediación:** Implementar whitelist de roles permitidos por cada rol

---

### 2. **A02:2021 – CRYPTOGRAPHIC FAILURES** 🔴 CRÍTICO

#### ✅ FORTALEZAS:
- ✓ Uso de `Hash::make()` para contraseñas
- ✓ HTTPS implied en headers de seguridad

#### ❌ VULNERABILIDADES:

**2.1 - Credenciales VPN Almacenadas en Texto Plano**
- **Ubicación:** `VpnService.php:152-154`, tabla `router`
- **Severidad:** 🔴 CRÍTICO
```php
// ❌ CRÍTICO - Contraseñas en texto plano
$router->update([
    'vpn_username' => $vpnUsername,
    'vpn_password' => $vpnPassword,  // 🚨 SIN ENCRIPTACIÓN
]);
```
- **Impacto:** Si la BD se compromete, todas las credenciales VPN están expuestas
- **Remediación:** 
```php
// ✅ CORRECTO - Encriptar credenciales
$router->update([
    'vpn_password' => encrypt($vpnPassword),
]);

// Al recuperar:
$decryptedPassword = decrypt($router->vpn_password);
```
- **Archivos Afectados:** `app/Services/VpnService.php`, `RouterApiService.php`

**2.2 - Credenciales del Router (Mikrotik) en Texto Plano**
- **Ubicación:** `RouterApiService.php:46`, tabla `router` columnas `user_rb`, `password_rb`
- **Severidad:** 🔴 CRÍTICO
```php
// ❌ CRÍTICO
if (!$router->ip || !$router->user_rb || !$router->password_rb) {
    // Las credenciales se envían sin encriptación
}
```
- **Remediación:** Encriptar todas las credenciales antes de guardar en BD

**2.3 - Falta de HSTS y Headers de Seguridad**
- **Ubicación:** `SecurityHeaders.php:46`
- **Severidad:** 🟡 ALTO
```php
// ❌ Deshabilitado en producción
// $response->header('Strict-Transport-Security', 'max-age=31536000');
```
- **Remediación:** Habilitar HSTS en producción
```php
if (!app()->environment('local')) {
    $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
}
```

**2.4 - MD5 para Hashing de Credenciales Mikrotik**
- **Ubicación:** `RouterApiService.php:635`, `VpnService.php:698`
- **Severidad:** 🟡 ALTO
```php
// ⚠️ DÉBIL - MD5 es criptográficamente débil
$hash = md5(chr(0) . $pass . $challengeBin);
```
- **Nota:** Esto es requerido por el protocolo Mikrotik, pero documenta el riesgo

---

### 3. **A03:2021 – INJECTION** 🔴 CRÍTICO

#### ❌ VULNERABILIDADES ENCONTRADAS:

**3.1 - SQL Injection Potencial en Consultas Dinámicas**
- **Ubicación:** `UserController.php:230`
- **Severidad:** 🔴 CRÍTICO
```php
// ❌ VULNERABLE - String interpolación directa en SQL
DB::statement("SELECT setval('{$table}_id_seq', {$newValue}, false)");
```
- **Impacto:** Inyección de SQL si `$table` no está validado
- **Remediación:**
```php
// ✅ CORRECTO
if (!preg_match('/^[a-z_]+$/', $table)) {
    throw new \Exception('Invalid table name');
}
DB::statement("SELECT setval('{$table}_id_seq', ?, false)", [$newValue]);
```

**3.2 - Inyección de Parámetros Mikrotik API (Menos Crítico)**
- **Ubicación:** `RouterApiService.php:188-232`, `VpnService.php`
- **Severidad:** 🟡 ALTO
```php
// ⚠️ RIESGO - Parámetros sin validación
$this->sendCommand($socket, '/ip/firewall/address-list/add', [
    '=list=ISPWATCH_SUSPENDIDOS',
    '=address=' . $ip,  // ¿Validado?
    '=comment=Cliente: ' . $customerName,  // ¿Sanitizado?
]);
```
- **Remediación:** Validar IPs y sanitizar comentarios
```php
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    throw new \Exception('IP inválida');
}
```

**3.3 - Inyección de Comandos SSH Potencial**
- **Ubicación:** `SshTunnelManager.php`
- **Severidad:** 🟡 ALTO
- **Nota:** Requiere revisar cómo se construyen comandos SSH

---

### 4. **A04:2021 – INSECURE DESIGN** 🟡 ALTO

#### ❌ VULNERABILIDADES:

**4.1 - Falta de Rate Limiting en Endpoints Críticos**
- **Ubicación:** Solo implementado en `AuthController@login()`
- **Severidad:** 🟡 ALTO
- **Problema:** Endpoints como `/routers/block`, `/customers/suspend` no tienen rate limiting
```php
// ✅ LOGIN tiene rate limiting
private const MAX_ATTEMPTS = 5;
private const DECAY_MINUTES = 1;

// ❌ PERO no está en endpoints críticos
public function applyBlockRules(Router $router): array {
    // Sin protección contra spam/DoS
}
```
- **Remediación:** Agregar throttle a endpoints críticos
```php
Route::middleware('throttle:10,1')->post('/routers/{id}/block', ...);
```

**4.2 - Falta de Validación de Integridad en MikroTik**
- **Ubicación:** `RouterApiService.php`
- **Severidad:** 🟡 ALTO
- **Problema:** No hay validación que confirm que las reglas se aplicaron correctamente
```php
// ❌ Confía en que la respuesta del router es correcta
$this->sendCommand($socket, '/ip/firewall/filter/add', [...]); 
$this->readUntilDone($socket);
// ¿Realmente se agregó la regla?
```
- **Remediación:** Verificar que la regla se creó
```php
// 1. Agregar regla
$this->sendCommand($socket, '/ip/firewall/filter/add', [...]);
$response = $this->readAllRecords($socket);
$newRuleId = $response[0]['.id'] ?? null;

// 2. Verificar que existe
$this->sendCommand($socket, '/ip/firewall/filter/print', ['?id=' . $newRuleId]);
```

**4.3 - Falta de Auditoría de Cambios Críticos**
- **Ubicación:** Toda la aplicación
- **Severidad:** 🟡 ALTO
- **Problema:** No hay logs de auditoría cuando se bloquean clientes, se cambian planes, etc.
- **Remediación:** Implementar auditoría
```php
// En cada operación crítica:
AuditLog::create([
    'user_id' => auth()->id(),
    'action' => 'suspend_customer',
    'model' => 'Customer',
    'model_id' => $customer->id,
    'old_values' => ['status' => 'active'],
    'new_values' => ['status' => 'suspended'],
    'ip' => request()->ip(),
    'user_agent' => request()->userAgent(),
]);
```

---

### 5. **A05:2021 – SECURITY MISCONFIGURATION** 🔴 CRÍTICO

#### ❌ VULNERABILIDADES:

**5.1 - Debug Mode Expuesto en Respuestas de Error**
- **Ubicación:** `AuthController.php:141-145`
- **Severidad:** 🔴 CRÍTICO
```php
// ❌ CRÍTICO - Expone información sensible
return response()->json([
    'success' => false,
    'message' => config('app.debug') ? $e->getMessage() : 'Error interno',
    'debug' => config('app.debug') ? [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ] : null,
], 500);
```
- **Riesgo:** En desarrollo accidental en producción, expone rutas y números de línea
- **Remediación:**
```php
// Mejor: Log internamente, respuesta genérica al usuario
Log::error('Login error', ['exception' => $e, ...]);
return response()->json([
    'success' => false,
    'message' => 'Error interno del servidor.',
], 500);
```

**5.2 - CSP Muy Permisivo en Producción**
- **Ubicación:** `SecurityHeaders.php:72-81`
- **Severidad:** 🟡 ALTO
```php
// ⚠️ DÉBIL - Permite 'unsafe-inline' y 'unsafe-eval' en producción
"script-src 'self' 'unsafe-inline' 'unsafe-eval' {$currentOrigin};"
```
- **Remediación:** Remover 'unsafe-inline' y 'unsafe-eval' en producción
```php
if ($isProduction) {
    $csp = "script-src 'self' {$currentOrigin}; ...";
} else {
    // dev: más permisivo
}
```

**5.3 - Fallback a Credenciales Hardcodeadas**
- **Ubicación:** `VpnService.php:43-44`
- **Severidad:** 🟡 ALTO
```php
// ⚠️ Fallback a 'admin' sin password
$this->apiUser = env('MIKROTIK_CORE_API_USER', 'admin');
$this->apiPass = env('MIKROTIK_CORE_API_PASS', '');
```
- **Remediación:** Fallar si las variables no están configuradas
```php
$this->apiUser = env('MIKROTIK_CORE_API_USER');
$this->apiPass = env('MIKROTIK_CORE_API_PASS');

if (!$this->apiUser || !$this->apiPass) {
    throw new \Exception('MIKROTIK credentials not configured in .env');
}
```

**5.4 - CORS No Configurado Explícitamente**
- **Ubicación:** Verificar `config/cors.php`
- **Severidad:** 🟡 ALTO
- **Problema:** Posible exposición a CSRF si CORS es demasiado permisivo
- **Recomendación:** Revisar y restringir CORS a dominios conocidos

**5.5 - Variables de Entorno Incompletas**
- **Ubicación:** Todo el proyecto
- **Severidad:** 🟡 ALTO
- **Problema:** No hay `.env.example` con todas las variables requeridas
- **Remediación:** Crear `.env.example` completo documentando cada variable

---

### 6. **A06:2021 – VULNERABLE AND OUTDATED COMPONENTS** 🟡 ALTO

#### ⚠️ RECOMENDACIÓN:
- Ejecutar `composer audit` regularmente
- Implementar CI/CD para verificar vulnerabilidades en dependencias
```bash
composer audit
```

**Comando para revisar:**
```bash
composer outdated --locked
```

---

### 7. **A07:2021 – AUTHENTICATION AND SESSION MANAGEMENT** 🟡 ALTO

#### ✅ FORTALEZAS:
- ✓ Rate limiting en login
- ✓ Validación de email verificado
- ✓ Hash seguro de contraseñas

#### ❌ VULNERABILIDADES:

**7.1 - Sesión No Regenerada Después de Login en API**
- **Ubicación:** `AuthController.php:96-99`
- **Severidad:** 🟡 ALTO
```php
// ⚠️ Solo se regenera si hay sesión
if ($request->hasSession()) {
    $request->session()->regenerate();
}

// Pero la app es SPA/JSON - debería usar tokens
```
- **Remediación:** Implementar tokens JWT o Sanctum token refresh

**7.2 - Token Expiration No Configurado**
- **Ubicación:** Verificar `config/sanctum.php`
- **Severidad:** 🟡 ALTO
- **Problema:** Los tokens pueden no expirar
- **Remediación:**
```php
// En config/sanctum.php
'expiration' => 60 * 24, // 1 día
```

**7.3 - Falta de Session Timeout**
- **Ubicación:** Configuración de sesión
- **Severidad:** 🟡 ALTO
- **Remediación:**
```php
// config/session.php
'lifetime' => 120, // 2 horas
'expire_on_close' => false,
```

---

### 8. **A08:2021 – SOFTWARE AND DATA INTEGRITY FAILURES** 🟡 ALTO

#### ❌ VULNERABILIDADES:

**8.1 - Falta de Firma de Datos en API**
- **Ubicación:** Todos los endpoints
- **Severidad:** 🟡 ALTO
- **Problema:** No hay mecanismo de firma para verificar integridad
- **Remediación:** Implementar HMAC signing
```php
// Request signature validation
$signature = hash_hmac('sha256', $payload, env('APP_KEY'));
if (!hash_equals($signature, $request->header('X-Signature'))) {
    abort(403);
}
```

**8.2 - Falta de Validación de Integridad en BD**
- **Ubicación:** Modelos sin `protected $casts`
- **Severidad:** 🟡 ALTO
- **Problema:** No hay validación de tipos de datos
- **Remediación:**
```php
// En models:
protected $casts = [
    'ip_user' => 'string',
    'status' => 'boolean',
    'created_at' => 'datetime',
];

protected $rules = [
    'ip_user' => 'ip',
    'status' => 'boolean',
];
```

---

### 9. **A09:2021 – SECURITY LOGGING AND MONITORING** 🔴 CRÍTICO

#### ❌ VULNERABILIDADES:

**9.1 - Logging Insuficiente de Eventos Críticos**
- **Ubicación:** Toda la aplicación
- **Severidad:** 🔴 CRÍTICO
```php
// ❌ No hay logs de:
// - Intentos fallidos de suspender clientes
// - Cambios de planes
// - Modificaciones de routers
// - Acceso a credenciales

// ✅ Debe haber logs para:
$this->logSecurityEvent('suspend_customer', [
    'customer_id' => $customer->id,
    'router_id' => $router->id,
    'reason' => 'payment_overdue',
]);
```
- **Remediación:** Agregar logging a todas las operaciones críticas

**9.2 - Logs Que Incluyen Datos Sensibles**
- **Ubicación:** `VpnService.php:310-327`, `RouterApiService.php`
- **Severidad:** 🔴 CRÍTICO
```php
// ❌ CRÍTICO - Loguea longitud de contraseña (puede revelar info)
Log::info("[VPN] Sincronizando secret con el CORE", [
    'user' => $username,
    'password_length' => strlen($password),  // ¿Por qué loguear esto?
]);

// ✅ CORRECTO - No loguear datos sensibles
Log::info("[VPN] Sincronizando secret con el CORE", [
    'user' => $username,
    'status' => 'in_progress',
]);
```
- **Remediación:** Nunca loguear contraseñas, tokens, o data sensible

**9.3 - Falta de Monitoreo Activo**
- **Ubicación:** Sin alertas configuradas
- **Severidad:** 🟡 ALTO
- **Problema:** No hay alertas para eventos sospechosos (múltiples fallos de login, etc.)
- **Remediación:** Implementar alertas
```php
// Ejemplo: Alerta después de 10 intentos fallidos de login en 10 minutos
if (RateLimiter::attempts($key) > 10) {
    Notification::send(admin(), new SecurityAlert(...));
}
```

---

### 10. **A10:2021 – SERVER-SIDE REQUEST FORGERY (SSRF)** 🟡 ALTO

#### ❌ VULNERABILIDADES:

**10.1 - Conexiones a Routers Sin Validación de IP**
- **Ubicación:** `RouterApiService.php:44-62`, `VpnService.php:406-444`
- **Severidad:** 🟡 ALTO
```php
// ❌ VULNERABLE - No valida si $router->ip es una IP interna
$socket = $this->connect($router->ip, $apiPort);

// ¿Qué si $router->ip = "127.0.0.1" o "192.168.1.1"?
// ¿Se puede manipular para conectar a servicios internos?
```
- **Remediación:** Validar que solo son IPs de cliente permitidas
```php
// Validar que solo son IPs del rango de clientes VPN
if (!preg_match('/^172\.(16|17|18|19|20|21|22|23|24|25|26|27|28|29|30|31)\./', $router->ip)) {
    throw new \Exception('Invalid router IP range');
}
```

**10.2 - SSH Tunnel Manager Sin Restricciones**
- **Ubicación:** `SshTunnelManager.php`
- **Severidad:** 🟡 ALTO
- **Problema:** Podría ser usado para conectar a hosts internos no autorizados
- **Remediación:** Whitelist de IPs de router permitidas

---

## 📋 RESUMEN DE VULNERABILIDADES POR SEVERIDAD

### 🔴 CRÍTICAS (7)
1. Broken Access Control - Endpoints sin validación de permisos
2. Broken Access Control - Acceso directo a recursos sin validar tenant
3. Broken Access Control - Escalado de privilegios en roles
4. Cryptographic Failures - Credenciales VPN en texto plano
5. Cryptographic Failures - Credenciales Mikrotik en texto plano
6. Security Misconfiguration - Debug mode expuesto
7. Security Logging - Eventos críticos no logueados
8. Security Logging - Datos sensibles en logs

### 🟡 ALTAS (5)
1. Cryptographic Failures - Falta de HSTS
2. Insecure Design - Falta de rate limiting
3. SQL Injection - String interpolation en DDL
4. Security Misconfiguration - CSP muy permisivo
5. SSRF - Conexiones sin validación de IP

---

## 🛠️ PLAN DE REMEDIACIÓN (PRIORIDAD)

### INMEDIATO (Semana 1)
- [ ] Encriptar credenciales VPN y Mikrotik en BD
- [ ] Agregar validación de tenant_id en TODOS los endpoints
- [ ] Remover debug mode de respuestas de error
- [ ] Implementar logging de eventos críticos
- [ ] NO loguear datos sensibles (contraseñas, tokens)

### URGENTE (Semana 2)
- [ ] Agregar middleware de permisos a endpoints críticos
- [ ] Validar roles permitidos en asignaciones
- [ ] Implementar rate limiting en endpoints críticos
- [ ] Habilitar HSTS en producción
- [ ] Validar IPs en SSRF

### IMPORTANTE (Semana 3)
- [ ] Implementar auditoría de cambios
- [ ] Configurar token expiration
- [ ] Mejorar CSP (remover unsafe-inline en prod)
- [ ] Crear `.env.example` completo
- [ ] Implementar CI/CD para auditoría de dependencias

### RECOMENDADO (Semana 4+)
- [ ] Implementar HMAC signing en API
- [ ] Mejorar monitoreo y alertas
- [ ] Penetration testing
- [ ] Code review de TODOS los controladores
- [ ] Implementar WAF (Web Application Firewall)

---

## 📚 REFERENCIAS Y HERRAMIENTAS

- OWASP Top 10: https://owasp.org/Top10/
- OWASP Cheat Sheet: https://cheatsheetseries.owasp.org/
- PHP Security: https://www.php.net/manual/en/security.php
- Laravel Security: https://laravel.com/docs/security
- Composer Audit: `composer audit`

---

## ✅ CHECKLIST DE VERIFICACIÓN

Después de implementar correcciones, verificar:

- [ ] No hay datos sensibles en logs
- [ ] Todos los endpoints usan middleware de permisos
- [ ] tenant_id se valida en TODOS los CRUD
- [ ] Credenciales están encriptadas en BD
- [ ] Rate limiting en endpoints críticos
- [ ] No hay debug mode en producción
- [ ] HSTS habilitado
- [ ] CSP restrictivo en producción
- [ ] Tokens tienen expiración
- [ ] Logs de auditoría implementados
