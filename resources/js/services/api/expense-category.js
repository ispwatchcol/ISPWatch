import { apiClient } from '../api'

// Expense category CRUD (tenant-scoped server-side).
export default {
    getAll(params = {}) {
        return apiClient.get('/expense-categories', { params })
    },
    create(data) {
        return apiClient.post('/expense-categories', data)
    },
    update(id, data) {
        return apiClient.put(`/expense-categories/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/expense-categories/${id}`)
    },
}
