import { apiClient } from '../api'

// Inventory branch CRUD (tenant-scoped server-side).
export default {
    getAll(params = {}) {
        return apiClient.get('/inventory-branches', { params })
    },
    create(data) {
        return apiClient.post('/inventory-branches', data)
    },
    update(id, data) {
        return apiClient.put(`/inventory-branches/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/inventory-branches/${id}`)
    },
}
