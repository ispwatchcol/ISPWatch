import { apiClient } from '../api'

// Equipos y materiales movidos en una visita de soporte.
//
// Espejo de installation-equipment, con la diferencia que define el módulo: el
// ticket mueve inventario en DOS sentidos. `direction: 'out'` entrega, y
// `direction: 'in'` retira de casa del cliente el equipo que ya tenía —el
// cambio de router de toda la vida—. Cada alta descuenta o repone existencias
// de verdad y queda en el kardex: no hay forma de mover un equipo aquí sin que
// el inventario lo refleje.
export default {
    // Lo ya movido en el ticket, entregas y retiros.
    list(ticketId) {
        return apiClient.get(`/support/${ticketId}/equipment`)
    },
    // { sources, devices, materials, installed }
    // `installed` es lo que el cliente tiene encima hoy: la lista de lo
    // retirable, que sale del inventario y no de las hojas de instalación.
    available(ticketId) {
        return apiClient.get(`/support/${ticketId}/equipment/available`)
    },
    add(ticketId, payload) {
        return apiClient.post(`/support/${ticketId}/equipment`, payload)
    },
    // Deshace la línea: la entrega vuelve a quien la aportó, el retiro vuelve
    // a casa del cliente.
    remove(ticketId, itemId) {
        return apiClient.delete(`/support/${ticketId}/equipment/${itemId}`)
    },
}
