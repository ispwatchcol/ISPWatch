<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <NotificationToast ref="toast" />

        <!-- Header -->
        <div class="flex items-center gap-3 sm:gap-4 mb-4 sm:mb-6">
        <button
            @click="router.push({ name: 'Customers' })"
            class="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-white transition"
        >
            <icon-mdi-arrow-left class="w-5 h-5 sm:w-6 sm:h-6" />
        </button>
        <div>
            <h1 class="text-xl sm:text-2xl md:text-3xl font-bold text-gray-800 dark:text-gray-100">Editar Cliente</h1>
            <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">Modifica los datos del cliente</p>
        </div>
        </div>

        <!-- Loading -->
        <div v-if="loadingData" class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
        <p class="text-gray-500 dark:text-gray-400 mt-4 text-sm sm:text-base">Cargando datos...</p>
        </div>

        <!-- Formulario -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 sm:p-6 md:p-8 max-w-7xl mx-auto border border-gray-100 dark:border-gray-700">
        <form @submit.prevent="handleSubmit">

            <!-- Sección: Datos de Acceso -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Datos de Acceso
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Email</label>
                <input v-model="form.email" type="email" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Nueva Contraseña (opcional)</label>
                <input v-model="form.password" type="password"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Dejar vacío para no cambiar" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Teléfono</label>
                <input v-model="form.tel" type="tel"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
            </div>

            <p v-if="emailTenant" class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                Email tenant (generado automáticamente):
                <span class="font-medium text-gray-600 dark:text-gray-300">{{ emailTenant }}</span>
            </p>
            </div>

            <!-- Sección: Información del Cliente -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Información del Cliente
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Nombre</label>
                <input v-model="form.name" type="text" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Apellido</label>
                <input v-model="form.last_name" type="text" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Cédula</label>
                <input v-model="form.cedula" type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="1234567890" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Ciudad</label>
                <input v-model="form.city" type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: La Vega" />
                </div>

                <div class="md:col-span-2">
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Departamento</label>
                <input v-model="form.state" type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Cundinamarca" />
                </div>
            </div>
            </div>

            <!-- Sección: Configuración del Servicio -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Configuración del Servicio
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">IP del Usuario</label>
                <input v-model="form.ip_user" type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="192.168.1.100" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Plan de Servicio</label>
                <select v-model="form.service_id"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option :value="null">Seleccionar plan...</option>
                    <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                </select>
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Sectorial</label>
                <select v-model="form.sectorial_id"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option :value="null">Seleccionar sectorial...</option>
                    <option v-for="s in sectorials" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Router</label>
                <select v-model="form.router_id"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option :value="null">Seleccionar router...</option>
                    <option v-for="rb in routers" :key="rb.id" :value="rb.id">{{ rb.name }}</option>
                </select>
                </div>
            </div>
            </div>

            <!-- Sección: Configuración PPPoE (aparece cuando el router tiene PPPoE activo) -->
            <div v-if="showPppoeSection" class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-blue-200 dark:border-blue-700 pb-2 flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                Configuración PPPoE
            </h2>

            <div class="flex items-center gap-3 mb-5">
                <button type="button" @click="form.create_pppoe_secret = !form.create_pppoe_secret"
                :class="form.create_pppoe_secret ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none">
                <span :class="form.create_pppoe_secret ? 'translate-x-6' : 'translate-x-1'"
                    class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" />
                </button>
                <span class="text-gray-700 dark:text-gray-300 font-medium">
                Crear / actualizar secret PPPoE en el router al guardar
                </span>
            </div>

            <div v-if="form.create_pppoe_secret" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Usuario PPPoE <span class="text-red-500">*</span>
                </label>
                <input v-model="form.pppoe_username" type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="juan.perez" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Contraseña PPPoE <span class="text-red-500">*</span>
                </label>
                <input v-model="form.pppoe_password" type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Contraseña del servicio PPPoE" />
                </div>
            </div>
            </div>

            <!-- Error inline -->
            <div v-if="errorMsg" class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            {{ errorMsg }}
            </div>

            <!-- Botones -->
            <div class="flex flex-col sm:flex-row gap-3 sm:gap-4">
            <button type="submit" :disabled="loading"
                class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 dark:disabled:bg-gray-600 text-white py-2.5 sm:py-3 rounded-lg font-medium transition text-sm sm:text-base">
                {{ loading ? 'Guardando...' : 'Actualizar Cliente' }}
            </button>
            <button type="button" @click="router.push({ name: 'Customers' })"
                class="px-6 sm:px-8 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 sm:py-3 rounded-lg transition text-sm sm:text-base">
                Cancelar
            </button>
            </div>
        </form>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'
import NotificationToast from '@/components/NotificationToast.vue'

const router = useRouter()
const route  = useRoute()
const toast  = ref(null)

const form = ref({
    email: '',
    password: '',
    tel: '',
    name: '',
    last_name: '',
    cedula: '',
    city: '',
    state: '',
    ip_user: '',
    service_id: null,
    sectorial_id: null,
    router_id: null,
    create_pppoe_secret: false,
    pppoe_username: '',
    pppoe_password: '',
})

const loading     = ref(false)
const loadingData = ref(true)
const errorMsg    = ref('')
const emailTenant = ref('')

const plans      = ref([])
const sectorials = ref([])
const routers    = ref([])

// Show PPPoE section whenever the selected router has pppoe = true
const selectedRouter   = computed(() => routers.value.find(r => r.id === form.value.router_id))
const showPppoeSection = computed(() => !!selectedRouter.value?.pppoe)

watch(showPppoeSection, (visible) => {
    if (visible && !form.value.pppoe_username) {
        const n = form.value.name.toLowerCase().replace(/\s+/g, '')
        const l = form.value.last_name.toLowerCase().replace(/\s+/g, '')
        if (n && l) form.value.pppoe_username = `${n}.${l}`
    }
    if (!visible) form.value.create_pppoe_secret = false
})

const loadCatalogs = async () => {
    try {
        const [plansRes, sectorialsRes, routersRes] = await Promise.all([
            api.plans.getAll(),
            api.sectorials.getAll(),
            api.routers.getAll(),
        ])
        plans.value      = plansRes.data.data || []
        sectorials.value = sectorialsRes.data || []
        routers.value    = routersRes.data || []
    } catch (err) {
        console.error('Error al cargar catálogos:', err)
    }
}

const loadCustomer = async () => {
    try {
        const { data: d } = await api.customers.getOne(route.params.id)
        form.value = {
            email:        d.email,
            password:     '',
            tel:          d.tel || '',
            name:         d.name,
            last_name:    d.last_name,
            cedula:       d.cedula || '',
            city:         d.city || '',
            state:        d.state || '',
            ip_user:      d.ip_user || '',
            service_id:   d.service_id || null,
            sectorial_id: d.sectorial_id || null,
            router_id:    d.router_id || null,
            create_pppoe_secret: false,
            pppoe_username: '',
            pppoe_password: '',
        }
        emailTenant.value = d.email_tenant || ''
    } catch (err) {
        console.error('Error al cargar cliente:', err)
        toast.value?.error('Error de carga', 'No se pudieron cargar los datos del cliente.')
        errorMsg.value = 'Error al cargar los datos del cliente.'
    } finally {
        loadingData.value = false
    }
}

const handleSubmit = async () => {
    if (form.value.create_pppoe_secret && (!form.value.pppoe_username || !form.value.pppoe_password)) {
        toast.value?.warning('Datos incompletos', 'Ingresa el usuario y contraseña PPPoE.')
        return
    }

    loading.value  = true
    errorMsg.value = ''

    try {
        const dataToSend = { ...form.value }
        if (!dataToSend.password) delete dataToSend.password

        const res   = await api.customers.update(route.params.id, dataToSend)
        const pppoe = res.data?.pppoe_provisioned

        if (pppoe && !pppoe.success) {
            toast.value?.warning(
                'Cliente actualizado',
                `Datos guardados correctamente, pero el secret PPPoE no se pudo crear: ${pppoe.message}`
            )
            setTimeout(() => router.push('/customers'), 2500)
        } else if (pppoe && pppoe.success) {
            toast.value?.success(
                'Cliente actualizado',
                `Datos guardados y secret PPPoE creado en el router correctamente.`
            )
            setTimeout(() => router.push('/customers'), 1500)
        } else {
            toast.value?.success('Cliente actualizado', 'Los datos fueron actualizados correctamente.')
            setTimeout(() => router.push('/customers'), 1500)
        }
    } catch (err) {
        console.error('Error al actualizar cliente:', err)
        const msg = err.response?.data?.message || 'Error al actualizar el cliente.'
        errorMsg.value = msg
        toast.value?.error('Error al actualizar', msg)
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    loadCatalogs()
    loadCustomer()
})
</script>
