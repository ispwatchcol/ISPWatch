<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Notification Toast -->
        <NotificationToast ref="toast" />
        
        <div v-if="loading" class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
            <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando ticket...</p>
        </div>

        <div v-else class="max-w-7xl mx-auto">
            <!-- Header -->
            <div class="mb-6 flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">
                        Ticket #{{ ticket.id }} - {{ ticket.subject }}
                    </h1>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">
                        Creado el {{ formatDate(ticket.created_at) }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <button
                        v-if="canEdit"
                        @click="router.push(`/support/${ticketId}/edit`)"
                        class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition"
                    >
                    <v-icon name="fa-edit" class="w-5 h-5 inline mr-2"></v-icon>
                        Editar
                    </button>
                    <button
                        @click="router.push('/support')"
                        class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition"
                    >
                    <v-icon name="ri-arrow-go-back-line" class="w-5 h-5 inline mr-2"></v-icon>
                        Volver
                    </button>
                </div>
            </div>

            <!-- Badges -->
            <div class="flex gap-3 mb-6">
                <span :class="getStatusBadgeClass(ticket.status)">{{ getStatusLabel(ticket.status) }}</span>
                <span :class="getPriorityBadgeClass(ticket.priority)">{{ getPriorityLabel(ticket.priority) }}</span>
                <span :class="getCategoryBadgeClass(ticket.category)">{{ getCategoryLabel(ticket.category) }}</span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna Principal -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Descripción -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Descripción</h2>
                        <p class="text-gray-600 dark:text-gray-300">{{ ticket.description || 'Sin descripción' }}</p>
                    </div>

                    <!-- Bitácora de Trabajo (Notas del Staff) -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Bitácora de Trabajo</h2>
                            <button 
                                v-if="canEdit"
                                @click="showNoteForm = true"
                                class="text-sm bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg transition flex items-center gap-1"
                            >
                                <v-icon name="md-add" class="w-4 h-4" />
                                Nueva Nota
                            </button>
                        </div>

                        <!-- Formulario para Nueva Nota -->
                        <div v-if="showNoteForm" class="mb-6 bg-gray-50 dark:bg-gray-700/50 p-4 rounded-lg border border-gray-200 dark:border-gray-600">
                            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
                                {{ editingNoteId ? 'Editar Nota' : 'Agregar Nueva Nota' }}
                            </h3>
                            <textarea 
                                v-model="noteContent"
                                rows="3"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 mb-3"
                                placeholder="Escribe aquí los detalles del trabajo realizado..."
                            ></textarea>
                            <div class="flex justify-end gap-2 text-sm">
                                <button 
                                    @click="cancelNote"
                                    class="px-3 py-1 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-600 rounded"
                                >
                                    Cancelar
                                </button>
                                <button 
                                    @click="saveNote"
                                    :disabled="!noteContent.trim() || savingNote"
                                    class="px-4 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition disabled:opacity-50"
                                >
                                    {{ savingNote ? 'Guardando...' : 'Guardar' }}
                                </button>
                            </div>
                        </div>

                        <!-- Lista de Notas -->
                        <div v-if="ticket.messages?.length > 0" class="space-y-4">
                            <div v-for="message in ticket.messages" :key="message.id" 
                                 class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-100 dark:border-gray-700 relative group">
                                <div class="flex justify-between items-start mb-2">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center text-blue-600 dark:text-blue-400 font-bold text-xs">
                                            {{ message.user?.user_name?.charAt(0) }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ message.user?.user_name }}</p>
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ formatDate(message.created_at) }}</p>
                                        </div>
                                    </div>
                                    <div v-if="canEdit" class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button @click="editNote(message)" class="p-1 text-gray-400 hover:text-blue-500 transition-colors">
                                            <v-icon name="fa-edit" class="w-4 h-4" />
                                        </button>
                                        <button @click="deleteNote(message.id)" class="p-1 text-gray-400 hover:text-red-500 transition-colors">
                                            <v-icon name="md-delete" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{{ message.message }}</p>
                            </div>
                        </div>
                        <div v-else class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm italic">
                            No hay notas registradas en este ticket.
                        </div>
                    </div>

                    <!-- Archivos Adjuntos -->
                    <div v-if="ticket.attachments?.length > 0" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Archivos Adjuntos</h2>
                        <div class="space-y-2">
                            <div v-for="attachment in ticket.attachments" :key="attachment.id"
                                 class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <div class="flex items-center gap-3">
                                    <v-icon name="bi-file-earmark" class="w-5 h-5 text-gray-500" />
                                    <span class="text-sm text-gray-800 dark:text-white">{{ attachment.file_name }}</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <button 
                                        v-if="isImage(attachment.file_name)"
                                        @click="openImage(attachment.url)"
                                        class="text-blue-600 hover:text-blue-700 text-sm flex items-center gap-1"
                                    >
                                        <v-icon name="fa-eye" class="w-4 h-4" />
                                        Ver
                                    </button>
                                    <a :href="attachment.url" target="_blank" download
                                       class="text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white text-sm flex items-center gap-1">
                                        <v-icon name="md-download" class="w-4 h-4" />
                                        Descargar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal de Visualización de Imagen (Estilo Manual) -->
                    <Teleport to="body">
                        <div 
                          v-if="lightboxImage" 
                          class="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-black/70 backdrop-blur-md"
                          @click="lightboxImage = null"
                        >
                          <div 
                            class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-5xl w-full mx-4 overflow-hidden transform transition-all border border-gray-100 dark:border-gray-700 mx-auto"
                            @click.stop
                          >
                            <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-start bg-indigo-50/50 dark:bg-gray-700/30">
                              <div>
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Vista Previa</h3>
                                 <span class="text-xs text-indigo-500 dark:text-indigo-300 uppercase font-semibold tracking-wider mt-1 block">Imagen Adjunta</span>
                              </div>
                              <button 
                                @click="lightboxImage = null"
                                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-600"
                              >
                                <v-icon name="io-close" class="w-6 h-6" />
                              </button>
                            </div>
                            
                            <div class="p-0 bg-gray-50 dark:bg-gray-900/50 flex justify-center items-center">
                                <img :src="lightboxImage" class="max-w-full max-h-[80vh] rounded-none shadow-sm" alt="Vista previa" />
                            </div>

                            <div class="p-6 border-t border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-800 flex justify-end gap-3">
                               <a 
                                :href="lightboxImage" 
                                download 
                                target="_blank"
                                class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 font-medium text-sm transition-colors flex items-center gap-2"
                              >
                                <v-icon name="md-download" class="w-4 h-4" />
                                Descargar
                              </a>
                              <button 
                                @click="lightboxImage = null"
                                class="px-5 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium text-sm transition-colors"
                              >
                                Cerrar
                              </button>
                            </div>
                          </div>
                        </div>
                    </Teleport>
                   
                   <!-- Gestión del Ticket (Staff Only) -->
                    <div v-if="canEdit" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                         <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Gestión del Ticket</h2>
                         
                         <div class="space-y-4">
                            <!-- Subir Imágenes de Trabajo -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Evidencia de Trabajo (Imágenes)</label>
                                <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-4 text-center">
                                    <input
                                        ref="fileInput"
                                        type="file"
                                        multiple
                                        accept="image/*"
                                        @change="handleFileChange"
                                        class="hidden"
                                    />
                                    <button
                                        type="button"
                                        @click="fileInput.click()"
                                        class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                                    >
                                        <v-icon name="bi-images" class="w-5 h-5 inline mr-2" />
                                        Seleccionar Imágenes
                                    </button>
                                </div>

                                <!-- Previsualización -->
                                <div v-if="selectedFiles.length > 0" class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                                    <div v-for="(file, index) in selectedFiles" :key="index" class="relative group">
                                        <img :src="file.preview" class="w-full h-24 object-cover rounded-lg border border-gray-200 dark:border-gray-700" />
                                        <button 
                                            @click="removeFile(index)"
                                            class="absolute top-1 right-1 bg-red-500 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition"
                                        >
                                            <v-icon name="io-close" class="w-3 h-3" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <button
                                @click="updateTicket"
                                :disabled="updating"
                                class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition disabled:opacity-50"
                            >
                                <span v-if="!updating">Actualizar Ticket</span>
                                <span v-else>Guardando...</span>
                            </button>
                         </div>
                    </div>
                </div>

                <!-- Columna Lateral -->
                <div class="space-y-6">
                    <!-- Info del Cliente -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Cliente</h3>
                        <div class="space-y-2">
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-semibold">Nombre:</span> {{ ticket.user?.user_name }} {{ ticket.user?.user_lastname }}
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-semibold">Email:</span> {{ ticket.user?.email }}
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-semibold">Teléfono:</span> {{ ticket.user?.tel }}
                            </p>
                        </div>
                    </div>
                    <!-- Staff asignado  -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Técnico asignado</h3>
                        <div class="space-y-2">
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-semibold">Nombre:</span> {{ ticket.staff?.user_name || "No asignado"}}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import NotificationToast from '../components/NotificationToast.vue'

const router = useRouter()
const route = useRoute()
const ticketId = route.params.id

const authStore = useAuthStore()
const canEdit = computed(() => authStore.hasPermission('view_support'))

const ticket = ref({})
const loading = ref(true)
const selectedFiles = ref([])
const updating = ref(false)
const fileInput = ref(null)
const toast = ref(null)

// Bitácora de Trabajo state
const showNoteForm = ref(false)
const noteContent = ref('')
const savingNote = ref(false)
const editingNoteId = ref(null)

const userData = JSON.parse(localStorage.getItem('userData')) || {}
const currentUserId = userData.id || 1

const editNote = (note) => {
    editingNoteId.value = note.id
    noteContent.value = note.message
    showNoteForm.value = true
}

const cancelNote = () => {
    showNoteForm.value = false
    noteContent.value = ''
    editingNoteId.value = null
}

const saveNote = async () => {
    if (!noteContent.value.trim()) return
    
    try {
        savingNote.value = true
        if (editingNoteId.value) {
            await api.support.updateMessage(editingNoteId.value, noteContent.value)
            toast.value?.success('Nota actualizada', 'La nota se ha actualizado correctamente.')
        } else {
            // isInternal = true for work log notes, userId from current session
            await api.support.addMessage(ticketId, noteContent.value, true, currentUserId)
            toast.value?.success('Nota guardada', 'La nota ha sido agregada a la bitácora.')
        }
        
        cancelNote()
        loadTicket() // Refresh ticket to see new messages
    } catch (err) {
        console.error('Error al guardar nota:', err)
        toast.value?.error('Error', 'No se pudo guardar la nota en la bitácora.')
    } finally {
        savingNote.value = false
    }
}

const deleteNote = async (id) => {
    if (!confirm('¿Estás seguro de eliminar esta nota?')) return
    
    try {
        await api.support.deleteMessage(id)
        toast.value?.success('Nota eliminada', 'La nota ha sido eliminada de la bitácora.')
        loadTicket()
    } catch (err) {
        console.error('Error al eliminar nota:', err)
        toast.value?.error('Error', 'No se pudo eliminar la nota.')
    }
}

const loadTicket = async () => {
    try {
        loading.value = true
        const response = await api.support.getOne(ticketId)
        ticket.value = response.data
    } catch (err) {
        console.error('Error al cargar ticket:', err)
        toast.value?.error('Error', 'Error al cargar los detalles del ticket.')
        router.push('/support')
    } finally {
        loading.value = false
    }
}

const lightboxImage = ref(null)

const isImage = (filename) => {
    if (!filename) return false
    const ext = filename.split('.').pop().toLowerCase()
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)
}

const openImage = (url) => {
    lightboxImage.value = url
}

const handleFileChange = (event) => {
    const files = Array.from(event.target.files)
    
    files.forEach(file => {
        if (!file.type.startsWith('image/')) return

        const reader = new FileReader()
        reader.onload = (e) => {
            selectedFiles.value.push({
                file,
                preview: e.target.result
            })
        }
        reader.readAsDataURL(file)
    })
    
    // reset input
    event.target.value = ''
}

const removeFile = (index) => {
    selectedFiles.value.splice(index, 1)
}

const updateTicket = async () => {
    try {
        updating.value = true
        
        const formData = new FormData()
        
        // Only append if it exists and is valid
        if (ticket.value.status) {
            formData.append('status', ticket.value.status)
        }
        
        // Method spoofing for files via PUT
        formData.append('_method', 'PUT') 
        
        if (selectedFiles.value.length > 0) {
            selectedFiles.value.forEach(f => {
                formData.append('attachments[]', f.file)
            })
        }
        
        await api.support.update(ticketId, formData) 
        toast.value?.success('Éxito', 'Ticket actualizado correctamente.')
        selectedFiles.value = []
        loadTicket()
    } catch (err) {
        console.error('Error al actualizar ticket:', err)
        
        if (err.response?.status === 422) {
            const validationErrors = err.response.data.errors
            const firstError = Object.values(validationErrors)[0][0]
            toast.value?.error('Error de validación', firstError)
        } else {
            toast.value?.error('Error', 'No se pudo actualizar el ticket.')
        }
    } finally {
        updating.value = false
    }
}

// Helper functions (same as Support.vue)
const getStatusBadgeClass = (status) => {
    const classes = {
        open: 'px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
        in_progress: 'px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
        resolved: 'px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        closed: 'px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
    }
    return classes[status] || classes.open
}

const getStatusLabel = (status) => {
    const labels = { open: 'Abierto', in_progress: 'En Progreso', resolved: 'Resuelto', closed: 'Cerrado' }
    return labels[status] || status
}

const getPriorityBadgeClass = (priority) => {
    const classes = {
        low: 'px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        medium: 'px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
        high: 'px-3 py-1 rounded-full text-xs font-semibold bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
        urgent: 'px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
    }
    return classes[priority] || classes.medium
}

const getPriorityLabel = (priority) => {
    const labels = { low: 'Baja', medium: 'Media', high: 'Alta', urgent: 'Urgente' }
    return labels[priority] || priority
}

const getCategoryBadgeClass = (category) => {
    const classes = {
        technical: 'px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
        billing: 'px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        services: 'px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
        general: 'px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
    }
    return classes[category] || classes.general
}

const getCategoryLabel = (category) => {
    const labels = { technical: 'Técnico', billing: 'Facturación', services: 'Servicios', general: 'General' }
    return labels[category] || category
}

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString('es-ES', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    })
}

onMounted(() => {
    loadTicket()
})
</script>
