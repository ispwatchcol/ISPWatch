import axios from 'axios'

// =========================
// AXIOS INSTANCE
// =========================
// Use relative URL '/api' for production (works with same-origin)
// VITE_API_URL can override for local development
export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: true,
})

/* =====================================
   🔐 INTERCEPTOR: INYECTAR TENANT
   - Lee tenant_id desde localStorage o sessionStorage
   - Lo agrega automáticamente como ?tenant=
===================================== */
apiClient.interceptors.request.use(
  config => {
    const userData =
      JSON.parse(localStorage.getItem('userData')) ||
      JSON.parse(sessionStorage.getItem('userData'))

    if (userData?.tenant_id) {
      config.params = {
        ...(config.params || {}),
        tenant: userData.tenant_id,
        tenant_id: userData.tenant_id // Fix for endpoints expecting tenant_id like Users/Staff
      }
    }

    // DON'T auto-inject user_id - it was causing support tickets to be filtered incorrectly
    // Each endpoint should explicitly request user_id if needed

    return config
  },
  error => Promise.reject(error)
)

// =========================
// API MODULES
// =========================
export default {
  // =========================
  // AUTH
  // =========================
  auth: {
    async login(credentials) {
      // First, get the CSRF cookie from Laravel Sanctum
      await apiClient.get('/sanctum/csrf-cookie')

      // Then perform the login with CSRF token in place
      return apiClient.post('/login', credentials)
    },
  },

  // =========================
  // PLANS
  // =========================
  plan: {
    getAll(params = {}) {
      return apiClient.get('/plans', { params })
    },
    getOne(id) {
      return apiClient.get(`/plans/${id}`)
    },
    // Alias 'show' para compatibilidad si lo usas en algún lado
    show(id) {
      return apiClient.get(`/plans/${id}`)
    },
    create(data) {
      return apiClient.post('/plans', data)
    },
    update(id, data) {
      return apiClient.put(`/plans/${id}`, data)
    },
    delete(id) {
      return apiClient.delete(`/plans/${id}`)
    },
  },

  // Alias para compatibilidad
  plans: {
    getAll(params = {}) {
      return apiClient.get('/plans', { params })
    },
  },

  // =========================
  // CUSTOMERS
  // =========================
  customers: {
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
    // Estadísticas y Mapa
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
      return apiClient.post('/customers/bulk-provision', { customer_ids: customerIds })
    },
    suspend(id) {
      return apiClient.post(`/customers/${id}/suspend`)
    },
    activate(id) {
      return apiClient.post(`/customers/${id}/activate`)
    }
  },

  // =========================
  // STAFF / USERS
  // =========================
  staff: {
    getAll(params = {}) {
      return apiClient.get('/staff', { params })
    },
    getOne(id) {
      return apiClient.get(`/staff/${id}`)
    },
    create(data) {
      return apiClient.post('/staff', data)
    },
    update(id, data) {
      return apiClient.put(`/staff/${id}`, data)
    },
    delete(id) {
      return apiClient.delete(`/staff/${id}`)
    },
  },

  // =========================
  // ROLES
  // =========================
  roles: {
    getAll() {
      return apiClient.get('/roles')
    },
  },

  // =========================
  // ROUTERS
  // =========================
  routers: {
    getAll(params = {}) {
      return apiClient.get('/routers', { params })
    },
    getOne(id) {
      return apiClient.get(`/routers/${id}`)
    },
    create(data) {
      return apiClient.post('/routers', data)
    },
    update(id, data) {
      return apiClient.put(`/routers/${id}`, data)
    },
    delete(id) {
      return apiClient.delete(`/routers/${id}`)
    },
  },

  // =========================
  // INVENTORY
  // =========================
  inventory: {
    getAll(params = {}) {
      return apiClient.get('/inventory', { params })
    },
    getOne(id) {
      return apiClient.get(`/inventory/${id}`)
    },
    create(data) {
      return apiClient.post('/inventory', data)
    },
    update(id, data) {
      return apiClient.put(`/inventory/${id}`, data)
    },
    delete(id) {
      return apiClient.delete(`/inventory/${id}`)
    },
  },

  // =========================
  // SECTORIALS
  // =========================
  sectorials: {
    getAll() {
      return apiClient.get('/sectorials')
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
    }
  },

  // =========================
  // SUPPORT
  // =========================
  support: {
    getAll(params = {}) {
      return apiClient.get('/support', { params })
    },
    getOne(id) {
      return apiClient.get(`/support/${id}`)
    },
    create(data) {
      // Support simple JSON data (user_id + subject)
      return apiClient.post('/support', data)
    },
    update(id, data) {
      if (data instanceof FormData) {
        // Must delete Content-Type to let browser set multipart boundary
        return apiClient.post(`/support/${id}`, data, {
          headers: {
            'Content-Type': undefined
          }
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
        user_id: userId  // Agregar user_id al request
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
    }
  },

  // =========================
  // TENANT
  // =========================
  tenant: {
    getOne(id) {
      return apiClient.get(`/tenants/${id}`)
    },
  },
}
