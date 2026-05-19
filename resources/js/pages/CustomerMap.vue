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

        <!-- Google Maps API key not configured -->
        <div
            v-else-if="apiKeyMissing"
            class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-100 dark:border-gray-700 p-10 text-center max-w-2xl mx-auto"
        >
            <div
                class="mx-auto w-16 h-16 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center mb-5"
            >
                <v-icon
                    name="ri-map-2-line"
                    class="w-8 h-8 text-blue-600 dark:text-blue-400"
                />
            </div>
            <h2
                class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-2"
            >
                Configura Google Maps para tu empresa
            </h2>
            <p
                class="text-gray-500 dark:text-gray-400 mb-6 leading-relaxed"
            >
                Para usar el Mapa de Clientes debes ingresar la clave de API de
                Google Maps de tu empresa. Es una sola clave por empresa: una
                vez guardada, el mapa se mostrará automáticamente aquí.
            </p>
            <button
                v-if="isAdmin"
                @click="router.push('/settings')"
                class="inline-flex items-center gap-2 bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white px-6 py-3 rounded-xl font-medium transition-all shadow-lg hover:shadow-xl"
            >
                <v-icon name="ri-settings-4-line" class="w-5 h-5" />
                Ir a Configuración
            </button>
            <p
                v-else
                class="text-sm text-amber-600 dark:text-amber-400"
            >
                Solicita a un administrador que registre la clave de API de
                Google Maps en <strong>Configuración</strong>.
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
                <div ref="mapEl" class="w-full h-[600px]"></div>
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
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from "vue";
import { useRouter } from "vue-router";
import api from "../services/api";
import tenantApi from "../services/api/tenant";

const router = useRouter();

const customers = ref([]);
const loading = ref(true);
const error = ref("");
const apiKeyMissing = ref(false);
const mapEl = ref(null);

let map = null;
let infoWindow = null;
let markers = [];

// Resolve admin role the same way Settings.vue does, to decide whether to
// offer the "Ir a Configuración" shortcut on the empty state.
const isAdmin = computed(() => {
    try {
        const raw =
            localStorage.getItem("userData") ||
            sessionStorage.getItem("userData");
        if (!raw) return false;
        const u = JSON.parse(raw);
        return u?.role_name?.toLowerCase() === "administrador";
    } catch {
        return false;
    }
});

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

const createPinUrl = (color) => {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="42" viewBox="0 0 32 42"><path d="M16 0C7.163 0 0 7.163 0 16c0 12 16 26 16 26s16-14 16-26C32 7.163 24.837 0 16 0z" fill="${color}" stroke="#fff" stroke-width="2"/><circle cx="16" cy="16" r="6" fill="#fff"/></svg>`;
    return "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(svg);
};

const escapeHtml = (value) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (ch) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            }[ch])
    );

const popupHtml = (customer) => `
    <div style="padding:12px;min-width:220px;font-family:inherit;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <div style="width:40px;height:40px;border-radius:9999px;background:#DBEAFE;display:flex;align-items:center;justify-content:center;font-size:18px;">👤</div>
            <div>
                <h3 style="font-weight:700;color:#1F2937;margin:0;">${escapeHtml(
                    customer.name
                )} ${escapeHtml(customer.last_name)}</h3>
                <p style="font-size:12px;color:#6B7280;margin:0;">${escapeHtml(
                    customer.email
                )}</p>
            </div>
        </div>
        <div style="font-size:13px;color:#4B5563;line-height:1.5;">
            <p style="margin:2px 0;"><strong>Departamento:</strong> ${escapeHtml(
                customer.department || "N/A"
            )}</p>
            <p style="margin:2px 0;"><strong>Posición:</strong> ${escapeHtml(
                customer.position || "N/A"
            )}</p>
            <p style="margin:2px 0;"><strong>Ciudad:</strong> ${escapeHtml(
                customer.city || "N/A"
            )}</p>
            <p style="margin:2px 0;"><strong>Dirección:</strong> ${escapeHtml(
                customer.address || "N/A"
            )}</p>
        </div>
        <div style="margin-top:12px;">
            <a href="/customers/${encodeURIComponent(customer.user_id)}/edit"
               style="display:block;text-align:center;background:#2563EB;color:#fff;padding:6px 12px;border-radius:6px;font-size:12px;text-decoration:none;">
                Editar
            </a>
        </div>
    </div>
`;

// Single shared loader promise so the Google Maps script is injected once.
let googleMapsPromise = null;

const loadGoogleMaps = (apiKey) => {
    if (window.google && window.google.maps) {
        return Promise.resolve(window.google);
    }
    if (googleMapsPromise) return googleMapsPromise;

    googleMapsPromise = new Promise((resolve, reject) => {
        const callbackName = "__ispwatchInitGmaps__";
        window[callbackName] = () => {
            resolve(window.google);
            delete window[callbackName];
        };

        const script = document.createElement("script");
        script.src =
            `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(
                apiKey
            )}` + `&callback=${callbackName}&loading=async&v=weekly`;
        script.async = true;
        script.defer = true;
        script.onerror = () => {
            googleMapsPromise = null;
            reject(new Error("SCRIPT_LOAD_ERROR"));
        };
        document.head.appendChild(script);
    });

    return googleMapsPromise;
};

const initMap = () => {
    if (!mapEl.value || !window.google?.maps) return;

    const g = window.google;

    map = new g.maps.Map(mapEl.value, {
        center: { lat: 4.5709, lng: -74.2973 }, // Colombia
        zoom: 6,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: true,
    });

    infoWindow = new g.maps.InfoWindow();
    markers = [];
    const bounds = new g.maps.LatLngBounds();

    customers.value.forEach((customer) => {
        const lat = Number(customer.latitude);
        const lng = Number(customer.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

        const color = getMarkerColor(customer.department);
        const marker = new g.maps.Marker({
            position: { lat, lng },
            map,
            title: `${customer.name} ${customer.last_name}`,
            icon: {
                url: createPinUrl(color),
                scaledSize: new g.maps.Size(32, 42),
                anchor: new g.maps.Point(16, 42),
            },
        });

        marker.addListener("click", () => {
            infoWindow.setContent(popupHtml(customer));
            infoWindow.open({ anchor: marker, map });
        });

        markers.push(marker);
        bounds.extend(marker.getPosition());
    });

    if (markers.length > 0) {
        map.fitBounds(bounds);
        // Don't zoom in too far when there's a single location.
        g.maps.event.addListenerOnce(map, "idle", () => {
            if (map.getZoom() > 16) map.setZoom(16);
        });
    }
};

const loadMapData = async () => {
    try {
        loading.value = true;
        error.value = "";
        apiKeyMissing.value = false;

        // 1. Resolve the tenant's Google Maps API key (one per company).
        const configResponse = await tenantApi.getMapsConfig();
        const config = configResponse.data?.data || {};

        if (!config.has_key || !config.google_maps_api_key) {
            apiKeyMissing.value = true;
            loading.value = false;
            return;
        }

        // Surface invalid/unauthorized key errors raised by Google.
        window.gm_authFailure = () => {
            error.value =
                "La clave de API de Google Maps no es válida o no está autorizada. Verifica la clave en Configuración y las restricciones en Google Cloud Console.";
            loading.value = false;
        };

        // 2. Load customer locations.
        const response = await api.customers.getMapData();
        customers.value = response.data;

        // 3. Load Google Maps with the tenant key, then render.
        await loadGoogleMaps(config.google_maps_api_key);

        loading.value = false;
        await nextTick();
        initMap();
    } catch (err) {
        console.error("Error al cargar el mapa:", err);
        error.value =
            "Error al cargar el mapa. Por favor, intenta nuevamente.";
        loading.value = false;
    }
};

onMounted(() => {
    loadMapData();
});

onBeforeUnmount(() => {
    if (markers.length) {
        markers.forEach((m) => m.setMap(null));
        markers = [];
    }
    if (infoWindow) infoWindow.close();
    map = null;
    delete window.gm_authFailure;
});
</script>
