import { apiClient } from '../api'

export default {
    index() {
        return apiClient.get('/document-templates')
    },
    show(type) {
        return apiClient.get(`/document-templates/${type}`)
    },
    // El listado de plantillas base viene dentro de show(); esto sólo baja el
    // cuerpo de la elegida, que pesa varios KB.
    starter(type, slug) {
        return apiClient.get(`/document-templates/${type}/starters/${slug}`)
    },
    update(type, bodyHtml, isAdvancedMode = false, pageSize = 'a4', pageOrientation = 'portrait') {
        return apiClient.put(`/document-templates/${type}`, {
            body_html: bodyHtml,
            is_advanced_mode: isAdvancedMode,
            page_size: pageSize,
            page_orientation: pageOrientation,
        })
    },
    reset(type) {
        return apiClient.post(`/document-templates/${type}/reset`)
    },
    // Returns a PDF blob (see 'documents.shells.*' server-side, or a full
    // HTML document via Pdf::loadHTML in modo avanzado) — never persisted,
    // safe to call on every keystroke pause. El tamaño/orientación van aquí y
    // no se leen de lo guardado: la vista previa tiene que reflejar lo que el
    // tenant tiene seleccionado ahora mismo en el editor.
    preview(type, bodyHtml, isAdvancedMode = false, pageSize = 'a4', pageOrientation = 'portrait') {
        return apiClient.post(
            `/document-templates/${type}/preview`,
            {
                body_html: bodyHtml,
                is_advanced_mode: isAdvancedMode,
                page_size: pageSize,
                page_orientation: pageOrientation,
            },
            { responseType: 'blob' }
        )
    },
}
