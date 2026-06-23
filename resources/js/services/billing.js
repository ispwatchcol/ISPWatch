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

  // Update Invoice
  updateInvoice(id, data) {
    return apiClient.put(`/billing/invoices/${id}`, data)
  },

  // Mark Invoice as unpaid (reverses its payments, restores balance)
  markUnpaid(id) {
    return apiClient.post(`/billing/invoices/${id}/mark-unpaid`)
  },

  // Delete Invoice (reverses payments to credit, removes items)
  deleteInvoice(id) {
    return apiClient.delete(`/billing/invoices/${id}`)
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

  // Adjust credit balance (manual correction)
  updateCredit(customerId, creditBalance, reason = '') {
    return apiClient.patch(`/billing/customers/${customerId}/credit`, { credit_balance: creditBalance, reason })
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

  // ── Failover: billing action logs ─────────────────────
  getActionLogs(params = {}) {
    return apiClient.get('/billing/action-logs', { params })
  },

  getActionLogStats(params = {}) {
    return apiClient.get('/billing/action-logs/stats', { params })
  },

  retryActionLog(id) {
    return apiClient.post(`/billing/action-logs/${id}/retry`)
  },

  retryAllActionLogs(period = null) {
    return apiClient.post('/billing/action-logs/retry-all', period ? { period } : {})
  },

  // ── Failover de cortes: suspension action logs (sync RB) ──
  getSuspensionLogs(params = {}) {
    return apiClient.get('/billing/suspension-logs', { params })
  },

  getSuspensionLogStats(params = {}) {
    return apiClient.get('/billing/suspension-logs/stats', { params })
  },

  retrySuspensionLog(id) {
    return apiClient.post(`/billing/suspension-logs/${id}/retry`)
  },

  reconcileSuspensions(routerId = null) {
    return apiClient.post('/billing/suspension-logs/reconcile', routerId ? { router_id: routerId } : {})
  },
}
