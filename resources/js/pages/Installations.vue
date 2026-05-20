<template>
  <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
    <NotificationToast ref="toast" />

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Instalaciones</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Órdenes de instalación de todos los clientes</p>
      </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 mb-6 max-w-7xl mx-auto">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Estado</label>
          <select v-model="filters.status" @change="load"
            class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
            <option value="">Todos</option>
            <option value="pendiente">Pendiente</option>
            <option value="completada">Completada</option>
            <option value="cancelada">Cancelada</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Desde</label>
          <input v-model="filters.from" type="date" @change="load"
            class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Hasta</label>
          <input v-model="filters.to" type="date" @change="load"
            class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" />
        </div>
      </div>
    </div>

    <!-- Tabla -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 max-w-7xl mx-auto overflow-hidden">

      <!-- Loading -->
      <div v-if="loading" class="text-center py-16">
        <div class="inline-block animate-spin rounded-full h-10 w-10 border-4 border-blue-500 border-t-transparent"></div>
        <p class="text-gray-500 dark:text-gray-400 mt-3">Cargando instalaciones...</p>
      </div>

      <!-- Vacío -->
      <div v-else-if="installations.length === 0" class="text-center py-16 text-gray-500 dark:text-gray-400">
        <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
        </svg>
        No hay órdenes de instalación con los filtros seleccionados.
      </div>

      <!-- Tabla -->
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-700">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Estado</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Fecha</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Cliente</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Técnico</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Dirección</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Equipo</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            <tr v-for="inst in installations" :key="inst.id"
              class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
              <td class="px-4 py-3">
                <span :class="statusBadge(inst.status)" class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase">
                  {{ inst.status }}
                </span>
              </td>
              <td class="px-4 py-3 text-gray-700 dark:text-gray-300 whitespace-nowrap">
                {{ formatDate(inst.scheduled_date) }}
              </td>
              <td class="px-4 py-3">
                <RouterLink
                  :to="`/customers/${inst.customer_id}/edit`"
                  class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                  {{ inst.customer_name || inst.customer_email || '—' }}
                </RouterLink>
              </td>
              <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ inst.technician || '—' }}</td>
              <td class="px-4 py-3 text-gray-600 dark:text-gray-300 max-w-[180px] truncate" :title="inst.address">
                {{ inst.address || '—' }}
              </td>
              <td class="px-4 py-3 text-gray-600 dark:text-gray-300 max-w-[180px] truncate" :title="inst.equipment">
                {{ inst.equipment || '—' }}
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-1">
                  <button @click="openEdit(inst)"
                    class="text-xs text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 px-2.5 py-1 rounded-lg transition font-medium">
                    Editar
                  </button>
                  <button @click="remove(inst)"
                    class="text-xs text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 px-2.5 py-1 rounded-lg transition font-medium">
                    Eliminar
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pie: total -->
      <div v-if="!loading && installations.length > 0"
        class="px-4 py-3 border-t border-gray-100 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">
        {{ installations.length }} orden{{ installations.length !== 1 ? 'es' : '' }}
        <span v-if="filters.status" class="ml-1 font-medium">· {{ filters.status }}</span>
      </div>
    </div>

    <!-- Modal editar -->
    <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg p-6">
        <h3 class="text-base font-bold text-gray-800 dark:text-white mb-4">Editar orden de instalación</h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha <span class="text-red-500">*</span></label>
            <input v-model="editForm.scheduled_date" type="date"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Estado</label>
            <select v-model="editForm.status"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
              <option value="pendiente">Pendiente</option>
              <option value="completada">Completada</option>
              <option value="cancelada">Cancelada</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Técnico</label>
            <input v-model="editForm.technician" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Dirección</label>
            <input v-model="editForm.address" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Equipo / Materiales</label>
            <input v-model="editForm.equipment" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Observaciones</label>
            <textarea v-model="editForm.notes" rows="2"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm resize-none"></textarea>
          </div>
        </div>

        <div v-if="editError" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ editError }}</div>

        <div class="flex gap-3 mt-5">
          <button @click="saveEdit" :disabled="saving"
            class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium py-2.5 rounded-lg transition">
            {{ saving ? 'Guardando...' : 'Actualizar' }}
          </button>
          <button @click="editing = null"
            class="flex-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white text-sm py-2.5 rounded-lg transition">
            Cancelar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/services/api'
import NotificationToast from '@/components/NotificationToast.vue'

const toast = ref(null)
const installations = ref([])
const loading = ref(true)

const filters = ref({ status: '', from: '', to: '' })

const editing  = ref(null)
const editForm = ref({})
const editError = ref('')
const saving   = ref(false)

const load = async () => {
  loading.value = true
  try {
    const params = {}
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.from)   params.from   = filters.value.from
    if (filters.value.to)     params.to     = filters.value.to
    const { data } = await api.customers.getAllInstallations(params)
    installations.value = data
  } catch {
    toast.value?.error('Error', 'No se pudieron cargar las instalaciones.')
  } finally {
    loading.value = false
  }
}

const openEdit = (inst) => {
  editError.value = ''
  editing.value = inst.id
  editForm.value = {
    scheduled_date: inst.scheduled_date?.split('T')[0] ?? inst.scheduled_date ?? '',
    technician: inst.technician ?? '',
    address:    inst.address ?? '',
    equipment:  inst.equipment ?? '',
    notes:      inst.notes ?? '',
    status:     inst.status,
  }
}

const saveEdit = async () => {
  editError.value = ''
  if (!editForm.value.scheduled_date) { editError.value = 'La fecha es obligatoria.'; return }
  saving.value = true
  try {
    await api.customers.updateInstallation(editing.value, editForm.value)
    toast.value?.success('Actualizada', 'Orden de instalación actualizada.')
    editing.value = null
    await load()
  } catch (err) {
    const msg = err.response?.data?.message || 'Error al actualizar.'
    editError.value = msg
  } finally {
    saving.value = false
  }
}

const remove = async (inst) => {
  if (!confirm(`¿Eliminar la orden de ${inst.customer_name || inst.customer_email} programada para ${formatDate(inst.scheduled_date)}?`)) return
  try {
    await api.customers.deleteInstallation(inst.id)
    toast.value?.success('Eliminada', 'Orden eliminada correctamente.')
    await load()
  } catch {
    toast.value?.error('Error', 'No se pudo eliminar la orden.')
  }
}

const statusBadge = (s) => ({
  pendiente:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  completada: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  cancelada:  'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
}[s] || 'bg-gray-100 text-gray-600')

const formatDate = (d) => {
  if (!d) return ''
  const date = new Date(d + (d.includes('T') ? '' : 'T00:00:00'))
  return date.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

onMounted(load)
</script>
