<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">
                    Estadísticas de Clientes
                </h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    Análisis detallado y métricas de clientes
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
                Cargando estadísticas...
            </p>
        </div>

        <!-- Error -->
        <div
            v-else-if="error"
            class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg"
        >
            {{ error }}
        </div>

        <!-- Statistics Content -->
        <div v-else>
            <!-- Key Metrics Cards -->
            <div
                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6"
            >
                <div
                    class="rounded-xl bg-white dark:bg-gray-800 shadow-md hover:shadow-lg transition p-6 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <h3
                                class="text-sm text-gray-500 dark:text-gray-400 font-medium"
                            >
                                Total Clientes
                            </h3>
                            <p
                                class="text-3xl font-bold text-gray-800 dark:text-gray-100 mt-2"
                            >
                                {{ stats.total_customers }}
                            </p>
                            <p
                                class="text-xs text-gray-400 dark:text-gray-500 mt-1"
                            >
                                Registrados en el sistema
                            </p>
                        </div>
                        <div
                            class="p-3 rounded-full bg-blue-50 dark:bg-blue-900"
                        >
                            <v-icon
                                name="pr-users"
                                class="w-8 h-8 text-blue-600 dark:text-blue-400"
                            />
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-xl bg-white dark:bg-gray-800 shadow-md hover:shadow-lg transition p-6 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <h3
                                class="text-sm text-gray-500 dark:text-gray-400 font-medium"
                            >
                                Nuevos Este Mes
                            </h3>
                            <p
                                class="text-3xl font-bold text-gray-800 dark:text-gray-100 mt-2"
                            >
                                {{ stats.new_this_month }}
                            </p>
                            <p
                                class="text-xs text-gray-400 dark:text-gray-500 mt-1"
                            >
                                Registrados en {{ currentMonth }}
                            </p>
                        </div>
                        <div
                            class="p-3 rounded-full bg-green-50 dark:bg-green-900"
                        >
                            <v-icon
                                name="pr-user-plus"
                                class="w-8 h-8 text-green-600 dark:text-green-400"
                            />
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-xl bg-white dark:bg-gray-800 shadow-md hover:shadow-lg transition p-6 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <h3
                                class="text-sm text-gray-500 dark:text-gray-400 font-medium"
                            >
                                Tasa de Crecimiento
                            </h3>
                            <p
                                class="text-3xl font-bold mt-2"
                                :class="
                                    stats.growth_rate >= 0
                                        ? 'text-green-600 dark:text-green-400'
                                        : 'text-red-600 dark:text-red-400'
                                "
                            >
                                {{ stats.growth_rate >= 0 ? "+" : ""
                                }}{{ stats.growth_rate }}%
                            </p>
                            <p
                                class="text-xs text-gray-400 dark:text-gray-500 mt-1"
                            >
                                Comparado con mes anterior
                            </p>
                        </div>
                        <div
                            class="p-3 rounded-full bg-purple-50 dark:bg-purple-900"
                        >
                            <v-icon
                                name="hi-trending-up"
                                class="w-8 h-8 text-purple-600 dark:text-purple-400"
                            />
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-xl bg-white dark:bg-gray-800 shadow-md hover:shadow-lg transition p-6 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <h3
                                class="text-sm text-gray-500 dark:text-gray-400 font-medium"
                            >
                                Clientes Activos
                            </h3>
                            <p
                                class="text-3xl font-bold text-gray-800 dark:text-gray-100 mt-2"
                            >
                                {{ stats.active_customers }}
                            </p>
                            <p
                                class="text-xs text-gray-400 dark:text-gray-500 mt-1"
                            >
                                Con actividad reciente
                            </p>
                        </div>
                        <div
                            class="p-3 rounded-full bg-yellow-50 dark:bg-yellow-900"
                        >
                            <v-icon
                                name="bi-activity"
                                class="w-8 h-8 text-yellow-600 dark:text-yellow-400"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Distribution Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Department Distribution -->
                <div
                    class="rounded-xl bg-white dark:bg-gray-800 shadow-md border border-gray-100 dark:border-gray-700"
                >
                    <div
                        class="p-6 border-b border-gray-100 dark:border-gray-700"
                    >
                        <h3
                            class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2"
                        >
                            <v-icon
                                name="oi-package"
                                class="w-5 h-5 text-blue-600 dark:text-blue-400"
                            />
                            Distribución por Departamento
                        </h3>
                        <p
                            class="text-sm text-gray-500 dark:text-gray-400 mt-1"
                        >
                            Clientes agrupados por departamento
                        </p>
                    </div>
                    <div class="p-6">
                        <div
                            v-if="stats.by_department.length === 0"
                            class="text-center py-8 text-gray-500 dark:text-gray-400"
                        >
                            No hay datos de departamentos
                        </div>
                        <div v-else class="space-y-3">
                            <div
                                v-for="dept in stats.by_department"
                                :key="dept.department"
                                class="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-gray-700/50"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-3 h-3 rounded-full bg-blue-500"
                                    ></div>
                                    <span
                                        class="text-sm font-medium text-gray-800 dark:text-gray-100"
                                        >{{
                                            dept.department ||
                                            "Sin departamento"
                                        }}</span
                                    >
                                </div>
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-32 bg-gray-200 dark:bg-gray-600 rounded-full h-2"
                                    >
                                        <div
                                            class="bg-blue-600 dark:bg-blue-400 h-2 rounded-full"
                                            :style="{
                                                width:
                                                    (dept.count /
                                                        stats.total_customers) *
                                                        100 +
                                                    '%',
                                            }"
                                        ></div>
                                    </div>
                                    <span
                                        class="text-sm font-bold text-gray-800 dark:text-gray-100 w-12 text-right"
                                        >{{ dept.count }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Position Distribution -->
                <div
                    class="rounded-xl bg-white dark:bg-gray-800 shadow-md border border-gray-100 dark:border-gray-700"
                >
                    <div
                        class="p-6 border-b border-gray-100 dark:border-gray-700"
                    >
                        <h3
                            class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2"
                        >
                            <v-icon
                                name="ri-map-pin-user-line"
                                class="w-5 h-5 text-purple-600 dark:text-purple-400"
                            />
                            Distribución por Posición
                        </h3>
                        <p
                            class="text-sm text-gray-500 dark:text-gray-400 mt-1"
                        >
                            Clientes agrupados por cargo
                        </p>
                    </div>
                    <div class="p-6">
                        <div
                            v-if="stats.by_position.length === 0"
                            class="text-center py-8 text-gray-500 dark:text-gray-400"
                        >
                            No hay datos de posiciones
                        </div>
                        <div v-else class="space-y-3">
                            <div
                                v-for="pos in stats.by_position"
                                :key="pos.position"
                                class="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-gray-700/50"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-3 h-3 rounded-full bg-purple-500"
                                    ></div>
                                    <span
                                        class="text-sm font-medium text-gray-800 dark:text-gray-100"
                                        >{{
                                            pos.position || "Sin posición"
                                        }}</span
                                    >
                                </div>
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-32 bg-gray-200 dark:bg-gray-600 rounded-full h-2"
                                    >
                                        <div
                                            class="bg-purple-600 dark:bg-purple-400 h-2 rounded-full"
                                            :style="{
                                                width:
                                                    (pos.count /
                                                        stats.total_customers) *
                                                        100 +
                                                    '%',
                                            }"
                                        ></div>
                                    </div>
                                    <span
                                        class="text-sm font-bold text-gray-800 dark:text-gray-100 w-12 text-right"
                                        >{{ pos.count }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Trend and Recent Customers -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Monthly Trend -->
                <div
                    class="lg:col-span-2 rounded-xl bg-white dark:bg-gray-800 shadow-md border border-gray-100 dark:border-gray-700"
                >
                    <div
                        class="p-6 border-b border-gray-100 dark:border-gray-700"
                    >
                        <h3
                            class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2"
                        >
                            <v-icon
                                name="hi-trending-up"
                                class="w-5 h-5 text-green-600 dark:text-green-400"
                            />
                            Tendencia de Crecimiento Mensual
                        </h3>
                        <p
                            class="text-sm text-gray-500 dark:text-gray-400 mt-1"
                        >
                            Evolución de clientes en los últimos 6 meses
                        </p>
                    </div>
                    <div class="p-6">
                        <div
                            v-if="stats.monthly_trend.length === 0"
                            class="text-center py-8 text-gray-500 dark:text-gray-400"
                        >
                            No hay datos de tendencia
                        </div>
                        <div
                            v-else
                            class="flex items-end justify-between gap-2 h-64"
                        >
                            <div
                                v-for="month in stats.monthly_trend"
                                :key="month.month"
                                class="flex-1 flex flex-col items-center gap-2"
                            >
                                <div
                                    class="text-xs font-bold text-gray-800 dark:text-gray-100"
                                >
                                    {{ month.count }}
                                </div>
                                <div
                                    class="w-full bg-gradient-to-t from-blue-600 to-blue-400 dark:from-blue-500 dark:to-blue-300 rounded-t-lg transition-all hover:opacity-80"
                                    :style="{
                                        height:
                                            (month.count /
                                                Math.max(
                                                    ...stats.monthly_trend.map(
                                                        (m) => m.count
                                                    )
                                                )) *
                                                100 +
                                            '%',
                                    }"
                                ></div>
                                <div
                                    class="text-xs text-gray-600 dark:text-gray-400 font-medium"
                                >
                                    {{ month.month }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Customers -->
                <div
                    class="rounded-xl bg-white dark:bg-gray-800 shadow-md border border-gray-100 dark:border-gray-700"
                >
                    <div
                        class="p-6 border-b border-gray-100 dark:border-gray-700"
                    >
                        <h3
                            class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2"
                        >
                            <v-icon
                                name="io-calendar"
                                class="w-5 h-5 text-blue-600 dark:text-blue-400"
                            />
                            Registros Recientes
                        </h3>
                        <p
                            class="text-sm text-gray-500 dark:text-gray-400 mt-1"
                        >
                            Últimos 5 clientes
                        </p>
                    </div>
                    <div class="p-6">
                        <div
                            v-if="stats.recent_customers.length === 0"
                            class="text-center py-8 text-gray-500 dark:text-gray-400"
                        >
                            No hay clientes recientes
                        </div>
                        <div v-else class="space-y-4">
                            <div
                                v-for="customer in stats.recent_customers"
                                :key="customer.user_id"
                                class="flex items-start gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-700/50 hover:bg-gray-100 dark:hover:bg-gray-700 transition"
                            >
                                <div
                                    class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center flex-shrink-0"
                                >
                                    <v-icon
                                        name="pr-user"
                                        class="w-5 h-5 text-blue-600 dark:text-blue-400"
                                    />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p
                                        class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate"
                                    >
                                        {{ customer.name }}
                                        {{ customer.last_name }}
                                    </p>
                                    <p
                                        class="text-xs text-gray-600 dark:text-gray-400 truncate"
                                    >
                                        {{ customer.email }}
                                    </p>
                                    <p
                                        class="text-xs text-gray-500 dark:text-gray-500 mt-1"
                                    >
                                        {{
                                            customer.department ||
                                            "Sin departamento"
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import api from "../services/api";

const router = useRouter();

const stats = ref({
    total_customers: 0,
    new_this_month: 0,
    growth_rate: 0,
    active_customers: 0,
    by_department: [],
    by_position: [],
    monthly_trend: [],
    recent_customers: [],
});

const loading = ref(true);
const error = ref("");

const currentMonth = computed(() => {
    const months = [
        "Enero",
        "Febrero",
        "Marzo",
        "Abril",
        "Mayo",
        "Junio",
        "Julio",
        "Agosto",
        "Septiembre",
        "Octubre",
        "Noviembre",
        "Diciembre",
    ];
    return months[new Date().getMonth()];
});

const loadStatistics = async () => {
    try {
        loading.value = true;
        error.value = "";
        const response = await api.customers.getStatistics();
        stats.value = response.data;
    } catch (err) {
        console.error("Error al cargar estadísticas:", err);
        error.value =
            "Error al cargar las estadísticas. Por favor, intenta nuevamente.";
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    loadStatistics();
});
</script>
