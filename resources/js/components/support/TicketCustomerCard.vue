<template>
    <!-- El cliente visto desde soporte (bitácora § 133): lo que hace falta para
         atender —dónde es, cómo contactarlo, qué tiene y cómo está— y, a quien
         ya ve facturación, cuánto debe. La usan el alta y el detalle. -->
    <div class="rounded-xl bg-white p-5 shadow-md dark:bg-gray-800" data-testid="ticket-customer-card">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h3 class="text-base font-bold text-gray-800 dark:text-white">{{ titulo }}</h3>
            <router-link
                v-if="puedeVerFicha && cliente"
                :to="`/customers/${cliente.user_id}/edit`"
                class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
            >
                Ver ficha
            </router-link>
        </div>

        <div v-if="cargando" class="flex items-center gap-2 py-3 text-sm text-gray-500 dark:text-gray-400">
            <div class="h-4 w-4 animate-spin rounded-full border-2 border-blue-500 border-t-transparent"></div>
            Cargando cliente…
        </div>

        <p v-else-if="error" class="text-sm text-gray-500 dark:text-gray-400">{{ error }}</p>

        <template v-else-if="cliente">
            <p class="text-sm font-semibold text-gray-800 dark:text-white">
                {{ cliente.name }} {{ cliente.last_name }}
                <span v-if="cliente.cedula" class="font-normal text-gray-500 dark:text-gray-400">· CC {{ cliente.cedula }}</span>
            </p>

            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <span :class="estadoServicio.clase">{{ estadoServicio.label }}</span>
                <span v-if="cliente.plan_name" class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                    {{ cliente.plan_name }}
                </span>
                <span v-if="cliente.is_fiber" class="rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/40 dark:text-purple-200">Fibra</span>
            </div>

            <!-- Dónde es -->
            <div v-if="cliente.address" class="mt-3 text-sm text-gray-700 dark:text-gray-300">
                <v-icon name="md-locationon" class="mr-1 inline h-4 w-4 text-gray-400" />
                {{ cliente.address }}<template v-if="cliente.city">, {{ cliente.city }}</template>
                <a v-if="urlMapa" :href="urlMapa" target="_blank" rel="noopener" class="ml-1 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">
                    Mapa
                </a>
            </div>

            <!-- Contacto: en el celular, a un toque. -->
            <div v-if="cliente.tel" class="mt-2 flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-700 dark:text-gray-300">
                    <v-icon name="md-phone" class="mr-1 inline h-4 w-4 text-gray-400" />{{ cliente.tel }}
                </span>
                <a v-if="telUrl(cliente.tel)" :href="telUrl(cliente.tel)"
                    class="rounded-md border border-gray-200 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    Llamar
                </a>
                <a v-if="whatsappUrl(cliente.tel)" :href="whatsappUrl(cliente.tel)" target="_blank" rel="noopener"
                    class="rounded-md border border-green-200 bg-green-50 px-2 py-1 text-xs font-semibold text-green-700 hover:bg-green-100 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">
                    WhatsApp
                </a>
            </div>
            <p v-if="cliente.email && !compacto" class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ cliente.email }}</p>

            <!-- Red: lo que el técnico mira antes de salir. -->
            <dl v-if="hayRed" class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-900/40">
                <template v-if="cliente.ip_user">
                    <dt class="text-gray-500 dark:text-gray-400">IP</dt>
                    <dd class="font-mono text-gray-800 dark:text-gray-100">{{ cliente.ip_user }}</dd>
                </template>
                <template v-if="cliente.router_name">
                    <dt class="text-gray-500 dark:text-gray-400">Router</dt>
                    <dd class="text-gray-800 dark:text-gray-100">{{ cliente.router_name }}</dd>
                </template>
                <template v-if="cliente.sectorial_name">
                    <dt class="text-gray-500 dark:text-gray-400">{{ cliente.is_fiber ? 'Caja / NAP' : 'Sector' }}</dt>
                    <dd class="text-gray-800 dark:text-gray-100">
                        {{ cliente.sectorial_name }}<template v-if="cliente.nap_port"> · puerto {{ cliente.nap_port }}</template>
                    </dd>
                </template>
                <template v-if="cliente.olt_name">
                    <dt class="text-gray-500 dark:text-gray-400">OLT</dt>
                    <dd class="text-gray-800 dark:text-gray-100">{{ cliente.olt_name }}</dd>
                </template>
            </dl>

            <!-- Dinero: sólo llega si quien mira ya ve facturación. -->
            <div v-if="facturacion" class="mt-3 rounded-lg border p-3 text-xs"
                :class="facturacion.overdue_invoices
                    ? 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/20'
                    : 'border-gray-200 dark:border-gray-700'">
                <p v-if="facturacion.pending_invoices" class="text-gray-800 dark:text-gray-100">
                    <strong>{{ formatCOP(facturacion.balance_due) }}</strong> pendiente en
                    {{ facturacion.pending_invoices }} factura{{ facturacion.pending_invoices === 1 ? '' : 's' }}
                    <span v-if="facturacion.overdue_invoices" class="font-semibold text-red-700 dark:text-red-300">
                        ({{ facturacion.overdue_invoices }} vencida{{ facturacion.overdue_invoices === 1 ? '' : 's' }})
                    </span>
                </p>
                <p v-else class="text-gray-600 dark:text-gray-300">Al día con sus facturas.</p>
                <p v-if="facturacion.credit_balance > 0" class="mt-0.5 text-emerald-700 dark:text-emerald-300">
                    Saldo a favor: {{ formatCOP(facturacion.credit_balance) }}
                </p>
            </div>

            <!-- Otros tickets abiertos: el aviso que evita el duplicado. -->
            <div v-if="abiertos.length" class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900/20" data-testid="open-tickets-warning">
                <p class="text-xs font-semibold text-amber-900 dark:text-amber-200">
                    {{ excluir ? 'Tiene otros tickets abiertos:' : 'Ya tiene tickets abiertos:' }}
                </p>
                <ul class="mt-1 space-y-1">
                    <li v-for="t in abiertos" :key="t.id" class="flex items-center justify-between gap-2 text-xs">
                        <router-link :to="`/support/${t.id}`" class="truncate font-medium text-amber-900 hover:underline dark:text-amber-100">
                            #{{ t.id }} · {{ t.subject }}
                        </router-link>
                        <span :class="groupBadgeClass(t.status_group)">{{ t.status_label }}</span>
                    </li>
                </ul>
                <p v-if="!excluir" class="mt-1.5 text-[11px] text-amber-800 dark:text-amber-300">
                    Si es el mismo problema, agrega una nota en ese ticket en vez de abrir otro.
                </p>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import api from '@/services/api'
import { usePermissions } from '@/composables/usePermissions'
import {
    groupBadgeClass, serviceStatusInfo, telUrl, whatsappUrl, mapsUrl, formatCOP,
} from '@/utils/ticketStyles'

const props = defineProps({
    /** users.id del cliente. */
    userId: { type: [Number, String], default: null },
    /** Ticket desde el que se mira: no se cuenta como «otro abierto». */
    excluir: { type: [Number, String], default: null },
    titulo: { type: String, default: 'Cliente' },
    compacto: { type: Boolean, default: false },
})

const emit = defineEmits(['cargado'])

const { can } = usePermissions()
const puedeVerFicha = computed(() => can('view_clients'))

const cargando = ref(false)
const error = ref('')
const datos = ref(null)

const cliente = computed(() => datos.value?.customer ?? null)
const abiertos = computed(() => datos.value?.open_tickets ?? [])
const facturacion = computed(() => datos.value?.billing ?? null)
const estadoServicio = computed(() => serviceStatusInfo(cliente.value?.service_status))
const urlMapa = computed(() => (cliente.value ? mapsUrl(cliente.value) : null))
const hayRed = computed(() => {
    const c = cliente.value
    return !!(c && (c.ip_user || c.router_name || c.sectorial_name || c.olt_name))
})

let peticion = 0

const cargar = async () => {
    const mia = ++peticion
    datos.value = null
    error.value = ''

    if (!props.userId) return

    cargando.value = true
    try {
        const params = props.excluir ? { exclude: props.excluir } : {}
        const { data } = await api.support.getCustomerContext(props.userId, params)
        if (mia !== peticion) return
        datos.value = data
        emit('cargado', data)
    } catch (err) {
        if (mia !== peticion) return
        error.value = err.response?.status === 404
            ? 'Este cliente ya no tiene ficha.'
            : 'No se pudo cargar la ficha del cliente.'
    } finally {
        if (mia === peticion) cargando.value = false
    }
}

watch(() => [props.userId, props.excluir], cargar, { immediate: true })

defineExpose({ recargar: cargar })
</script>
