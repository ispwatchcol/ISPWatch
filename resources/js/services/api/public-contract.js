import axios from 'axios'

/**
 * Cliente HTTP de la página pública de firma.
 *
 * Instancia PROPIA a propósito, no el `apiClient` compartido: aquel tiene un
 * interceptor que ante un 401 borra la sesión y redirige a "/". El cliente que
 * abre un link de firma no tiene sesión ninguna, y cualquier respuesta de error
 * lo sacaría de su contrato y lo dejaría mirando la pantalla de acceso del
 * panel del ISP, sin entender qué pasó.
 *
 * withCredentials va en false: no hay cookie de sesión que mandar y el token
 * del link es toda la autorización que existe aquí.
 */
const publicClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: false,
})

export default {
  /** Portada: para quién es el link y si todavía sirve. */
  show(token) {
    return publicClient.get(`/public/contract/${encodeURIComponent(token)}`)
  },

  /** Confirma identidad y devuelve el contrato completo para leerlo. */
  verify(token, payload) {
    return publicClient.post(`/public/contract/${encodeURIComponent(token)}/verify`, payload)
  },

  sign(token, payload) {
    return publicClient.post(`/public/contract/${encodeURIComponent(token)}/sign`, payload)
  },
}
