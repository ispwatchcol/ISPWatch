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
            v-if="faultAlerts.count > 0"
            class="flex items-center space-x-2 px-3 py-1 bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 rounded-full text-sm"
          >
            <v-icon name="oi-alert" class="h-4 w-4" />
            <span>Falla General ({{ faultAlerts.count }})</span>
          </div>
          <div
            v-else
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

      <!-- Alerta: routers marcados con Falla General -->
      <div
        v-if="faultAlerts.count > 0"
        class="mb-6 rounded-xl border border-red-200 dark:border-red-700 bg-red-50 dark:bg-red-900/30 p-5"
      >
        <div class="flex items-start gap-3">
          <div class="p-2 rounded-full bg-red-100 dark:bg-red-800/60 shrink-0">
            <v-icon name="oi-alert" class="w-5 h-5 text-red-600 dark:text-red-300" />
          </div>
          <div class="flex-1 min-w-0">
            <h3 class="font-semibold text-red-800 dark:text-red-200">
              Falla General activa en {{ faultAlerts.count }} router{{ faultAlerts.count > 1 ? 's' : '' }}
            </h3>
            <p class="text-sm text-red-700 dark:text-red-300 mt-1">
              Los siguientes routers están marcados con falla general y requieren atención:
            </p>
            <ul class="mt-3 flex flex-wrap gap-2">
              <li
                v-for="r in faultAlerts.routers"
                :key="r.id"
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-red-100 dark:bg-red-800/60 text-red-800 dark:text-red-200 text-xs font-medium"
              >
                <v-icon name="bi-router" class="w-3.5 h-3.5" />
                {{ r.name }}<span v-if="r.ip" class="opacity-70"> · {{ r.ip }}</span>
              </li>
            </ul>
          </div>
          <router-link
            to="/routers"
            class="shrink-0 text-sm font-medium text-red-700 dark:text-red-300 hover:underline whitespace-nowrap"
          >
            Ver routers →
          </router-link>
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="isLoading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div
          v-for="n in 4"
          :key="n"
          class="rounded-xl bg-white dark:bg-gray-800 shadow-md p-5 border border-gray-100 dark:border-gray-700 animate-pulse"
        >
          <div class="flex items-center justify-between">
            <div class="space-y-3 flex-1">
              <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-24"></div>
              <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded w-16"></div>
              <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-32"></div>
            </div>
            <div class="w-12 h-12 bg-gray-200 dark:bg-gray-700 rounded-full"></div>
          </div>
        </div>
      </div>

      <!-- Cards de métricas -->
      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
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
            <div class="p-3 rounded-full" :class="card.bgColor || 'bg-blue-50 dark:bg-blue-900'">
              <v-icon :name="card.icon" class="w-6 h-6" :class="card.iconColor || 'text-blue-600 dark:text-blue-400'" />
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
            <router-link
              v-for="action in actions"
              :key="action.id"
              :to="action.to"
              class="group flex items-center p-4 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-blue-900 hover:border-blue-200 dark:hover:border-blue-400 transition cursor-pointer"
            >
              <v-icon :name="action.icon" class="w-5 h-5 mr-3 text-blue-600 dark:text-blue-400" />
              <span class="text-gray-800 dark:text-gray-100 group-hover:text-blue-700 dark:group-hover:text-blue-400">{{ action.name }}</span>
            </router-link>
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
            <!-- Loading state for activities -->
            <template v-if="isLoading">
              <div v-for="n in 4" :key="n" class="flex items-start space-x-3 animate-pulse">
                <div class="w-3 h-3 rounded-full mt-2 bg-gray-200 dark:bg-gray-700"></div>
                <div class="flex-1 space-y-2">
                  <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
                  <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                </div>
              </div>
            </template>
            <!-- Activities list -->
            <template v-else>
              <div v-if="activities.length === 0" class="text-center text-gray-500 dark:text-gray-400 py-4">
                No hay actividades recientes
              </div>
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
            </template>
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
            <p class="text-sm text-green-600 dark:text-green-400 mt-1">{{ systemStatus.network?.label || 'Operativa' }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">99.9% uptime</p>
          </div>

          <div class="text-center">
            <div
              class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-3"
            >
              <v-icon name="bi-activity" class="h-8 w-8 text-blue-600 dark:text-blue-400" />
            </div>
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">Routers</h3>
            <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">{{ dashboardData?.cards?.infrastructure?.routers || 0 }} activos</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ systemStatus.servers?.label || 'Estables' }}</p>
          </div>

          <div class="text-center">
            <div
              class="w-16 h-16 bg-purple-100 dark:bg-purple-900 rounded-full flex items-center justify-center mx-auto mb-3"
            >
              <v-icon name="ri-map-pin-line" class="h-8 w-8 text-purple-600 dark:text-purple-400" />
            </div>
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">Cobertura</h3>
            <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">Óptima</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ systemStatus.coverage?.label || '0 antenas activas' }}</p>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from "vue";
import { apiClient } from "../services/api";

// Información dinámica del usuario
const user = ref({
  name: "Usuario",
  last_name: "",
  role: "",
});

// Loading state
const isLoading = ref(true);

// Dashboard data from API
const dashboardData = ref(null);

// System status
const systemStatus = computed(() => dashboardData.value?.system_status || {});

// Cards computed from API data
const cards = computed(() => {
  if (!dashboardData.value?.cards) {
    return [];
  }
  
  const data = dashboardData.value.cards;
  
  return [
    {
      title: "Clientes Activos",
      value: data.customers?.active || 0,
      description: `${data.customers?.total || 0} clientes totales`,
      icon: "bi-people",
      bgColor: "bg-blue-50 dark:bg-blue-900",
      iconColor: "text-blue-600 dark:text-blue-400",
    },
    {
      title: "Ingresos del Mes",
      value: formatCurrency(data.revenue?.monthly || 0),
      description: `${data.revenue?.collection_rate || 0}% tasa de recaudo`,
      icon: "fa-dollar-sign",
      bgColor: "bg-green-50 dark:bg-green-900",
      iconColor: "text-green-600 dark:text-green-400",
    },
    {
      title: "Tickets Abiertos",
      value: data.tickets?.open || 0,
      description: data.tickets?.urgent > 0 ? `${data.tickets.urgent} urgentes` : "Sin tickets urgentes",
      icon: "hi-ticket",
      bgColor: data.tickets?.urgent > 0 ? "bg-red-50 dark:bg-red-900" : "bg-yellow-50 dark:bg-yellow-900",
      iconColor: data.tickets?.urgent > 0 ? "text-red-600 dark:text-red-400" : "text-yellow-600 dark:text-yellow-400",
    },
    {
      title: "Antenas activas",
      value: data.infrastructure?.sectoriales || 0,
      description: `${data.infrastructure?.routers || 0} routers configurados`,
      icon: "ri-map-pin-line",
      bgColor: "bg-purple-50 dark:bg-purple-900",
      iconColor: "text-purple-600 dark:text-purple-400",
    },
  ];
});

// Activities from API
const activities = computed(() => dashboardData.value?.activities || []);

// Routers flagged with "Falla General" → dashboard alert
const faultAlerts = computed(
  () => dashboardData.value?.fault_alerts || { count: 0, routers: [] }
);

// Acciones rápidas with routes
const actions = ref([
  {
    id: 1,
    name: "Agregar cliente",
    icon: "bi-person-plus",
    to: "/customers/create",
  },
  {
    id: 2,
    name: "Registrar pago",
    icon: "fa-dollar-sign",
    to: "/billing/payments",
  },
  {
    id: 3,
    name: "Ver tickets",
    icon: "hi-ticket",
    to: "/support",
  },
  {
    id: 4,
    name: "Ver routers",
    icon: "bi-router",
    to: "/routers",
  },
]);

// Format currency
const formatCurrency = (value) => {
  const num = parseFloat(value) || 0;
  return '$' + num.toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
};

// Fetch dashboard stats
const fetchDashboardStats = async () => {
  try {
    isLoading.value = true;
    const response = await apiClient.get('/dashboard/stats');
    
    if (response.data.success) {
      dashboardData.value = response.data.data;
    }
  } catch (error) {
    console.error("Error al cargar estadísticas del dashboard:", error);
  } finally {
    isLoading.value = false;
  }
};

// Load user from storage
const loadUserFromStorage = () => {
  const localData = localStorage.getItem("userData");
  const sessionData = sessionStorage.getItem("userData");
  const storedJson = localData || sessionData;
  
  if (storedJson) {
    try {
      const userData = JSON.parse(storedJson);
      user.value = {
        name: userData.user_name || userData.name || "Usuario",
        last_name: userData.user_lastname || userData.last_name || "",
        role: userData.role_name || userData.role || "",
      };
    } catch (e) {
      console.error("Error parseando userData:", e);
    }
  }
};

onMounted(() => {
  loadUserFromStorage();
  fetchDashboardStats();
});

// Logout
const logout = () => {
  localStorage.clear();
  sessionStorage.clear();
  window.location.replace("/");
};
</script>

<style scoped>
body {
  font-family: "Inter", sans-serif;
}

.animate-pulse {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}
</style>
