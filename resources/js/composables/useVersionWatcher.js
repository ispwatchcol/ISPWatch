import { ref } from 'vue'
import axios from 'axios'
import { useAuthStore } from '@/stores/auth'

/**
 * Avisa cuando el servidor ya sirve una versión más nueva que la que este
 * navegador tiene cargada (KAN-101 · P-46).
 *
 * POR QUÉ HACE FALTA
 * Los chunks de Vite llevan hash de contenido y nunca se sirven rancios, pero
 * el documento HTML que los referencia tiene una URL estable. Una pestaña que
 * lleve horas abierta sigue ejecutando el código con el que se cargó, y nadie
 * le va a decir al usuario que pulse Ctrl+F5. El 2026-09-10 eso hizo que un
 * cliente siguiera viendo un formulario ya arreglado.
 *
 * QUÉ SE COMPARA — Y POR QUÉ NO ES EL NÚMERO DE VERSIÓN
 * `version` sólo se mueve al publicar (SemVer), y la mayoría de los despliegues
 * corrigen algo sin tocarlo: comparar eso dejaría el aviso mudo justo en los
 * despliegues más frecuentes. Se compara `build`, la huella del manifiesto de
 * Vite, que cambia siempre que cambia un chunk.
 *
 * El valor de referencia es el PRIMERO que se ve tras cargar, guardado sólo en
 * memoria. Nada de localStorage: un valor persistido de una sesión anterior
 * haría aparecer el aviso en una pestaña recién abierta, que por definición ya
 * tiene el código más reciente.
 *
 * Estado a nivel de módulo (fuera de la función) a propósito: es un único
 * vigilante para toda la aplicación, aunque varios componentes lo consulten.
 */

/** Ritmo de fondo. Un despliegue no es algo que ocurra cada minuto. */
const CHECK_EVERY_MS = 10 * 60 * 1000

/** Suelo entre comprobaciones, para que volver a la pestaña no dispare una ráfaga. */
const MIN_GAP_MS = 60 * 1000

const updateAvailable = ref(false)
const loadedBuild = ref(null)
const deployedVersion = ref(null)

let timer = null
let lastCheckedAt = 0
let listening = false

async function check(force = false) {
    // Sin sesión no hay a quién avisar, y el endpoint exige autenticación.
    if (!useAuthStore().isAuthenticated) return

    // Una vez que hay aviso, deja de preguntar: la respuesta ya no cambia nada.
    if (updateAvailable.value) return

    const now = Date.now()
    if (!force && now - lastCheckedAt < MIN_GAP_MS) return
    lastCheckedAt = now

    try {
        // axios directo y NO `apiClient`: su interceptor manda al login ante
        // cualquier 401, y una comprobación de fondo jamás debe echar a nadie
        // de una pantalla a medio llenar.
        const { data } = await axios.get('/api/system/version', {
            withCredentials: true,
            headers: { Accept: 'application/json' },
        })

        // `build` es null en desarrollo (no hay manifiesto: manda el dev
        // server). Se cae a `version` para no quedarse sin nada que comparar.
        const signature = data?.build ?? data?.version ?? null
        if (!signature) return

        deployedVersion.value = data?.version ?? null

        if (loadedBuild.value === null) {
            loadedBuild.value = signature
            return
        }

        if (signature !== loadedBuild.value) {
            updateAvailable.value = true
        }
    } catch {
        // Red caída, sesión vencida, despliegue a medias: nada de esto es
        // asunto de un aviso de cortesía. Se reintenta en el siguiente ciclo.
    }
}

function onVisibilityChange() {
    if (document.visibilityState === 'visible') check()
}

export function useVersionWatcher() {
    function start() {
        if (timer !== null) return

        check(true)
        timer = window.setInterval(check, CHECK_EVERY_MS)

        if (!listening) {
            // Volver a la pestaña es el momento más probable de haberse
            // perdido un despliegue, y también el mejor para avisar.
            document.addEventListener('visibilitychange', onVisibilityChange)
            listening = true
        }
    }

    function stop() {
        if (timer !== null) {
            window.clearInterval(timer)
            timer = null
        }

        if (listening) {
            document.removeEventListener('visibilitychange', onVisibilityChange)
            listening = false
        }
    }

    function reloadNow() {
        window.location.reload()
    }

    return { updateAvailable, deployedVersion, start, stop, reloadNow }
}
