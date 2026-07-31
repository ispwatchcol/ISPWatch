/**
 * Etiqueta y color del tipo de factura, compartidos por Facturación y Recaudos
 * para que el mismo tipo se vea siempre igual en todo el sistema.
 *
 * Los recaudos no tienen tipo propio: heredan el de las facturas que cubren, y
 * es lo que colorea los chips de "Facturas afectadas".
 */
const TYPES = {
    monthly:        { label: 'Plan Mensual',  color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' },
    installation:   { label: 'Instalación',   color: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' },
    additional:     { label: 'Adicional',     color: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300' },
    service_charge: { label: 'Cargo Ticket',  color: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' },
}

// Sin tipo = mensual: es lo que genera la facturación automática y las facturas
// antiguas se crearon antes de que existiera la columna.
const DEFAULT_TYPE = TYPES.monthly

export function invoiceTypeLabel(type) {
    return (TYPES[type] ?? DEFAULT_TYPE).label
}

export function invoiceTypeColor(type) {
    return (TYPES[type] ?? DEFAULT_TYPE).color
}
