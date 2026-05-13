import { apiClient } from '../api'

export default {
    getAll() {
        return apiClient.get('/roles')
    },
    getOne(id) {
        return apiClient.get(`/roles/${id}`)
    },
    create(data) {
        return apiClient.post('/roles', data)
    },
    update(id, data) {
        return apiClient.put(`/roles/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/roles/${id}`)
    },
    getPermissions() {
        return apiClient.get('/roles/permissions')
    },
}
