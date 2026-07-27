import { apiClient } from '../api'

export default {
    index() {
        return apiClient.get('/document-templates')
    },
    show(type) {
        return apiClient.get(`/document-templates/${type}`)
    },
    update(type, bodyHtml) {
        return apiClient.put(`/document-templates/${type}`, { body_html: bodyHtml })
    },
    reset(type) {
        return apiClient.post(`/document-templates/${type}/reset`)
    },
    // Returns a PDF blob (see 'documents.shells.*' server-side) — never
    // persisted, safe to call on every keystroke pause.
    preview(type, bodyHtml) {
        return apiClient.post(
            `/document-templates/${type}/preview`,
            { body_html: bodyHtml },
            { responseType: 'blob' }
        )
    },
}
