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
            <h1 class="text-xl sm:text-2xl md:text-3xl font-bold text-gray-800 dark:text-gray-100">Nuevo Cliente</h1>
            <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">Registra un nuevo cliente con sus credenciales</p>
        </div>
        </div>

        <!-- Formulario -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 sm:p-6 md:p-8 max-w-7xl mx-auto border border-gray-100 dark:border-gray-700">
        <form @submit.prevent="handleSubmit">

            <!-- Sección: Datos de Acceso -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Datos de Acceso
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Email <span class="text-red-500">*</span>
                </label>
                <input v-model="form.email" type="email" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="ejemplo@empresa.com" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Contraseña <span class="text-red-500">*</span>
                </label>
                <input v-model="form.password" type="password" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Mínimo 6 caracteres" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Teléfono</label>
                <input v-model="form.tel" type="tel"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="+57 300 123 4567" />
                </div>
            </div>
            </div>

            <!-- Sección: Información del Cliente -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Información del Cliente
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Nombre <span class="text-red-500">*</span>
                </label>
                <input v-model="form.name" type="text" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Juan" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Apellido <span class="text-red-500">*</span>
                </label>
                <input v-model="form.last_name" type="text" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Pérez" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Cédula <span class="text-red-500">*</span>
                </label>
                <input v-model="form.cedula" type="text" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: 1234567890" />
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
                <!-- IP del Usuario con selector de IPs libres -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    IP del Usuario
                    <span v-if="loadingFreeIps" class="ml-1 text-xs text-blue-400">cargando...</span>
                    <span v-else-if="freeIps.length > 0" class="ml-1 text-xs text-green-500">{{ freeIps.length }} libres</span>
                </label>
                <div class="relative">
                    <input v-model="form.ip_user" type="text"
                        class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 pr-10 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="192.168.1.100"
                        @focus="showIpDropdown = freeIps.length > 0"
                        @blur="setTimeout(() => showIpDropdown = false, 200)" />
                    <button v-if="freeIps.length > 0" type="button"
                        @click="showIpDropdown = !showIpDropdown"
                        class="absolute right-2 top-2.5 text-gray-400 hover:text-blue-500 transition">
                        <v-icon name="md-expandmore" class="w-5 h-5" />
                    </button>
                    <!-- Dropdown of free IPs -->
                    <div v-if="showIpDropdown && freeIps.length > 0"
                        class="absolute z-20 top-full left-0 right-0 mt-1 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-xl max-h-52 overflow-y-auto">
                        <div class="px-3 py-2 text-xs font-semibold text-gray-400 dark:text-gray-300 border-b border-gray-100 dark:border-gray-600 flex justify-between">
                            <span>IPs libres</span>
                            <span>{{ ipRangeStats.used }}/{{ ipRangeStats.total }} en uso</span>
                        </div>
                        <button v-for="ip in freeIps" :key="ip" type="button"
                            @mousedown.prevent="form.ip_user = ip; showIpDropdown = false"
                            class="w-full text-left px-4 py-2 text-sm font-mono text-gray-700 dark:text-gray-200 hover:bg-blue-50 dark:hover:bg-blue-900/30 hover:text-blue-700 dark:hover:text-blue-300 transition">
                            {{ ip }}
                        </button>
                    </div>
                </div>
                <!-- Range stats bar -->
                <div v-if="ipRangeStats.total > 0" class="mt-1.5 flex items-center gap-2">
                    <div class="flex-1 h-1 bg-gray-200 dark:bg-gray-600 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-500 rounded-full transition-all"
                            :style="{ width: ipRangeStats.usagePercent + '%' }" />
                    </div>
                    <span class="text-xs text-gray-400">{{ ipRangeStats.usagePercent }}%</span>
                </div>
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
                El secret PPPoE se creará automáticamente en <strong>{{ selectedRouter?.name }}</strong> al guardar.
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
                {{ loading ? 'Guardando...' : 'Guardar Cliente' }}
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
import { useRouter } from 'vue-router'
import api from '../services/api'
import NotificationToast from '@/components/NotificationToast.vue'

const router = useRouter()
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
const errorMsg       = ref('')
const pppoeUserError = ref('')
const pppoePassError = ref('')
const plans          = ref([])
const sectorials     = ref([])
const routers        = ref([])

// ── Free IP picker ───────────────────────────────────────────────────────────
const freeIps        = ref([])
const loadingFreeIps = ref(false)
const showIpDropdown = ref(false)
const ipRangeStats   = ref({ total: 0, used: 0, free: 0, usagePercent: 0 })

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

// Auto-fill credentials and toggle create_pppoe_secret when section appears/disappears
watch(showPppoeSection, (visible) => {
    form.value.create_pppoe_secret = visible
    if (visible && !form.value.pppoe_username) {
        const n = form.value.name.toLowerCase().replace(/\s+/g, '')
        const l = form.value.last_name.toLowerCase().replace(/\s+/g, '')
        if (n && l) form.value.pppoe_username = `${n}.${l}`
    }
})

// Re-fill username when name/last_name change while section is visible
watch([() => form.value.name, () => form.value.last_name], ([n, l]) => {
    if (!showPppoeSection.value) return
    const username = n.toLowerCase().replace(/\s+/g, '') + '.' + l.toLowerCase().replace(/\s+/g, '')
    if (username !== '.') form.value.pppoe_username = username
})

const loadFreeIps = async (routerId) => {
    freeIps.value      = []
    ipRangeStats.value = { total: 0, used: 0, free: 0, usagePercent: 0 }
    if (!routerId) return
    loadingFreeIps.value = true
    try {
        const res = await api.routers.getFreeIps(routerId)
        freeIps.value = res.data.free_ips ?? []
        const totals  = (res.data.ranges ?? []).reduce((a, r) => ({ total: a.total + r.total, used: a.used + r.used }), { total: 0, used: 0 })
        ipRangeStats.value = {
            total: totals.total,
            used:  totals.used,
            free:  freeIps.value.length,
            usagePercent: totals.total > 0 ? Math.round((totals.used / totals.total) * 100) : 0,
        }
    } catch (e) {
        console.warn('No se pudieron cargar IPs libres:', e)
    } finally {
        loadingFreeIps.value = false
    }
}

watch(() => form.value.router_id, (id) => loadFreeIps(id))

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

onMounted(loadCatalogs)

const handleSubmit = async () => {
    errorMsg.value     = ''
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
        const res   = await api.customers.create(form.value)
        const pppoe = res.data?.pppoe_provisioned

        if (showPppoeSection.value && pppoe && !pppoe.success) {
            toast.value?.warning(
                'Cliente creado con advertencia',
                `Datos guardados, pero el secret PPPoE no se pudo crear en ${selectedRouter.value?.name}: ${pppoe.message}`
            )
            setTimeout(() => router.push('/customers'), 2500)
        } else {
            const extra = showPppoeSection.value ? ` Secret PPPoE creado en ${selectedRouter.value?.name}.` : ''
            toast.value?.success('Cliente creado', `El cliente fue registrado correctamente.${extra}`)
            setTimeout(() => router.push('/customers'), 1500)
        }
    } catch (err) {
        console.error('Error al crear cliente:', err)
        const msg = err.response?.data?.message || 'Error al crear el cliente.'
        errorMsg.value = msg
        toast.value?.error('Error al crear', msg)
    } finally {
        loading.value = false
    }
}
</script>
