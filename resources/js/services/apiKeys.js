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

export default apiKeysService
