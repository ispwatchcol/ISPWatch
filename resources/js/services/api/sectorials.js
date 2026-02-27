import { apiClient } from '../api'

export default {
    getAll() {
        return apiClient.get('/sectorials')
    },
    getOne(id) {
        return apiClient.get(`/sectorials/${id}`)
    },
    create(data) {
        return apiClient.post('/sectorials', data)
    },
    update(id, data) {
        return apiClient.put(`/sectorials/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/sectorials/${id}`)
    },
}
