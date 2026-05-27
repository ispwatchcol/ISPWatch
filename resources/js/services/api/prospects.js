import { apiClient } from '../api'

export default {
    getAll(params = {}) {
        return apiClient.get('/prospects', { params })
    },
    getOne(id) {
        return apiClient.get(`/prospects/${id}`)
    },
    create(data) {
        return apiClient.post('/prospects', data)
    },
    update(id, data) {
        return apiClient.put(`/prospects/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/prospects/${id}`)
    },
    markConverted(id, userId) {
        return apiClient.post(`/prospects/${id}/mark-converted`, { user_id: userId })
    },
    scheduleInstallation(id, data) {
        return apiClient.post(`/prospects/${id}/installations`, data)
    },
}
