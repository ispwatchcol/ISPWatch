<template>
    <aside
        class="flex flex-col h-screen bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-700 w-64"
    >
        <!-- Logo -->
        <div class="px-6 py-8 border-b dark:border-gray-800">
            <div class="flex items-center gap-3">
                <div class="relative group">
                    <div class="absolute -inset-1 bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl blur opacity-25 group-hover:opacity-50 transition duration-200"></div>
                    <div class="relative w-10 h-10 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-md">
                        <v-icon name="md-router-round" class="h-6 w-6 text-white" />
                    </div>
                </div>
                <div>
                    <h1 class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-gray-900 to-gray-700 dark:from-white dark:to-gray-300">
                        ISPWATCH
                    </h1>
                    <p class="text-[10px] font-semibold tracking-wider text-gray-500 dark:text-gray-400 uppercase">
                        Sistema de Gestión
                    </p>
                </div>
            </div>
        </div>

        <!-- User Quick Info -->
        <div class="p-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/30">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
                    <v-icon
                        name="bi-person"
                        class="h-5 w-5 text-gray-500 dark:text-white"
                    />
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate">
                        {{ user?.role_name }}
                    </p>
                    <p class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate">
                        {{ user?.user_name }} {{ user?.user_lastname }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Menú -->
        <nav class="flex-1 overflow-y-auto p-4">
            <ul class="p-2">
            <!-- Dashboard - always visible -->
                <li v-if="canSee.dashboard">
                    <RouterLink
                        to="/"
                        class="group flex items-center gap-3 p-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/50 text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all duration-200"
                        active-class="bg-indigo-50 dark:bg-indigo-900/10 text-indigo-600 dark:text-indigo-400"
                    >
                        <v-icon
                            name="md-dashboard-outlined"
                            class="w-5 h-5 group-hover:scale-110 transition-transform duration-200 dark:text-white dark:group-hover:text-indigo-400"
                        />
                        <span class="text-sm font-medium">Dashboard</span>
                    </RouterLink>
                </li>

                <SubmenuItem
                    v-if="canSee.usuarios"
                    icon="bi-people"
                    title="Usuarios"
                    :items="usuariosItems"
                />

                <SubmenuItem
                    v-if="canSee.gestion"
                    icon="ri-list-settings-line"
                    title="Gestión"
                    :items="gestionItems"
                />

                <SubmenuItem
                    v-if="canSee.inventarios"
                    icon="oi-package"
                    title="Inventarios"
                    :items="inventariosItems"
                />

                <SubmenuItem
                    v-if="canSee.finanzas"
                    icon="ri-money-dollar-circle-line"
                    title="Finanzas"
                    :items="finanzasItems"
                />

                <!-- Staff -->
                <li v-if="canSee.staff">
                    <RouterLink
                        to="/staff"
                        class="group flex items-center gap-3 p-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/50 text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all duration-200"
                        active-class="bg-indigo-50 dark:bg-indigo-900/10 text-indigo-600 dark:text-indigo-400"
                    >
                        <v-icon name="bi-people" class="w-5 h-5 group-hover:scale-110 transition-transform duration-200 dark:text-white dark:group-hover:text-indigo-400" />
                        <span class="text-sm font-medium">Staff</span>
                    </RouterLink>
                </li>

                <!-- Administración de Roles -->
                <li v-if="canSee.staff && isAdmin">
                    <RouterLink
                        to="/roles"
                        class="group flex items-center gap-3 p-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/50 text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all duration-200"
                        active-class="bg-indigo-50 dark:bg-indigo-900/10 text-indigo-600 dark:text-indigo-400"
                    >
                        <v-icon name="md-adminpanelsettings-round" class="w-5 h-5 group-hover:scale-110 transition-transform duration-200 dark:text-white dark:group-hover:text-indigo-400" />
                        <span class="text-sm font-medium">Roles</span>
                    </RouterLink>
                </li>

                <SubmenuItem
                    v-if="isStaff && supportItems.length > 0"
                    icon="md-supportagent-round"
                    title="Soporte"
                    :items="supportItems"
                />

                <!-- Configuración -->
                <li v-if="canSee.configuracion">
                    <RouterLink
                        to="/settings"
                        class="group flex items-center gap-3 p-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/50 text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all duration-200"
                        active-class="bg-indigo-50 dark:bg-indigo-900/10 text-indigo-600 dark:text-indigo-400"
                    >
                        <v-icon
                            name="ri-settings-4-line"
                            class="w-5 h-5 group-hover:scale-110 transition-transform duration-200 dark:text-white dark:group-hover:text-indigo-400"
                        />
                        <span class="text-sm font-medium">Configuración</span>
                    </RouterLink>
                </li>

                <!-- Manual -->
                <li v-if="canSee.manual">
                    <RouterLink
                        to="/manual"
                        class="group flex items-center gap-3 p-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/50 text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all duration-200"
                        active-class="bg-indigo-50 dark:bg-indigo-900/10 text-indigo-600 dark:text-indigo-400"
                    >
                        <v-icon name="hi-book-open" class="w-5 h-5 group-hover:scale-110 transition-transform duration-200 dark:text-white dark:group-hover:text-indigo-400" />
                        <span class="text-sm font-medium">Manual de Usuario</span>
                    </RouterLink>
                </li>
                <!-- Acciones Masivas -->
                <li v-if="canSee.accionesMasivas">
                    <RouterLink
                        to="/mass-actions"
                        class="group flex items-center gap-3 p-2.5 rounded-xl hover:bg-red-50 dark:hover:bg-red-900/20 text-red-700 dark:text-red-400 transition-all duration-200"
                        active-class="bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400"
                    >
                        <icon-lucide-scissors class="w-5 h-5 group-hover:scale-110 transition-transform duration-200" />
                        <span class="text-sm font-medium">Acciones Masivas</span>
                    </RouterLink>
                </li>

            </ul>
        </nav>

        <!-- Footer -->
        <div class="px-4 py-4 border-t border-gray-100 dark:border-gray-800 space-y-4 bg-white dark:bg-gray-900">
            <!-- Timezone Clock -->
            <TimezoneClock :timezone="tenantTimezone" />
            
            <div class="space-y-3">
                <label
                    class="text-sm font-medium text-gray-700 dark:text-gray-300"
                    >Tema</label
                >
                <div class="grid grid-cols-3 gap-2 bg-gray-50 dark:bg-gray-800 p-1 rounded-xl">
                    <button
                        @click="setTheme('light')"
                        class="flex items-center justify-center p-1.5 rounded-lg transition-all duration-200"
                        :class="theme === 'light' ? 'bg-white dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                    >
                        <v-icon name="bi-sun" class="h-4 w-4" />
                    </button>
                    <button
                        @click="setTheme('dark')"
                        class="flex items-center justify-center p-1.5 rounded-lg transition-all duration-200"
                        :class="theme === 'dark' ? 'bg-white dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                    >
                        <v-icon name="bi-moon" class="h-4 w-4" />
                    </button>
                    <button
                        @click="setTheme('system')"
                        class="flex items-center justify-center p-1.5 rounded-lg transition-all duration-200"
                        :class="theme === 'system' ? 'bg-white dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                    >
                        <v-icon name="md-screenshotmonitor" class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <button
                @click="logout"
                class="w-full p-2 rounded-full justify-start text-red-600 border border-red-200 hover:bg-red-50 dark:text-red-400 dark:border-red-800 dark:hover:bg-red-900/20 bg-transparent flex items-center"
            >
                <v-icon name="md-logout-twotone" class="w-4 h-4 mr-2" />
                Cerrar sesión
            </button>
        </div>
    </aside>
</template>

<script setup>
import { ref, onMounted, computed } from "vue";
import SubmenuItem from "./SubmenuItem.vue";
import TimezoneClock from "./TimezoneClock.vue";
import { hasPermission, isStaffOrAdmin } from "../services/auth";
import { apiClient } from "../services/api";
import api from "../services/api";
import axios from "axios";

const user = ref({});
const theme = ref("system");
const tenantTimezone = ref("America/Bogota");

// ─── Acciones Masivas ─── (moved to /mass-actions page)

const isStaff = computed(() => isStaffOrAdmin());

const isAdmin = computed(() => {
    const role = user.value?.role_name || user.value?.role?.name || '';
    return role.toLowerCase() === 'administrador';
});

const supportItems = computed(() => {
    const items = [];
    
    if (hasPermission('support.view') || hasPermission('support.view.own')) {
        items.push({
            name: 'Tickets',
            to: '/support',
            icon: 'hi-ticket',
        });
    }
    
    if (hasPermission('support.create')) {
        items.push({
            name: 'Nuevo Ticket',
            to: '/support/create',
            icon: 'oi-diff-added',
        });
    }
    
    if (hasPermission('support.statistics')) {
        items.push({
            name: 'Estadísticas',
            to: '/support/statistics',
            icon: 'md-dashboard-outlined',
        });
    }
    
    return items;
});

const canSee = computed(() => ({
    dashboard:       hasPermission('dashboard.view'),
    usuarios:        hasPermission('customers.view'),
    gestion:         hasPermission('routers.view'),
    inventarios:     hasPermission('inventory.view'),
    finanzas:        hasPermission('billing.view'),
    staff:           hasPermission('staff.view'),
    configuracion:   hasPermission('settings.view'),
    manual:          true,
    accionesMasivas: hasPermission('mass_actions.execute'),
}));

// ─── Submenú: Usuarios ───
const usuariosItems = computed(() => {
    const items = [];
    if (hasPermission('customers.view'))
        items.push({ name: 'Lista de usuarios', to: '/customers', icon: 'bi-people' });
    if (hasPermission('customers.create'))
        items.push({ name: 'Agregar usuario', to: '/customers/create', icon: 'bi-person-plus' });
    if (hasPermission('customers.stats'))
        items.push({ name: 'Estadísticas', to: '/customers/statistics', icon: 'md-dashboard-outlined' });
    if (hasPermission('customers.map'))
        items.push({ name: 'Mapa de usuarios', to: '/customers/map', icon: 'ri-map-pin-user-line' });
    return items;
});

// ─── Submenú: Gestión ───
const gestionItems = computed(() => {
    const items = [];
    if (hasPermission('routers.view'))
        items.push({ name: 'Lista de Routers', to: '/routers', icon: 'bi-router' });
    if (hasPermission('plans.view'))
        items.push({ name: 'Plan de Internet', to: '/planes', icon: 'bi-speedometer2' });
    if (hasPermission('sectorials.view'))
        items.push({ name: 'Sectoriales', to: '/sectorials', icon: 'bi-broadcast-pin' });
    return items;
});

// ─── Submenú: Inventarios ───
const inventariosItems = computed(() => {
    const items = [];
    if (hasPermission('inventory.view'))
        items.push({ name: 'Lista de equipos', to: '/inventory', icon: 'bi-hdd-network' });
    if (hasPermission('inventory.create'))
        items.push({ name: 'Agregar equipo', to: '/inventory/create', icon: 'oi-diff-added' });
    if (hasPermission('inventory.view'))
        items.push({ name: 'Stock / Modelos', to: '/inventory/stocks', icon: 'md-inventory-round' });
    if (hasPermission('inventory.view'))
        items.push({ name: 'Proveedores', to: '/inventory/providers', icon: 'bi-building' });
    if (hasPermission('inventory.view'))
        items.push({ name: 'Sucursales', to: '/inventory/branches', icon: 'md-storemalldirectory' });
    return items;
});

// ─── Submenú: Finanzas ───
const finanzasItems = computed(() => {
    const items = [];
    if (hasPermission('billing.view'))
        items.push({ name: 'Resumen', to: '/billing/dashboard', icon: 'md-dashboard-outlined' });
    if (hasPermission('billing.view'))
        items.push({ name: 'Facturación', to: '/billing/invoices', icon: 'la-money-bill-wave-solid' });
    if (hasPermission('billing.view'))
        items.push({ name: 'Pagos / Recaudos', to: '/billing/payments', icon: 'md-payments-outlined' });
    if (hasPermission('billing.view'))
        items.push({ name: 'Formas de Pago', to: '/billing/payment-methods', icon: 'ri-bank-card-line' });
    return items;
});

onMounted(() => {
    const localData = localStorage.getItem("userData");
    const sessionData = sessionStorage.getItem("userData");

    const storedJson = localData || sessionData;

    if (storedJson) {
        try {
            user.value = JSON.parse(storedJson);
        } catch (e) {
            console.error("Error parseando userData:", e);
            user.value = {};
        }
    }

    // Cargar tema guardado o usar el sistema
    const savedTheme = localStorage.getItem("theme") || "system";
    setTheme(savedTheme);
    
    // Load tenant timezone
    loadTenantTimezone();
});

const setTheme = (mode) => {
    theme.value = mode;
    localStorage.setItem("theme", mode);

    const root = document.documentElement;
    if (mode === "dark") {
        root.classList.add("dark");
    } else if (mode === "light") {
        root.classList.remove("dark");
    } else if (mode === "system") {
        if (window.matchMedia("(prefers-color-scheme: dark)").matches) {
            root.classList.add("dark");
        } else {
            root.classList.remove("dark");
        }
    }
};

const loadTenantTimezone = async () => {
    try {
        if (!user.value?.tenant_id) return;
        
        const response = await apiClient.get(`/tenants/${user.value.tenant_id}`);
        
        if (response.data.success && response.data.data) {
            tenantTimezone.value = response.data.data.zone_tenant || 'America/Bogota';
        }
    } catch (error) {
        console.error('Error loading tenant timezone:', error);
    }
};

const logout = () => {
    localStorage.removeItem("isLoggedIn");
    localStorage.removeItem("userData");
    sessionStorage.removeItem("isLoggedIn");
    sessionStorage.removeItem("userData");
    window.location.replace("/");
};
</script>
<style scoped>
/* Custom Scrollbar */
nav::-webkit-scrollbar {
    width: 4px;
}

nav::-webkit-scrollbar-track {
    background: transparent;
}

nav::-webkit-scrollbar-thumb {
    background-color: #e5e7eb; /* gray-200 */
    border-radius: 20px;
}

.dark nav::-webkit-scrollbar-thumb {
    background-color: #374151; /* gray-700 */
}

nav:hover::-webkit-scrollbar-thumb {
    background-color: #d1d5db; /* gray-300 */
}

.dark nav:hover::-webkit-scrollbar-thumb {
    background-color: #4b5563; /* gray-600 */
}
</style>
