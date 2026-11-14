import { apiClient } from '../api'

// Catálogo de unidades de medida del inventario (metro, unidad, rollo…).
// Las básicas vienen con `built_in: true` y no se editan ni se borran.
export default {
    getAll() {
        return apiClient.get('/inventory-units')
    },
    create(data) {
        return apiClient.post('/inventory-units', data)
    },
    // Renombrar también renombra la unidad de los productos que la usan.
    update(id, data) {
        return apiClient.put(`/inventory-units/${id}`, data)
    },
    // 409 si algún producto la usa.
    delete(id) {
        return apiClient.delete(`/inventory-units/${id}`)
    },
}
