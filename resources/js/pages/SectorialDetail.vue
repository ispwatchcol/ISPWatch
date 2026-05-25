<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-4 md:p-6">
        <NotificationToast ref="toast" />

        <!-- Header -->
        <div class="max-w-7xl mx-auto mb-6">
            <div class="flex items-center gap-4 mb-4">
                <button
                    @click="router.push('/sectorials')"
                    class="p-2.5 rounded-xl bg-white dark:bg-gray-800 shadow-md hover:shadow-lg
                           text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                >
                    <v-icon name="md-arrowback" class="w-5 h-5" />
                </button>
                <div class="flex-1">
                    <div v-if="loading" class="h-8 w-64 bg-gray-200 dark:bg-gray-700 animate-pulse rounded"></div>
                    <div v-else class="flex items-center gap-3 flex-wrap">
                        <h1 class="text-2xl md:text-3xl font-bold text-gray-800 dark:text-white">
                            {{ sectorial?.name || 'Elemento' }}
                        </h1>
                        <span
                            :class="elementBadgeClasses"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border"
                        >
                            <v-icon :name="elementIcon" class="w-3.5 h-3.5" />
                            {{ elementLabel }}
                        </span>
                    </div>
                    <p v-if="!loading" class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        <span v-if="sectorial?.ip" class="font-mono">{{ sectorial.ip }}</span>
                        <span v-if="sectorial?.node_tower" class="ml-2">· {{ sectorial.node_tower }}</span>
                    </p>
                </div>
                <button
                    v-if="can('routers.edit')"
                    @click="router.push(`/sectorials/${sectorialId}/edit`)"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow flex items-center gap-2"
                >
                    <icon-lucide-pencil class="w-4 h-4" />
                    Editar
                </button>
            </div>

            <!-- Tabs -->
            <div class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-t-xl px-4">
                <nav class="-mb-px flex flex-wrap gap-1">
                    <button
                        v-for="t in tabs"
                        :key="t.id"
                        @click="activeTab = t.id"
                        :class="[
                            'flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition',
                            activeTab === t.id
                                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-300'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
                        ]"
                    >
                        <v-icon :name="t.icon" class="w-4 h-4" />
                        {{ t.label }}
                        <span v-if="t.count !== undefined" class="ml-1 px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-xs">{{ t.count }}</span>
                    </button>
                </nav>
            </div>
        </div>

        <div class="max-w-7xl mx-auto">
            <div class="bg-white dark:bg-gray-800 rounded-b-xl rounded-tr-xl shadow-md p-6 min-h-[400px]">

                <!-- INFO -->
                <div v-if="activeTab === 'info'">
                    <div v-if="loading" class="text-center py-12 text-gray-500">Cargando…</div>
                    <div v-else-if="sectorial" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <InfoRow icon="md-info"      label="Nombre"     :value="sectorial.name" />
                        <InfoRow icon="md-category"  label="Tipo"       :value="elementLabel" />
                        <InfoRow icon="md-router"    label="IP"         :value="sectorial.ip" mono />
                        <InfoRow icon="md-filterlist" label="Subtipo"   :value="sectorial.type" />
                        <InfoRow icon="md-wifi"      label="SSID"       :value="sectorial.ssid" />
                        <InfoRow icon="hi-wifi"      label="Frecuencia" :value="sectorial.frequency ? `${sectorial.frequency} MHz` : null" />
                        <InfoRow icon="md-settings"  label="Nodo torre" :value="sectorial.node_tower" />
                        <InfoRow icon="md-person"    label="Usuario RB" :value="sectorial.user_rb" />
                        <div v-if="sectorial.comments" class="md:col-span-2">
                            <div class="text-sm text-gray-500 dark:text-gray-400 mb-1 flex items-center gap-2">
                                <v-icon name="md-description" class="w-4 h-4" />
                                Comentarios
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700/40 rounded-lg p-3 text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap">{{ sectorial.comments }}</div>
                        </div>
                    </div>
                </div>

                <!-- PHOTOS -->
                <div v-else-if="activeTab === 'photos'">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Galería de fotos</h3>
                        <label class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg cursor-pointer flex items-center gap-2 shadow">
                            <icon-lucide-image-plus class="w-4 h-4" />
                            Subir fotos
                            <input type="file" accept="image/*" multiple class="hidden" @change="onPhotoUpload" />
                        </label>
                    </div>
                    <div v-if="loadingPhotos" class="text-center py-12 text-gray-500">Cargando…</div>
                    <div v-else-if="photos.length === 0" class="text-center py-12 text-gray-400">
                        <icon-lucide-image-off class="w-12 h-12 mx-auto mb-2" />
                        <p>Aún no hay fotos. Sube la primera.</p>
                    </div>
                    <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                        <div v-for="p in photos" :key="p.id" class="group relative rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/30">
                            <a :href="p.url" target="_blank" class="block aspect-square">
                                <img :src="p.url" :alt="p.file_name" class="w-full h-full object-cover" />
                            </a>
                            <div class="absolute inset-x-0 bottom-0 p-2 bg-gradient-to-t from-black/70 to-transparent text-white text-xs">
                                <div class="truncate font-medium">{{ p.file_name }}</div>
                                <div v-if="p.user" class="opacity-80 truncate">{{ p.user.user_name }} {{ p.user.user_lastname }}</div>
                            </div>
                            <button
                                v-if="can('routers.delete')"
                                @click="deletePhoto(p)"
                                class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 bg-red-600 hover:bg-red-700 text-white p-1.5 rounded-full transition"
                            >
                                <icon-lucide-trash-2 class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TICKETS -->
                <div v-else-if="activeTab === 'tickets'">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Tickets vinculados</h3>
                    <div v-if="loadingTickets" class="text-center py-12 text-gray-500">Cargando…</div>
                    <div v-else-if="tickets.length === 0" class="text-center py-12 text-gray-400">
                        <icon-lucide-ticket class="w-12 h-12 mx-auto mb-2" />
                        <p>No hay tickets vinculados a este elemento.</p>
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="t in tickets" :key="t.id"
                             class="flex items-start justify-between p-4 bg-gray-50 dark:bg-gray-700/40 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-indigo-400 transition">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-xs font-mono text-gray-500">#{{ t.id }}</span>
                                    <span :class="ticketStatusClass(t.status)" class="px-2 py-0.5 text-xs rounded-full font-medium">{{ ticketStatusLabel(t.status) }}</span>
                                    <span :class="ticketPriorityClass(t.priority)" class="px-2 py-0.5 text-xs rounded-full font-medium">{{ t.priority }}</span>
                                </div>
                                <div class="font-medium text-gray-800 dark:text-gray-100 mt-1 truncate">{{ t.subject }}</div>
                                <div v-if="t.description" class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ t.description }}</div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ formatDate(t.created_at) }}
                                    <span v-if="t.user"> · {{ t.user.user_name }} {{ t.user.user_lastname }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DOCS / NOTES -->
                <div v-else-if="activeTab === 'docs'">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Documentación / Notas</h3>
                        <button @click="openNoteEditor()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg flex items-center gap-2 shadow">
                            <icon-lucide-plus class="w-4 h-4" />
                            Nueva nota
                        </button>
                    </div>
                    <div v-if="loadingNotes" class="text-center py-12 text-gray-500">Cargando…</div>
                    <div v-else-if="notes.length === 0" class="text-center py-12 text-gray-400">
                        <icon-lucide-file-text class="w-12 h-12 mx-auto mb-2" />
                        <p>Aún no hay documentación. Agrega la primera nota.</p>
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="n in notes" :key="n.id" class="p-4 bg-gray-50 dark:bg-gray-700/40 rounded-lg border border-gray-200 dark:border-gray-700">
                            <div class="flex items-start justify-between gap-3 mb-2">
                                <div class="flex-1">
                                    <h4 v-if="n.title" class="font-semibold text-gray-800 dark:text-gray-100">{{ n.title }}</h4>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        {{ formatDate(n.created_at) }}
                                        <span v-if="n.user"> · {{ n.user.user_name }} {{ n.user.user_lastname }}</span>
                                    </div>
                                </div>
                                <div class="flex gap-1">
                                    <button @click="openNoteEditor(n)" class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-500">
                                        <icon-lucide-pencil class="w-4 h-4" />
                                    </button>
                                    <button @click="deleteNote(n)" class="p-1.5 rounded hover:bg-red-100 dark:hover:bg-red-900/30 text-red-500">
                                        <icon-lucide-trash-2 class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                            <div class="text-sm text-gray-700 dark:text-gray-200 whitespace-pre-wrap">{{ n.content }}</div>
                        </div>
                    </div>
                </div>

                <!-- HISTORY -->
                <div v-else-if="activeTab === 'history'">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Bitácora</h3>
                    <div v-if="loadingHistory" class="text-center py-12 text-gray-500">Cargando…</div>
                    <div v-else-if="history.length === 0" class="text-center py-12 text-gray-400">
                        <icon-lucide-clock class="w-12 h-12 mx-auto mb-2" />
                        <p>No hay eventos registrados.</p>
                    </div>
                    <ol v-else class="relative border-l-2 border-gray-200 dark:border-gray-700 ml-3 space-y-4">
                        <li v-for="h in history" :key="h.id" class="ml-6">
                            <span class="absolute -left-2 w-4 h-4 rounded-full" :class="historyDot(h.action)"></span>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ h.description }}</span>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ h.action }}</span>
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5">
                                {{ formatDate(h.created_at) }}
                                <span v-if="h.user"> · {{ h.user.user_name }} {{ h.user.user_lastname }}</span>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Note editor modal -->
        <div v-if="noteEditorOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="closeNoteEditor">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl p-6 m-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100">{{ editingNote?.id ? 'Editar nota' : 'Nueva nota' }}</h2>
                    <button @click="closeNoteEditor" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <icon-lucide-x class="w-6 h-6" />
                    </button>
                </div>
                <div class="space-y-3">
                    <input
                        v-model="editingNote.title"
                        type="text"
                        placeholder="Título (opcional)"
                        class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
                    />
                    <textarea
                        v-model="editingNote.content"
                        rows="10"
                        placeholder="Contenido (markdown soportado)…"
                        class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
                    ></textarea>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="closeNoteEditor" class="px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Cancelar</button>
                    <button @click="saveNote" :disabled="!editingNote.content" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white disabled:opacity-50">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, h } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'
import NotificationToast from '@/components/NotificationToast.vue'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()
const router = useRouter()
const route = useRoute()

const sectorialId = computed(() => route.params.id)
const toast = ref(null)

const sectorial = ref(null)
const loading = ref(true)

const activeTab = ref('info')

const photos = ref([])
const loadingPhotos = ref(false)

const tickets = ref([])
const loadingTickets = ref(false)

const notes = ref([])
const loadingNotes = ref(false)

const history = ref([])
const loadingHistory = ref(false)

const noteEditorOpen = ref(false)
const editingNote = ref({ id: null, title: '', content: '' })

const ELEMENTS = {
    sectorial: { label: 'Sectorial', icon: 'md-router',      color: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800' },
    switch:    { label: 'Switch',    icon: 'bi-hdd-network',  color: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-900/30 dark:text-purple-300 dark:border-purple-800' },
    nodo:      { label: 'Nodo',      icon: 'bi-diagram-3',    color: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800' },
}

const elementLabel  = computed(() => ELEMENTS[sectorial.value?.element_type]?.label || 'Sectorial')
const elementIcon   = computed(() => ELEMENTS[sectorial.value?.element_type]?.icon  || 'md-router')
const elementBadgeClasses = computed(() => ELEMENTS[sectorial.value?.element_type]?.color || ELEMENTS.sectorial.color)

const tabs = computed(() => [
    { id: 'info',    label: 'Información',   icon: 'md-info' },
    { id: 'photos',  label: 'Fotos',         icon: 'bi-images',      count: photos.value.length },
    { id: 'tickets', label: 'Tickets',       icon: 'hi-ticket',      count: tickets.value.length },
    { id: 'docs',    label: 'Documentación', icon: 'md-description', count: notes.value.length },
    { id: 'history', label: 'Historial',     icon: 'bi-arrow-repeat',count: history.value.length },
])

const loadSectorial = async () => {
    loading.value = true
    try {
        const { data } = await api.sectorials.getOne(sectorialId.value)
        sectorial.value = data
    } catch (e) {
        toast.value?.error('Error', 'No se pudo cargar el elemento')
    } finally {
        loading.value = false
    }
}

const loadPhotos = async () => {
    loadingPhotos.value = true
    try {
        const { data } = await api.sectorials.getPhotos(sectorialId.value)
        photos.value = data
    } catch (e) {
        toast.value?.error('Error', 'No se pudieron cargar las fotos')
    } finally {
        loadingPhotos.value = false
    }
}

const loadTickets = async () => {
    loadingTickets.value = true
    try {
        const { data } = await api.sectorials.getTickets(sectorialId.value)
        tickets.value = data
    } catch (e) {
        toast.value?.error('Error', 'No se pudieron cargar los tickets')
    } finally {
        loadingTickets.value = false
    }
}

const loadNotes = async () => {
    loadingNotes.value = true
    try {
        const { data } = await api.sectorials.getNotes(sectorialId.value)
        notes.value = data
    } catch (e) {
        toast.value?.error('Error', 'No se pudieron cargar las notas')
    } finally {
        loadingNotes.value = false
    }
}

const loadHistory = async () => {
    loadingHistory.value = true
    try {
        const { data } = await api.sectorials.getHistory(sectorialId.value)
        history.value = data
    } catch (e) {
        toast.value?.error('Error', 'No se pudo cargar el historial')
    } finally {
        loadingHistory.value = false
    }
}

watch(activeTab, (tab) => {
    if (tab === 'photos'  && photos.value.length === 0)  loadPhotos()
    if (tab === 'tickets' && tickets.value.length === 0) loadTickets()
    if (tab === 'docs'    && notes.value.length === 0)   loadNotes()
    if (tab === 'history' && history.value.length === 0) loadHistory()
})

const onPhotoUpload = async (e) => {
    const files = Array.from(e.target.files || [])
    if (files.length === 0) return
    try {
        await api.sectorials.uploadPhotos(sectorialId.value, files)
        toast.value?.success('Fotos subidas', `${files.length} foto(s) agregadas`)
        await loadPhotos()
    } catch (err) {
        toast.value?.error('Error', err.response?.data?.message || 'No se pudieron subir las fotos')
    } finally {
        e.target.value = ''
    }
}

const deletePhoto = async (photo) => {
    if (!confirm('¿Eliminar esta foto?')) return
    try {
        await api.sectorials.deletePhoto(sectorialId.value, photo.id)
        photos.value = photos.value.filter(p => p.id !== photo.id)
        toast.value?.success('Foto eliminada', '')
    } catch (e) {
        toast.value?.error('Error', 'No se pudo eliminar la foto')
    }
}

const openNoteEditor = (note = null) => {
    editingNote.value = note
        ? { id: note.id, title: note.title || '', content: note.content || '' }
        : { id: null, title: '', content: '' }
    noteEditorOpen.value = true
}

const closeNoteEditor = () => {
    noteEditorOpen.value = false
    editingNote.value = { id: null, title: '', content: '' }
}

const saveNote = async () => {
    try {
        if (editingNote.value.id) {
            await api.sectorials.updateNote(sectorialId.value, editingNote.value.id, {
                title: editingNote.value.title || null,
                content: editingNote.value.content,
            })
            toast.value?.success('Nota actualizada', '')
        } else {
            await api.sectorials.createNote(sectorialId.value, {
                title: editingNote.value.title || null,
                content: editingNote.value.content,
            })
            toast.value?.success('Nota creada', '')
        }
        closeNoteEditor()
        await loadNotes()
    } catch (e) {
        toast.value?.error('Error', 'No se pudo guardar la nota')
    }
}

const deleteNote = async (note) => {
    if (!confirm('¿Eliminar esta nota?')) return
    try {
        await api.sectorials.deleteNote(sectorialId.value, note.id)
        notes.value = notes.value.filter(n => n.id !== note.id)
        toast.value?.success('Nota eliminada', '')
    } catch (e) {
        toast.value?.error('Error', 'No se pudo eliminar la nota')
    }
}

const ticketStatusLabel = (s) => ({
    open: 'Abierto', in_progress: 'En progreso', resolved: 'Resuelto', closed: 'Cerrado'
}[s] || s)
const ticketStatusClass = (s) => ({
    open:        'bg-blue-100 text-blue-700',
    in_progress: 'bg-amber-100 text-amber-700',
    resolved:    'bg-green-100 text-green-700',
    closed:      'bg-gray-200 text-gray-700',
}[s] || 'bg-gray-100 text-gray-700')
const ticketPriorityClass = (p) => ({
    low:    'bg-gray-100 text-gray-700',
    medium: 'bg-blue-100 text-blue-700',
    high:   'bg-orange-100 text-orange-700',
    urgent: 'bg-red-100 text-red-700',
}[p] || 'bg-gray-100 text-gray-700')

const historyDot = (action) => {
    if (action.startsWith('photo'))  return 'bg-indigo-500'
    if (action.startsWith('note'))   return 'bg-emerald-500'
    if (action.startsWith('ticket')) return 'bg-amber-500'
    if (action === 'created')        return 'bg-blue-500'
    if (action === 'updated')        return 'bg-purple-500'
    return 'bg-gray-400'
}

const formatDate = (iso) => {
    if (!iso) return ''
    try {
        return new Date(iso).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' })
    } catch { return iso }
}

const InfoRow = (props) => h('div', { class: 'bg-gray-50 dark:bg-gray-700/40 rounded-lg p-3' }, [
    h('div', { class: 'text-xs text-gray-500 dark:text-gray-400 mb-1 flex items-center gap-2' }, [
        h('span', { class: 'inline-block w-1 h-3 rounded bg-indigo-400' }),
        props.label,
    ]),
    h('div', { class: ['text-sm font-medium text-gray-800 dark:text-gray-100', props.mono ? 'font-mono' : ''] }, props.value || '—'),
])

onMounted(loadSectorial)
</script>
