import { apiClient } from '../api'

export default {
    getAll() {
        return apiClient.get('/roles')
    },
    getPermissions() {
        return apiClient.get('/roles/permissions')
    },
}
