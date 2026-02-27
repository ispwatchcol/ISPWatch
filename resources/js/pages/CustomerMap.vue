<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">
                    Mapa de Clientes
                </h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    Visualización geográfica de ubicaciones de clientes
                </p>
            </div>
            <button
                @click="router.push('/customers')"
                class="bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white px-6 py-3 rounded-lg flex items-center gap-2 transition"
            >
                <icon-mdi-arrow-left class="w-5 h-5" />
                Volver a Clientes
            </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
            <div
                class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"
            ></div>
            <p class="text-gray-500 dark:text-gray-400 mt-4">
                Cargando mapa...
            </p>
        </div>

        <!-- Error -->
        <div
            v-else-if="error"
            class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg"
        >
            {{ error }}
        </div>

        <!-- Map Container -->
        <div v-else>
            <!-- Stats Bar -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="p-3 rounded-full bg-blue-50 dark:bg-blue-900"
                        >
                            <v-icon
                                name="ri-map-pin-line"
                                class="w-6 h-6 text-blue-600 dark:text-blue-400"
                            />
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                En el Mapa
                            </p>
                            <p
                                class="text-2xl font-bold text-gray-800 dark:text-gray-100"
                            >
                                {{ customers.length }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="p-3 rounded-full bg-green-50 dark:bg-green-900"
                        >
                            <v-icon
                                name="ri-map-pin-user-line"
                                class="w-6 h-6 text-green-600 dark:text-green-400"
                            />
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Ciudades
                            </p>
                            <p
                                class="text-2xl font-bold text-gray-800 dark:text-gray-100"
                            >
                                {{ uniqueCities }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="p-3 rounded-full bg-purple-50 dark:bg-purple-900"
                        >
                            <v-icon
                                name="oi-package"
                                class="w-6 h-6 text-purple-600 dark:text-purple-400"
                            />
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Departamentos
                            </p>
                            <p
                                class="text-2xl font-bold text-gray-800 dark:text-gray-100"
                            >
                                {{ uniqueDepartments }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Map -->
            <div
                class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700"
            >
                <div id="map" class="w-full h-[600px]"></div>
            </div>

            <!-- Legend -->
            <div
                class="mt-4 bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
            >
                <h3
                    class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3"
                >
                    Leyenda - Colores por Departamento
                </h3>
                <div
                    class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3"
                >
                    <div
                        v-for="(color, dept) in departmentColors"
                        :key="dept"
                        class="flex items-center gap-2"
                    >
                        <div
                            class="w-4 h-4 rounded-full"
                            :style="{ backgroundColor: color }"
                        ></div>
                        <span
                            class="text-xs text-gray-600 dark:text-gray-400"
                            >{{ dept || "Sin departamento" }}</span
                        >
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import { useRouter } from "vue-router";
import api from "../services/api";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

const router = useRouter();

const customers = ref([]);
const loading = ref(true);
const error = ref("");
let map = null;
let markers = [];

// Department color mapping
const departmentColors = ref({
    Ventas: "#3B82F6",
    Marketing: "#10B981",
    Técnico: "#8B5CF6",
    Soporte: "#F59E0B",
    Administración: "#EF4444",
    "Sin departamento": "#6B7280",
});

const uniqueCities = computed(() => {
    const cities = new Set(customers.value.map((c) => c.city).filter(Boolean));
    return cities.size;
});

const uniqueDepartments = computed(() => {
    const depts = new Set(
        customers.value.map((c) => c.department).filter(Boolean)
    );
    return depts.size;
});

const getMarkerColor = (department) => {
    return (
        departmentColors.value[department] ||
        departmentColors.value["Sin departamento"]
    );
};

const createCustomIcon = (color) => {
    const svgIcon = `
        <svg width="32" height="42" viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg">
            <path d="M16 0C7.163 0 0 7.163 0 16c0 12 16 26 16 26s16-14 16-26C32 7.163 24.837 0 16 0z" 
                  fill="${color}" stroke="#fff" stroke-width="2"/>
            <circle cx="16" cy="16" r="6" fill="#fff"/>
        </svg>
    `;

    return L.divIcon({
        html: svgIcon,
        className: "custom-marker",
        iconSize: [32, 42],
        iconAnchor: [16, 42],
        popupAnchor: [0, -42],
    });
};

const initMap = () => {
    // Initialize Leaflet map
    if (map) {
        map.off();
        map.remove();
        map = null;
    }
    map = L.map("map").setView([4.5709, -74.2973], 6); // Centered on Bogotá, Colombia

    // Add OpenStreetMap tiles
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "© OpenStreetMap contributors",
        maxZoom: 18,
        minZoom: 5,
    }).addTo(map);

    // Add markers for each customer
    customers.value.forEach((customer) => {
        if (customer.latitude && customer.longitude) {
            const color = getMarkerColor(customer.department);
            const icon = createCustomIcon(color);

            const marker = L.marker([customer.latitude, customer.longitude], {
                icon,
            }).addTo(map).bindPopup(`
                    <div class="p-3 min-w-[200px]">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                <span class="text-blue-600 font-bold text-lg">👤</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800">${
                                    customer.name
                                } ${customer.last_name}</h3>
                                <p class="text-xs text-gray-500">${
                                    customer.email
                                }</p>
                            </div>
                        </div>
                        <div class="space-y-1 text-sm">
                            <p class="text-gray-600"><strong>Departamento:</strong> ${
                                customer.department || "N/A"
                            }</p>
                            <p class="text-gray-600"><strong>Posición:</strong> ${
                                customer.position || "N/A"
                            }</p>
                            <p class="text-gray-600"><strong>Ciudad:</strong> ${
                                customer.city || "N/A"
                            }</p>
                            <p class="text-gray-600"><strong>Dirección:</strong> ${
                                customer.address || "N/A"
                            }</p>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <a href="/customers/${customer.user_id}/edit" 
                               class="flex-1 text-center bg-blue-600 text-white px-3 py-1.5 rounded text-xs hover:bg-blue-700">
                                Editar
                            </a>
                        </div>
                    </div>
                `);

            markers.push(marker);
        }
    });

    // Fit bounds to show all markers
    if (markers.length > 0) {
        const group = L.featureGroup(markers);
        map.fitBounds(group.getBounds().pad(0.1));
    }
};

const loadMapData = async () => {
    try {
        loading.value = true;
        error.value = "";
        const response = await api.customers.getMapData();
        customers.value = response.data;

        // Stop loading BEFORE trying to init map so the v-else div renders
        loading.value = false;

        // Wait for DOM update to ensure #map div exists
        setTimeout(() => {
            initMap();
            if (customers.value.length === 0) {
                // Optionally log or handle empty state, but don't hide the map
                console.info("No hay clientes con ubicación registrada en el mapa.");
            }
        }, 100);
    } catch (err) {
        console.error("Error al cargar datos del mapa:", err);
        error.value =
            "Error al cargar los datos del mapa. Por favor, intenta nuevamente.";
        loading.value = false;
    }
};

onMounted(() => {
    loadMapData();
});

onBeforeUnmount(() => {
    if (map) {
        map.remove();
    }
});
</script>

<style scoped>
#map {
    z-index: 1;
}

:deep(.leaflet-popup-content-wrapper) {
    border-radius: 8px;
    padding: 0;
}

:deep(.leaflet-popup-content) {
    margin: 0;
    min-width: 250px;
}

:deep(.custom-marker) {
    background: none;
    border: none;
}
</style>
