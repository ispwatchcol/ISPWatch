<template>
    <div class="container mx-auto p-6 space-y-6">
        <!-- Encabezado -->
        <div class="flex items-center justify-between">
            <div>
            <h1 class="text-3xl font-semibold text-gray-700 dark:text-white">
                Dashboard
            </h1>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Bienvenido de vuelta, {{user.name}}</p>
            </div>
            <div class="flex items-center space-x-2">
            <div variant="outline" class="flex items-center space-x-2 bg-green-50 text-green-700 border-green-200">
                <v-icon name="bi-activity" class="h-3 w-3 mr-1" />
                <p>Sistema Activo</p>
            </div>
            <button variant="outline" size="sm" class="flex items-center space-x-2">
                <v-icon name="fa-regular-bell" class="h-4 w-4" />
                <p>Notificaciones</p>
            </button>
            </div>
        </div>

        <!-- cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div
                v-for="card in cards"
                :key="card.title"
                class="rounded-lg border-none bg-card text-card-foreground shadow-sm hover:shadow-lg transition-shadow"
                >
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm text-muted-foreground font-medium text-gray-600 dark:text-gray-400">{{ card.title }}</h3>
                            <p class="text-2xl font-semibold text-gray-700 dark:text-white m-2 ml-0">{{ card.value }}</p>
                            <p class="text-xs text-muted-foreground">{{ card.description }}</p>
                        </div>
                        <div class="p-3 rounded-full bg-gray-100 dark:bg-gray-800">
                            <v-icon :name="card.icon" class="w-6 h-6 text-blue-600" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- quick actions -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="rounded-lg border-none bg-card text-card-foreground shadow-sm lg:col-span-2">
                <div class="flex flex-col space-y-1.5 p-6">
                    <h3 class="text-2xl font-semibold text-gray-700 dark:text-white leading-none tracking-tight flex items-center space-x-2">
                        <v-icon name="ri-settings-4-line" class="w-5 h-5" />
                        <span>Acciones rápidas</span>
                    </h3>
                    <p class="text-sm text-muted-foreground">Accede rápidamente a las funciones más utilizadas</p>
                </div>
                <div class="p-6 pt-0">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div
                        v-for="action in actions"
                        :key="action.id"
                        class="w-full h-auto justify-start rounded-lg hover:bg-blue-50 hover:border-blue-200 transition-colors bg-transparent"
                        >
                            <a href="">
                                <button class="inline-flex items-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border-none border-input hover:text-accent-foreground w-full h-auto p-4 justify-start hover:bg-blue-50 hover:border-blue-200 transition-colors bg-transparent">
                                    <v-icon :name="action.icon" class="w-5 h-5 mr-3 text-blue-600" />
                                    <span>{{ action.name }}</span>
                                <span class="text-sm text-muted-foreground">{{ action.date }}</span>
                                </button>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- last activities -->
            <div class="rounded-lg border-none bg-card text-card-foreground shadow-sm">
                <div class="flex flex-col space-y-1.5 p-4">
                    <h3 class="text-2xl font-semibold text-gray-700 dark:text-white leading-none tracking-tight flex items-center space-x-2">
                        <v-icon name="bi-activity" class="w-5 h-5" />
                        <span>Últimas Actividades</span>
                    </h3>
                    <p class="text-sm text-muted-foreground">Últimas acciones en el sistema</p>
                </div>
                <div class="p-6 pt-0">
                    <div 
                        v-for="(activity, index) in activities"
                        :key="index"
                        class="flex items-start space-x-3"
                    >
                        <!-- estilo del punto -->
                        <div
                            class="w-2 h-2 rounded-full mt-2"
                            :class="{
                                'bg-green-500': activity.type === 'success',
                                'bg-yellow-500': activity.type === 'warning',
                                'bg-red-500': activity.type === 'error',
                            }"
                        />
                        <!-- mostrar la información de la actividad -->
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ activity.action }}
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ activity.user }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                {{ activity.time }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- system status -->
        <div class="rounded-lg border-none bg-card text-card-foreground shadow-sm">
            <div class="flex flex-col space-y-1.5 p-6">
                <h3 class="text-2xl font-semibold text-gray-700 dark:text-white leading-none tracking-tight flex items-center space-x-2">
                    <v-icon name="hi-wifi" class="w-5 h-5" />
                    <span>Estado del Sistema</span>
                </h3>
                <p class="text-sm text-muted-foreground">Monitoreo en tiempo real de la infraestructura</p>
            </div>
            <div class="p-6 pt-0">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <v-icon name="hi-wifi" class="h-8 w-8 text-green-600" />
                        </div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">Red Principal</h3>
                        <p class="text-sm text-green-600 mt-1">Operativa</p>
                        <p class="text-xs text-gray-500 mt-1">99.9% uptime</p>
                    </div>
                    <div class="text-center">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3">
                        <v-icon name="bi-activity" class="h-8 w-8 text-blue-600" />
                        </div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">Servidores</h3>
                        <p class="text-sm text-blue-600 mt-1">Estables</p>
                        <p class="text-xs text-gray-500 mt-1">CPU: 45% | RAM: 62%</p>
                    </div>

                    <div class="text-center">
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-3">
                        <v-icon name="ri-map-pin-line" class="h-8 w-8 text-purple-600" />
                        </div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">Cobertura</h3>
                        <p class="text-sm text-purple-600 mt-1">Óptima</p>
                        <p class="text-xs text-gray-500 mt-1">5 antenas activas</p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>

<script setup>
import { ref } from "vue";
import { OhVueIcon, addIcons } from "oh-vue-icons";
import {
    PrUsers,
    FaDollarSign,
    OiAlert,
    HiTrendingUp,
    BiActivity,
    RiMapPinLine,
    RiSettings4Line,
    FaRegularBell,
    IoCalendar,
} from "oh-vue-icons/icons";

addIcons(
    PrUsers,
    FaDollarSign,
    OiAlert,
    HiTrendingUp,
    BiActivity,
    RiMapPinLine,
    RiSettings4Line,
    FaRegularBell,
    IoCalendar,
);

// Registrar componente global localmente
const user = ref({
    name: "David Gómez",
});

const cards = ref([
    {
        title: "Total Usuarios",
        icon: "pr-users",
        value: "1,243",
        description: "+12% vs mes anterior",
    },
    {
        title: "Ingresos Mensuales",
        icon: "fa-dollar-sign",
        value: "$45,678",
        description: "+8% vs mes anterior",
    },
    {
        title: "Equipos Activos",
        icon: "ri-settings-4-line",
        value: "856",
        description: "+5% vs mes anterior",
    },
    {
        title: "Tickets Abiertos",
        icon: "oi-alert",
        value: "23",
        description: "-15% vs mes anterior",
    },
]);

    const actions = ref([
    { id: 1, name: "Ver mapa de usuarios", icon: "ri-map-pin-line" },
    { id: 2, name: "Gestionar staff", icon: "pr-users" },
    { id: 3, name: "Configuración", icon: "ri-settings-4-line" },
    { id: 4, name: "Reportes financieros", icon: "fa-dollar-sign" },
]);

    const activities = ref([
    { 
        type: "success",
        action: "Usuario registrado",
        user: "Juan Pérez",
        time: "Hace 2 horas",
    },
    { 
        type: "success",
        action: "Pago registrado",
        user: "Hernan Suárez",
        time: "Hace 2 horas",
    },
    { 
        type: "error",
        action: "Usuario no se registró",
        user: "Almeida Juarez",
        time: "Hace 3 horas",
    },
    {
        type: "warning",
        action: "Pago pendiente",
        user: "María López",
        time: "Hace 5 horas",
    },
    {
        type: "error",
        action: "Error en el sistema",
        user: "Carlos Ramírez",
        time: "Hace 1 día",
    },
]);
</script>

<style scoped>
    /* Colores de texto personalizados para simular muted-foreground */
    .text-muted-foreground {
        color: #6b7280;
    }

    /* Fondo de tarjeta */
    .bg-card {
        background-color: white;
    }
</style>
