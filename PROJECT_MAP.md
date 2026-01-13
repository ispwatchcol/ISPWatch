# Mapa del Proyecto (ISPWatch)

## 1. Resumen del Proyecto

Sistema de Gestión de Servicios de Internet (ISP) diseñado como una Single Page Application (SPA).
Permite la administración de clientes, tickets de soporte, gestión de routers, inventario, facturación y staff.
El backend está construido con **Laravel 12** sirve como API y el frontend con **Vue 3** (Composition API) + **Vite**.

## 2. Árbol de Directorios Principal

-   `app/`: Núcleo de la lógica backend.
    -   `Http/Controllers/`: Controladores que manejan las solicitudes API.
    -   `Models/`: Modelos Eloquent que representan las tablas de la BD.
-   `database/`:
    -   `migrations/`: Definiciones de estructura de base de datos.
    -   `seeders/`: Datos de prueba/iniciales.
-   `resources/`:
    -   `js/`: Código fuente de la aplicación Vue.
        -   `components/`: Componentes UI reutilizables (ej. Sidebar, SubmenuItem).
        -   `layouts/`: Estructuras de página (probablemente DefaultLayout).
        -   `pages/`: Vistas principales asociadas a rutas (Dashboard, Customers, Support).
        -   `router/`: Configuración de rutas del lado del cliente (`index.js`).
        -   `services/`: Módulos de lógica (autenticación, llamadas API).
        -   `app.js`: Punto de entrada del frontend.
-   `routes/`:
    -   `api.php`: Rutas para endpoints JSON (backend).
    -   `web.php`: Rutas web, principalmente para servir la SPA (catch-all).
-   `public/`: Directorio raíz web accesible públicamente (activos compilados, index.php).

## 3. Puntos de Entrada y Flujo

-   **Backend**: El servidor web dirige el tráfico a `public/index.php`.
    -   Rutas API (`/api/*`) van a `routes/api.php` y devuelven JSON.
    -   Cualquier otra ruta (`/*`) va a `routes/web.php`, que retorna la vista de la aplicación SPA.
-   **Frontend**: Al cargar la página, se ejecuta `resources/js/app.js`.
    1. Importa estilos globales (`app.css` + Tailwind).
    2. Registra plugins (Router, Supabase, Iconos).
    3. Monta la instancia de Vue en el elemento `#app`.
    4. El Router determina qué componente de `pages/` mostrar según la URL.

## 4. Componentes y Módulos Principales

-   **Autenticación**: Manejada vía API/Tokens (implícito en `services/auth.js`), con referencias a roles de usuario.
-   **Módulos de Negocio**:
    -   **Usuarios/Clientes**: Gestión y mapeo.
    -   **Infraestructura**: Routers, Planes, Sectoriales.
    -   **Inventario**: Equipos y stock.
    -   **Finanzas**: Facturación.
    -   **Soporte**: Tickets y seguimiento (con roles `staff`).
-   **UI**: Uso extensivo de `oh-vue-icons` para iconografía y componentes modulares (`v-icon`).

## 5. Configuración

-   **Backend**: Archivo `.env` para credenciales de BD (`DB_*`) y claves de aplicación (`APP_KEY`).
-   **Frontend**: `vite.config.js` maneja la compilación y plugins (Vue, imports automáticos).
-   **Estilos**: `tailwind.config.js` y `postcss.config.js` definen el sistema de diseño.
-   **Servicios Externos**: Claves de Supabase configuradas en entorno (`VITE_SUPABASE_*` probables).

## 6. Scripts y Comandos

-   **Desarrollo**:
    -   `npm run dev`: Ejecuta concurrentemente el servidor Laravel y Vite (recomendado).
-   **Construcción**:
    -   `npm run build`: Genera los assets estáticos para producción en `public/build/`.
-   **Backend**:
    -   `php artisan serve`: Inicia servidor de desarrollo PHP.
    -   `php artisan migrate`: Ejecuta migraciones de base de datos.
    -   `php artisan route:list`: Lista todas las rutas registradas.

## 7. Dependencias Externas

-   **Base de Datos**: Relacional (MySQL/MariaDB sugerido por Laravel estándar).
-   **Supabase**: Se utiliza el cliente `@supabase/supabase-js`, posiblemente para autenticación o base de datos en tiempo real.
-   **Frontend UI**:
    -   `tailwindcss`: Framework CSS utilitario.
    -   `oh-vue-icons`: Librería de iconos.
    -   `vue-router`: Enrutamiento SPA.
-   **HTTP**: `axios` para comunicación cliente-servidor.

## 8. Testing

-   **Backend**: Framework **PHPUnit** configurado (`phpunit.xml`).
    -   Tests ubicados en `tests/Feature` y `tests/Unit`.
    -   Ejecutar con: `php artisan test`.
-   **Frontend**: No se observa configuración explícita de tests unitarios JS (Jest/Vitest) en `package.json` (Pendiente por confirmar implementación).

## 9. Decisiones y Convenciones

-   **Vue Style**: Se utiliza **Composition API** con sintaxis `<script setup>`.
-   **Routing**: El frontend maneja la navegación completa; el backend sirve principalmente datos (API First).
-   **Iconografía**: Estandarizada con prefijos de colección (ej. `md-`, `bi-`, `pr-`) usando `oh-vue-icons`.
-   **Naming**:
    -   Archivos Vue en `PascalCase`.
    -   Clases CSS utilitarias (Tailwind).
