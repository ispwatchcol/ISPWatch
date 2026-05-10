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
        <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando datos...</p>
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

            <!-- Alerta: plan PPPoE pero router sin Control PPPOE -->
            <div v-if="pppoeMismatch" class="mt-4 flex items-start gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 rounded-lg px-4 py-3">
                <span class="text-amber-500 text-lg leading-none mt-0.5">⚠</span>
                <p class="text-sm text-amber-800 dark:text-amber-300">
                El plan seleccionado es <strong>PPPoE</strong> pero el router
                <strong>{{ selectedRouter?.name }}</strong> no tiene habilitado el
                <strong>Control PPPOE</strong>. Activa esa opción en el router o selecciona uno compatible.
                </p>
            </div>
            </div>

            <!-- Sección: Credenciales PPPoE (obligatorio cuando el router tiene Control PPPOE activo) -->
            <div v-if="showPppoeSection" class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-1 border-b border-blue-200 dark:border-blue-700 pb-2 flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                Credenciales PPPoE
                <span class="text-sm font-normal text-blue-600 dark:text-blue-400 ml-1">(requerido — el router usa Control PPPOE)</span>
            </h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                El secret PPPoE se creará / actualizará automáticamente en <strong>{{ selectedRouter?.name }}</strong> al guardar.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Usuario PPPoE <span class="text-red-500">*</span>
                </label>
                <input v-model="form.pppoe_username" type="text"
                    :class="pppoeUserError ? 'border-red-500 focus:ring-red-500' : 'border-gray-200 dark:border-gray-600 focus:ring-blue-500'"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border focus:outline-none focus:ring-2"
                    placeholder="juan.perez" />
                <p v-if="pppoeUserError" class="mt-1 text-xs text-red-500">{{ pppoeUserError }}</p>
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Contraseña PPPoE <span class="text-red-500">*</span>
                </label>
                <input v-model="form.pppoe_password" type="text"
                    :class="pppoePassError ? 'border-red-500 focus:ring-red-500' : 'border-gray-200 dark:border-gray-600 focus:ring-blue-500'"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border focus:outline-none focus:ring-2"
                    placeholder="Contraseña del servicio PPPoE" />
                <p v-if="pppoePassError" class="mt-1 text-xs text-red-500">{{ pppoePassError }}</p>
                </div>
            </div>
            </div>

            <!-- Error inline general -->
            <div v-if="errorMsg" class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg text-sm">
            {{ errorMsg }}
            </div>

            <!-- Botones -->
            <div class="flex flex-col sm:flex-row gap-3 sm:gap-4">
            <button type="submit" :disabled="loading || pppoeMismatch"
                class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white py-2.5 sm:py-3 rounded-lg font-medium transition text-sm sm:text-base">
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

const loading        = ref(false)
const loadingData    = ref(true)
const errorMsg       = ref('')
const pppoeUserError = ref('')
const pppoePassError = ref('')
const emailTenant    = ref('')

const plans      = ref([])
const sectorials = ref([])
const routers    = ref([])

const selectedPlan   = computed(() => plans.value.find(p => p.id === form.value.service_id))
const selectedRouter = computed(() => routers.value.find(r => r.id === form.value.router_id))

// Detect PPPoE plan by type_plan name, plan name, or pppoe_pool field
const isPppoePlan = computed(() => {
    if (!selectedPlan.value) return false
    const typeName = (selectedPlan.value.type_plan?.name ?? '').toLowerCase()
    const planName = (selectedPlan.value.name ?? '').toLowerCase()
    return typeName.includes('pppoe') || planName.includes('pppoe') || !!selectedPlan.value.pppoe_pool
})

// PPPoE section is shown (and mandatory) when the router has Control PPPOE active
const showPppoeSection = computed(() => !!selectedRouter.value?.pppoe)

// Mismatch: PPPoE plan selected but router doesn't support PPPoE
const pppoeMismatch = computed(() =>
    isPppoePlan.value && !!selectedRouter.value && !selectedRouter.value.pppoe
)

// Sync create_pppoe_secret flag with router PPPoE state
watch(showPppoeSection, (visible) => {
    form.value.create_pppoe_secret = visible
    if (visible && !form.value.pppoe_username) {
        const n = form.value.name.toLowerCase().replace(/\s+/g, '')
        const l = form.value.last_name.toLowerCase().replace(/\s+/g, '')
        if (n && l) form.value.pppoe_username = `${n}.${l}`
    }
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
            pppoe_username: d.pppoe_username || '',
            pppoe_password: d.pppoe_password || '',
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

// After catalogs and customer load, sync the PPPoE flag based on the loaded router
watch([() => form.value.router_id, routers], () => {
    if (selectedRouter.value) {
        form.value.create_pppoe_secret = !!selectedRouter.value.pppoe
        if (selectedRouter.value.pppoe && !form.value.pppoe_username) {
            const n = form.value.name.toLowerCase().replace(/\s+/g, '')
            const l = form.value.last_name.toLowerCase().replace(/\s+/g, '')
            if (n && l) form.value.pppoe_username = `${n}.${l}`
        }
    }
}, { immediate: false })

const handleSubmit = async () => {
    errorMsg.value       = ''
    pppoeUserError.value = ''
    pppoePassError.value = ''

    // Hard block: PPPoE plan assigned to non-PPPoE router
    if (pppoeMismatch.value) {
        toast.value?.error('Configuración inválida',
            `El plan PPPoE requiere un router con Control PPPOE activo. Actívalo en la configuración del router "${selectedRouter.value?.name}" primero.`)
        return
    }

    // PPPoE credentials required when section is visible
    if (showPppoeSection.value) {
        let valid = true
        if (!form.value.pppoe_username.trim()) {
            pppoeUserError.value = 'El usuario PPPoE es obligatorio.'
            valid = false
        }
        if (!form.value.pppoe_password.trim()) {
            pppoePassError.value = 'La contraseña PPPoE es obligatoria.'
            valid = false
        }
        if (!valid) return
    }

    loading.value = true

    try {
        const dataToSend = { ...form.value }
        if (!dataToSend.password) delete dataToSend.password

        const res   = await api.customers.update(route.params.id, dataToSend)
        const pppoe = res.data?.pppoe_provisioned

        if (showPppoeSection.value && pppoe && !pppoe.success) {
            toast.value?.warning(
                'Cliente actualizado con advertencia',
                `Datos guardados, pero el secret PPPoE no se pudo actualizar en ${selectedRouter.value?.name}: ${pppoe.message}`
            )
            setTimeout(() => router.push('/customers'), 2500)
        } else if (showPppoeSection.value && pppoe?.success) {
            toast.value?.success(
                'Cliente actualizado',
                `Datos guardados y secret PPPoE actualizado en ${selectedRouter.value?.name} correctamente.`
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
