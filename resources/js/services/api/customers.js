import { apiClient } from '../api'

export default {
    getAll(params = {}) {
        return apiClient.get('/customers', { params })
    },
    getOne(id) {
        return apiClient.get(`/customers/${id}`)
    },
    create(data) {
        return apiClient.post('/customers', data)
    },
    update(id, data) {
        return apiClient.put(`/customers/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/customers/${id}`)
    },
    getStatistics() {
        return apiClient.get('/customers/statistics')
    },
    getMapData() {
        return apiClient.get('/customers/map')
    },
    provision(id) {
        return apiClient.post(`/customers/${id}/provision`)
    },
    bulkProvision(customerIds) {
        return apiClient.post('/customers/bulk-provision', { customer_ids: customerIds })
    },
    suspend(id) {
        return apiClient.post(`/customers/${id}/suspend`)
    },
    activate(id) {
        return apiClient.post(`/customers/${id}/activate`)
    },
}
