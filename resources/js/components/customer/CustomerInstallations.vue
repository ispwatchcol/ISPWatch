<template>
  <div class="space-y-8">

    <!-- Botón nueva orden -->
    <div class="flex items-center justify-between">
      <h3 class="text-base font-bold text-gray-800 dark:text-white">Órdenes de instalación</h3>
      <button
        @click="openForm()"
        class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Nueva orden
      </button>
    </div>

    <!-- Formulario (crear / editar) -->
    <section v-if="showForm" class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-5">
      <h4 class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-4">
        {{ editing ? 'Editar orden' : 'Nueva orden de instalación' }}
      </h4>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <!-- Fecha programada -->
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha programada <span class="text-red-500">*</span></label>
          <input v-model="form.scheduled_date" type="date"
            class="w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>

        <!-- Estado -->
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Estado</label>
          <select v-model="form.status"
            class="w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="pendiente">Pendiente</option>
            <option value="completada">Completada</option>
            <option value="cancelada">Cancelada</option>
          </select>
        </div>

        <!-- Técnico -->
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Técnico asignado</label>
          <input v-model="form.technician" type="text" placeholder="Nombre del técnico"
            class="w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>

        <!-- Dirección -->
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Dirección de instalación</label>
          <input v-model="form.address" type="text" placeholder="Dirección"
            class="w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>

        <!-- Equipo -->
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Equipo / Materiales</label>
          <input v-model="form.equipment" type="text" placeholder="Ej: Router TP-Link, cable UTP 20m, antena sectorial"
            class="w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>

        <!-- Observaciones -->
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Observaciones</label>
          <textarea v-model="form.notes" rows="3" placeholder="Notas adicionales para la instalación..."
            class="w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"></textarea>
        </div>
      </div>

      <div v-if="formError" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ formError }}</div>

      <div class="flex gap-3 mt-4">
        <button @click="saveForm" :disabled="saving"
          class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
          {{ saving ? 'Guardando...' : (editing ? 'Actualizar' : 'Crear orden') }}
        </button>
        <button @click="closeForm" type="button"
          class="bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white text-sm px-5 py-2.5 rounded-lg transition">
          Cancelar
        </button>
      </div>
    </section>

    <!-- Lista de órdenes -->
    <section>
      <div v-if="loading" class="text-center py-10">
        <div class="inline-block animate-spin rounded-full h-7 w-7 border-4 border-blue-500 border-t-transparent"></div>
      </div>

      <div v-else-if="installations.length === 0" class="text-center py-12 bg-gray-50 dark:bg-gray-900 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 text-gray-500 dark:text-gray-400">
        <svg class="w-10 h-10 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
        </svg>
        No hay órdenes de instalación registradas.
      </div>

      <div v-else class="space-y-3">
        <div v-for="inst in installations" :key="inst.id"
          class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">

          <!-- Información principal -->
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-2">
              <span :class="statusBadge(inst.status)" class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase">
                {{ inst.status }}
              </span>
              <span class="text-sm font-semibold text-gray-800 dark:text-white">
                {{ formatDate(inst.scheduled_date) }}
              </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
              <div v-if="inst.technician" class="flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                {{ inst.technician }}
              </div>
              <div v-if="inst.address" class="flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                {{ inst.address }}
              </div>
              <div v-if="inst.equipment" class="sm:col-span-2 flex items-start gap-1.5">
                <svg class="w-3.5 h-3.5 shrink-0 text-gray-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                </svg>
                {{ inst.equipment }}
              </div>
              <div v-if="inst.notes" class="sm:col-span-2 text-gray-500 dark:text-gray-400 text-xs italic mt-1">
                {{ inst.notes }}
              </div>
            </div>

            <div v-if="inst.completed_at" class="mt-2 text-xs text-emerald-600 dark:text-emerald-400">
              Completada el {{ formatDatetime(inst.completed_at) }}
            </div>
          </div>

          <!-- Acciones -->
          <div class="flex gap-2 shrink-0">
            <button @click="openForm(inst)"
              class="text-xs text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 px-3 py-1.5 rounded-lg transition font-medium">
              Editar
            </button>
            <button @click="removeInstallation(inst)"
              class="text-xs text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 px-3 py-1.5 rounded-lg transition font-medium">
              Eliminar
            </button>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'

const props = defineProps({
  customerId: { type: [String, Number], required: true },
})

const emit = defineEmits(['notify'])

const installations = ref([])
const loading       = ref(true)
const showForm      = ref(false)
const saving        = ref(false)
const formError     = ref('')
const editing       = ref(null)

const emptyForm = () => ({
  scheduled_date: '',
  technician: '',
  address: '',
  equipment: '',
  notes: '',
  status: 'pendiente',
})

const form = ref(emptyForm())

const loadInstallations = async () => {
  loading.value = true
  try {
    const { data } = await api.customers.getInstallations(props.customerId)
    installations.value = data
  } catch {
    emit('notify', { type: 'error', title: 'Error', message: 'No se pudieron cargar las instalaciones.' })
  } finally {
    loading.value = false
  }
}

const openForm = (inst = null) => {
  formError.value = ''
  if (inst) {
    editing.value = inst.id
    form.value = {
      scheduled_date: inst.scheduled_date?.split('T')[0] ?? inst.scheduled_date ?? '',
      technician: inst.technician ?? '',
      address: inst.address ?? '',
      equipment: inst.equipment ?? '',
      notes: inst.notes ?? '',
      status: inst.status,
    }
  } else {
    editing.value = null
    form.value = emptyForm()
  }
  showForm.value = true
}

const closeForm = () => {
  showForm.value = false
  editing.value  = null
  formError.value = ''
}

const saveForm = async () => {
  formError.value = ''
  if (!form.value.scheduled_date) {
    formError.value = 'La fecha programada es obligatoria.'
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      await api.customers.updateInstallation(editing.value, form.value)
      emit('notify', { type: 'success', title: 'Actualizada', message: 'Orden de instalación actualizada.' })
    } else {
      await api.customers.createInstallation(props.customerId, form.value)
      emit('notify', { type: 'success', title: 'Creada', message: 'Orden de instalación creada correctamente.' })
    }
    closeForm()
    await loadInstallations()
  } catch (err) {
    const msg = err.response?.data?.message || 'Error al guardar la orden.'
    formError.value = msg
    emit('notify', { type: 'error', title: 'Error', message: msg })
  } finally {
    saving.value = false
  }
}

const removeInstallation = async (inst) => {
  if (!confirm(`¿Eliminar la orden programada para ${formatDate(inst.scheduled_date)}?`)) return
  try {
    await api.customers.deleteInstallation(inst.id)
    emit('notify', { type: 'success', title: 'Eliminada', message: 'Orden de instalación eliminada.' })
    await loadInstallations()
  } catch {
    emit('notify', { type: 'error', title: 'Error', message: 'No se pudo eliminar la orden.' })
  }
}

const statusBadge = (status) => ({
  pendiente:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  completada: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  cancelada:  'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
}[status] || 'bg-gray-100 text-gray-600')

const formatDate = (d) => {
  if (!d) return ''
  const date = new Date(d + (d.includes('T') ? '' : 'T00:00:00'))
  return date.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

const formatDatetime = (d) => {
  if (!d) return ''
  return new Date(d).toLocaleString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

onMounted(loadInstallations)
</script>
