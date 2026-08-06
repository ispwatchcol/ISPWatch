import { apiClient } from '../api'

// Asignaciones de servicios adicionales a un cliente.
// Las cuatro rutas van anidadas bajo el cliente: el ámbito viaja siempre en la
// URL, así que no hay forma de tocar la asignación de otro pasando sólo su id.
export default {
    getAll(customerId) {
        return apiClient.get(`/billing/customers/${customerId}/additional-services`)
    },
    create(customerId, data) {
        return apiClient.post(`/billing/customers/${customerId}/additional-services`, data)
    },
    update(customerId, id, data) {
        return apiClient.put(`/billing/customers/${customerId}/additional-services/${id}`, data)
    },
    delete(customerId, id) {
        return apiClient.delete(`/billing/customers/${customerId}/additional-services/${id}`)
    },
}
