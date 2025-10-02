<template>
  <div class="flex h-screen bg-gray-100">
    <!-- Contenido principal -->
    <main class="flex-1 overflow-y-auto p-6 bg-gray-100 min-h-screen">
      <!-- Encabezado -->
      <div class="block items-center justify-center mb-6">
        <h1 class="text-3xl font-bold mb-2 text-gray-800">Dashboard</h1>
        <p class="text-gray-500">
          Bienvenido de vuelta, {{ user.name || 'Usuario' }} {{ user.last_name || '' }}
        </p>
      </div>

      <!-- Estado superior -->
      <div class="flex items-center justify-between space-x-4 mb-6">
        <!-- Estado del sistema -->
        <div
          class="flex items-center space-x-2 px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm"
        >
          <v-icon name="bi-activity" class="h-4 w-4" />
          <span>Sistema Activo</span>
        </div>

        <!-- Notificaciones -->
        <button
          class="flex items-center px-3 py-1 rounded-full border border-gray-300 hover:bg-gray-100 transition"
        >
          <v-icon name="fa-regular-bell" class="h-4 w-4 mr-2 text-gray-700" />
          <span class="text-gray-700 text-sm">Notificaciones</span>
        </button>

        <!-- Logout rápido -->
        <button
          @click="logout"
          class="flex items-center px-3 py-1 bg-red-500 text-white rounded-full hover:bg-red-600 transition"
        >
          <v-icon name="oi-alert" class="h-4 w-4 mr-2" />
          <span class="text-sm">Cerrar Sesión</span>
        </button>
      </div>

      <!-- Cards de métricas -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div
          v-for="card in cards"
          :key="card.title"
          class="rounded-xl bg-white shadow-md hover:shadow-lg transition p-5 border border-gray-100"
        >
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-sm text-gray-500 font-medium">{{ card.title }}</h3>
              <p class="text-2xl font-bold text-gray-800 mt-2">{{ card.value }}</p>
              <p class="text-xs text-gray-400 mt-1">{{ card.description }}</p>
            </div>
            <div class="p-3 rounded-full bg-blue-50">
              <v-icon :name="card.icon" class="w-6 h-6 text-blue-600" />
            </div>
          </div>
        </div>
      </div>

      <!-- Acciones rápidas y últimas actividades -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Acciones rápidas -->
        <div class="rounded-xl bg-white shadow-md lg:col-span-2 border border-gray-100">
          <div class="p-6 border-b border-gray-100">
            <h3 class="text-xl font-semibold text-gray-800 flex items-center space-x-2">
              <v-icon name="ri-settings-4-line" class="w-5 h-5 text-blue-600" />
              <span>Acciones rápidas</span>
            </h3>
            <p class="text-sm text-gray-500 mt-1">
              Accede rápidamente a las funciones más utilizadas
            </p>
          </div>
          <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div
              v-for="action in actions"
              :key="action.id"
              class="group flex items-center p-4 rounded-lg border border-gray-200 hover:bg-blue-50 hover:border-blue-200 transition cursor-pointer"
            >
              <v-icon :name="action.icon" class="w-5 h-5 mr-3 text-blue-600" />
              <span class="text-gray-800 group-hover:text-blue-700">{{ action.name }}</span>
            </div>
          </div>
        </div>

        <!-- Últimas actividades -->
        <div class="rounded-xl bg-white shadow-md border border-gray-100">
          <div class="p-6 border-b border-gray-100">
            <h3 class="text-xl font-semibold text-gray-800 flex items-center space-x-2">
              <v-icon name="bi-activity" class="w-5 h-5 text-green-600" />
              <span>Últimas Actividades</span>
            </h3>
            <p class="text-sm text-gray-500 mt-1">Últimas acciones en el sistema</p>
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
                  'bg-green-500': activity.type === 'success',
                  'bg-yellow-500': activity.type === 'warning',
                  'bg-red-500': activity.type === 'error',
                }"
              ></div>
              <div class="flex-1">
                <p class="text-sm font-semibold text-gray-800">
                  {{ activity.action }}
                </p>
                <p class="text-sm text-gray-600">{{ activity.user }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ activity.time }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Estado del sistema -->
      <div class="rounded-xl bg-white shadow-md border border-gray-100">
        <div class="p-6 border-b border-gray-100">
          <h3 class="text-xl font-semibold text-gray-800 flex items-center space-x-2">
            <v-icon name="hi-wifi" class="w-5 h-5 text-green-600" />
            <span>Estado del Sistema</span>
          </h3>
          <p class="text-sm text-gray-500 mt-1">
            Monitoreo en tiempo real de la infraestructura
          </p>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
          <div class="text-center">
            <div
              class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3"
            >
              <v-icon name="hi-wifi" class="h-8 w-8 text-green-600" />
            </div>
            <h3 class="font-semibold text-gray-800">Red Principal</h3>
            <p class="text-sm text-green-600 mt-1">Operativa</p>
            <p class="text-xs text-gray-500 mt-1">99.9% uptime</p>
          </div>

          <div class="text-center">
            <div
              class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3"
            >
              <v-icon name="bi-activity" class="h-8 w-8 text-blue-600" />
            </div>
            <h3 class="font-semibold text-gray-800">Servidores</h3>
            <p class="text-sm text-blue-600 mt-1">Estables</p>
            <p class="text-xs text-gray-500 mt-1">CPU: 45% | RAM: 62%</p>
          </div>

          <div class="text-center">
            <div
              class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-3"
            >
              <v-icon name="ri-map-pin-line" class="h-8 w-8 text-purple-600" />
            </div>
            <h3 class="font-semibold text-gray-800">Cobertura</h3>
            <p class="text-sm text-purple-600 mt-1">Óptima</p>
            <p class="text-xs text-gray-500 mt-1">5 antenas activas</p>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import axios from "axios";
import { OhVueIcon, addIcons } from "oh-vue-icons";
import {
  OiAlert,
  BiActivity,
  PrUsers,
  FaDollarSign,
  RiSettings4Line,
  RiMapPinLine,
  FaRegularBell,
} from "oh-vue-icons/icons";

addIcons(
  OiAlert,
  BiActivity,
  PrUsers,
  FaDollarSign,
  RiSettings4Line,
  RiMapPinLine,
  FaRegularBell
);

// Información dinámica del usuario
const user = ref({
  name: "",
  last_name: "",
  role: "",
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
    console.error("Error al cargar el perfil del usuario:", error);
  }
};

onMounted(() => {
  fetchUserProfile();
});

// Menú lateral
const menuItems = ref([
  { name: "Dashboard", icon: "bi-activity", route: "/dashboard" },
  { name: "Clientes", icon: "pr-users", route: "/clientes" },
  { name: "Finanzas", icon: "fa-dollar-sign", route: "/finanzas" },
  { name: "Sistema", icon: "ri-settings-4-line", route: "/sistema" },
  { name: "Fichas HotSpot", icon: "ri-map-pin-line", route: "/hotspot" },
  { name: "Soporte Técnico", icon: "oi-alert", route: "/soporte" },
  { name: "Almacén", icon: "fa-regular-bell", route: "/almacen" },
  { name: "Staff", icon: "pr-users", route: "/staff" },
  { name: "Ajustes", icon: "ri-settings-4-line", route: "/ajustes" },
  { name: "Mi Empresa", icon: "fa-regular-bell", route: "/empresa" },
  { name: "Afiliado", icon: "fa-regular-bell", route: "/afiliado" },
  { name: "Manual", icon: "fa-regular-bell", route: "/manual" },
  { name: "Recursos Adicionales", icon: "fa-regular-bell", route: "/recursos" },
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
