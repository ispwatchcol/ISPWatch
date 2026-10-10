// Consumo de materiales «por cantidad» en la orden de instalación y en el
// ticket de soporte. Las dos pantallas registran igual: misma precisión, misma
// clave de reenvío, mismo resumen de cable.
//
// Quien DECIDE es el servidor (InventoryLedger): la precisión del producto, el
// saldo disponible y la clave repetida se validan allá. Esto sólo evita mandar
// lo que va a ser rechazado y lo explica antes.

/** Decimales que admite el producto. 2 si el servidor no lo informa (comportamiento previo). */
export const materialDecimals = (m) => {
  const d = Number(m?.decimals)
  return Number.isInteger(d) && d >= 0 && d <= 2 ? d : 2
}

/** `step` del input: 1 para piezas, 0.1 / 0.01 para lo que se fracciona. */
export const quantityStep = (m) => {
  const d = materialDecimals(m)
  return d === 0 ? '1' : (1 / 10 ** d).toFixed(d)
}

export const fmtQty = (n) => {
  const value = Number(n) || 0
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/0+$/, '').replace('.', ',')
}

/**
 * Motivo por el que la cantidad no se puede registrar, o '' si se puede.
 * `m` es la fila de /equipment/available (con quantity = saldo a tu alcance).
 */
export const quantityError = (m, qty) => {
  if (!m) return 'Elige el material.'
  const value = Number(qty)
  if (!Number.isFinite(value) || value <= 0) return 'Indica cuánto se usó (mayor que cero).'

  const d = materialDecimals(m)
  const factor = 10 ** d
  if (Math.abs(Math.round(value * factor) - value * factor) > 1e-6) {
    return d === 0
      ? `Se registra en cantidades enteras${m.unit ? ` (${m.unit})` : ''}.`
      : `Admite hasta ${d} decimal(es).`
  }

  if (value > Number(m.quantity) + 1e-9) {
    return `Disponible en ${m.source_label}: ${fmtQty(m.quantity)}${m.unit ? ` ${m.unit}` : ''}.`
  }

  return ''
}

/**
 * Clave de un intento de registro. Se genera al pulsar «Agregar» y se
 * reenvía igual si el intento se repite sin respuesta (doble clic, red caída):
 * el servidor devuelve la línea ya creada en vez de descontar otra vez.
 */
export const newRequestKey = () => {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  // Fuera de un contexto seguro (http en la LAN) randomUUID no existe.
  const rnd = () => Math.random().toString(16).slice(2, 10)
  return `${Date.now().toString(16)}-${rnd()}-${rnd()}-${rnd()}`
}

/**
 * ¿Hay que conservar la clave para reintentar? Sólo cuando no se sabe si el
 * servidor la procesó: sin respuesta o con error 5xx. Un 4xx es un rechazo
 * definitivo y el siguiente intento es otro registro.
 */
export const keepKeyAfterError = (e) => !e?.response || e.response.status >= 500

const LENGTH_UNITS = new Set(['m', 'm.', 'mt', 'mts', 'mts.', 'metro', 'metros', 'mtr', 'mtrs'])

export const isLengthUnit = (unit) => LENGTH_UNITS.has(String(unit ?? '').trim().toLowerCase())

/**
 * Resumen calculado del cable de una orden: sale de las líneas de material
 * registradas en metros, no de un campo digitado aparte.
 *
 * @returns {{ total: number, lines: Array<{label: string, quantity: number}> }}
 */
export const cableSummary = (items) => {
  const byLabel = new Map()
  for (const it of items ?? []) {
    if (it.is_device || it.is_return || it.is_reversed || !isLengthUnit(it.unit)) continue
    const label = it.label || `${it.brand ?? ''} ${it.model ?? ''}`.trim() || 'Material'
    byLabel.set(label, (byLabel.get(label) ?? 0) + (Number(it.quantity) || 0))
  }
  const lines = [...byLabel.entries()].map(([label, quantity]) => ({ label, quantity }))
  return { total: lines.reduce((s, l) => s + l.quantity, 0), lines }
}
