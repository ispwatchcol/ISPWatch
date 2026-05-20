import { apiClient } from '../api'

export default {
    getOne(id) {
        return apiClient.get(`/tenants/${id}`)
    },
    // Google Maps config for the current user's tenant. Readable by any
    // authenticated user (non-admins included) so the customer map can render.
    getMapsConfig() {
        return apiClient.get('/tenant/maps-config')
    },
}
