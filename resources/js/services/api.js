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
    roles: {
        getAll() {
            return apiClient.get('/roles')
        }
    },
    tenant: {
        getOne(id) {
            return apiClient.get(`/tenants/${id}`)
        }
    },
}
