<template>
  <div class="flex h-screen bg-gray-100 dark:bg-gray-900">
    <!-- Contenido principal -->
    <main class="flex-1 overflow-y-auto p-6 bg-gray-100 dark:bg-gray-900 min-h-screen">
      <!-- Encabezado y Estado Superior Combinados -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <!-- Título y Bienvenida -->
        <div>
          <h1 class="text-3xl font-bold mb-2 text-gray-800 dark:text-gray-100">Dashboard</h1>
          <p class="text-gray-500 dark:text-gray-400">
            Bienvenido de vuelta, {{ user.name || 'Usuario' }} {{ user.last_name || '' }}
          </p>
        </div>

        <!-- Botones de Acción -->
        <div class="flex flex-wrap items-center gap-4">
          <!-- Estado del sistema -->
          <div
            class="flex items-center space-x-2 px-3 py-1 bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 rounded-full text-sm"
          >
            <v-icon name="bi-activity" class="h-4 w-4" />
            <span>Sistema Activo</span>
          </div>

          <!-- Notificaciones -->
          <button
            class="flex items-center px-3 py-1 rounded-full border border-gray-300 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
            <v-icon name="fa-regular-bell" class="h-4 w-4 mr-2 text-gray-700 dark:text-gray-300" />
            <span class="text-gray-700 dark:text-gray-300 text-sm">Notificaciones</span>
          </button>

          <!-- Logout rápido -->
          <button
            @click="logout"
            class="flex items-center px-3 py-1 bg-red-500 text-white dark:bg-red-700 dark:text-white rounded-full hover:bg-red-600 dark:hover:bg-red-800 transition"
          >
            <v-icon name="oi-alert" class="h-4 w-4 mr-2" />
            <span class="text-sm">Cerrar Sesión</span>
          </button>
        </div>
      </div>

      <!-- Cards de métricas -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div
          v-for="card in cards"
          :key="card.title"
          class="rounded-xl bg-white dark:bg-gray-800 shadow-md hover:shadow-lg transition p-5 border border-gray-100 dark:border-gray-700"
        >
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-sm text-gray-500 dark:text-gray-400 font-medium">{{ card.title }}</h3>
              <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-2">{{ card.value }}</p>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ card.description }}</p>
            </div>
            <div class="p-3 rounded-full bg-blue-50 dark:bg-blue-900">
              <v-icon :name="card.icon" class="w-6 h-6 text-blue-600 dark:text-blue-400" />
            </div>
          </div>
        </div>
      </div>

      <!-- Acciones rápidas y últimas actividades -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Acciones rápidas -->
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow-md lg:col-span-2 border border-gray-100 dark:border-gray-700">
          <div class="p-6 border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
              <v-icon name="ri-settings-4-line" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
              <span>Acciones rápidas</span>
            </h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
              Accede rápidamente a las funciones más utilizadas
            </p>
          </div>
          <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div
              v-for="action in actions"
              :key="action.id"
              class="group flex items-center p-4 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-blue-900 hover:border-blue-200 dark:hover:border-blue-400 transition cursor-pointer"
            >
              <v-icon :name="action.icon" class="w-5 h-5 mr-3 text-blue-600 dark:text-blue-400" />
              <span class="text-gray-800 dark:text-gray-100 group-hover:text-blue-700 dark:group-hover:text-blue-400">{{ action.name }}</span>
            </div>
          </div>
        </div>

        <!-- Últimas actividades -->
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow-md border border-gray-100 dark:border-gray-700">
          <div class="p-6 border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
              <v-icon name="bi-activity" class="w-5 h-5 text-green-600 dark:text-green-400" />
              <span>Últimas Actividades</span>
            </h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Últimas acciones en el sistema</p>
          </div>
          <div class="p-6 space-y-4">
            <div
              v-for="(activity, index) in activities"
              :key="index"
              class="flex items-start space-x-3"
            >
              <div
                class="w-3 h-3 rounded-full mt-2"
                :class="{
                  'bg-green-500 dark:bg-green-400': activity.type === 'success',
                  'bg-yellow-500 dark:bg-yellow-400': activity.type === 'warning',
                  'bg-red-500 dark:bg-red-400': activity.type === 'error',
                }"
              ></div>
              <div class="flex-1">
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                  {{ activity.action }}
                </p>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ activity.user }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ activity.time }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Estado del sistema -->
      <div class="rounded-xl bg-white dark:bg-gray-800 shadow-md border border-gray-100 dark:border-gray-700">
        <div class="p-6 border-b border-gray-100 dark:border-gray-700">
          <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
            <v-icon name="hi-wifi" class="w-5 h-5 text-green-600 dark:text-green-400" />
            <span>Estado del Sistema</span>
          </h3>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Monitoreo en tiempo real de la infraestructura
          </p>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
          <div class="text-center">
            <div
              class="w-16 h-16 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mx-auto mb-3"
            >
              <v-icon name="hi-wifi" class="h-8 w-8 text-green-600 dark:text-green-400" />
            </div>
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">Red Principal</h3>
            <p class="text-sm text-green-600 dark:text-green-400 mt-1">Operativa</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">99.9% uptime</p>
          </div>

          <div class="text-center">
            <div
              class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-3"
            >
              <v-icon name="bi-activity" class="h-8 w-8 text-blue-600 dark:text-blue-400" />
            </div>
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">Servidores</h3>
            <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">Estables</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">CPU: 45% | RAM: 62%</p>
          </div>

          <div class="text-center">
            <div
              class="w-16 h-16 bg-purple-100 dark:bg-purple-900 rounded-full flex items-center justify-center mx-auto mb-3"
            >
              <v-icon name="ri-map-pin-line" class="h-8 w-8 text-purple-600 dark:text-purple-400" />
            </div>
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">Cobertura</h3>
            <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">Óptima</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">5 antenas activas</p>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import axios from "axios";

// Información dinámica del usuario
const user = ref({
  name: "Admin",
  last_name: "Principal",
  role: "Administrador",
});

const fetchUserProfile = async () => {
  try {
    const response = await axios.get("/api/user-profile", {
      headers: {
        Authorization: `Bearer ${localStorage.getItem("token")}`,
      },
    });
    user.value = response.data;
  } catch (error) {
    // Si falla, se mantiene el usuario de ejemplo
    console.error("Error al cargar el perfil del usuario:", error);
  }
};

onMounted(() => {
  fetchUserProfile();
});

// Cards métricas básicas
const cards = ref([
  {
    title: "Clientes",
    value: 120,
    description: "Activos este mes",
    icon: "pr-users",
  },
  {
    title: "Ingresos",
    value: "$5,200",
    description: "Total mensual",
    icon: "fa-dollar-sign",
  },
  {
    title: "Alertas",
    value: 3,
    description: "Pendientes de revisión",
    icon: "oi-alert",
  },
  {
    title: "Antenas activas",
    value: 5,
    description: "Cobertura actual",
    icon: "ri-map-pin-line",
  },
]);

// Acciones rápidas básicas
const actions = ref([
  {
    id: 1,
    name: "Agregar cliente",
    icon: "pr-users",
  },
  {
    id: 2,
    name: "Registrar pago",
    icon: "fa-dollar-sign",
  },
  {
    id: 3,
    name: "Crear alerta",
    icon: "oi-alert",
  },
  {
    id: 4,
    name: "Configurar antena",
    icon: "ri-map-pin-line",
  },
]);

// Últimas actividades básicas
const activities = ref([
  {
    action: "Cliente Juan Pérez agregado",
    user: "Admin",
    time: "Hace 2 horas",
    type: "success",
  },
  {
    action: "Pago registrado por Ana Torres",
    user: "Admin",
    time: "Hace 1 hora",
    type: "success",
  },
  {
    action: "Alerta de red creada",
    user: "Admin",
    time: "Hace 30 minutos",
    type: "warning",
  },
  {
    action: "Antena configurada",
    user: "Admin",
    time: "Hace 10 minutos",
    type: "success",
  },
]);

// Navegar entre rutas
const navigate = (route) => {
  window.location.href = route;
};

// Logout
const logout = () => {
  localStorage.clear();
  sessionStorage.clear();
  window.location.href = "/";
};
</script>

<style scoped>
body {
  font-family: "Inter", sans-serif;
}
</style>