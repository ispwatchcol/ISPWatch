import { apiClient } from '../api'

export default {
    // `config` pasa opciones de axios; la que importa es `silentForbidden: true`
    // para listas OPCIONALES (desplegables) cuyo 403 la pantalla ya tolera: sin
    // ella, el interceptor mostraría el cartel de «no tienes permiso».
    getAll(params = {}, config = {}) {
        return apiClient.get('/staff', { params, ...config })
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
}
