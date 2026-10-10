import { ref } from 'vue'

/**
 * La cola de notificaciones, compartida por toda la aplicación.
 *
 * POR QUÉ VIVE FUERA DE UN COMPONENTE
 *
 * Antes cada pantalla montaba su propio `<NotificationToast>`, y cada instancia
 * traía su propio contenedor `fixed` y su propia lista. Con 37 pantallas eso son
 * 37 contenedores potenciales compitiendo por la esquina superior derecha, cada
 * uno con su `z-index`, y ninguno con forma de saber qué hay pintado encima.
 *
 * El fallo concreto que lo destapó: el aviso «este ticket tiene un cargo sin
 * anular» salía DETRÁS del modal de archivado, porque el contenedor estaba en
 * `z-[100]` y el modal en `z-[9999]`. Subir el número del contenedor lo habría
 * tapado hasta el siguiente modal con un número más alto.
 *
 * Ahora el estado está aquí —un módulo, una lista— y sólo `NotificationHost`
 * lo pinta, una vez, montado en `App.vue` por encima de todo (`.z-app-toast`).
 *
 * `ref` a nivel de módulo y no `reactive`: es la misma instancia para todos los
 * que importen este archivo, que es justo lo que se quiere.
 */
const notifications = ref([])

let contador = 0

/**
 * Cuánto dura en pantalla cada tipo, en milisegundos.
 *
 * Los errores duran más porque son los que hay que LEER: un mensaje de
 * validación del backend puede ocupar dos líneas y cinco segundos no alcanzan
 * si además hay que buscar el número de factura que menciona. Los aciertos se
 * confirman de un vistazo.
 */
const DURACION = {
    success: 5000,
    info: 5000,
    warning: 8000,
    error: 8000,
}

export function removeNotification(id) {
    const i = notifications.value.findIndex(n => n.id === id)

    if (i > -1) {
        notifications.value.splice(i, 1)
    }
}

/**
 * @param {'success'|'error'|'warning'|'info'} type
 * @param {string} title
 * @param {string} message
 * @param {number|{duration?: number, action?: object}} options
 *        Un número se interpreta como duración, por compatibilidad con las
 *        llamadas que ya existían.
 */
export function addNotification(type, title, message, options = {}) {
    const id = ++contador

    const duration = typeof options === 'number'
        ? options
        : (options?.duration ?? DURACION[type] ?? 5000)

    const action = typeof options === 'object' && options !== null ? options.action : null

    notifications.value.push({ id, type, title, message, action })

    // `duration: 0` deja el aviso hasta que alguien lo cierre a mano. Es
    // deliberado y se conserva: hay errores que no deben desaparecer solos.
    if (duration > 0) {
        setTimeout(() => removeNotification(id), duration)
    }

    return id
}

export function clearNotifications() {
    notifications.value = []
}

/** La misma API que exponía `NotificationToast`, para no tocar 37 pantallas. */
export function useNotifications() {
    return {
        notifications,
        success: (title, message, options) => addNotification('success', title, message, options),
        error: (title, message, options) => addNotification('error', title, message, options),
        warning: (title, message, options) => addNotification('warning', title, message, options),
        info: (title, message, options) => addNotification('info', title, message, options),
        remove: removeNotification,
        clear: clearNotifications,
    }
}
