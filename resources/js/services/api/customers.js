import { apiClient } from '../api'

export default {
    getAll(params = {}) {
        return apiClient.get('/customers', { params })
    },
    getOne(id) {
        return apiClient.get(`/customers/${id}`)
    },
    create(data) {
        return apiClient.post('/customers', data)
    },
    update(id, data) {
        return apiClient.put(`/customers/${id}`, data)
    },
    delete(id) {
        return apiClient.delete(`/customers/${id}`)
    },
    getStatistics() {
        return apiClient.get('/customers/statistics')
    },
    getMapData() {
        return apiClient.get('/customers/map')
    },
    provision(id) {
        return apiClient.post(`/customers/${id}/provision`)
    },
    bulkProvision(customerIds) {
        return apiClient.post('/customers/bulk-provision', { customer_ids: customerIds }, { timeout: 600000 })
    },
    suspend(id) {
        return apiClient.post(`/customers/${id}/suspend`)
    },
    activate(id) {
        return apiClient.post(`/customers/${id}/activate`)
    },

    // ─── Documents ───
    getDocuments(id) {
        return apiClient.get(`/customers/${id}/documents`)
    },
    uploadDocuments(id, formData) {
        return apiClient.post(`/customers/${id}/documents`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
    },
    deleteDocument(documentId) {
        return apiClient.delete(`/customers/documents/${documentId}`)
    },

    // ─── Installations ───
    getAllInstallations(params = {}) {
        return apiClient.get('/installations', { params })
    },
    getInstallations(id) {
        return apiClient.get(`/customers/${id}/installations`)
    },
    createInstallation(id, data) {
        return apiClient.post(`/customers/${id}/installations`, data)
    },
    updateInstallation(installationId, data) {
        return apiClient.put(`/customers/installations/${installationId}`, data)
    },
    deleteInstallation(installationId) {
        return apiClient.delete(`/customers/installations/${installationId}`)
    },

    // ─── Contract ───
    getContractData(id) {
        return apiClient.get(`/customers/${id}/contract-data`)
    },
    signContract(id, payload) {
        return apiClient.post(`/customers/${id}/contract-sign`, payload)
    },
}
