<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Nuevo Ticket de Soporte</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Crea un nuevo ticket de soporte</p>
        </div>

        <!-- Formulario -->
        <div class="max-w-3xl bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 border border-gray-100 dark:border-gray-700">
            <form @submit.prevent="handleSubmit">
                <!-- Selección de Cliente -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Cliente <span class="text-red-500">*</span>
                    </label>
                    <v-select
                        v-model="form.user_id"
                        :options="customers"
                        :reduce="customer => customer.user_id"
                        label="fullname"
                        placeholder="Buscar cliente..."
                        class="style-chooser"
                    >
                         <template #option="{ fullname, email }">
                            <div class="flex flex-col">
                                <span class="font-medium">{{ fullname }}</span>
                                <span class="text-xs text-gray-500">{{ email }}</span>
                            </div>
                        </template>
                    </v-select>
                    <p v-if="errors.user_id" class="mt-1 text-sm text-red-500">{{ errors.user_id }}</p>
                </div>

                <!-- Asunto -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Asunto <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="form.subject"
                        type="text"
                        placeholder="Ej: Problema con conexión a internet"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :class="{ 'border-red-500': errors.subject }"
                    />
                    <p v-if="errors.subject" class="mt-1 text-sm text-red-500">{{ errors.subject }}</p>
                </div>

                <!-- Descripción -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Descripción
                    </label>
                    <textarea
                        v-model="form.description"
                        rows="5"
                        placeholder="Describe tu problema en detalle..."
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    ></textarea>
                </div>

                <!-- Categoría -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Categoría <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.category"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :class="{ 'border-red-500': errors.category }"
                    >
                        <option value="">Selecciona una categoría</option>
                        <option value="technical">Técnico</option>
                        <option value="billing">Facturación</option>
                        <option value="services">Servicios</option>
                        <option value="general">General</option>
                    </select>
                    <p v-if="errors.category" class="mt-1 text-sm text-red-500">{{ errors.category }}</p>
                </div>

                <!-- Archivos Adjuntos -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Archivos Adjuntos
                    </label>
                    <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 text-center">
                        <input
                            ref="fileInput"
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.txt"
                            @change="handleFileChange"
                            class="hidden"
                        />
                        <button
                            type="button"
                            @click="$refs.fileInput.click()"
                            class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                        >
                            <v-icon name="pr-paperclip" class="w-5 h-5 inline mr-2" />
                            Seleccionar archivos
                        </button>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                            Máx. 5 archivos • 10MB cada uno • JPG, PNG, PDF, DOC, DOCX, TXT
                        </p>
                    </div>

                    <!-- Lista de archivos seleccionados -->
                    <div v-if="selectedFiles.length > 0" class="mt-4 space-y-2">
                        <div
                            v-for="(file, index) in selectedFiles"
                            :key="index"
                            class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg"
                        >
                            <div class="flex items-center gap-3">
                                <!-- Preview de imagen -->
                                <img
                                    v-if="file.preview"
                                    :src="file.preview"
                                    class="w-12 h-12 object-cover rounded"
                                />
                                <div v-else class="w-12 h-12 bg-gray-200 dark:bg-gray-600 rounded flex items-center justify-center">
                                    <v-icon name="pr-file" class="w-6 h-6 text-gray-500" />
                                </div>
                                
                                <div>
                                    <p class="text-sm font-medium text-gray-800 dark:text-white">{{ file.name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatFileSize(file.size) }}</p>
                                </div>
                            </div>
                            
                            <button
                                type="button"
                                @click="removeFile(index)"
                                class="text-red-500 hover:text-red-700 transition"
                            >
                                <v-icon name="io-close-circle" class="w-6 h-6" />
                            </button>
                        </div>
                    </div>
                    
                    <p v-if="errors.attachments" class="mt-2 text-sm text-red-500">{{ errors.attachments }}</p>
                </div>

                <!-- Botones -->
                <div class="flex gap-3">
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span v-if="!submitting">Crear Ticket</span>
                        <span v-else class="flex items-center justify-center gap-2">
                            <div class="animate-spin rounded-full h-5 w-5 border-2 border-white border-t-transparent"></div>
                            Creando...
                        </span>
                    </button>
                    <button
                        type="button"
                        @click="router.push('/support')"
                        class="px-6 py-3 rounded-lg font-medium border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const router = useRouter()

const form = ref({
    user_id: '', // Customer ID
    subject: '',
    description: '',
    category: ''
})

const customers = ref([])
const loadingCustomers = ref(false)

const selectedFiles = ref([])
const errors = ref({})
const submitting = ref(false)
const fileInput = ref(null)

const handleFileChange = (event) => {
    const files = Array.from(event.target.files)
    
    // Validar máximo 5 archivos
    if (selectedFiles.value.length + files.length > 5) {
        alert('Máximo 5 archivos permitidos')
        return
    }

    files.forEach(file => {
        // Validar tamaño (10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert(`El archivo ${file.name} excede el tamaño máximo de 10MB`)
            return
        }

        // Crear preview para imágenes
        const fileData = {
            file: file,
            name: file.name,
            size: file.size,
            preview: null
        }

        if (file.type.startsWith('image/')) {
            const reader = new FileReader()
            reader.onload = (e) => {
                fileData.preview = e.target.result
            }
            reader.readAsDataURL(file)
        }

        selectedFiles.value.push(fileData)
    })

    // Limpiar input
    event.target.value = ''
}

const removeFile = (index) => {
    selectedFiles.value.splice(index, 1)
}

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes'
    const k = 1024
    const sizes = ['Bytes', 'KB', 'MB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}

const validate = () => {
    errors.value = {}

    if (!form.value.user_id) {
        errors.value.user_id = 'El cliente es requerido'
    }

    if (!form.value.subject || form.value.subject.trim() === '') {
        errors.value.subject = 'El asunto es requerido'
    }

    if (!form.value.category) {
        errors.value.category = 'La categoría es requerida'
    }

    return Object.keys(errors.value).length === 0
}

const handleSubmit = async () => {
    if (!validate()) return

    try {
        submitting.value = true

        // Crear FormData para enviar archivos
        const formData = new FormData()
        formData.append('user_id', form.value.user_id)
        formData.append('subject', form.value.subject)
        if (form.value.description) {
            formData.append('description', form.value.description)
        }
        formData.append('category', form.value.category)

        // Agregar archivos
        selectedFiles.value.forEach((fileData) => {
            formData.append('attachments[]', fileData.file)
        })

        await api.support.create(formData)

        alert('Ticket creado correctamente. ✅')
        router.push('/support')
    } catch (err) {
        console.error('Error al crear ticket:', err)
        if (err.response?.data?.errors) {
            errors.value = err.response.data.errors
        } else {
            alert('Error al crear el ticket. Por favor intenta de nuevo.')
        }
    } finally {
        submitting.value = false
    }
}
const loadCustomers = async () => {
    try {
        loadingCustomers.value = true
        const response = await api.customers.getAll()
        customers.value = response.data.map(c => ({
            ...c,
            fullname: `${c.name} ${c.last_name} (${c.email})`
        }))
    } catch (err) {
        console.error('Error al cargar clientes:', err)
    } finally {
        loadingCustomers.value = false
    }
}

// Cargar clientes al montar
import { onMounted } from 'vue'
onMounted(() => {
    loadCustomers()
})
</script>

<style>
.style-chooser .vs__search::placeholder,
.style-chooser .vs__dropdown-toggle,
.style-chooser .vs__dropdown-menu {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    color: #374151;
    text-transform: lowercase;
    font-variant: small-caps;
}

.dark .style-chooser .vs__search::placeholder,
.dark .style-chooser .vs__dropdown-toggle,
.dark .style-chooser .vs__dropdown-menu {
    background: #1f2937;
    border: 1px solid #4b5563;
    color: #e5e7eb;
}

.dark .style-chooser .vs__clear,
.dark .style-chooser .vs__open-indicator {
    fill: #9ca3af;
}

.dark .style-chooser .vs__dropdown-option {
    color: #e5e7eb;
}

.dark .style-chooser .vs__dropdown-option--highlight {
    background: #374151;
    color: white;
}
</style>
