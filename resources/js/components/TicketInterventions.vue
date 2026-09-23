<template>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
        <div class="flex justify-between items-center mb-1">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Intervenciones</h2>
            <button
                v-if="puedeIntervenir && !ticketArchivado"
                type="button"
                @click="abrirFormularioNuevo"
                class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline"
            >
                + Registrar intervención
            </button>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
            Visitas y atenciones remotas. Una intervención finalizada no se edita: se reabre
            indicando el motivo, y la corrección queda en el historial.
        </p>

        <!-- Cargando -->
        <div v-if="cargando" class="flex items-center gap-3 py-6 text-gray-500 dark:text-gray-400">
            <div class="animate-spin rounded-full h-5 w-5 border-2 border-blue-500 border-t-transparent"></div>
            <span class="text-sm">Cargando intervenciones…</span>
        </div>

        <!-- Error -->
        <div
            v-else-if="error"
            class="rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4"
        >
            <p class="text-sm text-amber-800 dark:text-amber-200">{{ error }}</p>
            <button type="button" @click="cargar" class="mt-2 text-sm font-medium underline">
                Reintentar
            </button>
        </div>

        <!-- Vacío: se dice que faltan, no se esconde la sección -->
        <p v-else-if="!intervenciones.length" class="text-sm text-gray-500 dark:text-gray-400">
            No hay intervenciones registradas en este ticket.
        </p>

        <ol v-else class="space-y-4">
            <li
                v-for="i in intervenciones"
                :key="i.id"
                class="border border-gray-200 dark:border-gray-700 rounded-lg p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-gray-100 dark:bg-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-200">
                            {{ i.sequence }}
                        </span>
                        <span
                            class="px-2 py-0.5 rounded text-xs font-medium"
                            :class="i.kind === 'presencial'
                                ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200'
                                : 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200'"
                        >
                            {{ i.kind === 'presencial' ? 'Presencial' : 'Remota' }}
                        </span>
                        <span
                            v-if="i.is_open"
                            class="px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"
                        >
                            En curso
                        </span>
                    </div>

                    <div v-if="puedeIntervenir && !ticketArchivado" class="flex items-center gap-3 text-sm">
                        <button v-if="i.is_open" type="button" @click="abrirFormularioEdicion(i)"
                                class="text-blue-600 dark:text-blue-400 hover:underline">
                            Editar
                        </button>
                        <button v-if="i.is_open" type="button" @click="finalizar(i)"
                                class="text-green-700 dark:text-green-400 hover:underline">
                            Finalizar
                        </button>
                        <button v-else type="button" @click="abrirReapertura(i)"
                                class="text-amber-700 dark:text-amber-400 hover:underline">
                            Reabrir
                        </button>
                    </div>
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Técnico</dt>
                        <dd class="text-gray-800 dark:text-gray-100">
                            {{ i.technician_name || '—' }}
                            <span v-if="i.assistant_name" class="text-gray-500 dark:text-gray-400">
                                · con {{ i.assistant_name }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Inicio y fin</dt>
                        <dd class="text-gray-800 dark:text-gray-100">
                            {{ fecha(i.started_at) }} → {{ i.finished_at ? fecha(i.finished_at) : 'en curso' }}
                        </dd>
                    </div>
                    <div v-for="campo in camposDeTexto" :key="campo.clave" class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ campo.etiqueta }}
                        </dt>
                        <dd class="text-gray-800 dark:text-gray-100 whitespace-pre-line">
                            {{ i[campo.clave] || '—' }}
                        </dd>
                    </div>
                </dl>

                <!-- Evidencia de esta visita. El archivo no se duplica: son los
                     mismos adjuntos del ticket, enlazados a la intervención. -->
                <div v-if="i.attachments?.length" class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                        Evidencia
                    </p>
                    <ul class="space-y-1">
                        <li v-for="a in i.attachments" :key="a.id" class="text-sm flex items-center gap-2">
                            <a :href="a.url" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">
                                {{ a.file_name }}
                            </a>
                            <span v-if="a.evidence_type" class="text-xs text-gray-500 dark:text-gray-400">
                                ({{ a.evidence_type }})
                            </span>
                            <span v-if="a.description" class="text-xs text-gray-500 dark:text-gray-400">
                                — {{ a.description }}
                            </span>
                        </li>
                    </ul>
                </div>
            </li>
        </ol>

        <!-- Alta / edición -->
        <Teleport to="body">
            <div v-if="formularioAbierto" class="fixed inset-0 z-app-modal flex items-center justify-center p-4 bg-black/60"
                 @click="cerrarFormulario">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto"
                     @click.stop>
                    <div class="p-6 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                            {{ editandoId ? 'Editar intervención' : 'Registrar intervención' }}
                        </h3>
                    </div>

                    <div class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Tipo <span class="text-red-500">*</span>
                                </label>
                                <select v-model="form.kind" :class="claseCampo(errores.kind)">
                                    <option value="presencial">Presencial</option>
                                    <option value="remoto">Remota</option>
                                </select>
                                <p v-if="errores.kind" class="mt-1 text-sm text-red-500">{{ errores.kind[0] }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Inicio <span class="text-red-500">*</span>
                                </label>
                                <input v-model="form.started_at" type="datetime-local" :class="claseCampo(errores.started_at)" />
                                <p v-if="errores.started_at" class="mt-1 text-sm text-red-500">{{ errores.started_at[0] }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Técnico responsable <span class="text-red-500">*</span>
                                </label>
                                <select v-model="form.technician_id" :class="claseCampo(errores.technician_id)">
                                    <option :value="null">— Seleccionar —</option>
                                    <option v-for="t in tecnicos" :key="t.id" :value="t.id">{{ nombreDe(t) }}</option>
                                </select>
                                <p v-if="errores.technician_id" class="mt-1 text-sm text-red-500">
                                    {{ errores.technician_id[0] }}
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Acompañante
                                </label>
                                <select v-model="form.assistant_id" :class="claseCampo(errores.assistant_id)">
                                    <option :value="null">— Ninguno —</option>
                                    <option v-for="t in tecnicos" :key="t.id" :value="t.id">{{ nombreDe(t) }}</option>
                                </select>
                                <p v-if="errores.assistant_id" class="mt-1 text-sm text-red-500">
                                    {{ errores.assistant_id[0] }}
                                </p>
                            </div>
                        </div>

                        <div v-for="campo in camposDeTexto" :key="campo.clave">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                {{ campo.etiqueta }}
                            </label>
                            <textarea v-model="form[campo.clave]" rows="2" :class="claseCampo(errores[campo.clave])"></textarea>
                        </div>

                        <!-- Finalizar al guardar es opcional: una atención remota
                             de dos minutos se registra ya terminada. -->
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" v-model="finalizarAlGuardar" class="rounded" />
                            Marcar como finalizada al guardar
                        </label>

                        <div v-if="finalizarAlGuardar">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Fin
                            </label>
                            <input v-model="form.finished_at" type="datetime-local" :class="claseCampo(errores.finished_at)" />
                            <p v-if="errores.finished_at" class="mt-1 text-sm text-red-500">{{ errores.finished_at[0] }}</p>
                        </div>

                        <p v-if="errorGeneral" class="text-sm text-red-500">{{ errorGeneral }}</p>
                    </div>

                    <div class="p-6 border-t border-gray-100 dark:border-gray-700 flex justify-end gap-3">
                        <button type="button" @click="cerrarFormulario"
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

        <!-- Reapertura: el motivo es obligatorio porque es la única vía de
             corrección que existe. Sin explicación sería un borrado con otro
             nombre. -->
        <Teleport to="body">
            <div v-if="reabriendo" class="fixed inset-0 z-app-modal flex items-center justify-center p-4 bg-black/60"
                 @click="reabriendo = null">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg" @click.stop>
                    <div class="p-6 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                            Reabrir intervención #{{ reabriendo.sequence }}
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Quedará registrado en el historial del ticket con tu nombre y la fecha.
                        </p>
                    </div>
                    <div class="p-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Motivo <span class="text-red-500">*</span>
                        </label>
                        <textarea v-model="motivoReapertura" rows="3" :class="claseCampo(errores.reason)"
                                  placeholder="Entre 10 y 500 caracteres"></textarea>
                        <p v-if="errores.reason" class="mt-1 text-sm text-red-500">{{ errores.reason[0] }}</p>
                        <p v-if="errorGeneral" class="mt-1 text-sm text-red-500">{{ errorGeneral }}</p>
                    </div>
                    <div class="p-6 border-t border-gray-100 dark:border-gray-700 flex justify-end gap-3">
                        <button type="button" @click="reabriendo = null"
                                class="px-5 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm">
                            Cancelar
                        </button>
                        <button type="button" :disabled="guardando" @click="confirmarReapertura"
                                class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm disabled:opacity-50">
                            {{ guardando ? 'Reabriendo…' : 'Reabrir' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'

/**
 * PR F1 · Intervenciones técnicas del ticket (§ 14 de la Solicitud Maestra).
 *
 * Componente aparte y no un bloque más dentro de SupportDetail.vue, que ya pasa
 * de mil ochocientas líneas. Es el mismo criterio con el que se extrajo
 * `TicketDiagnosisFields.vue`.
 *
 * NO HAY BOTÓN DE BORRAR, Y NO ES UN OLVIDO. Una intervención no se borra: si
 * está mal se reabre indicando el motivo y se corrige, y la corrección queda en
 * el historial. El § 15.10 exige que el expediente no pierda sus intervenciones.
 */

const props = defineProps({
    ticketId: { type: [Number, String], required: true },
    /** ¿Tiene `ticket_intervene`? Lo decide el servidor; aquí sólo se pinta. */
    puedeIntervenir: { type: Boolean, default: false },
    /** Un ticket archivado no admite escrituras: hay que restaurarlo antes. */
    ticketArchivado: { type: Boolean, default: false },
    /** Personal del tenant para los desplegables de técnico y acompañante. */
    tecnicos: { type: Array, default: () => [] },
})

const emit = defineEmits(['cambio'])

const camposDeTexto = [
    { clave: 'finding', etiqueta: 'Hallazgo' },
    { clave: 'action_taken', etiqueta: 'Acción realizada' },
    { clave: 'outcome', etiqueta: 'Resultado' },
    { clave: 'next_step', etiqueta: 'Próximo paso' },
]

const intervenciones = ref([])
const cargando = ref(true)
const error = ref('')

const formularioAbierto = ref(false)
const editandoId = ref(null)
const guardando = ref(false)
const errores = ref({})
const errorGeneral = ref('')
const finalizarAlGuardar = ref(false)

const reabriendo = ref(null)
const motivoReapertura = ref('')

const form = ref(formVacio())

function formVacio() {
    return {
        kind: 'presencial',
        technician_id: null,
        assistant_id: null,
        started_at: ahoraLocal(),
        finished_at: null,
        finding: '',
        action_taken: '',
        outcome: '',
        next_step: '',
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

function nombreDe(t) {
    return [t.user_name, t.user_lastname].filter(Boolean).join(' ') || t.name || t.email
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
        const { data } = await api.support.getInterventions(props.ticketId)
        intervenciones.value = data.data ?? []
    } catch (e) {
        error.value = e.response?.data?.message || 'No se pudieron cargar las intervenciones.'
    } finally {
        cargando.value = false
    }
}

function abrirFormularioNuevo() {
    editandoId.value = null
    form.value = formVacio()
    finalizarAlGuardar.value = false
    errores.value = {}
    errorGeneral.value = ''
    formularioAbierto.value = true
}

function abrirFormularioEdicion(i) {
    editandoId.value = i.id
    form.value = {
        kind: i.kind,
        technician_id: i.technician_id,
        assistant_id: i.assistant_id,
        started_at: (i.started_at || '').slice(0, 16),
        finished_at: i.finished_at ? i.finished_at.slice(0, 16) : null,
        finding: i.finding || '',
        action_taken: i.action_taken || '',
        outcome: i.outcome || '',
        next_step: i.next_step || '',
    }
    finalizarAlGuardar.value = false
    errores.value = {}
    errorGeneral.value = ''
    formularioAbierto.value = true
}

function cerrarFormulario() {
    formularioAbierto.value = false
}

function cuerpoDelFormulario() {
    const cuerpo = { ...form.value }

    if (!finalizarAlGuardar.value) {
        delete cuerpo.finished_at
    } else if (!cuerpo.finished_at) {
        cuerpo.finished_at = ahoraLocal()
    }

    return cuerpo
}

async function guardar() {
    try {
        guardando.value = true
        errores.value = {}
        errorGeneral.value = ''

        if (editandoId.value) {
            await api.support.updateIntervention(props.ticketId, editandoId.value, cuerpoDelFormulario())
        } else {
            await api.support.createIntervention(props.ticketId, cuerpoDelFormulario())
        }

        formularioAbierto.value = false
        await cargar()
        emit('cambio')
    } catch (e) {
        errores.value = e.response?.data?.errors || {}
        // El backend explica por qué rechazó —por ejemplo, que la intervención
        // ya está finalizada y hay que reabrirla—. Mostrarlo tal cual evita que
        // el usuario tenga que adivinar.
        errorGeneral.value = Object.keys(errores.value).length
            ? ''
            : (e.response?.data?.message || 'No se pudo guardar la intervención.')
    } finally {
        guardando.value = false
    }
}

/** Finalizar sin abrir el formulario: es la acción más frecuente en campo. */
async function finalizar(i) {
    try {
        guardando.value = true
        await api.support.updateIntervention(props.ticketId, i.id, { finished_at: ahoraLocal() })
        await cargar()
        emit('cambio')
    } catch (e) {
        error.value = e.response?.data?.message || 'No se pudo finalizar la intervención.'
    } finally {
        guardando.value = false
    }
}

function abrirReapertura(i) {
    reabriendo.value = i
    motivoReapertura.value = ''
    errores.value = {}
    errorGeneral.value = ''
}

async function confirmarReapertura() {
    try {
        guardando.value = true
        errores.value = {}
        errorGeneral.value = ''

        await api.support.reopenIntervention(props.ticketId, reabriendo.value.id, motivoReapertura.value)

        reabriendo.value = null
        await cargar()
        emit('cambio')
    } catch (e) {
        errores.value = e.response?.data?.errors || {}
        errorGeneral.value = Object.keys(errores.value).length
            ? ''
            : (e.response?.data?.message || 'No se pudo reabrir la intervención.')
    } finally {
        guardando.value = false
    }
}

onMounted(cargar)

defineExpose({ cargar })
</script>
