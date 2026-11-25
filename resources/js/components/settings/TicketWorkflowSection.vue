<template>
  <!-- Flujo de tickets del ISP (bitácora § 133). Lo decide quien administra la
       empresa: el servidor lo exige igual (`manage_tenant` en /tenant/config). -->
  <div v-if="puedeAdministrar"
       class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6"
       data-testid="ticket-workflow-section">
    <div class="flex items-center gap-3 mb-4">
      <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
        <v-icon name="hi-ticket" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
      </div>
      <div>
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Flujo de los tickets de soporte</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">Cuántos pasos y qué datos pide un ticket antes de poder cerrarse.</p>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
      <label v-for="op in OPCIONES" :key="op.valor"
        class="cursor-pointer rounded-xl border-2 p-4 transition"
        :class="modo === op.valor
          ? 'border-blue-600 bg-blue-50 dark:border-blue-500 dark:bg-blue-900/20'
          : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600'">
        <div class="flex items-center gap-2">
          <input v-model="modo" type="radio" :value="op.valor" class="text-blue-600 focus:ring-blue-500" />
          <span class="font-semibold text-gray-800 dark:text-white">{{ op.titulo }}</span>
          <span v-if="op.recomendado" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
            {{ op.recomendado }}
          </span>
        </div>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ op.resumen }}</p>
        <ul class="mt-2 list-inside list-disc space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
          <li v-for="d in op.detalle" :key="d">{{ d }}</li>
        </ul>
      </label>
    </div>

    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
      Cambiarlo no toca los tickets existentes ni su historial: sólo cambia qué pasos se ofrecen y qué se exige
      para cerrar desde ese momento. Los permisos de cada rol (quién puede cerrar, reabrir…) son los mismos en los dos.
    </p>

    <div class="mt-4 flex justify-end">
      <button type="button" @click="guardar" :disabled="guardando || modo === original"
        class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 transition">
        {{ guardando ? 'Guardando…' : 'Guardar' }}
      </button>
    </div>

    <NotificationToast ref="toast" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiClient } from '@/services/api'
import tenantApi from '@/services/api/tenant'
import { useAuthStore } from '@/stores/auth'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'
import NotificationToast from '@/components/NotificationToast.vue'

const OPCIONES = [
  {
    valor: 'simple',
    titulo: 'Simple',
    recomendado: 'Recomendado para la mayoría',
    resumen: 'Abierto → En proceso → (En espera) → Cerrado.',
    detalle: [
      'Se puede cerrar desde cualquier estado.',
      'Para cerrar basta con decir qué se hizo.',
      'Causa, resultado y medición final son opcionales.',
    ],
  },
  {
    valor: 'formal',
    titulo: 'Formal',
    recomendado: null,
    resumen: 'Radicado → Clasificación → Asignado → Intervención → Restablecido → Cerrado.',
    detalle: [
      'Para mesas de ayuda con proceso formal (por contrato o auditoría).',
      'Para cerrar exige causa confirmada, acción, resultado y medición final (o por qué no se pudo).',
      'Separa a quien resuelve (propone) de quien cierra.',
    ],
  },
]

const auth = useAuthStore()
const toast = ref(null)
const { cargar: recargarCatalogos } = useTicketCatalogs()

const puedeAdministrar = computed(() => auth.hasPermission('manage_tenant'))

const modo = ref('formal')
const original = ref('formal')
const guardando = ref(false)

const cargar = async () => {
  const tenantId = auth.user?.tenant_id
  if (!tenantId) return
  try {
    const { data } = await apiClient.get(`/tenants/${tenantId}`)
    const t = data?.data ?? {}
    modo.value = t.ticket_workflow_mode === 'simple' ? 'simple' : 'formal'
    original.value = modo.value
  } catch {
    // Sin dato, se muestra el formal (el de siempre) y se puede guardar igual.
  }
}

const guardar = async () => {
  guardando.value = true
  try {
    await tenantApi.updateConfig({ ticket_workflow_mode: modo.value })
    original.value = modo.value
    // Las pantallas de soporte leen el modo del catálogo: se refresca ya.
    recargarCatalogos(true)
    toast.value?.success('Guardado', modo.value === 'simple'
      ? 'Los tickets usan ahora el flujo simple.'
      : 'Los tickets usan ahora el flujo formal.')
  } catch (e) {
    toast.value?.error('Error', e.response?.data?.message || 'No se pudo guardar la configuración.')
  } finally {
    guardando.value = false
  }
}

onMounted(() => {
  if (puedeAdministrar.value) cargar()
})
</script>
