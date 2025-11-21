import axios from 'axios';

// base settings of axios
const apiClient = axios.create({
    baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    }
})

// interceptor for add token
// apiClient.interceptors.request.use(
//     config => {
//         const token = localStorage.getItem('auth_token')
//         if (token) {
//             config.headers.Authorization = `Bearer ${token}`
//         }
//         return config
//     },
//     error => {
//         return Promise.reject(error)
//     }
// )

// // interceptor for handle errors
// apiClient.interceptors.response.use(
//     response => response,
//     error => {
//         if (error.response?.status === 401) {
//             // expired token
//             localStorage.removeItem('auth_token')
//             window.location.href = '/login'
//         }
//         return Promise.reject(error)
//     }
// )

export default {
    auth: {
        login(credentials) {
            return apiClient.post('/login', credentials)
        },
    },
    staff: { // users
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
    tenant: {
        getOne(id) {
            return apiClient.get(`/tenants/${id}`)
        }
    },
    roles: {
        getAll() {
            return apiClient.get('/roles')
        }
    },
    customers : {
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
        }
    },
    routers : {
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
    inventory: {
        getAll(params) {
            return apiClient.get('/inventory/devices', { params })
        },
        getOne(id) {
            return apiClient.get(`/inventory/devices/${id}`)
        },
        create(data) {
            return apiClient.post('/inventory/devices', data)
        },
        update(id, data) {
            return apiClient.put(`/inventory/devices/${id}`, data)
        },
        delete(id) {
            return apiClient.delete(`/inventory/devices/${id}`)
        }
    },
    sectorials: {
        getAll() {
            return apiClient.get('/sectorials')
        }
    },
}
