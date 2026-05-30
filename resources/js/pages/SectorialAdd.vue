<template>
    <div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 p-4 md:p-8">
        <!-- Notification Toast -->
        <NotificationToast ref="toast" />
        
        <!-- Header mejorado -->
        <div class="max-w-5xl mx-auto mb-8">
            <div class="flex items-center gap-4">
                <button
                    @click="router.push('/sectorials')"
                    class="p-2.5 rounded-xl bg-white dark:bg-gray-800 shadow-md hover:shadow-lg 
                           text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 
                           transition-all duration-300 transform hover:-translate-x-1"
                >
                    <v-icon name="md-arrowback" class="w-5 h-5" />
                </button>
                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <div class="p-3 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl shadow-lg">
                            <v-icon name="md-settings" class="w-6 h-6 text-white" />
                        </div>
                        <div>
                            <h1 class="text-2xl md:text-3xl font-bold text-gray-800 dark:text-white">
                                Nuevo Elemento de Red
                            </h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Registra una sectorial, switch o nodo en la red
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Formulario Principal Unificado -->
        <div class="max-w-5xl mx-auto">
            <form @submit.prevent="handleSubmit">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border border-gray-100 dark:border-gray-700 p-6 md:p-8">

                    <!-- Tipo de elemento (Sectorial / Switch / Nodo) -->
                    <div class="mb-6">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            <v-icon name="md-filterlist" class="w-4 h-4 text-indigo-500" />
                            Tipo de elemento <span class="text-red-500">*</span>
                        </label>
                        <div class="grid grid-cols-3 gap-3">
                            <button
                                v-for="opt in elementTypes"
                                :key="opt.value"
                                type="button"
                                @click="form.element_type = opt.value"
                                :class="[
                                    'flex flex-col items-center gap-2 p-4 rounded-xl border-2 transition-all',
                                    form.element_type === opt.value
                                        ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/30 shadow-md'
                                        : 'border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/30 hover:border-indigo-300'
                                ]"
                            >
                                <v-icon :name="opt.icon" class="w-7 h-7" :class="form.element_type === opt.value ? 'text-indigo-600 dark:text-indigo-300' : 'text-gray-500 dark:text-gray-400'" />
                                <span class="text-sm font-semibold" :class="form.element_type === opt.value ? 'text-indigo-700 dark:text-indigo-200' : 'text-gray-700 dark:text-gray-300'">{{ opt.label }}</span>
                                <span class="text-[11px] text-gray-500 dark:text-gray-400 text-center leading-tight">{{ opt.hint }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                        <!-- Nombre -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-info" class="w-4 h-4 text-indigo-500" />
                                Nombre <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-indigo-500 dark:focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10
                                       transition-all duration-300 placeholder:text-gray-400"
                                placeholder="Ej: Sectorial Norte A"
                            />
                        </div>

                        <!-- IP -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-router" class="w-4 h-4 text-blue-500" />
                                Dirección IP
                            </label>
                            <input
                                v-model="form.ip"
                                type="text"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-blue-500 dark:focus:border-blue-400 focus:ring-4 focus:ring-blue-500/10
                                       transition-all duration-300 placeholder:text-gray-400 font-mono"
                                placeholder="192.168.1.100"
                            />
                        </div>

                        <!-- Tipo (subtipo, ej. Access Point/Bridge - solo aplica a sectorial) -->
                        <div v-if="form.element_type === 'sectorial'" class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-filterlist" class="w-4 h-4 text-purple-500" />
                                Subtipo
                            </label>
                            <div class="relative">
                                <select
                                    v-model="form.type"
                                    class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                           bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                           focus:border-purple-500 dark:focus:border-purple-400 focus:ring-4 focus:ring-purple-500/10
                                           transition-all duration-300 appearance-none cursor-pointer"
                                >
                                    <option :value="null" disabled>Seleccione un tipo...</option>
                                    <option value="Access Point">📡 Access Point</option>
                                    <option value="Station">📶 Station</option>
                                    <option value="NAP">🏢 NAP (Nodo de Acceso Principal)</option>
                                    <option value="Bridge">🌉 Bridge</option>
                                    <option value="Repeater">🔄 Repeater</option>
                                    <option value="PTP">↔️ PTP (Punto a Punto)</option>
                                    <option value="PTMP">🔀 PTMP (Punto Multipunto)</option>
                                </select>
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <v-icon name="md-keyboardarrowdown" class="w-5 h-5 text-gray-400" />
                                </div>
                            </div>
                        </div>

                        <!-- Router (Zona) -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-router" class="w-4 h-4 text-green-500" />
                                Router <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <select
                                    v-model="form.zona_id"
                                    required
                                    class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                           bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                           focus:border-green-500 dark:focus:border-green-400 focus:ring-4 focus:ring-green-500/10
                                           transition-all duration-300 appearance-none cursor-pointer"
                                >
                                    <option :value="null" disabled>Seleccione un router...</option>
                                    <option v-for="router in routers" :key="router.id" :value="router.id">
                                        {{ router.name }} ({{ router.ip }})
                                    </option>
                                </select>
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <v-icon name="md-keyboardarrowdown" class="w-5 h-5 text-gray-400" />
                                </div>
                            </div>
                        </div>

                        <!-- SSID -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-wifi" class="w-4 h-4 text-cyan-500" />
                                SSID
                            </label>
                            <input
                                v-model="form.ssid"
                                type="text"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-cyan-500 dark:focus:border-cyan-400 focus:ring-4 focus:ring-cyan-500/10
                                       transition-all duration-300 placeholder:text-gray-400"
                                placeholder="ISPWATCH-5G"
                            />
                        </div>

                        <!-- Frecuencia -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="hi-wifi" class="w-4 h-4 text-orange-500" />
                                Frecuencia (MHz)
                            </label>
                            <input
                                v-model="form.frequency"
                                type="number"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-orange-500 dark:focus:border-orange-400 focus:ring-4 focus:ring-orange-500/10
                                       transition-all duration-300 placeholder:text-gray-400"
                                placeholder="5800"
                            />
                        </div>

                        <!-- Usuario RB -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-person" class="w-4 h-4 text-emerald-500" />
                                Usuario RouterBoard
                            </label>
                            <input
                                v-model="form.user_rb"
                                type="text"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-emerald-500 dark:focus:border-emerald-400 focus:ring-4 focus:ring-emerald-500/10
                                       transition-all duration-300 placeholder:text-gray-400"
                                placeholder="admin"
                            />
                        </div>

                        <!-- Contraseña RB -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-lock" class="w-4 h-4 text-emerald-500" />
                                Contraseña RouterBoard
                            </label>
                            <input
                                v-model="form.pass_rb"
                                type="password"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-emerald-500 dark:focus:border-emerald-400 focus:ring-4 focus:ring-emerald-500/10
                                       transition-all duration-300 placeholder:text-gray-400"
                                placeholder="••••••••"
                            />
                        </div>

                        <!-- Nodo Torre -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-settings" class="w-4 h-4 text-teal-500" />
                                Nodo Torre
                            </label>
                            <input
                                v-model="form.node_tower"
                                type="text"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-teal-500 dark:focus:border-teal-400 focus:ring-4 focus:ring-teal-500/10
                                       transition-all duration-300 placeholder:text-gray-400"
                                placeholder="Torre Central"
                            />
                        </div>

                        <!-- Latitud -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-locationon" class="w-4 h-4 text-rose-500" />
                                Latitud
                            </label>
                            <input
                                v-model="coordinates.lat"
                                type="text"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-rose-500 dark:focus:border-rose-400 focus:ring-4 focus:ring-rose-500/10
                                       transition-all duration-300 placeholder:text-gray-400 font-mono"
                                placeholder="4.6097"
                            />
                        </div>

                        <!-- Longitud -->
                        <div class="group">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-locationon" class="w-4 h-4 text-pink-500" />
                                Longitud
                            </label>
                            <input
                                v-model="coordinates.lng"
                                type="text"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-pink-500 dark:focus:border-pink-400 focus:ring-4 focus:ring-pink-500/10
                                       transition-all duration-300 placeholder:text-gray-400 font-mono"
                                placeholder="-74.0817"
                            />
                        </div>

                        <!-- Comentarios - Full Width -->
                        <div class="group md:col-span-2 lg:col-span-3">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <v-icon name="md-description" class="w-4 h-4 text-amber-500" />
                                Notas adicionales
                            </label>
                            <textarea
                                v-model="form.comments"
                                rows="3"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 
                                       bg-gray-50 dark:bg-gray-700/50 text-gray-800 dark:text-white
                                       focus:border-amber-500 dark:focus:border-amber-400 focus:ring-4 focus:ring-amber-500/10
                                       transition-all duration-300 placeholder:text-gray-400 resize-none"
                                placeholder="Agrega cualquier información adicional..."
                            ></textarea>
                        </div>
                    </div>

                    <!-- Error Message -->
                    <div v-if="error" class="mt-6 bg-red-50 dark:bg-red-900/20 border-2 border-red-200 dark:border-red-500/50 
                                text-red-700 dark:text-red-400 px-5 py-4 rounded-xl flex items-center gap-3">
                        <v-icon name="md-error" class="w-5 h-5 flex-shrink-0" />
                        <span>{{ error }}</span>
                    </div>

                    <!-- Botones de Acción -->
                    <div class="flex flex-col sm:flex-row gap-4 mt-8">
                        <button
                            type="submit"
                            :disabled="loading"
                            class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 
                                   disabled:from-gray-400 disabled:to-gray-500 text-white py-4 rounded-xl font-semibold 
                                   shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 
                                   transition-all duration-300 flex items-center justify-center gap-2"
                        >
                            <v-icon v-if="!loading" name="md-save" class="w-5 h-5" />
                            <div v-else class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                            {{ loading ? 'Guardando...' : `Crear ${elementLabel}` }}
                        </button>
                        <button
                            type="button"
                            @click="router.push('/sectorials')"
                            class="px-8 py-4 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 
                                   text-gray-700 dark:text-gray-200 rounded-xl font-medium transition-all duration-300
                                   flex items-center justify-center gap-2"
                        >
                            <v-icon name="md-close" class="w-5 h-5" />
                            Cancelar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { supabase } from '@/supabase.js'
import api from '../services/api'
import NotificationToast from '@/components/NotificationToast.vue'

const router = useRouter()

const form = ref({
    name: '',
    element_type: 'sectorial',
    ip: '',
    type: null,
    user_rb: '',
    pass_rb: '',
    zona_id: null,
    frequency: null,
    node_tower: '',
    comments: '',
    ssid: '',
    tenant_id: null
})

const elementTypes = [
    { value: 'sectorial', label: 'Sectorial', icon: 'md-router',      hint: 'Punto de acceso wireless' },
    { value: 'switch',    label: 'Switch',    icon: 'bi-hdd-network', hint: 'Switch / equipo de capa 2' },
    { value: 'nodo',      label: 'Nodo',      icon: 'bi-diagram-3',   hint: 'Nodo / sitio / torre' },
]

const loading = ref(false)
const error = ref('')
const routers = ref([])
const toast = ref(null)

const coordinates = ref({
    lat: '',
    lng: ''
})

const elementLabel = computed(() => {
    const found = elementTypes.find(e => e.value === form.value.element_type)
    return found ? found.label : 'Elemento'
})

const loadRouters = async () => {
  const userData = 
    JSON.parse(localStorage.getItem("userData")) ??
    JSON.parse(sessionStorage.getItem("userData"))

  if (!userData || !userData.tenant_id) {
    console.error("⚠️ No se encontró tenant_id del usuario autenticado.")
    error.value = "No se pudo obtener la información del usuario"
    return
  }

  const { data, error: fetchError } = await supabase
    .from("router")
    .select("id, name, ip")
    .eq("tenant_id", userData.tenant_id)
    .eq("status", "active")

  if (fetchError) {
    console.error("❌ Error al cargar routers:", fetchError.message)
    error.value = "Error al cargar la lista de routers"
    return
  }

  routers.value = data || []
}

onMounted(() => {
  const userData = 
    JSON.parse(localStorage.getItem("userData")) ??
    JSON.parse(sessionStorage.getItem("userData"))
  
  if (userData?.tenant_id) {
    form.value.tenant_id = userData.tenant_id
  } else {
    error.value = 'No se pudo obtener la información del tenant. Por favor inicia sesión nuevamente.'
  }
  
  loadRouters()
})

const handleSubmit = async () => {
    loading.value = true
    error.value = ''

    if (!form.value.tenant_id) {
        const userData = 
            JSON.parse(localStorage.getItem("userData")) ??
            JSON.parse(sessionStorage.getItem("userData"))
        
        if (userData?.tenant_id) {
            form.value.tenant_id = userData.tenant_id
        } else {
            toast.value?.error('Sesión inválida', 'No se encontró información del tenant.')
            loading.value = false
            return
        }
    }

    try {
        const dataToSend = { ...form.value }
        
        if (coordinates.value.lat && coordinates.value.lng) {
            dataToSend.coordinates = JSON.stringify({
                lat: parseFloat(coordinates.value.lat),
                lng: parseFloat(coordinates.value.lng)
            })
        } else {
            dataToSend.coordinates = null
        }
        
        await api.sectorials.create(dataToSend)
        toast.value?.success('Sectorial creada', 'La sectorial ha sido registrada correctamente')
        setTimeout(() => {
            router.push({ name: 'Sectorials' })
        }, 1500)
    } catch (err) {
        const detail = err.response?.data?.error
        const msg = err.response?.data?.message || 'Error al crear la sectorial'
        error.value = detail ? `${msg}: ${detail}` : msg
        console.error('Error al crear sectorial:', err.response?.data ?? err)
        toast.value?.error('Error al crear', error.value)
    } finally {
        loading.value = false
    }
}
</script>