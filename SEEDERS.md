# Documentación de Seeders Modulares

Los seeders han sido refactorizados para ser modulares y utilizar `updateOrInsert`, permitiendo ejecuciones repetidas sin duplicar datos.

## Orden de Ejecución (DatabaseSeeder)

1. **RoleSeeder**: Crea los roles `Administrador`, `Staff` y `Cliente`.
2. **TenantSeeder**: Crea un Tenant de demostración (`ISPWatch Main`).
3. **TypePlansSeeder**: Define los tipos de planes técnicos (`queue`, `pppoe`, `hotspot`, `pcq`).
4. **CutTypeSeeder**: Define los tipos de corte (`Automático`, `Manual`, `Sin Corte`).
5. **ServicePlanSeeder**: Crea planes de internet de ejemplo (10MB, 20MB, etc.).
6. **RouterSeeder**: Crea routers de prueba con coordenadas y configuración básica.
7. **UsersSeeder**: Crea usuarios base (Admin, Staff, Cliente Jorge) y sus perfiles asociados.
8. **CustomerSeeder**: (Opcional) Agrega más datos de clientes para pruebas de interfaz.

## Características Técnicas

- **Idempotencia**: Se usa `updateOrInsert` con llaves únicas (ID o código) para asegurar que el seeder pueda correr múltiples veces.
- **Relaciones**: Los seeders buscan IDs previos (como `type_plan_id`) para mantener la integridad referencial.
- **Uso de Entorno**: Diseñado para inicializar rápidamente un entorno local o de staging.

## Comando de Ejecución (Informativo)

`php artisan db:seed` (No ejecutar en este paso según restricciones).
