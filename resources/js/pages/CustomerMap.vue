<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">
                    Mapa de Clientes
                </h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    Visualización geográfica, zonas de cobertura y trazabilidad
                    de red
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
            <p class="text-gray-500 dark:text-gray-400 mb-6 leading-relaxed">
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
            <p v-else class="text-sm text-amber-600 dark:text-amber-400">
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center gap-3">
                        <div class="p-3 rounded-full bg-blue-50 dark:bg-blue-900">
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
                                {{ filteredCustomers.length }}
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

                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="p-3 rounded-full bg-amber-50 dark:bg-amber-900"
                        >
                            <v-icon
                                name="bi-broadcast-pin"
                                class="w-6 h-6 text-amber-600 dark:text-amber-400"
                            />
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Nodos / Sectoriales
                            </p>
                            <p
                                class="text-2xl font-bold text-gray-800 dark:text-gray-100"
                            >
                                {{ routers.length }} / {{ sectorials.length }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Controls: filters + layers -->
            <div
                class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-100 dark:border-gray-700 p-4 mb-4 flex flex-col lg:flex-row lg:items-end gap-4"
            >
                <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label
                            class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1"
                            >Filtrar por nodo</label
                        >
                        <select
                            v-model="selectedRouterId"
                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100"
                        >
                            <option value="all">Todos los nodos</option>
                            <option
                                v-for="r in routers"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{ r.name }}
                            </option>
                            <option value="none">Sin nodo asignado</option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1"
                            >Estado del servicio</label
                        >
                        <select
                            v-model="selectedStatus"
                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100"
                        >
                            <option value="all">Todos</option>
                            <option value="activo">Activo</option>
                            <option value="suspendido">Suspendido</option>
                            <option value="cancelado">Cancelado</option>
                            <option value="gratis">Gratis / Cortesía</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="layer in layerToggles"
                        :key="layer.key"
                        type="button"
                        :title="layer.desc"
                        :aria-pressed="layers[layer.key]"
                        @click="layers[layer.key] = !layers[layer.key]"
                        :class="[
                            'inline-flex items-center gap-2 px-3.5 py-2 rounded-lg border-2 text-sm font-medium transition-all select-none',
                            layers[layer.key]
                                ? layer.activeClass + ' shadow-sm'
                                : 'border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/40 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500',
                        ]"
                    >
                        <v-icon :name="layer.icon" class="w-4 h-4 flex-shrink-0" />
                        <span class="flex flex-col items-start leading-tight">
                            <span>{{ layer.label }}</span>
                            <span class="text-[10px] font-normal opacity-70">{{
                                layer.desc
                            }}</span>
                        </span>
                    </button>
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
                    Leyenda
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
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{
                            dept || "Sin departamento"
                        }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div
                            class="w-4 h-4 rotate-45 bg-[#2563EB] border border-white"
                        ></div>
                        <span class="text-xs text-gray-600 dark:text-gray-400"
                            >Nodo / Router</span
                        >
                    </div>
                    <div class="flex items-center gap-2">
                        <div
                            class="w-0 h-0 border-l-[7px] border-r-[7px] border-b-[12px] border-l-transparent border-r-transparent border-b-amber-500"
                        ></div>
                        <span class="text-xs text-gray-600 dark:text-gray-400"
                            >Sectorial (cobertura)</span
                        >
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import {
    ref,
    computed,
    onMounted,
    onBeforeUnmount,
    nextTick,
    watch,
} from "vue";
import { useRouter } from "vue-router";
import api from "../services/api";
import tenantApi from "../services/api/tenant";
import { useAuthStore } from "../stores/auth";
import { effectiveCoverageRadius, antennaLabel } from "../constants/antennas";

const router = useRouter();
const authStore = useAuthStore();

const allCustomers = ref([]);
const routers = ref([]);
const sectorials = ref([]);
const loading = ref(true);
const error = ref("");
const apiKeyMissing = ref(false);
const mapEl = ref(null);

// Filters
const selectedRouterId = ref("all"); // 'all' | 'none' | router id
const selectedStatus = ref("all"); // 'all' | service_status value

// Layer toggles
const layers = ref({
    customers: true,
    coverage: true,
    heatmap: false,
    traces: false,
    nodes: true,
});
// Cada capa muestra algo distinto. El subtítulo deja claro qué hace cada una
// para que «Zonas de cobertura» (círculos por antena) y «Mapa de calor»
// (densidad de clientes) no se confundan entre sí.
const layerToggles = [
    {
        key: "customers",
        label: "Clientes",
        desc: "Pines de clientes",
        icon: "ri-map-pin-line",
        activeClass:
            "border-blue-500 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300",
    },
    {
        key: "coverage",
        label: "Zonas de cobertura",
        desc: "Radio por antena",
        icon: "bi-broadcast-pin",
        activeClass:
            "border-amber-500 bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300",
    },
    {
        key: "heatmap",
        label: "Mapa de calor",
        desc: "Densidad de clientes",
        icon: "bi-activity",
        activeClass:
            "border-rose-500 bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300",
    },
    {
        key: "traces",
        label: "Trazabilidad",
        desc: "Cliente → nodo",
        icon: "bi-diagram-3",
        activeClass:
            "border-emerald-500 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300",
    },
    {
        key: "nodes",
        label: "Nodos",
        desc: "Antenas / routers",
        icon: "bi-router",
        activeClass:
            "border-indigo-500 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300",
    },
];

// Plain (non-reactive) Google Maps objects
let map = null;
let infoWindow = null;
let clusterer = null;
let customerMarkers = [];
let heatmap = null;
let coverageCircles = [];
let traceLines = [];
let nodeMarkers = [];
let mapReady = false;

const isAdmin = computed(() => authStore.isAdmin);

const departmentColors = ref({
    Ventas: "#3B82F6",
    Marketing: "#10B981",
    Técnico: "#8B5CF6",
    Soporte: "#F59E0B",
    Administración: "#EF4444",
    "Sin departamento": "#6B7280",
});

const filteredCustomers = computed(() =>
    allCustomers.value.filter((c) => {
        const lat = Number(c.latitude);
        const lng = Number(c.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return false;

        if (selectedRouterId.value === "none") {
            if (c.router_id) return false;
        } else if (selectedRouterId.value !== "all") {
            if (String(c.router_id) !== String(selectedRouterId.value))
                return false;
        }

        if (
            selectedStatus.value !== "all" &&
            (c.service_status || "activo") !== selectedStatus.value
        )
            return false;

        return true;
    })
);

const uniqueCities = computed(
    () =>
        new Set(filteredCustomers.value.map((c) => c.city).filter(Boolean)).size
);

const uniqueDepartments = computed(
    () =>
        new Set(
            filteredCustomers.value.map((c) => c.department).filter(Boolean)
        ).size
);

const getMarkerColor = (department) =>
    departmentColors.value[department] ||
    departmentColors.value["Sin departamento"];

const createPinUrl = (color) => {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="42" viewBox="0 0 32 42"><path d="M16 0C7.163 0 0 7.163 0 16c0 12 16 26 16 26s16-14 16-26C32 7.163 24.837 0 16 0z" fill="${color}" stroke="#fff" stroke-width="2"/><circle cx="16" cy="16" r="6" fill="#fff"/></svg>`;
    return "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(svg);
};

const routerIconUrl = () => {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 28 28"><rect x="4" y="4" width="20" height="20" rx="3" transform="rotate(45 14 14)" fill="#2563EB" stroke="#fff" stroke-width="2"/></svg>`;
    return "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(svg);
};

const sectorialIconUrl = () => {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 28 28"><path d="M14 3 L25 24 H3 Z" fill="#F59E0B" stroke="#fff" stroke-width="2"/></svg>`;
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

const customerPopup = (c) => `
    <div style="padding:12px;min-width:220px;font-family:inherit;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <div style="width:40px;height:40px;border-radius:9999px;background:#DBEAFE;display:flex;align-items:center;justify-content:center;font-size:18px;">👤</div>
            <div>
                <h3 style="font-weight:700;color:#1F2937;margin:0;">${escapeHtml(
                    c.name
                )} ${escapeHtml(c.last_name)}</h3>
                <p style="font-size:12px;color:#6B7280;margin:0;">${escapeHtml(
                    c.email
                )}</p>
            </div>
        </div>
        <div style="font-size:13px;color:#4B5563;line-height:1.5;">
            <p style="margin:2px 0;"><strong>Estado:</strong> ${escapeHtml(
                c.service_status || "activo"
            )}</p>
            <p style="margin:2px 0;"><strong>Departamento:</strong> ${escapeHtml(
                c.department || "N/A"
            )}</p>
            <p style="margin:2px 0;"><strong>Ciudad:</strong> ${escapeHtml(
                c.city || "N/A"
            )}</p>
            <p style="margin:2px 0;"><strong>Dirección:</strong> ${escapeHtml(
                c.address || "N/A"
            )}</p>
        </div>
        <div style="margin-top:12px;">
            <a href="/customers/${encodeURIComponent(c.user_id)}/edit"
               style="display:block;text-align:center;background:#2563EB;color:#fff;padding:6px 12px;border-radius:6px;font-size:12px;text-decoration:none;">
                Editar
            </a>
        </div>
    </div>`;

const nodePopup = (node, kind) => `
    <div style="padding:12px;min-width:200px;font-family:inherit;">
        <h3 style="font-weight:700;color:#1F2937;margin:0 0 6px;">${escapeHtml(
            node.name
        )}</h3>
        <p style="font-size:12px;color:#6B7280;margin:0 0 6px;">${
            kind === "router" ? "Nodo / Router" : "Sectorial"
        }</p>
        ${
            kind === "sectorial"
                ? `<div style="font-size:13px;color:#4B5563;line-height:1.5;">
                       ${
                           node.type
                               ? `<p style="margin:2px 0;"><strong>Tipo:</strong> ${escapeHtml(
                                     node.type
                                 )}</p>`
                               : ""
                       }
                       <p style="margin:2px 0;"><strong>Antena:</strong> ${escapeHtml(
                           antennaLabel(node.antenna_type) || "N/A"
                       )}</p>
                       <p style="margin:2px 0;"><strong>Frecuencia:</strong> ${escapeHtml(
                           node.frequency || "N/A"
                       )}</p>
                       <p style="margin:2px 0;"><strong>Torre/Nodo:</strong> ${escapeHtml(
                           node.node_tower || "N/A"
                       )}</p>
                       <p style="margin:2px 0;"><strong>Cobertura:</strong> ${effectiveCoverageRadius(
                           node
                       )} m</p>
                   </div>`
                : ""
        }
    </div>`;

// ── Google Maps + MarkerClusterer loaders ──────────────────────────────────
let googleMapsPromise = null;
const loadGoogleMaps = (apiKey) => {
    if (window.google && window.google.maps) {
        return Promise.resolve(window.google);
    }
    if (googleMapsPromise) return googleMapsPromise;

    googleMapsPromise = new Promise((resolve, reject) => {
        const cb = "__ispwatchInitGmaps__";
        // If neither onload nor the callback fires (e.g. the request is blocked
        // by an ad-blocker / browser shield), fail loudly instead of hanging.
        const timeout = setTimeout(() => {
            googleMapsPromise = null;
            reject(
                new Error(
                    "El script de Google Maps no respondió (¿bloqueado por el navegador, un ad-blocker/Brave Shields, o sin conexión?)"
                )
            );
        }, 15000);

        window[cb] = () => {
            clearTimeout(timeout);
            resolve(window.google);
            delete window[cb];
        };
        const script = document.createElement("script");
        script.src =
            `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(
                apiKey
            )}` +
            `&callback=${cb}&loading=async&v=weekly&libraries=visualization,geometry`;
        script.async = true;
        script.defer = true;
        script.onerror = () => {
            clearTimeout(timeout);
            googleMapsPromise = null;
            reject(
                new Error(
                    "No se pudo descargar el script de Google Maps (red bloqueada o sin conexión)"
                )
            );
        };
        document.head.appendChild(script);
    });
    return googleMapsPromise;
};

let clustererPromise = null;
const loadMarkerClusterer = () => {
    if (window.markerClusterer) return Promise.resolve(window.markerClusterer);
    if (clustererPromise) return clustererPromise;

    clustererPromise = new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src =
            "https://unpkg.com/@googlemaps/markerclusterer@2.5.3/dist/index.min.js";
        script.async = true;
        script.onload = () => resolve(window.markerClusterer);
        script.onerror = () => {
            clustererPromise = null;
            reject(new Error("CLUSTERER_LOAD_ERROR"));
        };
        document.head.appendChild(script);
    });
    return clustererPromise;
};

// ── Layer rendering ────────────────────────────────────────────────────────
const clearLayers = () => {
    if (clusterer) {
        clusterer.clearMarkers();
        clusterer.setMap(null);
        clusterer = null;
    }
    customerMarkers.forEach((m) => m.setMap(null));
    customerMarkers = [];
    if (heatmap) {
        heatmap.setMap(null);
        heatmap = null;
    }
    coverageCircles.forEach((c) => c.setMap(null));
    coverageCircles = [];
    traceLines.forEach((l) => l.setMap(null));
    traceLines = [];
    nodeMarkers.forEach((m) => m.setMap(null));
    nodeMarkers = [];
    if (infoWindow) infoWindow.close();
};

const applyLayers = () => {
    if (!mapReady || !map || !window.google?.maps) return;
    const g = window.google;
    clearLayers();

    const list = filteredCustomers.value;
    const bounds = new g.maps.LatLngBounds();
    let hasBounds = false;

    // Customers (clustered markers)
    if (layers.value.customers) {
        list.forEach((c) => {
            const pos = {
                lat: Number(c.latitude),
                lng: Number(c.longitude),
            };
            const marker = new g.maps.Marker({
                position: pos,
                title: `${c.name} ${c.last_name}`,
                icon: {
                    url: createPinUrl(getMarkerColor(c.department)),
                    scaledSize: new g.maps.Size(32, 42),
                    anchor: new g.maps.Point(16, 42),
                },
            });
            marker.addListener("click", () => {
                infoWindow.setContent(customerPopup(c));
                infoWindow.open({ anchor: marker, map });
            });
            customerMarkers.push(marker);
            bounds.extend(pos);
            hasBounds = true;
        });

        if (window.markerClusterer && customerMarkers.length) {
            clusterer = new window.markerClusterer.MarkerClusterer({
                map,
                markers: customerMarkers,
            });
        } else {
            customerMarkers.forEach((m) => m.setMap(map));
        }
    }

    // Heatmap
    if (layers.value.heatmap && g.maps.visualization) {
        heatmap = new g.maps.visualization.HeatmapLayer({
            data: list.map(
                (c) =>
                    new g.maps.LatLng(
                        Number(c.latitude),
                        Number(c.longitude)
                    )
            ),
            radius: 35,
            opacity: 0.7,
        });
        heatmap.setMap(map);
        list.forEach((c) => {
            bounds.extend({
                lat: Number(c.latitude),
                lng: Number(c.longitude),
            });
            hasBounds = true;
        });
    }

    // Coverage zones (circles around sectorials)
    if (layers.value.coverage) {
        sectorials.value.forEach((s) => {
            const center = {
                lat: Number(s.latitude),
                lng: Number(s.longitude),
            };
            const circle = new g.maps.Circle({
                map,
                center,
                radius: effectiveCoverageRadius(s),
                strokeColor: "#F59E0B",
                strokeOpacity: 0.8,
                strokeWeight: 1,
                fillColor: "#F59E0B",
                fillOpacity: 0.12,
            });
            coverageCircles.push(circle);
            const cb = circle.getBounds();
            if (cb) {
                bounds.union(cb);
                hasBounds = true;
            }
        });
    }

    // Network nodes (routers + sectorials)
    if (layers.value.nodes) {
        routers.value.forEach((r) => {
            const pos = {
                lat: Number(r.latitude),
                lng: Number(r.longitude),
            };
            const marker = new g.maps.Marker({
                position: pos,
                title: r.name,
                zIndex: 999,
                icon: {
                    url: routerIconUrl(),
                    scaledSize: new g.maps.Size(28, 28),
                    anchor: new g.maps.Point(14, 14),
                },
            });
            marker.addListener("click", () => {
                infoWindow.setContent(nodePopup(r, "router"));
                infoWindow.open({ anchor: marker, map });
            });
            marker.setMap(map);
            nodeMarkers.push(marker);
            bounds.extend(pos);
            hasBounds = true;
        });

        sectorials.value.forEach((s) => {
            const pos = {
                lat: Number(s.latitude),
                lng: Number(s.longitude),
            };
            const marker = new g.maps.Marker({
                position: pos,
                title: s.name,
                zIndex: 999,
                icon: {
                    url: sectorialIconUrl(),
                    scaledSize: new g.maps.Size(28, 28),
                    anchor: new g.maps.Point(14, 24),
                },
            });
            marker.addListener("click", () => {
                infoWindow.setContent(nodePopup(s, "sectorial"));
                infoWindow.open({ anchor: marker, map });
            });
            marker.setMap(map);
            nodeMarkers.push(marker);
            bounds.extend(pos);
            hasBounds = true;
        });
    }

    // Traceability lines (customer → its router/node)
    if (layers.value.traces) {
        const routerById = new Map(
            routers.value.map((r) => [String(r.id), r])
        );
        list.forEach((c) => {
            if (!c.router_id) return;
            const node = routerById.get(String(c.router_id));
            if (!node) return;
            const line = new g.maps.Polyline({
                map,
                path: [
                    {
                        lat: Number(c.latitude),
                        lng: Number(c.longitude),
                    },
                    {
                        lat: Number(node.latitude),
                        lng: Number(node.longitude),
                    },
                ],
                geodesic: true,
                strokeColor:
                    (c.service_status || "activo") === "activo"
                        ? "#10B981"
                        : "#EF4444",
                strokeOpacity: 0.5,
                strokeWeight: 1.5,
            });
            traceLines.push(line);
        });
    }

    if (hasBounds) {
        map.fitBounds(bounds);
        g.maps.event.addListenerOnce(map, "idle", () => {
            if (map.getZoom() > 16) map.setZoom(16);
        });
    }
};

const initMap = async () => {
    if (!mapEl.value) throw new Error("Contenedor del mapa no disponible");
    if (!window.google?.maps)
        throw new Error("Google Maps no quedó disponible tras la carga");
    const g = window.google;
    map = new g.maps.Map(mapEl.value, {
        center: { lat: 4.5709, lng: -74.2973 },
        zoom: 6,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: true,
    });
    infoWindow = new g.maps.InfoWindow();
    mapReady = true;
    applyLayers();
};

const loadMapData = async () => {
    let step = "inicial";
    try {
        loading.value = true;
        error.value = "";
        apiKeyMissing.value = false;

        step = "leer configuración del tenant";
        const configResponse = await tenantApi.getMapsConfig();
        const config = configResponse.data?.data || {};
        if (!config.has_key || !config.google_maps_api_key) {
            apiKeyMissing.value = true;
            loading.value = false;
            return;
        }

        window.gm_authFailure = () => {
            error.value =
                "La clave de API de Google Maps fue rechazada por Google. Causas típicas: la facturación no está activada, la «Maps JavaScript API» no está habilitada, o la restricción de dominio (HTTP referrer) no incluye esta URL. Revísalo en Google Cloud Console.";
            loading.value = false;
        };

        step = "obtener datos de clientes y nodos";
        const response = await api.customers.getMapData();
        const data = response.data || {};
        allCustomers.value = data.customers || [];
        routers.value = data.routers || [];
        sectorials.value = data.sectorials || [];

        step = "cargar Google Maps";
        await loadGoogleMaps(config.google_maps_api_key);

        // Clusterer is optional: if the CDN fails we fall back to plain markers.
        step = "cargar agrupador de marcadores (opcional)";
        await loadMarkerClusterer().catch((e) =>
            console.warn("MarkerClusterer no disponible, se usarán marcadores individuales:", e)
        );

        loading.value = false;
        await nextTick();
        step = "renderizar el mapa";
        await initMap();
    } catch (err) {
        console.error(`Error al cargar el mapa (paso: ${step}):`, err);
        const detail =
            err?.response?.status
                ? `HTTP ${err.response.status}`
                : err?.message || String(err);
        error.value = `Error al cargar el mapa al ${step}. Detalle: ${detail}. Revisa la consola del navegador (F12) para más información.`;
        loading.value = false;
    }
};

watch(
    [filteredCustomers, layers],
    () => {
        applyLayers();
    },
    { deep: true }
);

onMounted(() => {
    loadMapData();
});

onBeforeUnmount(() => {
    clearLayers();
    map = null;
    mapReady = false;
    delete window.gm_authFailure;
});
</script>
