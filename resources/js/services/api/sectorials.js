import { apiClient } from '../api'

export default {
    getAll(params = {}) {
        return apiClient.get('/sectorials', { params })
    },
    getOne(id) {
        return apiClient.get(`/sectorials/${id}`)
    },
    create(data) {
        return apiClient.post('/sectorials', data)
    },
    update(id, data) {
        return apiClient.put(`/sectorials/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/sectorials/${id}`)
    },

    // Photos
    getPhotos(sectorialId) {
        return apiClient.get(`/sectorials/${sectorialId}/photos`)
    },
    uploadPhotos(sectorialId, files, caption = null) {
        const fd = new FormData()
        files.forEach(f => fd.append('photos[]', f))
        if (caption) fd.append('caption', caption)
        return apiClient.post(`/sectorials/${sectorialId}/photos`, fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
    },
    deletePhoto(sectorialId, photoId) {
        return apiClient.delete(`/sectorials/${sectorialId}/photos/${photoId}`)
    },

    // Notes
    getNotes(sectorialId) {
        return apiClient.get(`/sectorials/${sectorialId}/notes`)
    },
    createNote(sectorialId, data) {
        return apiClient.post(`/sectorials/${sectorialId}/notes`, data)
    },
    updateNote(sectorialId, noteId, data) {
        return apiClient.put(`/sectorials/${sectorialId}/notes/${noteId}`, data)
    },
    deleteNote(sectorialId, noteId) {
        return apiClient.delete(`/sectorials/${sectorialId}/notes/${noteId}`)
    },

    // History / Tickets
    getHistory(sectorialId) {
        return apiClient.get(`/sectorials/${sectorialId}/history`)
    },
    getTickets(sectorialId) {
        return apiClient.get(`/sectorials/${sectorialId}/tickets`)
    },
}
