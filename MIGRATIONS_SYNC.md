# Migraciones de Sincronización (Post-Cambios Manuales)

Este documento detalla los cambios capturados en las migraciones para sincronizar el estado manual de la base de datos en Supabase con el código del repositorio.

## Cambios Realizados (`2026_01_20_232000_sync_database_manual_changes.php`)

### 1. Columna `tenant_id` y Claves Foráneas

Se detectó que varias tablas carecían de la columna `tenant_id` o de su restricción de clave foránea, lo cual es crítico para la arquitectura multi-tenant:

- **Tablas afectadas:** `router`, `sectorial`, `inventory_stock`, `inventory_provider`, `inventory_branch`, `inventory_device`, `ip_range`, `billing`.
- **Acción:** Se agregó la columna (si no existía) y se creó la relación `foreign key` hacia la tabla `tenant`.

### 2. Sincronización de `service_plan`

- Se agregó la restricción de clave foránea para `tenant_id` (la columna ya había sido agregada en una migración previa pero faltaba el constraint en algunos entornos).

### 3. Sincronización de `router`

- Se aseguró la existencia de la columna `wan_interface` mediante verificación de esquema, evitando conflictos con cambios manuales previos.

### 4. Verificaciones de Seguridad

- Todas las operaciones utilizan `Schema::hasTable()` y `Schema::hasColumn()` para permitir su ejecución en entornos donde algunos cambios ya se aplicaron manualmente sin generar errores.
