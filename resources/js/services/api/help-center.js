import { apiClient } from '../api'

export default {
    getAll() {
        return apiClient.get('/help-center')
    },
    createCategory(data) {
        return apiClient.post('/help-center/categories', data)
    },
    updateCategory(id, data) {
        return apiClient.put(`/help-center/categories/${id}`, data)
    },
    deleteCategory(id) {
        return apiClient.delete(`/help-center/categories/${id}`)
    },
    createArticle(data) {
        return apiClient.post('/help-center/articles', data)
    },
    updateArticle(id, data) {
        return apiClient.put(`/help-center/articles/${id}`, data)
    },
    deleteArticle(id) {
        return apiClient.delete(`/help-center/articles/${id}`)
    },
}
