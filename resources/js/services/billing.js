import { apiClient } from './api'

export default {
  // List Invoices
  getInvoices(params = {}) {
    return apiClient.get('/billing/invoices', { params })
  },
  
  // Single Invoice
  getInvoice(id) {
    return apiClient.get(`/billing/invoices/${id}`)
  },
  
  // Create Manual (Draft)
  createInvoice(data) {
    return apiClient.post('/billing/invoices', data)
  },
  
  // Add Items
  addItems(id, data) {
    return apiClient.post(`/billing/invoices/${id}/items`, data)
  },
  
  // Download PDF
  downloadPdf(id) {
    return apiClient.get(`/billing/invoices/${id}/pdf`, { 
        responseType: 'blob' 
    })
  },
  
  // Register Payment
  registerPayment(data) {
    return apiClient.post('/billing/payments', data)
  },
  
  // Customer Balance
  getBalance(customerId) {
    return apiClient.get(`/billing/customers/${customerId}/balance`)
  },
  
  // Admin: Run Monthly
  runMonthly(period) {
    return apiClient.post('/billing/run-monthly', { period })
  }
}
