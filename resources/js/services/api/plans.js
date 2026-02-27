import { apiClient } from '../api'

export default {
    getAll(params = {}) {
        return apiClient.get('/plans', { params })
    },
    getOne(id) {
        return apiClient.get(`/plans/${id}`)
    },
    show(id) {
        return apiClient.get(`/plans/${id}`)
    },
    create(data) {
        return apiClient.post('/plans', data)
    },
    update(id, data) {
        return apiClient.put(`/plans/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/plans/${id}`)
    },
}
