/**
 * Mensaje legible de un error de axios.
 *
 * Sin esto el usuario veía "Request failed with status code 422": el código HTTP
 * en crudo, sin decir qué campo estaba mal. Laravel manda el detalle en
 * `errors` (por campo) y un resumen en `message`; se prefiere el primero porque
 * es el que nombra el campo que choca.
 */
export function firstError(error, fallback = 'Ocurrió un error inesperado.') {
  const errors = error?.response?.data?.errors
  if (errors) {
    const first = Object.values(errors)[0]
    if (Array.isArray(first) && first.length) return first[0]
    if (typeof first === 'string') return first
  }

  return error?.response?.data?.message || error?.message || fallback
}

export default firstError
