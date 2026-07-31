import { apiClient } from '../api'

export default {
    index() {
        return apiClient.get('/document-templates')
    },
    show(type) {
        return apiClient.get(`/document-templates/${type}`)
    },
    update(type, bodyHtml, isAdvancedMode = false) {
        return apiClient.put(`/document-templates/${type}`, {
            body_html: bodyHtml,
            is_advanced_mode: isAdvancedMode,
        })
    },
    reset(type) {
        return apiClient.post(`/document-templates/${type}/reset`)
    },
    // Returns a PDF blob (see 'documents.shells.*' server-side, or a full
    // HTML document via Pdf::loadHTML in modo avanzado) — never persisted,
    // safe to call on every keystroke pause.
    preview(type, bodyHtml, isAdvancedMode = false) {
        return apiClient.post(
            `/document-templates/${type}/preview`,
            { body_html: bodyHtml, is_advanced_mode: isAdvancedMode },
            { responseType: 'blob' }
        )
    },
}
