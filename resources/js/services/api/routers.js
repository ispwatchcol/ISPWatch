import { apiClient } from '../api'

export default {
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
    getInterfaces(id) {
        return apiClient.get(`/routers/${id}/interfaces`)
    },
    setWanInterface(id, wanInterface) {
        return apiClient.post(`/routers/${id}/set-wan-interface`, {
            wan_interface: wanInterface,
        })
    },
    applyBlockRules(id) {
        return apiClient.post(`/routers/${id}/apply-block-rules`)
    },
}
