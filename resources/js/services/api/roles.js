import { apiClient } from '../api'

export default {
    getAll() {
        return apiClient.get('/roles')
    },
}
