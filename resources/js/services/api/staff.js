import { apiClient } from '../api'

export default {
    getAll(params = {}) {
        return apiClient.get('/staff', { params })
    },
    getOne(id) {
        return apiClient.get(`/staff/${id}`)
    },
    create(data) {
        return apiClient.post('/staff', data)
    },
    update(id, data) {
        return apiClient.put(`/staff/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/staff/${id}`)
    },
}
