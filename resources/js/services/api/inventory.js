import { apiClient } from '../api'

export default {
    getAll(params = {}) {
        return apiClient.get('/inventory', { params })
    },
    getOne(id) {
        return apiClient.get(`/inventory/${id}`)
    },
    create(data) {
        return apiClient.post('/inventory', data)
    },
    update(id, data) {
        return apiClient.put(`/inventory/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/inventory/${id}`)
    },
    // Kardex: historial de movimientos, paginado y filtrable.
    movements(params = {}) {
        return apiClient.get('/inventory/movements', { params })
    },
    // Qué tiene encima un custodio (holder_type: branch|user).
    holdings(params = {}) {
        return apiClient.get('/inventory/holdings', { params })
    },
    // Entrega/traspaso de equipos y materiales a un custodio.
    transfer(payload) {
        return apiClient.post('/inventory/transfers', payload)
    },
    retire(id, payload = {}) {
        return apiClient.post(`/inventory/${id}/retire`, payload)
    },
}
