# 🚀 INSTRUCCIONES DE DEPLOY - IMPLEMENTACIONES DE SEGURIDAD

**Fecha:** 2026-05-14
**Versión:** 1.0

---

## ⚠️ IMPORTANTE ANTES DE INICIAR

1. **RESPALDAR LA BASE DE DATOS:**
```bash
# PostgreSQL
pg_dump -U username databasename > backup_$(date +%Y%m%d_%H%M%S).sql
```

2. **RESPALDAR APP_KEY:**
```bash
# Guarda tu .env en lugar seguro
cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
```

3. **NO CAMBIES APP_KEY DURANTE DEPLOYMENT**
   - Las credenciales encriptadas dependen de APP_KEY
   - Cambiarla sin rotación hará que sean irrecuperables

---

## 📋 PASOS DE INSTALACIÓN

### Paso 1: Aplicar Cambios del Código
```bash
# Asegúrate de estar en el directorio del proyecto
cd /path/to/ISPWatch

# Hacer pull de los cambios
git pull origin axelcanobranches2

# Instalar cualquier dependencia nueva
composer install
```

### Paso 2: Ejecutar Migraciones
```bash
# En desarrollo/staging:
php artisan migrate

# En producción (con confirmación):
php artisan migrate --force

# Si necesitas ver qué se va a migrar:
php artisan migrate:status
```

### Paso 3: Limpiar Cache
```bash
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Paso 4: Verificar Instalación
```bash
# Ver que las migraciones se ejecutaron correctamente
php artisan migrate:status

# Verificar que la tabla audit_logs existe
php artisan tinker
>>> DB::table('audit_logs')->count()
=> 0

# Salir de tinker
>>> exit
```

---

## 🔐 VERIFICACIÓN DE SEGURIDAD POST-DEPLOY

### 1. Verificar Encriptación de Credenciales
```bash
php artisan tinker

# Verificar que las credenciales se guardan encriptadas
$router = App\Models\Router::first();
echo $router->vpn_password_encrypted;
// Debe mostrar algo como: eyJpdiI6IkF...

# Verificar que se desencriptan automáticamente
echo $router->vpn_password_encrypted; // Debe mostrar el valor desencriptado

exit
```

### 2. Verificar Auditoría
```bash
# Hacer una acción que genere auditoría (ej: crear/actualizar usuario)
# Verificar que se registró:

php artisan tinker
>>> App\Models\AuditLog::latest()->first()
=> App\Models\AuditLog {#4
     id: 1,
     user_id: 1,
     action: "create_user",
     ...
   }
exit
```

### 3. Verificar tenant_id Validation
```bash
# Intentar acceder a usuario de otro tenant debe fallar
curl -H "Authorization: Bearer TOKEN_TENANT1" \
     http://localhost:8000/api/staff/USER_ID_TENANT2

# Debe devolver 403 Forbidden
```

### 4. Verificar Middleware de Permisos
```bash
# Usuario sin permiso debe ser rechazado
curl -X POST \
     -H "Authorization: Bearer TOKEN_WITHOUT_PERMISSION" \
     http://localhost:8000/api/customers/1/suspend

# Debe devolver 403 Forbidden
```

---

## ⚠️ POSIBLES PROBLEMAS Y SOLUCIONES

### Problema 1: "Column does not exist" Error
```
SQLSTATE[42703]: Undefined column: 7 ERROR: column "vpn_password_encrypted" does not exist
```

**Solución:**
```bash
# Asegúrate que las migraciones se ejecutaron
php artisan migrate:status

# Si no aparecen las nuevas migraciones:
php artisan migrate:refresh --seed # SOLO EN DEV!
```

### Problema 2: "Decryption failed" Error
```
Illuminate\Encryption\DecryptException: The payload is invalid.
```

**Solución:**
- Verifica que APP_KEY no cambió
- Verifica que APP_CIPHER es correcto en .env (debe ser "AES-256-CBC")

### Problema 3: Credenciales No Se Guardan Encriptadas
```php
$router->vpn_password_encrypted = "plain_text"; // ❌
$router->vpn_password_encrypted = $plaintext; // ✅
// Laravel automáticamente lo encripta al guardar
```

### Problema 4: Tests Fallan Después de Migraciones
```bash
# Regenerar test database:
php artisan migrate:fresh --env=testing
php artisan test
```

---

## 🔄 ROLLBACK (Si algo falla)

### Opción 1: Rollback de Migraciones
```bash
# Revertir última migración
php artisan migrate:rollback

# Revertir todas las migraciones
php artisan migrate:reset

# Revertir y re-migrar
php artisan migrate:refresh
```

### Opción 2: Restaurar desde Backup
```bash
# Restaurar base de datos
psql -U username databasename < backup_YYYYMMDD_HHMMSS.sql

# Restaurar .env
cp .env.backup.YYYYMMDD_HHMMSS .env

# Limpiar cache
php artisan cache:clear
```

---

## 📝 CAMBIOS QUE REQUIEREN ATENCIÓN ESPECIAL

### 1. Referencias a Credenciales en Código
**Buscar y actualizar:**
```bash
grep -r "->user_rb[^_]" app/
grep -r "->password_rb[^_]" app/
grep -r "->vpn_password[^_]" app/
grep -r "->vpn_username[^_]" app/
```

**Actualizar a:**
```php
// Usar las nuevas columnas encriptadas
$router->user_rb_encrypted
$router->password_rb_encrypted
$router->vpn_username_encrypted
$router->vpn_password_encrypted
```

### 2. Logs que Contienen Credenciales
```bash
# Buscar referencias a credenciales en logs
grep -r "password_length\|user_rb\|vpn_password" storage/logs/
```

**Limpiar logs antiguos:**
```bash
rm storage/logs/*.log
```

---

## 🧪 TESTING POST-DEPLOY

### Test de Seguridad Básico
```bash
#!/bin/bash

echo "Testing Security Implementation..."

# Test 1: tenant_id validation
echo "Test 1: Tenant isolation..."
curl -s -H "Authorization: Bearer $TOKEN" \
     http://localhost:8000/api/staff/999 | grep -q "Unauthorized" && echo "✅ PASS" || echo "❌ FAIL"

# Test 2: Permission middleware
echo "Test 2: Permission checking..."
curl -s -X POST -H "Authorization: Bearer $TOKEN_LIMITED" \
     http://localhost:8000/api/customers/1/suspend | grep -q "Forbidden" && echo "✅ PASS" || echo "❌ FAIL"

# Test 3: Encrypted credentials
echo "Test 3: Credential encryption..."
php artisan tinker --execute="App\Models\Router::first()->vpn_password_encrypted" | grep -q "eyJ" && echo "✅ PASS" || echo "❌ FAIL"

# Test 4: Audit logging
echo "Test 4: Audit logging..."
php artisan tinker --execute="App\Models\AuditLog::count()" | grep -q "[1-9]" && echo "✅ PASS" || echo "❌ FAIL"

echo "Security Testing Complete!"
```

---

## 📊 MONITOREO POST-DEPLOY

### 1. Monitorear Errores de Encriptación
```bash
# Monitorear logs en tiempo real
tail -f storage/logs/laravel.log | grep -i "encrypt\|decrypt\|failed"
```

### 2. Monitorear Auditoría
```bash
php artisan tinker
>>> App\Models\AuditLog::latest()->take(10)->get();
```

### 3. Monitorear Permisos
```bash
# Buscar 403 Forbidden en logs
tail -f storage/logs/laravel.log | grep "403\|Forbidden"
```

---

## ✅ CHECKLIST POST-DEPLOY

- [ ] Todas las migraciones completadas exitosamente
- [ ] No hay errores en `php artisan migrate:status`
- [ ] Tabla `audit_logs` existe y funciona
- [ ] Credenciales se guardan encriptadas
- [ ] tenant_id se valida correctamente
- [ ] Middleware de permisos funciona
- [ ] HSTS está habilitado en producción
- [ ] No hay datos sensibles en logs
- [ ] Tests pasan
- [ ] Performance es aceptable
- [ ] Backups están actualizados

---

## 🆘 SOPORTE Y CONTACTO

Si encuentras problemas:

1. Revisa `SECURITY_IMPLEMENTATION_SUMMARY.md` para detalles técnicos
2. Consulta logs: `tail -f storage/logs/laravel.log`
3. Verifica migraciones: `php artisan migrate:status`
4. Busca en auditoría: `App\Models\AuditLog::latest()->get()`

---

## 📞 CONTACTO PARA EMERGENCIAS

Si hay un problema crítico y necesitas revertir:
1. Restaura el backup de BD
2. Restaura el .env.backup
3. Limpia el cache: `php artisan cache:clear`
4. Contacta al equipo de seguridad

**Última Actualización:** 2026-05-14
**Versión de Laravel:** 11.x
**PHP:** 8.2+
