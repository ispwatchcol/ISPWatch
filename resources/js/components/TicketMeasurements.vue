<template>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
        <div class="flex justify-between items-center mb-1">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Pruebas técnicas</h2>
            <button
                v-if="puedeMedir && !bloqueado"
                type="button"
                @click="abrirFormularioNuevo"
                class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline"
            >
                + Registrar medición
            </button>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
            Estado técnico antes, durante y después de la atención. Para cerrar el ticket hace
            falta una medición final o explicar por qué no fue posible tomarla.
        </p>

        <!-- Cargando -->
        <div v-if="cargando" class="flex items-center gap-3 py-6 text-gray-500 dark:text-gray-400">
            <div class="animate-spin rounded-full h-5 w-5 border-2 border-blue-500 border-t-transparent"></div>
            <span class="text-sm">Cargando mediciones…</span>
        </div>

        <!-- Error -->
        <div v-else-if="error"
             class="rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4">
            <p class="text-sm text-amber-800 dark:text-amber-200">{{ error }}</p>
            <button type="button" @click="cargar" class="mt-2 text-sm font-medium underline">Reintentar</button>
        </div>

        <template v-else>
            <!-- Aviso de la regla 5. Se dice ANTES de intentar cerrar, no después. -->
            <div
                v-if="!pruebaFinal.present && !pruebaFinal.waiver"
                class="mb-4 rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-3"
            >
                <p class="text-sm text-amber-800 dark:text-amber-200">
                    Todavía no hay medición <strong>final</strong>. Sin ella el ticket no se puede
                    cerrar, salvo que se indique por qué no fue posible tomarla — se pide al cerrar.
                </p>
            </div>

            <div
                v-else-if="pruebaFinal.waiver"
                class="mb-4 rounded-lg border border-gray-200 dark:border-gray-700 p-3"
            >
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    <strong>Sin medición final:</strong> {{ pruebaFinal.waiver.reason_label }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ pruebaFinal.waiver.note }}</p>
            </div>

            <!-- Comparación antes / después (§ 13) -->
            <div v-if="comparacion.length" class="mb-5 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 text-left">
                            <th class="pb-2 pr-4 font-medium">Prueba</th>
                            <th class="pb-2 pr-4 font-medium">Inicial</th>
                            <th class="pb-2 pr-4 font-medium">Seguimiento</th>
                            <th class="pb-2 font-medium">Final</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="fila in comparacion"
                            :key="fila.test_type"
                            class="border-t border-gray-100 dark:border-gray-700"
                        >
                            <td class="py-2 pr-4 text-gray-800 dark:text-gray-100">{{ fila.test_type }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ fila.initial || '—' }}</td>
                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ fila.follow_up || '—' }}</td>
                            <td class="py-2" :class="fila.complete
                                ? 'text-gray-800 dark:text-gray-100 font-medium'
                                : 'text-gray-400 dark:text-gray-500 italic'">
                                {{ fila.final || 'sin medición final' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Vacío: se dice que faltan, no se esconde la sección -->
            <p v-if="!mediciones.length" class="text-sm text-gray-500 dark:text-gray-400">
                No hay mediciones registradas en este ticket.
            </p>

            <!-- Detalle -->
            <ul v-else class="space-y-2">
                <li
                    v-for="m in mediciones"
                    :key="m.id"
                    class="flex flex-wrap items-center gap-2 text-sm border border-gray-100 dark:border-gray-700 rounded-lg px-3 py-2"
                >
                    <span class="px-2 py-0.5 rounded text-xs font-medium" :class="claseDeFase(m.phase)">
                        {{ m.phase_label }}
                    </span>
                    <span class="font-medium text-gray-800 dark:text-gray-100">{{ m.test_type }}</span>
                    <span class="text-gray-800 dark:text-gray-100">{{ m.value }} {{ m.unit }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        · {{ fecha(m.measured_at) }} · origen: {{ m.source }}
                        <template v-if="m.intervention"> · visita {{ m.intervention.sequence }}</template>
                        <template v-if="m.recorded_by_name"> · {{ m.recorded_by_name }}</template>
                    </span>
                    <button
                        v-if="puedeMedir && !bloqueado"
                        type="button"
                        @click="abrirFormularioEdicion(m)"
                        class="ml-auto text-blue-600 dark:text-blue-400 hover:underline text-xs"
                    >
                        Corregir
                    </button>
                </li>
            </ul>
        </template>

        <!-- Alta / corrección -->
        <Teleport to="body">
            <div v-if="formularioAbierto"
                 class="fixed inset-0 z-app-modal flex items-center justify-center p-4 bg-black/60"
                 @click="formularioAbierto = false">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto"
                     @click.stop>
                    <div class="p-6 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                            {{ editandoId ? 'Corregir medición' : 'Registrar medición' }}
                        </h3>
                    </div>

                    <div class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Tipo de prueba <span class="text-red-500">*</span>
                                </label>
                                <!-- Texto libre con sugerencias: el § 12 las enumera
                                     en prosa y no les asigna código (decisión S-4). -->
                                <input v-model="form.test_type" list="sugerencias-de-prueba"
                                       :class="claseCampo(errores.test_type)"
                                       placeholder="RSSI, latencia, potencia óptica…" />
                                <datalist id="sugerencias-de-prueba">
                                    <option v-for="s in sugerenciasPlanas" :key="s" :value="s" />
                                </datalist>
                                <p v-if="errores.test_type" class="mt-1 text-sm text-red-500">
                                    {{ errores.test_type[0] }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Campo libre. Las sugerencias vienen del documento del cliente.
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Resultado <span class="text-red-500">*</span>
                                </label>
                                <input v-model="form.value" :class="claseCampo(errores.value)"
                                       placeholder="-76, 93, conectado…" />
                                <p v-if="errores.value" class="mt-1 text-sm text-red-500">{{ errores.value[0] }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Unidad
                                </label>
                                <input v-model="form.unit" :class="claseCampo(errores.unit)"
                                       placeholder="dBm, %, ms…" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Fecha y hora <span class="text-red-500">*</span>
                                </label>
                                <input v-model="form.measured_at" type="datetime-local"
                                       :class="claseCampo(errores.measured_at)" />
                                <p v-if="errores.measured_at" class="mt-1 text-sm text-red-500">
                                    {{ errores.measured_at[0] }}
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Origen <span class="text-red-500">*</span>
                                </label>
                                <input v-model="form.source" :class="claseCampo(errores.source)"
                                       placeholder="CPE, OLT, RADIUS, manual…" />
                                <p v-if="errores.source" class="mt-1 text-sm text-red-500">{{ errores.source[0] }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Fase <span class="text-red-500">*</span>
                                </label>
                                <select v-model="form.phase" :class="claseCampo(errores.phase)">
                                    <option v-for="f in measurementPhases" :key="f.code" :value="f.code">
                                        {{ f.label }}
                                    </option>
                                </select>
                                <p v-if="errores.phase" class="mt-1 text-sm text-red-500">{{ errores.phase[0] }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Intervención
                                </label>
                                <select v-model="form.intervention_id" :class="claseCampo(errores.intervention_id)">
                                    <option :value="null">— Ninguna —</option>
                                    <option v-for="i in intervenciones" :key="i.id" :value="i.id">
                                        {{ i.sequence }} · {{ i.kind === 'presencial' ? 'Presencial' : 'Remota' }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <p v-if="errorGeneral" class="text-sm text-red-500">{{ errorGeneral }}</p>
                    </div>

                    <div class="p-6 border-t border-gray-100 dark:border-gray-700 flex justify-end gap-3">
                        <button type="button" @click="formularioAbierto = false"
                                class="px-5 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm">
                            Cancelar
                        </button>
                        <button type="button" :disabled="guardando" @click="guardar"
                                class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm disabled:opacity-50">
                            {{ guardando ? 'Guardando…' : 'Guardar' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../services/api'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'

/**
 * PR F2 · Pruebas técnicas estructuradas (§ 12 y § 13 de la Solicitud Maestra).
 *
 * Componente aparte y no un bloque más en SupportDetail.vue, que ya pasa de dos
 * mil líneas. Mismo criterio que `TicketDiagnosisFields` y `TicketInterventions`.
 *
 * NO HAY BOTÓN DE BORRAR. Una medición es la constancia de lo que se leyó, y el
 * § 15.5 la convierte en requisito de cierre: poder esconderla equivaldría a
 * poder saltarse el requisito sin que constara. Si el valor está mal, se corrige
 * mientras el ticket siga abierto y la corrección queda en el historial.
 */

const props = defineProps({
    ticketId: { type: [Number, String], required: true },
    /** ¿Tiene `ticket_intervene`? Lo decide el servidor; aquí sólo se pinta. */
    puedeMedir: { type: Boolean, default: false },
    /** Archivado o cerrado: en ambos casos el expediente no se retoca. */
    bloqueado: { type: Boolean, default: false },
    /** Visitas del ticket, para poder colgar la medición de una. */
    intervenciones: { type: Array, default: () => [] },
})

const emit = defineEmits(['cambio'])

const { measurementPhases, measurementSuggestions, cargar: cargarCatalogos } = useTicketCatalogs()

const mediciones = ref([])
const comparacion = ref([])
const pruebaFinal = ref({ present: false, waiver: null })
const cargando = ref(true)
const error = ref('')

const formularioAbierto = ref(false)
const editandoId = ref(null)
const guardando = ref(false)
const errores = ref({})
const errorGeneral = ref('')

const form = ref(formVacio())

/** Todas las sugerencias del § 12 en una lista, para el `<datalist>`. */
const sugerenciasPlanas = computed(() => {
    const vistas = new Set()
    Object.values(measurementSuggestions.value || {}).forEach((grupo) => {
        (grupo || []).forEach((s) => vistas.add(s))
    })
    return [...vistas]
})

function formVacio() {
    return {
        test_type: '',
        value: '',
        unit: '',
        measured_at: ahoraLocal(),
        source: '',
        phase: 'inicial',
        intervention_id: null,
    }
}

/** `datetime-local` quiere `YYYY-MM-DDTHH:mm` en hora local, sin zona. */
function ahoraLocal() {
    const d = new Date()
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
    return d.toISOString().slice(0, 16)
}

function fecha(valor) {
    if (!valor) return '—'
    return new Date(valor).toLocaleString('es-CO', { timeZone: 'America/Bogota' })
}

function claseDeFase(fase) {
    if (fase === 'final') return 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
    if (fase === 'seguimiento') return 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200'
    return 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'
}

function claseCampo(error) {
    return [
        'w-full px-4 py-2 rounded-lg border bg-white dark:bg-gray-700 text-gray-800 dark:text-white',
        'focus:outline-none focus:ring-2 focus:ring-blue-500',
        error ? 'border-red-500' : 'border-gray-300 dark:border-gray-600',
    ]
}

async function cargar() {
    try {
        cargando.value = true
        error.value = ''
        const { data } = await api.support.getMeasurements(props.ticketId)
        mediciones.value = data.data ?? []
        comparacion.value = data.comparison ?? []
        pruebaFinal.value = data.final_test ?? { present: false, waiver: null }
    } catch (e) {
        error.value = e.response?.data?.message || 'No se pudieron cargar las mediciones.'
    } finally {
        cargando.value = false
    }
}

function abrirFormularioNuevo() {
    editandoId.value = null
    form.value = formVacio()
    errores.value = {}
    errorGeneral.value = ''
    formularioAbierto.value = true
}

function abrirFormularioEdicion(m) {
    editandoId.value = m.id
    form.value = {
        test_type: m.test_type,
        value: m.value,
        unit: m.unit || '',
        measured_at: (m.measured_at || '').slice(0, 16),
        source: m.source,
        phase: m.phase,
        intervention_id: m.intervention_id ?? null,
    }
    errores.value = {}
    errorGeneral.value = ''
    formularioAbierto.value = true
}

async function guardar() {
    try {
        guardando.value = true
        errores.value = {}
        errorGeneral.value = ''

        const cuerpo = { ...form.value, unit: form.value.unit || null }

        if (editandoId.value) {
            await api.support.updateMeasurement(props.ticketId, editandoId.value, cuerpo)
        } else {
            await api.support.createMeasurement(props.ticketId, cuerpo)
        }

        formularioAbierto.value = false
        await cargar()
        // El cierre depende de que exista una medición final: la pantalla tiene
        // que recalcular los requisitos, no sólo repintar esta lista.
        emit('cambio')
    } catch (e) {
        errores.value = e.response?.data?.errors || {}
        errorGeneral.value = Object.keys(errores.value).length
            ? ''
            : (e.response?.data?.message || 'No se pudo guardar la medición.')
    } finally {
        guardando.value = false
    }
}

onMounted(() => {
    cargarCatalogos()
    cargar()
})

defineExpose({ cargar })
</script>
