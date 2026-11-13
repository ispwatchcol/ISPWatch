import { ref, readonly } from 'vue'

/**
 * Avisos GRANDES de «no tienes permiso».
 *
 * Problema que resuelve: cuando el backend respondía 403, cada pantalla lo
 * trataba a su manera —un toast chico y genérico («Error al cargar…»), o nada— y
 * el usuario quedaba perdido sin saber que lo que pasaba era un permiso faltante
 * (así costó diagnosticar el «Error al cargar los detalles del ticket» de un
 * técnico: era un 403 de `/staff` disfrazado de error de carga).
 *
 * El interceptor de `services/api.js` anota aquí todo 403 que sea de permisos, y
 * `PermissionDeniedAlert` (montado una sola vez en `App.vue`) lo pinta en
 * grande. Estado a nivel de módulo, como `useServiceSuspension.js`: lo escribe un
 * interceptor de axios que vive fuera de Vue.
 *
 * Este archivo NO importa `services/api.js` (el interceptor lo importa a él).
 *
 * LLAMADAS OPCIONALES: una petición cuyo fallo la pantalla ya tolera (p. ej. la
 * lista de personal para un desplegable) se marca con `silentForbidden: true` en
 * su config de axios y no dispara el aviso. Sin esa marca, un técnico vería el
 * cartel en cada pantalla que pide algo extra que su rol no cubre.
 */

const denials = ref([])

/** Máximo de avisos distintos apilados; el resto se descarta (el más viejo). */
const MAX_DENIALS = 5

/**
 * ¿Este error de axios es un 403 POR PERMISOS?
 *
 * No todo 403 lo es: «los tickets no se pueden eliminar» o «no se puede eliminar
 * un rol en uso» son reglas de negocio y su pantalla ya las explica. Se
 * reconocen los de permisos por:
 *   - `required_permission` en el cuerpo (CheckPermission y SupportTicketController::negar),
 *   - o el texto de los middleware/controladores: «Forbidden…», «Unauthorized…»
 *     o «…permiso(s)…».
 */
export function isPermissionDeniedError(error) {
    const res = error?.response
    if (res?.status !== 403) return false
    if (error?.config?.silentForbidden) return false

    const data = res.data
    if (data && typeof data === 'object') {
        if (data.required_permission) return true
        if (typeof data.message === 'string'
            && /^(forbidden|unauthorized)|permiso/i.test(data.message.trim())) {
            return true
        }
    }

    return false
}

/** Lo que el usuario puede decirle a quien lo atienda: «GET /staff». */
function describeRequest(error) {
    const method = (error?.config?.method || '').toUpperCase()
    const url = (error?.config?.url || '').split('?')[0]
    return [method, url].filter(Boolean).join(' ')
}

/** Lo llama el interceptor ante un 403 de permisos. */
export function reportPermissionDenied(error) {
    const data = error?.response?.data ?? {}
    const required = [].concat(data.required_permission ?? []).filter(Boolean)
    const request = describeRequest(error)

    // Misma petición y mismo permiso = mismo aviso: una pantalla que dispara tres
    // veces la misma llamada no debe apilar tres carteles idénticos.
    const key = `${request}|${required.join(',')}`
    if (denials.value.some(d => d.key === key)) return

    denials.value = [
        ...denials.value,
        {
            key,
            request,
            required,
            // Nombre en español de cada permiso (el de la pantalla de Roles) y
            // el rol con que el servidor evaluó la petición. Ambos opcionales:
            // los 403 que no vienen de CheckPermission no los traen.
            labels: data.permission_labels && typeof data.permission_labels === 'object'
                ? data.permission_labels : {},
            roleName: typeof data.role?.name === 'string' ? data.role.name : '',
            // Texto del backend sólo como dato secundario: viene en inglés en los
            // middleware («Forbidden - …») y el cartel ya explica el caso en español.
            serverMessage: typeof data.message === 'string' ? data.message : '',
            noRole: /no role assigned/i.test(data.message || ''),
        },
    ].slice(-MAX_DENIALS)
}

export function dismissPermissionDenied() {
    denials.value = []
}

export function usePermissionDenied() {
    return {
        denials: readonly(denials),
        dismiss: dismissPermissionDenied,
    }
}
