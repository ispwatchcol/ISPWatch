<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <!-- Header -->
        <div class="flex items-center gap-3 sm:gap-4 mb-4 sm:mb-6">
        <button
            @click="router.push({ name: 'Customers' })"
            class="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-white transition"
        >
            <icon-mdi-arrow-left class="w-5 h-5 sm:w-6 sm:h-6" />
        </button>
        <div>
            <h1 class="text-xl sm:text-2xl md:text-3xl font-bold text-gray-800 dark:text-gray-100">Nuevo Cliente</h1>
            <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">Registra un nuevo cliente con sus credenciales</p>
        </div>
        </div>

        <!-- Formulario -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 sm:p-6 md:p-8 max-w-7xl mx-auto border border-gray-100 dark:border-gray-700">
        <form @submit.prevent="handleSubmit">
            
            <!-- Sección: Datos del Usuario -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Datos de Acceso
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Email -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Email <span class="text-red-500">*</span>
                </label>
                <input
                    v-model="form.email"
                    type="email"
                    required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="ejemplo@empresa.com"
                />
                </div>

                <!-- Contraseña -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Contraseña <span class="text-red-500">*</span>
                </label>
                <input
                    v-model="form.password"
                    type="password"
                    required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Mínimo 6 caracteres"
                />
                </div>

                <!-- Teléfono -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Teléfono</label>
                <input
                    v-model="form.tel"
                    type="tel"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: +57 300 123 4567"
                />
                </div>

                <!-- Email Tenant (opcional) -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Email Tenant (Opcional)</label>
                <input
                    v-model="form.email_tenant"
                    type="email"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="correo@tenant.com"
                />
                </div>
            </div>
            </div>

            <!-- Sección: Datos del Perfil -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Información del Cliente
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Nombre -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Nombre <span class="text-red-500">*</span>
                </label>
                <input
                    v-model="form.name"
                    type="text"
                    required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: Juan"
                />
                </div>

                <!-- Apellido -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Apellido <span class="text-red-500">*</span>
                </label>
                <input
                    v-model="form.last_name"
                    type="text"
                    required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: Pérez"
                />
                </div>

                <!-- Departamento -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Departamento</label>
                <input
                    v-model="form.department"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: Ventas"
                />
                </div>

                <!-- Posición -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Posición</label>
                <input
                    v-model="form.position"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: Gerente de Ventas"
                />
                </div>
            </div>
            </div>

            <!-- Sección: Configuración del Servicio -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Configuración del Servicio
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- IP del Usuario -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    IP del Usuario
                </label>
                <input
                    v-model="form.ip_user"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: 192.168.1.100"
                />
                </div>

                <!-- Plan/Servicio -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Plan de Servicio
                </label>
                <select
                    v-model="form.service_id"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                >
                    <option :value="null">Seleccionar plan...</option>
                    <option v-for="plan in plans" :key="plan.id" :value="plan.id">
                        {{ plan.name }}
                    </option>
                </select>
                </div>

                <!-- Sectorial -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Sectorial
                </label>
                <select
                    v-model="form.sectorial_id"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                >
                    <option :value="null">Seleccionar sectorial...</option>
                    <option v-for="sectorial in sectorials" :key="sectorial.id" :value="sectorial.id">
                        {{ sectorial.name }}
                    </option>
                </select>
                </div>
                <!-- Router (Nuevo) -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Router
                </label>
                <select
                    v-model="form.router_id"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                >
                    <option :value="null">Seleccionar router...</option>
                    <option v-for="router in routers" :key="router.id" :value="router.id">
                        {{ router.name }}
                    </option>
                </select>
                </div>
            </div>
            </div>

            <!-- Error -->
            <div v-if="error" class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            {{ error }}
            </div>

            <!-- Botones -->
            <div class="flex flex-col sm:flex-row gap-3 sm:gap-4">
            <button
                type="submit"
                :disabled="loading"
                class="flex-1 bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 disabled:bg-gray-400 dark:disabled:bg-gray-600 text-white py-2.5 sm:py-3 rounded-lg font-medium transition text-sm sm:text-base"
            >
                {{ loading ? 'Guardando...' : 'Guardar Cliente' }}
            </button>
            <button
                type="button"
                @click="router.push({ name: 'Customers' })"
                class="px-6 sm:px-8 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 sm:py-3 rounded-lg transition text-sm sm:text-base"
            >
                Cancelar
            </button>
            </div>
        </form>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const router = useRouter()

const form = ref({
    // datos del usuario
    email: '',
    password: '',
    tel: '',
    email_tenant: '',

    // datos para el perfil
    name: '',
    last_name: '',
    department: '',
    position: '',

    // configuración del servicio
    ip_user: '',
    service_id: null,
    sectorial_id: null,
    router_id: null,
})

const loading = ref(false)
const error = ref('')

// Datos para selects
const plans = ref([])
const sectorials = ref([])
const routers = ref([])

// Cargar catálogos
const loadCatalogs = async () => {
    try {
        const [plansRes, sectorialsRes, routersRes] = await Promise.all([
            api.plans.getAll(),
            api.sectorials.getAll(),
            api.routers.getAll(),
        ])
        // PlanController devuelve { data: [...] }
        plans.value = plansRes.data.data || []
        // SectorialController devuelve [...]
        sectorials.value = sectorialsRes.data || []
        // RouterController devuelve directamente un array
        routers.value = routersRes.data || []
    } catch (err) {
        console.error('Error al cargar catálogos:', err)
    }
}

onMounted(loadCatalogs)

const handleSubmit = async () => {
    loading.value = true
    error.value = ''

    try {
        await api.customers.create(form.value)
        alert('Cliente creado exitosamente ✅')
        router.push('/customers')
    } catch (err) {
        console.error('Error al crear cliente:', err)
        error.value = err.response?.data?.message || 'Error al crear el cliente. Por favor, intenta nuevamente.'
    } finally {
        loading.value = false
    }
}
</script>