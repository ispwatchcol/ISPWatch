import axios from 'axios'

// axios instance
const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  }
})

// =========================
// API MODULES
// =========================
export default {
  // AUTH
  auth: {
    login(credentials) {
      return apiClient.post('/login', credentials)
    },
  },
  
  plan: {
    getAll(params) {
      return apiClient.get('/plans', { params })
    },
    getOne(id) {
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

  // CUSTOMERS
  customers: {
    getAll(params) {
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
  },

  // STAFF / USERS
  staff: {
    getAll(params) {
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
    }
  },

  // ROLES
  roles: {
    getAll() {
      return apiClient.get('/roles')
    }
  },

  // ROUTERS
  routers: {
    getAll(params) {
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
    }
  },

  // INVENTORY ✅ RUTA CORRECTA
  inventory: {
    getAll(params) {
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
    }
  },

  // SECTORIALS
  sectorials: {
    getAll() {
      return apiClient.get('/sectorials')
    }
  },
}
