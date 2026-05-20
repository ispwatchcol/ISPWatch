<template>
  <div class="space-y-4">

    <!-- Header -->
    <div class="flex items-center justify-between">
      <h3 class="text-base font-bold text-gray-800 dark:text-white">Tickets de soporte</h3>
      <RouterLink
        :to="`/support/create?customer_id=${customerId}`"
        class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Nuevo ticket
      </RouterLink>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-10">
      <div class="inline-block animate-spin rounded-full h-7 w-7 border-4 border-blue-500 border-t-transparent"></div>
    </div>

    <!-- Vacío -->
    <div v-else-if="tickets.length === 0"
      class="text-center py-12 bg-gray-50 dark:bg-gray-900 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 text-gray-500 dark:text-gray-400">
      <svg class="w-10 h-10 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
      </svg>
      Este cliente no tiene tickets registrados.
    </div>

    <!-- Lista -->
    <div v-else class="space-y-2">
      <RouterLink
        v-for="ticket in tickets"
        :key="ticket.id"
        :to="`/support/${ticket.id}`"
        class="block bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-4 hover:border-blue-400 dark:hover:border-blue-600 transition-colors group">

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
          <!-- Izquierda: estado + asunto -->
          <div class="flex items-start gap-3 min-w-0">
            <span :class="statusBadge(ticket.status)" class="shrink-0 mt-0.5 px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase">
              {{ statusLabel(ticket.status) }}
            </span>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-800 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400 truncate">
                {{ ticket.subject }}
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">
                {{ ticket.description }}
              </p>
            </div>
          </div>

          <!-- Derecha: prioridad + categoría + fecha -->
          <div class="flex items-center gap-2 shrink-0 flex-wrap sm:flex-nowrap">
            <span :class="priorityBadge(ticket.priority)" class="px-2 py-0.5 rounded text-xs font-medium">
              {{ priorityLabel(ticket.priority) }}
            </span>
            <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 capitalize">
              {{ categoryLabel(ticket.category) }}
            </span>
            <span class="text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap">
              {{ formatDate(ticket.created_at) }}
            </span>
          </div>
        </div>

        <!-- Staff asignado -->
        <div v-if="ticket.staff" class="mt-2 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
          Asignado a: <span class="font-medium text-gray-600 dark:text-gray-300">{{ ticket.staff.name ?? ticket.staff.user_name }}</span>
        </div>
      </RouterLink>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/services/api'

const props = defineProps({
  customerId: { type: [String, Number], required: true },
})

const tickets = ref([])
const loading = ref(true)

const load = async () => {
  try {
    const { data } = await api.support.getAll({ user_id: props.customerId })
    tickets.value = Array.isArray(data) ? data : (data.data ?? [])
  } catch {
    tickets.value = []
  } finally {
    loading.value = false
  }
}

const statusLabel = (s) => ({ open: 'Abierto', in_progress: 'En progreso', resolved: 'Resuelto', closed: 'Cerrado' }[s] ?? s)
const priorityLabel = (p) => ({ low: 'Baja', medium: 'Media', high: 'Alta', urgent: 'Urgente' }[p] ?? p)
const categoryLabel = (c) => ({ technical: 'Técnico', billing: 'Facturación', services: 'Servicios', general: 'General' }[c] ?? c)

const statusBadge = (s) => ({
  open:        'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
  in_progress: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  resolved:    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  closed:      'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
}[s] ?? 'bg-gray-100 text-gray-600')

const priorityBadge = (p) => ({
  low:    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  medium: 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400',
  high:   'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
  urgent: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
}[p] ?? 'bg-gray-100 text-gray-600')

const formatDate = (d) => {
  if (!d) return ''
  return new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

onMounted(load)
</script>
