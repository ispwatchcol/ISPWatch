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
    // Partial update of the CURRENT user's own tenant (derived server-side
    // from the authenticated user, no id needed) — used by the document
    // templates branding panel (brand_color, document_footer_text).
    updateConfig(payload) {
        return apiClient.put('/tenant/config', payload)
    },
    uploadLogo(file) {
        const formData = new FormData()
        formData.append('logo', file)
        return apiClient.post('/tenant/logo', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
    },
}
