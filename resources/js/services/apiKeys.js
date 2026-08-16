import { apiClient } from './api'

/**
 * Gestión de las llaves de la API pública de solo lectura.
 *
 * Sólo el tenant operador puede llamar a estos endpoints; el backend lo vuelve
 * a comprobar (ApiClientController::authorizeOperator) porque el permiso
 * `manage_api_keys` lo tiene el rol Administrador de todos los tenants.
 */
export const apiKeysService = {
  /** Clientes de API con sus llaves y el catálogo de permisos de lectura. */
  list() {
    return apiClient.get('/api-clients').then(r => r.data)
  },

  /** Tenants disponibles para el desplegable de alta. */
  tenants() {
    return apiClient.get('/api-clients/tenants').then(r => r.data.data)
  },

  createClient(payload) {
    return apiClient.post('/api-clients', payload).then(r => r.data.data)
  },

  updateClient(id, payload) {
    return apiClient.put(`/api-clients/${id}`, payload).then(r => r.data.data)
  },

  /**
   * Emite una llave. La respuesta trae `plain_text_token`: es la ÚNICA vez que
   * el texto plano existe fuera del servidor. En la base sólo queda el hash,
   * así que si no se copia aquí, se pierde y hay que emitir otra.
   */
  createKey(clientId, payload) {
    return apiClient.post(`/api-clients/${clientId}/keys`, payload).then(r => r.data.data)
  },

  revokeKey(clientId, tokenId) {
    return apiClient.delete(`/api-clients/${clientId}/keys/${tokenId}`).then(r => r.data)
  },

  logs(clientId, limit = 50) {
    return apiClient.get(`/api-clients/${clientId}/logs`, { params: { limit } }).then(r => r.data.data)
  },
}

/**
 * Auto-servicio: el ISP administra las llaves de SU empresa.
 *
 * Rutas distintas de las de arriba, no las mismas con otro parámetro. El
 * backend nunca acepta un `tenant_id` por aquí: lo toma de la sesión, así que
 * no hay nada que mandarle ni forma de apuntar a otra empresa desde el cliente.
 *
 * `list()` devuelve además `limits`, con los topes vigentes y cuánto lleva
 * consumido el tenant. El formulario los usa para avisar ANTES de escribir, en
 * vez de dejar que el servidor rechace después.
 */
export const myApiKeysService = {
  list() {
    return apiClient.get('/my-api-keys').then(r => r.data)
  },

  createClient(payload) {
    return apiClient.post('/my-api-keys/clients', payload).then(r => r.data.data)
  },

  /** Única vez que existe `plain_text_token` fuera del servidor. */
  createKey(clientId, payload) {
    return apiClient.post(`/my-api-keys/clients/${clientId}/keys`, payload).then(r => r.data.data)
  },

  revokeKey(clientId, tokenId) {
    return apiClient.delete(`/my-api-keys/clients/${clientId}/keys/${tokenId}`).then(r => r.data)
  },

  logs(clientId, limit = 50) {
    return apiClient.get(`/my-api-keys/clients/${clientId}/logs`, { params: { limit } }).then(r => r.data.data)
  },
}

export default apiKeysService
