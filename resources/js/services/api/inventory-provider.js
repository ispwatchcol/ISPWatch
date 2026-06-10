import { apiClient } from '../api'

// Inventory provider CRUD (tenant-scoped server-side).
export default {
    getAll(params = {}) {
        return apiClient.get('/inventory-providers', { params })
    },
    create(data) {
        return apiClient.post('/inventory-providers', data)
    },
    update(id, data) {
        return apiClient.put(`/inventory-providers/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/inventory-providers/${id}`)
    },
}
