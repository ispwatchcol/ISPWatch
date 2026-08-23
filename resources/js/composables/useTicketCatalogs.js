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

// PR #2 — vocabulario de diagnóstico del Anexo A. Llega en la misma respuesta
// que los tres de arriba: la pantalla de soporte los necesita a la vez y
// partirlo en dos peticiones no compensa para sesenta filas.
const symptoms = ref([])
const causes = ref([])
const actions = ref([])
const results = ref([])

const cargado = ref(false)
const error = ref(false)

// Se guarda la promesa en vuelo, no sólo el flag: si dos componentes se montan
// a la vez —la lista de tickets y la ficha del cliente, por ejemplo— sin esto
// saldrían dos peticiones idénticas antes de que la primera respondiera.
let enVuelo = null

async function cargar(forzar = false) {
    if (cargado.value && !forzar) return
    if (enVuelo) return enVuelo

    error.value = false

    enVuelo = apiClient.get('/catalogs/ticket')
        .then(({ data }) => {
            statuses.value = data.statuses ?? []
            priorities.value = data.priorities ?? []
            categories.value = data.categories ?? []
            symptoms.value = data.symptoms ?? []
            causes.value = data.causes ?? []
            actions.value = data.actions ?? []
            results.value = data.results ?? []
            cargado.value = true
        })
        .catch(() => {
            // Sin catálogo, `etiqueta()` devuelve el propio código. La pantalla
            // se ve peor pero sigue siendo usable, que es mejor que romperla.
            //
            // El diagnóstico es distinto: sus desplegables se quedarían VACÍOS y
            // sin explicación, así que se marca el error para que la pantalla
            // pueda decir por qué no hay opciones en vez de fingir que no las hay.
            statuses.value = []
            priorities.value = []
            categories.value = []
            symptoms.value = []
            causes.value = []
            actions.value = []
            results.value = []
            error.value = true
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
        symptoms,
        causes,
        actions,
        results,
        cargado,
        error,
        cargar,

        statusLabel: (code) => etiqueta(statuses, code),
        priorityLabel: (code) => etiqueta(priorities, code),
        categoryLabel: (code) => etiqueta(categories, code),
        symptomLabel: (code) => etiqueta(symptoms, code),
        causeLabel: (code) => etiqueta(causes, code),
        actionLabel: (code) => etiqueta(actions, code),
        resultLabel: (code) => etiqueta(results, code),
    }
}
