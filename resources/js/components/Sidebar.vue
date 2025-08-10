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


    <!-- Menú -->
    <nav class="flex-1 overflow-y-auto">
        <ul class="p-2">
            <li>
                <RouterLink
                to="/dashboard"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="md-dashboard-outlined" class="w-5 h-5" />
                <span>Dashboard</span>
                </RouterLink>
            </li>

            <!-- Ejemplo con submenú -->
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
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="pr-users" class="w-5 h-5" />
                <span>Staff</span>
                </RouterLink>
            </li>

            <li>
                <RouterLink
                to="/soporte"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="fa-headphones-alt" class="w-5 h-5" />
                <span>Soporte</span>
                </RouterLink>
            </li>

            <li>
                <RouterLink
                to="/configuracion"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="ri-settings-4-line" class="w-5 h-5" />
                <span>Configuración</span>
                </RouterLink>
            </li>

            <li>
                <RouterLink
                to="/manual"
                class="flex items-center gap-3 p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
                >
                <v-icon name="hi-book-open" class="w-5 h-5" />
                <span>Manual de Usuario</span>
                </RouterLink>
            </li>
        </ul>
    </nav>

    <!-- Footer -->
    <div class="p-4 border-t border-gray-200 dark:border-gray-700">
        <button
            class="flex items-center gap-3 w-full p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
        >
        <v-icon name="bi-log-out" class="w-5 h-5" />
        <span>Cerrar sesión</span>
        </button>
    </div>
    </aside>
</template>

<script setup>
import SubmenuItem from "./SubmenuItem.vue";
</script>
