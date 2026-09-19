/**
 * Reglas de factura que la interfaz necesita conocer para no ofrecer acciones
 * que la API va a rechazar.
 *
 * Viven aquí y no dentro de cada pantalla porque el borrado y la anulación se
 * disparan desde dos sitios —el listado y el detalle— y ya pasó una vez: cada
 * pantalla escribió su propio aviso y una acabó advirtiendo de algo que la otra
 * callaba (ver `InvoiceDeleteWarning`).
 *
 * ESTO NO ES LA AUTORIZACIÓN. El servidor vuelve a comprobarlo todo en
 * `Invoice::sePuedeBorrar()` y en el endpoint de anulación; lo de aquí sólo
 * decide qué botón se pinta.
 */

/** Los dos estados que significan «esto ya no se cobra». */
export const ESTADOS_ANULADOS = ['void', 'cancelled']

export function estaAnulada(invoice) {
    return ESTADOS_ANULADOS.includes(invoice?.status)
}

/**
 * ¿Se puede DESTRUIR esta factura?
 *
 * Sólo un borrador que no salió nunca: sin número y sin ticket detrás. En la
 * práctica ninguna lo cumple —toda factura nace con número y en `issued`—, así
 * que el botón «Eliminar» deja de aparecer. Se mantiene la comprobación porque
 * `draft` es el valor por defecto de la columna y un `INSERT` a mano puede
 * producir uno.
 *
 * Espejo de `App\Models\Invoice::sePuedeBorrar()`.
 */
export function sePuedeBorrar(invoice) {
    if (!invoice) return false

    return invoice.status === 'draft'
        && !String(invoice.number ?? '').trim()
        && (invoice.ticket_id === null || invoice.ticket_id === undefined)
}

/** Una factura viva se puede anular; una ya anulada, no. */
export function sePuedeAnular(invoice) {
    return Boolean(invoice) && !estaAnulada(invoice)
}
