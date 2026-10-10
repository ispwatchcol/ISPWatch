// Piezas compartidas por las dos pantallas que agendan y editan órdenes de
// instalación (Instalaciones y la pestaña Instalaciones del cliente). Van aquí
// para que las dos digan lo mismo: el plan se resume igual, se envía igual y
// los bloqueos se explican con las mismas palabras.
//
// Los bloqueos los DECIDE el servidor; esto sólo evita ofrecer un botón que va
// a ser rechazado y explica por qué.

const fmtQty = (n) => {
  const value = Number(n) || 0
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace('.', ',')
}

/** «30 m CABLE UTP · 1 TP-LINK ARCHER C6», o el texto libre de siempre. */
export const equipmentSummary = (inst) => {
  const plan = (inst?.planned_items ?? [])
    .map(p => `${fmtQty(p.quantity)}${p.unit ? ` ${p.unit}` : ''} ${p.label}`)
    .join(' · ')
  return [plan, inst?.equipment].filter(Boolean).join(' · ')
}

export const deleteBlockedReason = (inst) => {
  const motivos = []
  if (Number(inst?.equipment_items_count) > 0) motivos.push('ya descargó equipos o materiales del inventario')
  if (inst?.is_signed) motivos.push('tiene la hoja firmada')
  if (inst?.invoice_id) motivos.push('tiene factura')
  return motivos.length ? `No se puede eliminar: ${motivos.join(', ')}.` : ''
}

// Sin sugerir que se «devuelvan» las líneas: el consumo registrado es real, y
// deshacerlo para poder cancelar sería inventar una devolución.
export const cancelBlockedReason = (inst) => {
  if (Number(inst?.equipment_items_count) > 0) {
    return 'No se puede cancelar: la orden registra consumo real de inventario. '
      + 'Se mantiene en su estado actual hasta que exista la conciliación de consumos.'
  }
  if (inst?.is_signed) return 'No se puede cancelar: la orden ya está firmada.'
  return ''
}

/** Copia editable del plan que viene del servidor. */
export const copyPlan = (inst) => (inst?.planned_items ?? []).map(p => ({ ...p, notes: p.notes ?? '' }))

/**
 * Lo que viaja en `planned_items`. Una línea ya guardada va por `id` —así el
 * servidor conserva su etiqueta y unidad congeladas—; una nueva, por producto.
 */
export const planPayload = (lines) => (lines ?? []).map(l => (l.id
  ? { id: l.id, quantity: Number(l.quantity), notes: (l.notes || '').trim() || null }
  : { stock_id: l.stock_id, quantity: Number(l.quantity), notes: (l.notes || '').trim() || null }))
