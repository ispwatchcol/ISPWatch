import { ref, readonly } from 'vue'

/**
 * ¿Converza le cortó el panel a este ISP? (KAN-126)
 *
 * El backend responde 423 con `code: 'service_suspended'` a cualquier llamada
 * del panel cuando el ISP tiene una factura de Converza vencida. El interceptor
 * de `services/api.js` lo anota aquí, y `DefaultLayout` cambia el panel entero
 * por la pantalla de «servicio suspendido».
 *
 * Estado a nivel de módulo (fuera de la función) a propósito, como en
 * `useVersionWatcher.js`: es uno solo para toda la aplicación, y lo escriben
 * tanto el interceptor de axios —que vive fuera de Vue— como los componentes.
 *
 * Este archivo NO importa `services/api.js`: el interceptor lo importa a él, y
 * al revés habría un ciclo. La consulta a `/api/saas/status` la hace la pantalla.
 */

const suspension = ref(null)

/**
 * Cada sección del panel monta su propio DefaultLayout, así que sin este suelo
 * se preguntaría en cada cambio de sección. Entre una y otra, cualquier llamada
 * del panel ya trae el 423 si el corte llega a mitad de sesión.
 */
const MIN_GAP_MS = 60 * 1000
let lastCheckedAt = 0

/** Normaliza lo que manda el backend; `null` si no es una suspensión. */
function normalize(data) {
    if (!data || data.suspended !== true) return null

    return {
        reason: data.reason ?? null,
        since: data.since ?? null,
        message: data.message || 'El servicio está suspendido.',
        paymentInstructions: data.payment_instructions || null,
    }
}

/** Lo llama el interceptor ante un 423 `service_suspended`, o la pantalla al comprobar. */
export function reportServiceStatus(data) {
    suspension.value = normalize(data)
}

/**
 * Pregunta `/api/saas/status` al montar el panel, para mostrar la pantalla
 * antes de que cada página falle por su cuenta.
 *
 * `get` es la función de `apiClient` y llega como argumento para no importar
 * `services/api.js` desde aquí (ver arriba). Un error deja el estado como
 * estaba: igual que en el backend, ante la duda el panel sigue abierto.
 */
export async function checkServiceStatus(get) {
    const now = Date.now()
    if (now - lastCheckedAt < MIN_GAP_MS) return
    lastCheckedAt = now

    try {
        const { data } = await get('/saas/status')
        reportServiceStatus(data?.data)
    } catch {
        // Falla abierto.
    }
}

/** ¿Este error de axios es el corte del panel? */
export function isServiceSuspendedError(error) {
    return error?.response?.status === 423
        && error?.response?.data?.code === 'service_suspended'
}

export function useServiceSuspension() {
    return {
        suspension: readonly(suspension),
    }
}
