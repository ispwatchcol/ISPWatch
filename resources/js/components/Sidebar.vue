<template>
    <aside
        class="flex flex-col h-screen bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-700 w-64"
    >
<!-- Logo -->
    <div class="flex items-center gap-2 px-6 py-8 border-b dark:border-gray-600">
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
            <v-icon name="pr-user" class="h-6 w-6 text-gray-800 dark:text-gray-300 z-10" />
            <div class="ml-3 flex-1">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-100">Rol</p>
                <p variant="outline" class="text-xs font-bold text-gray-700 dark:text-gray-100">
                Nombre Usuario
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
                { name: 'Lista de usuarios', to: '/customers', icon: 'pr-users' },
                { name: 'Agregar usuario', to: '/customers/create', icon: 'pr-user-plus' },
                { name: 'Estadísticas', to: '/customers/statics', icon: 'md-dashboard-outlined' },
                { name: 'Mapa de usuarios', to: '/customers/map', icon: 'ri-map-pin-user-line' }
                ]"
            />

            <SubmenuItem
                icon="bi-router"
                title="Routers"
                :items="[
                { name: 'Lista de routers', to: '/routers', icon: 'bi-router' },
                { name: 'Agregar router', to: '/routers/create', icon: 'oi-diff-added' }
                ]"
            />

            <SubmenuItem
                icon="oi-package"
                title="Inventarios"
                :items="[
                { name: 'Lista de equipos', to: '/inventory/devices', icon: 'oi-package' },
                { name: 'Agregar equipo', to: '/inventory/devices/create', icon: 'oi-diff-added' }
                ]"
            />

            <SubmenuItem
                icon="ri-money-dollar-circle-line"
                title="Finanzas"
                :items="[
                { name: 'Facturación', to: '/billing', icon: 'la-money-bill-wave-solid' }
                ]"
            />

            <li>
                <RouterLink
                to="/users"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="pr-users" class="w-5 h-5 mr-1" />
                <span class="text-sm pt-1">Staff</span>
                </RouterLink>
            </li>

            <li>
                <RouterLink
                to="/support"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="md-supportagent-round" class="w-5 h-5 mr-1" />
                <span class="text-sm pt-1">Soporte</span>
                </RouterLink>
            </li>

            <li>
                <RouterLink
                to="/settings"
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
        <button class="flex items-center justify-center p-2 rounded-full text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700"
        >
            <v-icon name="bi-sun" class="h-4 w-4" />
        </button>
        <button class="flex items-center justify-center p-2 rounded-full text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700"
        >
            <v-icon name="bi-moon" class="h-4 w-4" />
        </button>
        <button class="flex items-center justify-center p-2 rounded-full text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700"
        >
            <v-icon name="md-screenshotmonitor" class="h-4 w-4" />
        </button>
        </div>
    </div>
        <button
            class="w-full p-2 rounded-full justify-start text-red-600 border border-red-200 hover:bg-red-50 dark:text-red-400 dark:border-red-800 dark:hover:bg-red-900/20 bg-transparent"
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
