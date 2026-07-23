import { ref } from 'vue'
import api from '../services/api'

// Mismos valores que ya usaba el polling de Customers.vue: 2.5s entre
// consultas, ~75s sin avance (más que el peor caso de un cliente) antes de
// asumir que no hay worker de cola corriendo.
const POLL_MS_DEFAULT = 2500
const STALL_MS_DEFAULT = 75000

/**
 * Polling de un BulkProvisionRun (job_id) para el caso de UN solo cliente —
 * usado por CustomerAdd.vue / CustomerEdit.vue tras crear/editar con
 * push_to_router=true. Extrae y generaliza (sin disparar toasts) la lógica de
 * polling + interpretación de resultado que ya usaba Customers.vue para su
 * caso "un solo cliente seleccionado" (results.length === 1).
 *
 * Estados expuestos:
 *   'idle'    -> sin job en curso.
 *   'polling' -> aprovisionamiento en progreso (progress.current/total).
 *   'done'    -> terminó; ver `result` (normalizado) para éxito/advertencia/fallo real.
 *   'timeout' -> no se pudo confirmar el resultado (red o worker caído); ver `errorMessage`.
 *
 * Nota: un error de red/backend en la llamada que CREA el job (create/update)
 * ocurre ANTES de tener job_id y no pasa por este composable — eso lo sigue
 * manejando el catch() de cada pantalla, como hoy.
 */
export function useProvisionPolling() {
    const state = ref('idle')
    const progress = ref({ current: 0, total: 0 })
    const result = ref(null)
    const errorMessage = ref('')

    /**
     * Normaliza la fila de resultado de un solo cliente (mismo shape que ya
     * devuelve CustomerProvisioningService::provisionOne / provisionByControlMode)
     * a un objeto simple para que cada pantalla arme su propio mensaje.
     */
    function interpretSingleResult(r) {
        if (!r) return null
        const alreadyOnRb = r.queue_result?.action === 'updated' || r.pppoe_result?.action === 'updated'
        return {
            raw: r,
            success: !!r.success,
            alreadyOnRb,
            pppoeApplies: !!r.pppoe_applies,
            pppoeCreated: !!r.pppoe_created,
            pppoeSkipped: !!r.pppoe_skipped,
            queueOk: r.queue_ok,
            queueMessage: r.queue_message,
            pppoeMessage: r.pppoe_message,
            message: r.message,
            customerName: r.customer_name,
        }
    }

    async function pollJob(jobId, { pollMs = POLL_MS_DEFAULT, stallMs = STALL_MS_DEFAULT } = {}) {
        state.value = 'polling'
        progress.value = { current: 0, total: 1 }
        result.value = null
        errorMessage.value = ''

        const stallLimit = Math.ceil(stallMs / pollMs)
        let status = null
        let pollErrors = 0
        let lastProcessed = -1
        let stallPolls = 0

        // eslint-disable-next-line no-constant-condition
        while (true) {
            await new Promise(r => setTimeout(r, pollMs))
            let processed = lastProcessed
            try {
                const st = await api.customers.bulkProvisionStatus(jobId)
                status = st.data
                pollErrors = 0
                processed = status.processed || 0
                progress.value = { current: processed, total: status.total || 1 }
                if (status.status === 'done') break
            } catch (e) {
                if (++pollErrors >= 5) {
                    state.value = 'timeout'
                    errorMessage.value = 'No se pudo consultar el estado del aprovisionamiento (problema de red).'
                    return null
                }
                continue // blip de red: reintentar sin contar como estancamiento
            }

            if (processed === lastProcessed) {
                if (++stallPolls >= stallLimit) {
                    state.value = 'timeout'
                    errorMessage.value = 'El aprovisionamiento no avanzó. Probablemente el worker de la cola no está corriendo (php artisan queue:work).'
                    return null
                }
            } else {
                stallPolls = 0
                lastProcessed = processed
            }
        }

        const interpreted = interpretSingleResult((status.results || [])[0])
        result.value = interpreted
        state.value = 'done'
        return interpreted
    }

    function reset() {
        state.value = 'idle'
        progress.value = { current: 0, total: 0 }
        result.value = null
        errorMessage.value = ''
    }

    return { state, progress, result, errorMessage, pollJob, reset }
}
