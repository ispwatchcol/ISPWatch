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
    syncPppoeProfile(id, data, params = {}) {
        return apiClient.post(`/plans/${id}/sync-pppoe-profile`, data, { params })
    },
    delete(id) {
        return apiClient.delete(`/plans/${id}`)
    },
}
