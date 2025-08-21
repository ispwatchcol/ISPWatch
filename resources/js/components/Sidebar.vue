<template>
    <aside
        class="flex flex-col h-screen bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-700 w-64"
    >
<!-- Logo -->
    <div class="flex items-center gap-2 px-6 py-4 border-b border-gray-200">
        <div className="w-10 h-10 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-lg flex items-center justify-center">
            <v-icon name="md-router-round" class="h-6 w-6 text-white" />
        </div>
        <div class="ml-3">
            <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">ISPWATCH</h1>
            <p class="text-xs text-gray-500 dark:text-gray-400">Sistema de Gestión</p>
        </div>
    </div>

    <!-- info rápida del usuario actual -->
    <div class="p-4 border-b border-gray-200 dark:border-gray-700">
        <div class="flex items-center">
            <div class="w-8 h-8 bg-gray-300 dark:bg-gray-600 rounded-full flex items-center justify-center">
                <v-icon name="pr-user" class="h-4 w-4 text-gray-600 dark:text-gray-300" />
            </div>
            <div class="ml-3 flex-1">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-100">Admin</p>
                <p variant="outline" class="text-xs font-bold text-gray-700 dark:text-gray-100">
                Administrador principal
                </p>
            </div>
        </div>
    </div>


    <!-- Menú -->
    <nav class="flex-1 overflow-y-auto p-4">
        <ul class="p-2">
            <li>
                <RouterLink
                to="/"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="md-dashboard-outlined" class="w-5 h-5 mr-1" />
                <span class="text-sm pt-1">Dashboard</span>
                </RouterLink>
            </li>

            <!-- Submenú -->
            <SubmenuItem
                icon="pr-users"
                title="Usuarios"
                :items="[
                { name: 'Lista de usuarios', to: '/usuarios', icon: 'pr-users' },
                { name: 'Agregar usuario', to: '/usuarios/nuevo', icon: 'pr-user-plus' },
                { name: 'Estadísticas', to: '/usuarios/estadisticas', icon: 'md-dashboard-outlined' },
                { name: 'Mapa de usuarios', to: '/usuarios/mapa', icon: 'ri-map-pin-user-line' }
                ]"
            />

            <SubmenuItem
                icon="bi-router"
                title="Routers"
                :items="[
                { name: 'Lista de routers', to: '/routers', icon: 'bi-router' },
                { name: 'Agregar router', to: '/routers/nuevo', icon: 'oi-diff-added' }
                ]"
            />

            <SubmenuItem
                icon="oi-package"
                title="Inventarios"
                :items="[
                { name: 'Lista de equipos', to: '/inventarios', icon: 'oi-package' },
                { name: 'Agregar equipo', to: '/inventarios/nuevo', icon: 'oi-diff-added' }
                ]"
            />

            <SubmenuItem
                icon="ri-money-dollar-circle-line"
                title="Finanzas"
                :items="[
                { name: 'Facturación', to: '/facturacion', icon: 'la-money-bill-wave-solid' }
                ]"
            />

            <li>
                <RouterLink
                to="/Staff"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="pr-users" class="w-5 h-5 mr-1" />
                <span class="text-sm pt-1">Staff</span>
                </RouterLink>
            </li>

            <li>
                <RouterLink
                to="/soporte"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="fa-headphones-alt" class="w-5 h-5 mr-1" />
                <span class="text-sm pt-1">Soporte</span>
                </RouterLink>
            </li>

            <li>
                <RouterLink
                to="/configuracion"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="ri-settings-4-line" class="w-5 h-5 mr-1" />
                <span class="text-sm pt-1">Configuración</span>
                </RouterLink>
            </li>

            <li>
                <RouterLink
                to="/manual"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="hi-book-open" class="w-5 h-5 mr-1" />
                <span class="text-sm pt-1">Manual de Usuario</span>
                </RouterLink>
            </li>
        </ul>
    </nav>

    <!-- Footer -->
    <div class="p-4 border-t border-gray-200 dark:border-gray-700 space-y-4">
    <div class="space-y-3">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Tema</label>
        <div class="grid grid-cols-3 gap-2">
        <button class="flex items-center justify-center"
        >
            <v-icon name="bi-sun backgr" class="h-4 w-4" />
        </button>
        <button class="flex items-center justify-center"
        >
            <v-icon name="bi-moon" class="h-4 w-4" />
        </button>
        <button class="flex items-center justify-center"
        >
            <v-icon name="md-screenshotmonitor" class="h-4 w-4" />
        </button>
        </div>
    </div>
        <button
            class="w-full justify-start text-red-600 border-red-200 hover:bg-red-50 dark:text-red-400 dark:border-red-800 dark:hover:bg-red-900/20 bg-transparent"
        >
        <v-icon name="md-logout-twotone" class="w-4 h-4 mr-2" />
        Cerrar sesión
        </button>
    </div>
    </aside>
</template>

<script setup>
import SubmenuItem from "./SubmenuItem.vue";
</script>
