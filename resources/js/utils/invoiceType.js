/**
 * Catálogo de tipos de factura, compartido por Facturación, Recaudos y el
 * detalle de factura para que el mismo tipo se vea siempre igual.
 *
 * Los tipos ya no viven sólo aquí: el backend expone un catálogo administrable
 * (/billing/invoice-types) donde cada tenant agrega los suyos — equipos, TV,
 * reconexión... — encima de los cuatro del sistema. Este módulo mantiene una
 * copia reactiva en memoria y cae en los del sistema mientras carga o si la
 * petición falla, para que la tabla nunca quede con chips en blanco.
 *
 * Los recaudos no tienen tipo propio: heredan el de las facturas que cubren, y
 * es lo que colorea los chips de "Facturas afectadas".
 */
import { ref } from 'vue'
import { apiClient } from '@/services/api'

/**
 * Clases completas por color: Tailwind sólo conserva las que aparecen
 * literalmente en el código, así que NO se pueden componer con plantillas
 * (`bg-${color}-100` desaparecería en el build de producción).
 */
const PALETTE = {
    blue:    'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    purple:  'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
    amber:   'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    rose:    'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
    cyan:    'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-300',
    indigo:  'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300',
    orange:  'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
    teal:    'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300',
    slate:   'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
}

export const INVOICE_TYPE_COLORS = Object.keys(PALETTE)

/** Clases del chip a partir del color, para previsualizar tipos aún sin guardar. */
export function paletteClasses(color) {
    return PALETTE[color] ?? PALETTE.slate
}

/** Espejo de los tipos que siembra la migración: fallback sin red. */
const SYSTEM_TYPES = [
    { slug: 'monthly',        name: 'Plan Mensual',       color: 'blue',    is_system: true, is_active: true },
    { slug: 'installation',   name: 'Instalación',        color: 'emerald', is_system: true, is_active: true },
    { slug: 'additional',     name: 'Servicio Adicional', color: 'purple',  is_system: true, is_active: true },
    { slug: 'service_charge', name: 'Cargo de Ticket',    color: 'amber',   is_system: true, is_active: true },
]

export const invoiceTypes = ref([...SYSTEM_TYPES])

let loadPromise = null

/**
 * Trae el catálogo del tenant. Comparte una sola petición entre todos los
 * componentes que la piden al montarse; `force` la repite tras un alta o baja.
 */
export async function loadInvoiceTypes(force = false) {
    if (force) loadPromise = null

    if (!loadPromise) {
        loadPromise = apiClient.get('/billing/invoice-types')
            .then(({ data }) => {
                if (Array.isArray(data) && data.length) invoiceTypes.value = data
                return invoiceTypes.value
            })
            .catch(() => {
                // Sin permiso o sin red: seguimos con los del sistema.
                loadPromise = null
                return invoiceTypes.value
            })
    }

    return loadPromise
}

const findType = (slug) => invoiceTypes.value.find(t => t.slug === slug)

/** "factura_de_equipos" → "Factura de equipos", para tipos ya borrados. */
const humanize = (slug) => {
    const words = String(slug).replace(/[_-]+/g, ' ').trim()
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}

export function invoiceTypeLabel(slug) {
    // Sin tipo = mensual: es lo que genera la facturación automática y las
    // facturas antiguas se crearon antes de que existiera la columna.
    if (!slug) return findType('monthly')?.name ?? 'Plan Mensual'
    return findType(slug)?.name ?? humanize(slug)
}

export function invoiceTypeColor(slug) {
    return paletteClasses(findType(slug || 'monthly')?.color)
}

/** Sólo los tipos utilizables al emitir una factura nueva. */
export function activeInvoiceTypes() {
    return invoiceTypes.value.filter(t => t.is_active !== false)
}
