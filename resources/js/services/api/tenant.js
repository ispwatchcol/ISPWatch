import { apiClient } from '../api'

export default {
    getOne(id) {
        return apiClient.get(`/tenants/${id}`)
    },
}
