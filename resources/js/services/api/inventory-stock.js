import { apiClient } from '../api'

// Inventory stock CRUD (tenant-scoped server-side).
export default {
    getAll(params = {}) {
        return apiClient.get('/inventory-stock', { params })
    },
    create(data) {
        return apiClient.post('/inventory-stock', data)
    },
    update(id, data) {
        return apiClient.put(`/inventory-stock/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/inventory-stock/${id}`)
    },
}
