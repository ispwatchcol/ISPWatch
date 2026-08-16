import { ref } from 'vue'
import { apiClient } from '../services/api'

/**
 * Catálogos del ticket de soporte (estados, prioridades, categorías).
 *
 * FASE 1 · R2 — sustituye los mapas de etiquetas que estaban escritos a mano en
 * Support.vue, SupportDetail.vue, SupportEdit.vue, SupportCreate.vue y
 * CustomerTickets.vue. Tenerlos duplicados en cinco sitios significaba que
 * agregar un estado obligaba a acordarse de los cinco, y que la interfaz podía
 * ofrecer opciones que el backend ya no aceptaba (o al revés).
 *
 * DOS COSAS QUE NO SE MEZCLAN
 *
 *   · La ETIQUETA viene del catálogo y puede cambiar sin desplegar.
 *   · El COLOR se decide por CÓDIGO, que es estable, y por eso los mapas de
 *     clases de Tailwind se quedan en los componentes. Un color no es un dato
 *     de negocio y no tiene por qué viajar en la respuesta.
 *
 * El estado es de MÓDULO, no por componente: los catálogos son los mismos para
 * toda la aplicación y no cambian durante la sesión, así que se piden una sola
 * vez aunque haya varias pantallas montadas a la vez.
 */

const statuses = ref([])
const priorities = ref([])
const categories = ref([])
const cargado = ref(false)

// Se guarda la promesa en vuelo, no sólo el flag: si dos componentes se montan
// a la vez —la lista de tickets y la ficha del cliente, por ejemplo— sin esto
// saldrían dos peticiones idénticas antes de que la primera respondiera.
let enVuelo = null

async function cargar(forzar = false) {
    if (cargado.value && !forzar) return
    if (enVuelo) return enVuelo

    enVuelo = apiClient.get('/catalogs/ticket')
        .then(({ data }) => {
            statuses.value = data.statuses ?? []
            priorities.value = data.priorities ?? []
            categories.value = data.categories ?? []
            cargado.value = true
        })
        .catch(() => {
            // Sin catálogo, `etiqueta()` devuelve el propio código. La pantalla
            // se ve peor pero sigue siendo usable, que es mejor que romperla.
            statuses.value = []
            priorities.value = []
            categories.value = []
        })
        .finally(() => { enVuelo = null })

    return enVuelo
}

/** Etiqueta de un código; si no se conoce, se muestra el código tal cual. */
function etiqueta(lista, code) {
    if (!code) return ''
    return lista.value.find((f) => f.code === code)?.label ?? code
}

export function useTicketCatalogs() {
    return {
        statuses,
        priorities,
        categories,
        cargado,
        cargar,

        statusLabel: (code) => etiqueta(statuses, code),
        priorityLabel: (code) => etiqueta(priorities, code),
        categoryLabel: (code) => etiqueta(categories, code),
    }
}
