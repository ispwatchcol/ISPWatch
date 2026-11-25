/**
 * Presentación compartida de los tickets (bitácora § 133).
 *
 * Antes cada pantalla tenía su propio mapa de colores por código de estado, y
 * sólo conocía los cuatro estados viejos (open, in_progress, resolved,
 * closed): los 18 del flujo nuevo caían todos al azul por defecto, y
 * «Radicado» se veía igual que «Servicio restablecido».
 *
 * Ahora el color va por GRUPO de estado —lo calcula el servidor, ver
 * `TicketWorkflow::GRUPOS`— y vive en un solo sitio. El color es presentación,
 * no dato de negocio: por eso no viaja en la respuesta.
 */

const BASE = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap'

const GRUPOS = {
    nuevo: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    en_curso: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
    en_espera: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    para_cerrar: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    cerrado: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
}

const PUNTOS = {
    nuevo: 'bg-sky-500',
    en_curso: 'bg-indigo-500',
    en_espera: 'bg-amber-500',
    para_cerrar: 'bg-emerald-500',
    cerrado: 'bg-gray-400',
}

const PRIORIDADES = {
    low: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    medium: 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    high: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200',
    urgent: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
}

/** Insignia del estado, por grupo. */
export const groupBadgeClass = (grupo) => `${BASE} ${GRUPOS[grupo] || GRUPOS.nuevo}`

/** Punto de color del grupo (pestañas, leyendas). */
export const groupDotClass = (grupo) => `inline-block h-2 w-2 rounded-full ${PUNTOS[grupo] || PUNTOS.nuevo}`

/** Insignia de la prioridad. */
export const priorityBadgeClass = (prioridad) => `${BASE} ${PRIORIDADES[prioridad] || PRIORIDADES.medium}`

/** Estado del servicio del cliente (customer_profile.service_status). */
export const serviceStatusInfo = (estado) => {
    const mapa = {
        activo: { label: 'Activo', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' },
        gratis: { label: 'Cortesía', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' },
        suspendido: { label: 'Cortado', clase: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' },
        retirado: { label: 'Retirado', clase: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' },
        cancelado: { label: 'Cancelado', clase: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' },
    }
    const fila = mapa[estado || 'activo'] || { label: estado, clase: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }
    return { label: fila.label, clase: `${BASE} ${fila.clase}` }
}

/**
 * Cuánto lleva abierto un ticket: «hace 3 h», «hace 2 d», y un nivel para
 * pintarlo (normal / atención a las 24 h / tarde a las 72 h).
 */
export const ageInfo = (fecha, ahora = Date.now()) => {
    if (!fecha) return { texto: '—', nivel: 'normal', horas: 0 }
    const horas = Math.max(0, (ahora - new Date(fecha).getTime()) / 36e5)
    let texto
    if (horas < 1) texto = `hace ${Math.max(1, Math.round(horas * 60))} min`
    else if (horas < 24) texto = `hace ${Math.floor(horas)} h`
    else texto = `hace ${Math.floor(horas / 24)} d`
    const nivel = horas >= 72 ? 'tarde' : horas >= 24 ? 'atencion' : 'normal'
    return { texto, nivel, horas }
}

export const ageClass = (nivel) => ({
    tarde: 'text-red-600 dark:text-red-400 font-semibold',
    atencion: 'text-amber-600 dark:text-amber-400 font-medium',
    normal: 'text-gray-500 dark:text-gray-400',
}[nivel] || 'text-gray-500 dark:text-gray-400')

/** Sólo dígitos del teléfono; null si no hay nada marcable. */
const digitos = (tel) => {
    const d = String(tel || '').replace(/\D/g, '')
    return d.length >= 7 ? d : null
}

export const telUrl = (tel) => {
    const d = digitos(tel)
    return d ? `tel:${d}` : null
}

/**
 * Enlace de WhatsApp. Un número colombiano de 10 dígitos (3xx…) se completa
 * con el 57; uno que ya trae indicativo se deja como está.
 */
export const whatsappUrl = (tel) => {
    let d = digitos(tel)
    if (!d) return null
    if (d.length === 10 && d.startsWith('3')) d = `57${d}`
    return `https://wa.me/${d}`
}

/** Mapa: coordenadas si las hay; si no, la dirección como búsqueda. */
export const mapsUrl = ({ latitude, longitude, address, city } = {}) => {
    const lat = parseFloat(latitude)
    const lng = parseFloat(longitude)
    if (Number.isFinite(lat) && Number.isFinite(lng) && (lat !== 0 || lng !== 0)) {
        return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`
    }
    const texto = [address, city].filter(Boolean).join(', ')
    return texto ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(texto)}` : null
}

export const formatCOP = (valor) => new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: 'COP', maximumFractionDigits: 0,
}).format(parseFloat(valor) || 0)
