<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex items-center gap-4 mb-6">
        <button
            @click="router.push('/sectorials')"
            class="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-white transition"
        >
            <v-icon name="md-arrowback" class="w-6 h-6" />
        </button>
        <div>
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Editar Sectorial</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Modifica los datos de la sectorial</p>
        </div>
        </div>

        <!-- Loading -->
        <div v-if="loadingData" class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
        <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando datos...</p>
        </div>

        <!-- Formulario -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 max-w-7xl mx-auto border border-gray-100 dark:border-gray-700">
        <form @submit.prevent="handleSubmit">
            
            <!-- Información Básica -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Información Básica
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
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
                    placeholder="Ej: Sectorial Norte A"
                />
                </div>

                <!-- Tipo -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Tipo</label>
                <input
                    v-model="form.type"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: Mikrotik"
                />
                </div>

                <!-- Usuario RB -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Usuario RouterBoard</label>
                <input
                    v-model="form.user_rb"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: admin"
                />
                </div>

                <!-- Contraseña RB -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Contraseña RouterBoard (opcional)
                </label>
                <input
                    v-model="form.pass_rb"
                    type="password"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Dejar vacío para no cambiar"
                />
                </div>

                <!-- Zona ID -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Zona ID</label>
                <input
                    v-model="form.zona_id"
                    type="number"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: 1"
                />
                </div>

                <!-- Frecuencia -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Frecuencia (MHz)</label>
                <input
                    v-model="form.frequency"
                    type="number"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: 5800"
                />
                </div>

                <!-- Nodo Torre -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Nodo Torre</label>
                <input
                    v-model="form.node_tower"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: Torre Central"
                />
                </div>

                <!-- SSID -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">SSID</label>
                <input
                    v-model="form.ssid"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: ISPWATCH-5G"
                />
                </div>
            </div>
            </div>

            <!-- Comentarios -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Información Adicional
            </h2>

            <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Comentarios</label>
                <textarea
                v-model="form.comments"
                rows="4"
                class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                placeholder="Notas adicionales sobre la sectorial..."
                ></textarea>
            </div>
            </div>

            <!-- Coordenadas (opcional, si lo necesitas) -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Ubicación (Opcional)
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Latitud -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Latitud</label>
                <input
                    v-model="coordinates.lat"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: 4.6097"
                />
                </div>

                <!-- Longitud -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Longitud</label>
                <input
                    v-model="coordinates.lng"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Ej: -74.0817"
                />
                </div>
            </div>
            </div>

            <!-- Error -->
            <div v-if="error" class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            {{ error }}
            </div>

            <!-- Botones -->
            <div class="flex gap-4">
            <button
                type="submit"
                :disabled="loading"
                class="flex-1 bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 disabled:bg-gray-400 dark:disabled:bg-gray-600 text-white py-3 rounded-lg font-medium transition"
            >
                {{ loading ? 'Guardando...' : 'Actualizar Sectorial' }}
            </button>
            <button
                type="button"
                @click="router.push('/sectorials')"
                class="px-8 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-3 rounded-lg transition"
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
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'

const router = useRouter()
const route = useRoute()

const form = ref({
    name: '',
    type: '',
    user_rb: '',
    pass_rb: '',
    zona_id: null,
    frequency: null,
    node_tower: '',
    comments: '',
    ssid: ''
})

const coordinates = ref({
    lat: '',
    lng: ''
})

const loading = ref(false)
const loadingData = ref(true)
const error = ref('')

const loadSectorial = async () => {
    try {
        const response = await api.sectorials.getOne(route.params.id)
        const sectorial = response.data

        form.value = {
        name: sectorial.name || '',
        type: sectorial.type || '',
        user_rb: sectorial.user_rb || '',
        pass_rb: '', // No cargar la contraseña por seguridad
        zona_id: sectorial.zona_id || null,
        frequency: sectorial.frequency || null,
        node_tower: sectorial.node_tower || '',
        comments: sectorial.comments || '',
        ssid: sectorial.ssid || ''
        }

        // Cargar coordenadas SOLO si existen y son válidas
        if (sectorial.coordinates) {
        try {
            let coords = null
            
            // Si coordinates es un string, intentar parsearlo
            if (typeof sectorial.coordinates === 'string') {
            // Verificar que no esté vacío y sea JSON válido
            const trimmed = sectorial.coordinates.trim()
            if (trimmed && trimmed !== '' && trimmed !== 'null') {
                coords = JSON.parse(trimmed)
            }
            } else if (typeof sectorial.coordinates === 'object' && sectorial.coordinates !== null) {
            // Si ya es un objeto, usarlo directamente
            coords = sectorial.coordinates
            }
            
            // Solo asignar si coords es válido y tiene lat/lng
            if (coords && (coords.lat || coords.lng)) {
            coordinates.value = {
                lat: coords.lat || '',
                lng: coords.lng || ''
            }
            } else {
            // Inicializar vacío si no hay datos válidos
            coordinates.value = { lat: '', lng: '' }
            }
        } catch (e) {
            console.warn('No se pudieron parsear las coordenadas:', e.message)
            // Inicializar vacío en caso de error
            coordinates.value = { lat: '', lng: '' }
        }
        } else {
        // Inicializar vacío si no hay coordenadas
        coordinates.value = { lat: '', lng: '' }
        }
    } catch (err) {
        console.error('Error al cargar sectorial:', err)
        error.value = err.response?.data?.message || 'Error al cargar los datos de la sectorial'
    } finally {
        loadingData.value = false
    }
}

const handleSubmit = async () => {
    loading.value = true
    error.value = ''

    try {
        const dataToSend = { ...form.value }
        
        // Si no se cambió la contraseña, no enviarla
        if (!dataToSend.pass_rb) {
        delete dataToSend.pass_rb
        }

        // Solo agregar coordenadas si AMBAS están completas
        if (coordinates.value.lat && coordinates.value.lng) {
        dataToSend.coordinates = JSON.stringify({
            lat: parseFloat(coordinates.value.lat),
            lng: parseFloat(coordinates.value.lng)
        })
        } else {
        // Si están vacías, enviar null o no enviar el campo
        dataToSend.coordinates = null
        }

        await api.sectorials.update(route.params.id, dataToSend)
        alert('Sectorial actualizada correctamente ✅')
        router.push('/sectorials')
    } catch (err) {
        console.error('Error al actualizar sectorial:', err)
        error.value = err.response?.data?.message || 'Error al actualizar la sectorial'
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    loadSectorial()
})
</script>