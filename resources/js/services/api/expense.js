import { apiClient } from '../api'

// Expense CRUD (tenant-scoped server-side). No delete: expenses are voided
// (status: 'anulado') via update instead of being removed.
export default {
    getAll(params = {}) {
        return apiClient.get('/expenses', { params })
    },
    // Mismos filtros que el listado, sin paginar: el CSV cubre todo el filtro.
    exportCsv(params = {}) {
        return apiClient.get('/expenses/export', { params, responseType: 'blob' })
    },
    create(data) {
        return apiClient.post('/expenses', data)
    },
    update(id, data) {
        return apiClient.put(`/expenses/${id}`, data)
    },
}
