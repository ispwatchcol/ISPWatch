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
    delete(id) {
        return apiClient.delete(`/support/${id}`)
    },
    getStatistics() {
        return apiClient.get('/support/statistics')
    },
    addMessage(ticketId, message, isInternal = false, userId = 1) {
        return apiClient.post(`/support/${ticketId}/message`, {
            message,
            is_internal: isInternal,
            user_id: userId,
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
}
