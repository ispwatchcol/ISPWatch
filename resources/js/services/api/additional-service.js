import { apiClient } from '../api'

// Catálogo de servicios adicionales recurrentes (tenant-scoped server-side).
export default {
    getAll(params = {}) {
        return apiClient.get('/billing/additional-services', { params })
    },
    create(data) {
        return apiClient.post('/billing/additional-services', data)
    },
    update(id, data) {
        return apiClient.put(`/billing/additional-services/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/billing/additional-services/${id}`)
    },
}
