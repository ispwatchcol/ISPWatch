<template>
  <div class="space-y-6">
    <!-- Sólo lectura: los equipos se instalan y retiran desde la hoja de la
         orden o desde un ticket, donde queda la firma y el kardex. -->
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <h3 class="text-base font-bold text-gray-800 dark:text-white">Equipos y materiales</h3>
      <p class="text-xs text-gray-500 dark:text-gray-400">
        Se instalan o retiran desde la orden de instalación o desde un ticket.
      </p>
    </div>

    <div v-if="loading" class="text-center py-10">
      <div class="inline-block animate-spin rounded-full h-7 w-7 border-4 border-blue-500 border-t-transparent"></div>
    </div>

    <div v-else-if="error" class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
      {{ error }}
    </div>

    <template v-else>
      <!-- Resumen -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
          <p class="text-xs text-gray-500 dark:text-gray-400">Equipos en casa</p>
          <p class="text-2xl font-bold text-gray-800 dark:text-white" data-testid="devices-count">{{ data.devices.length }}</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
          <p class="text-xs text-gray-500 dark:text-gray-400">Valor de los equipos</p>
          <p class="text-2xl font-bold text-gray-800 dark:text-white">{{ formatCurrency(devicesValue) }}</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
          <p class="text-xs text-gray-500 dark:text-gray-400">Materiales distintos usados</p>
          <p class="text-2xl font-bold text-gray-800 dark:text-white">{{ data.materials.length }}</p>
        </div>
      </div>

      <!-- Equipos instalados -->
      <section>
        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Equipos instalados</h4>
        <div v-if="!data.devices.length" class="text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 text-center">
          No tiene equipos con serial instalados.
        </div>
        <div v-else class="space-y-2">
          <div v-for="d in data.devices" :key="d.id"
            class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ d.item }}</p>
              <p class="text-xs text-gray-600 dark:text-gray-300 mt-0.5 break-all">
                Serial: <span class="font-mono">{{ d.serial || '—' }}</span>
                <template v-if="d.mac"> · MAC: <span class="font-mono">{{ d.mac }}</span></template>
              </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap text-xs shrink-0">
              <span v-if="d.since" class="text-gray-500 dark:text-gray-400">Desde {{ formatDate(d.since) }}</span>
              <OriginLink :origin="d.origin" />
              <span v-if="d.unit_price !== null" class="font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(d.unit_price) }}</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Materiales -->
      <section>
        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Materiales usados</h4>
        <div v-if="!data.materials.length" class="text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 text-center">
          No se le han registrado materiales.
        </div>
        <div v-else class="space-y-2">
          <div v-for="m in data.materials" :key="m.stock_id"
            class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl">
            <button type="button" @click="toggle(m.stock_id)"
              class="w-full flex items-center justify-between gap-3 p-4 text-left">
              <span class="text-sm font-semibold text-gray-800 dark:text-white">{{ m.item }}</span>
              <span class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 shrink-0">
                {{ formatQty(m.total) }} {{ m.unit || '' }}
                <svg class="w-4 h-4 transition-transform" :class="open[m.stock_id] ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </span>
            </button>
            <ul v-if="open[m.stock_id]" class="border-t border-gray-200 dark:border-gray-700 px-4 py-2 space-y-1">
              <li v-for="(l, i) in m.lines" :key="i" class="flex items-center justify-between gap-2 text-xs text-gray-600 dark:text-gray-300">
                <span>{{ formatQty(l.quantity) }} {{ m.unit || '' }} · {{ formatDate(l.date) }}</span>
                <OriginLink :origin="l.origin" />
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Retirados -->
      <section v-if="data.retired.length">
        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Equipos retirados</h4>
        <div class="space-y-2">
          <div v-for="r in data.retired" :key="`${r.id}-${r.date}`"
            class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 opacity-80">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ r.item }}</p>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 break-all">
                Serial: <span class="font-mono">{{ r.serial || '—' }}</span>
                <template v-if="r.status === 'retired'"> · dado de baja</template>
              </p>
            </div>
            <div class="flex items-center gap-2 text-xs shrink-0">
              <span class="text-gray-500 dark:text-gray-400">Retirado {{ formatDate(r.date) }}</span>
              <OriginLink :origin="r.origin" />
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/services/api'

const props = defineProps({
  customerId: { type: [String, Number], required: true },
})

const data = ref({ devices: [], materials: [], retired: [] })
const loading = ref(true)
const error = ref('')
const open = ref({})

const load = async () => {
  try {
    const res = await api.customers.getEquipment(props.customerId)
    data.value = {
      devices:   res.data?.devices   ?? [],
      materials: res.data?.materials ?? [],
      retired:   res.data?.retired   ?? [],
    }
  } catch (e) {
    error.value = e?.response?.status === 403
      ? 'No tienes permiso para ver los equipos de este cliente.'
      : 'No se pudieron cargar los equipos del cliente.'
  } finally {
    loading.value = false
  }
}

const toggle = (id) => { open.value = { ...open.value, [id]: !open.value[id] } }

const devicesValue = computed(() =>
  data.value.devices.reduce((s, d) => s + (Number(d.unit_price) || 0), 0)
)

const formatDate = (d) => d
  ? new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
  : ''
const formatQty = (n) => Number(n).toLocaleString('es-CO', { maximumFractionDigits: 2 })
const formatCurrency = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(n) || 0)

// «Orden #12» / «Ticket #40» con enlace a donde se registró.
const OriginLink = (p) => {
  const o = p.origin
  if (!o) return null
  const to = o.type === 'installation' ? `/installations/${o.id}` : `/support/${o.id}`
  return h(RouterLink, {
    to,
    class: 'px-2 py-0.5 rounded bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 hover:underline whitespace-nowrap',
  }, () => o.label)
}
OriginLink.props = ['origin']

onMounted(load)
</script>
