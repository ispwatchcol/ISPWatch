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
}
