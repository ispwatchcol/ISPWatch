/**
 * Cómo se nombra una unidad con serial en pantalla: modelo · serial · MAC.
 *
 * Los tres datos van siempre, y el que falta se dice («sin informar») en vez
 * de omitirse: dos LDF del mismo modelo sólo se distinguen por su serial o su
 * MAC, y un hueco callado hace pensar que el dato existe y no se muestra.
 */

export const deviceModelText = (d) =>
  `${d?.brand ?? ''} ${d?.model ?? ''}`.trim() || 'Modelo sin informar'

export const deviceSerialText = (d) => (d?.serial ? `S/N ${d.serial}` : 'S/N sin informar')

export const deviceMacText = (d) => (d?.mac ? `MAC ${d.mac}` : 'MAC sin informar')

/** «HUAWEI LDF HG8145 · S/N 48575443A1 · MAC sin informar» */
export const deviceFullLabel = (d) => [deviceModelText(d), deviceSerialText(d), deviceMacText(d)].join(' · ')

/** Sólo serial y MAC, para cuando el modelo ya se ve al lado. */
export const deviceIdsText = (d) => `${deviceSerialText(d)} · ${deviceMacText(d)}`

// Serial y MAC se teclean de mil formas (AA:BB…, aa-bb…, AABB…): se comparan
// sin separadores ni mayúsculas para que la búsqueda encuentre la unidad igual.
const normalize = (value) => String(value ?? '').toLowerCase().replace(/[\s:.\-]/g, '')

/** ¿La unidad coincide con lo buscado por serial o por MAC? */
export const deviceMatchesSearch = (d, query) => {
  const q = normalize(query)
  if (!q) return true
  return normalize(d?.serial).includes(q) || normalize(d?.mac).includes(q)
}
