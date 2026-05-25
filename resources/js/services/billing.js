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

  // Update Payment
  updatePayment(id, data) {
    return apiClient.put(`/billing/payments/${id}`, data)
  },

  // Delete Payment
  deletePayment(id) {
    return apiClient.delete(`/billing/payments/${id}`)
  },

  // Customer Balance
  getBalance(customerId) {
    return apiClient.get(`/billing/customers/${customerId}/balance`)
  },

  getStats(tenantId) {
    return apiClient.get('/billing/stats', { params: { tenant: tenantId } })
  },

  // List Payments
  getPayments(params = {}) {
    return apiClient.get('/billing/payments', { params })
  },

  // Admin: Run Monthly
  runMonthly(period) {
    return apiClient.post('/billing/run-monthly', { period })
  },

  // Admin: Run Overdue Process
  runOverdue() {
    return apiClient.post('/billing/run-overdue')
  },

  // Send Payment Reminder
  sendReminder(invoiceId) {
    return apiClient.post(`/billing/invoices/${invoiceId}/send-reminder`)
  },

  // Send Bulk Reminders
  sendBulkReminders(invoiceIds) {
    return apiClient.post('/billing/invoices/bulk-reminders', { invoice_ids: invoiceIds })
  },

  // Check WhatsApp configuration status
  getWhatsAppStatus() {
    return apiClient.get('/billing/whatsapp-status')
  },

  // Create additional charge (not linked to a ticket)
  storeAdditionalCharge(data) {
    return apiClient.post('/billing/additional-charges', data)
  },
}
