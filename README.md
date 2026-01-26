# 🚀 ISPWatch

**La Solución Inteligente para la Gestión de tu ISP**

ISPWatch es una plataforma completa diseñada para optimizar, automatizar y escalar la administración de Proveedores de Servicios de Internet (ISP). Gestiona clientes, monitorea redes, administra facturación y automatiza suspensiones/reactivaciones, todo en un solo lugar.

---

## ✨ Características

- **Gestión de Clientes** - Asigna IPs, configura conexiones automáticamente y realiza seguimiento de servicios
- **Facturación Automática** - Genera facturas, automatiza cobros y suspende/reactiva servicios
- **Monitoreo en Tiempo Real** - Supervisa la red con alertas, gestiona VLANs y conexiones core
- **Sistema de Tickets** - Soporte al cliente con base de conocimientos y encuestas de satisfacción
- **Seguridad Avanzada** - Roles personalizados, auditoría de acciones y autenticación 2FA
- **Integración MikroTik** - Conexión con RouterBoards para gestión de red automatizada

---

## 🛠 Tecnologías

| Backend | Frontend | Base de Datos |
|---------|----------|---------------|
| PHP 8.2+ | Vue 3 | PostgreSQL (Supabase) |
| Laravel 12 | Vite | |
| Livewire | TailwindCSS | |
| Laravel Sanctum | Vue Router | |

---

## 📋 Requisitos Previos

- **PHP** >= 8.2
- **Composer** >= 2.0
- **Node.js** >= 18.x
- **NPM** >= 9.x
- **PostgreSQL** (o cuenta en Supabase)

---

## 🚀 Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/tuusuario/ISPWatch.git
cd ISPWatch
```

### 2. Instalar dependencias de PHP

```bash
composer install
```

### 3. Instalar dependencias de Node.js

```bash
npm install
```

### 4. Configurar variables de entorno

```bash
cp .env.example .env
```

Edita el archivo `.env` con tus credenciales:

```env
# Aplicación
APP_NAME=ISPWatch
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Base de Datos (PostgreSQL/Supabase)
DB_CONNECTION=pgsql
DB_HOST=tu-host-supabase.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_contraseña

# Supabase (Frontend)
VITE_SUPABASE_URL=https://tu-proyecto.supabase.co
VITE_SUPABASE_ANON_KEY=tu_anon_key

# VPN Portal (opcional)
PORTAL_IP=192.168.88.252

# Email (opcional)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=tu_email@gmail.com
MAIL_PASSWORD="tu_app_password"
MAIL_ENCRYPTION=tls
```

### 5. Generar clave de aplicación

```bash
php artisan key:generate
```

### 6. Ejecutar migraciones

```bash
php artisan migrate
```

### 7. Ejecutar seeders (datos iniciales)

```bash
php artisan db:seed
```

Esto creará:
- Roles: Administrador, Staff, Cliente
- Tenant de demostración
- Tipos de planes (queue, pppoe, hotspot, pcq)
- Tipos de corte
- Planes de servicio de ejemplo
- Routers de prueba
- Usuarios base (Admin, Staff, Cliente)

---

## ▶️ Ejecutar en Desarrollo

### Opción 1: Comando único (recomendado)

```bash
npm run dev
```

Esto inicia:
- Servidor Laravel (`php artisan serve --host=0.0.0.0`)
- Vite dev server para hot-reload

### Opción 2: Terminales separadas

```bash
# Terminal 1: Backend
php artisan serve

# Terminal 2: Frontend
npm run vite
```

### Opción 3: Composer dev (con cola y logs)

```bash
composer run dev
```

Accede a la aplicación en: **http://localhost:8000**

---

## 🏗️ Build para Producción

```bash
npm run build
```

Esto genera los assets optimizados en `public/build/`.

---

## 📁 Estructura del Proyecto

```
ISPWatch/
├── app/                    # Código PHP (Controllers, Models, Services)
│   ├── Http/Controllers/   # Controladores de la API
│   ├── Models/             # Modelos Eloquent
│   └── Services/           # Servicios (RouterApi, VPN, etc.)
├── config/                 # Configuración de Laravel
├── database/
│   ├── migrations/         # Migraciones de BD
│   └── seeders/            # Datos iniciales
├── resources/
│   ├── js/                 # Código Vue.js
│   │   ├── components/     # Componentes reutilizables
│   │   └── pages/          # Páginas de la aplicación
│   ├── css/                # Estilos
│   └── views/              # Vistas Blade
├── routes/
│   ├── api.php             # Rutas de API
│   └── web.php             # Rutas web
├── public/                 # Assets públicos
└── storage/                # Logs y archivos generados
```

---

## 🔐 Usuarios por Defecto

Después de ejecutar los seeders, puedes acceder con:

| Rol | Usuario | 
|-----|---------|
| Administrador | (revisar seeder para credenciales) |
| Staff | (revisar seeder para credenciales) |
| Cliente | (revisar seeder para credenciales) |

---

## 🧪 Testing

```bash
php artisan test
```

O con configuración limpia:

```bash
composer run test
```

---

## 📞 Soporte

¿Preguntas o problemas? Abre un issue en el repositorio o contacta al equipo de desarrollo.

---

## 📄 Licencia

Este proyecto está bajo la Licencia MIT.
