import { apiClient } from '../api'

export default {
    getAll(params = {}) {
        return apiClient.get('/support', { params })
    },
    getOne(id) {
        return apiClient.get(`/support/${id}`)
    },
    create(data) {
        return apiClient.post('/support', data)
    },
    update(id, data) {
        if (data instanceof FormData) {
            return apiClient.post(`/support/${id}`, data, {
                headers: { 'Content-Type': undefined },
            })
        }
        return apiClient.put(`/support/${id}`, data)
    },
    // `delete(id)` se retiró: los tickets no se eliminan. El backend responde
    // 403 a `DELETE /support/{id}` y el modelo lanza ante cualquier borrado.
    // El archivado reversible llega en una fase posterior (PR C del diseño).
    getStatistics() {
        return apiClient.get('/support/statistics')
    },
    // El AUTOR no viaja en el cuerpo. Lo ponía el cliente leyéndolo de
    // localStorage —donde la sesión sólo está si se marcó «recordarme»— y sin
    // ese dato mandaba `user_id: 1`, que no existe: 422 en cada nota. Lo decide
    // el servidor a partir de la sesión, que además impide firmar por otro.
    addMessage(ticketId, message, isInternal = false) {
        return apiClient.post(`/support/${ticketId}/message`, {
            message,
            is_internal: isInternal,
        })
    },
    updateMessage(messageId, message) {
        return apiClient.put(`/support/messages/${messageId}`, { message })
    },
    deleteMessage(messageId) {
        return apiClient.delete(`/support/messages/${messageId}`)
    },
    updateStatus(ticketId, status) {
        return apiClient.patch(`/support/${ticketId}/status`, { status })
    },
    generateCharge(ticketId, data) {
        return apiClient.post(`/support/${ticketId}/charge`, data)
    },
    // PR #3 · Historial inalterable. Sólo lectura: no hay create/update/delete
    // aquí ni en el backend, y el modelo lanza si alguien lo intenta.
    getHistory(ticketId, page = 1) {
        return apiClient.get(`/support/${ticketId}/history`, { params: { page } })
    },
    getCharges(ticketId) {
        return apiClient.get(`/support/${ticketId}/charges`)
    },
}
