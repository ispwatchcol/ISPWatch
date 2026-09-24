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
    // REVIERTE la línea: la entrega vuelve a quien la aportó, el retiro vuelve
    // a casa del cliente. No borra nada —la línea se queda marcada en la hoja,
    // con actor, motivo y fecha—, y por eso el motivo es obligatorio.
    //
    // Viaja en el CUERPO de un DELETE, que axios admite con `data`. Podría
    // haber sido un POST a ‹/reverse›, pero el gesto de la pantalla es la
    // papelera y el verbo la acompaña; lo que cambia es la semántica del
    // servidor, no la del botón.
    remove(ticketId, itemId, reason) {
        return apiClient.delete(`/support/${ticketId}/equipment/${itemId}`, {
            data: { reason },
        })
    },
}
