/**
 * Búsqueda de texto para las listas que filtran en el navegador.
 *
 * - Sin tildes ni mayúsculas: «garcia» encuentra «García».
 * - Sin separadores: «rgew300» encuentra «RG-EW300» y «aabb» encuentra «AA:BB».
 * - Varias palabras: tienen que aparecer TODAS, en cualquier campo
 *   («ew300 chaguani» = ese modelo en esa bodega).
 *
 * Es la misma regla que ya usaban SearchableSelect y SearchPickerModal; aquí
 * vive una sola vez para las páginas de listas.
 */

export const fold = (value) => String(value ?? '')
  .normalize('NFD').replace(/[̀-ͯ]/g, '')
  .toLowerCase()

export const compact = (value) => fold(value).replace(/[\s:.\-_/]/g, '')

/** Palabras de la búsqueda, ya plegadas. Vacío = no filtrar. */
export const searchTerms = (query) => fold(query).split(/\s+/).filter(Boolean)

/**
 * Índice de una fila: se arma una vez (al cargar) y no en cada tecla.
 * @param {Array<unknown>} texts campos de la fila donde buscar
 */
export const searchIndex = (texts) => {
  const joined = texts.filter(v => v !== null && v !== undefined && v !== '').join(' ')
  return { words: fold(joined), flat: compact(joined) }
}

/** ¿La fila (su índice) tiene todas las palabras? */
export const matchesIndex = (terms, index) =>
  terms.every(t => index.words.includes(t) || index.flat.includes(compact(t)))

/** Atajo para filas sueltas, sin índice precalculado. */
export const matchesSearch = (query, texts) => {
  const terms = searchTerms(query)
  return terms.length === 0 || matchesIndex(terms, searchIndex(texts))
}
