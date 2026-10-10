import { apiClient } from '../api'

export default {
    getAll(params = {}) {
        return apiClient.get('/support', { params })
    },
    getOne(id) {
        return apiClient.get(`/support/${id}`)
    },
    create(data) {
        return apiClient.post('/support', data)
    },
    update(id, data) {
        if (data instanceof FormData) {
            return apiClient.post(`/support/${id}`, data, {
                headers: { 'Content-Type': undefined },
            })
        }
        return apiClient.put(`/support/${id}`, data)
    },
    // `delete(id)` se retiró: los tickets no se eliminan. El backend responde
    // 403 a `DELETE /support/{id}` y el modelo lanza ante cualquier borrado
    // FÍSICO. Lo que sustituye a borrar es archivar, aquí debajo.

    // PR C · Archivado reversible y auditado. Sólo para Administradores.
    //
    // `reason` es obligatorio (10–500) y `confirm_ticket_id` es la doble
    // confirmación: el número del ticket, tecleado. Los dos se validan también
    // en el servidor — una barrera que sólo vive en el navegador no es una
    // barrera. Para un ticket abierto o en progreso hacen falta además
    // `reason_code` y `acknowledge_active`.
    archive(ticketId, data) {
        return apiClient.post(`/support/${ticketId}/archive`, data)
    },
    restore(ticketId, data) {
        return apiClient.post(`/support/${ticketId}/restore`, data)
    },
    getArchived(params = {}) {
        return apiClient.get('/support/archived', { params })
    },
    getStatistics() {
        return apiClient.get('/support/statistics')
    },
    // El AUTOR no viaja en el cuerpo. Lo ponía el cliente leyéndolo de
    // localStorage —donde la sesión sólo está si se marcó «recordarme»— y sin
    // ese dato mandaba `user_id: 1`, que no existe: 422 en cada nota. Lo decide
    // el servidor a partir de la sesión, que además impide firmar por otro.
    addMessage(ticketId, message, isInternal = false) {
        return apiClient.post(`/support/${ticketId}/message`, {
            message,
            is_internal: isInternal,
        })
    },
    updateMessage(messageId, message) {
        return apiClient.put(`/support/messages/${messageId}`, { message })
    },
    deleteMessage(messageId) {
        return apiClient.delete(`/support/messages/${messageId}`)
    },
    // ── Workflow formal (Solicitud Maestra §7, §15, §18) ──
    //
    // El estado dejó de moverse por el `PUT` del formulario: una transición
    // valida el ESTADO ORIGEN contra la matriz del servidor. Y cerrar, proponer
    // cerrar y reabrir tienen cada uno su ruta, su permiso y sus requisitos.

    /** Qué puede hacer AHORA este usuario con este ticket. Lo decide el servidor. */
    getTransitions(ticketId) {
        return apiClient.get(`/support/${ticketId}/transitions`)
    },

    updateStatus(ticketId, status, reason = null) {
        return apiClient.patch(`/support/${ticketId}/status`, reason ? { status, reason } : { status })
    },

    /** Propuesta de cierre: NO cierra. Deja el ticket en observación. */
    // `extra` lleva, cuando hace falta, la justificacion de por que no hubo
    // medicion final (regla 5 del parrafo 15): `final_test_waiver_reason` y
    // `final_test_waiver_note`. Se manda en el mismo POST que el cierre porque
    // es ahi donde el documento la exige.
    proposeClosure(ticketId, reason, extra = {}) {
        return apiClient.post(`/support/${ticketId}/propose-closure`, { reason, ...extra })
    },

    closeTicket(ticketId, reason = null, extra = {}) {
        return apiClient.post(`/support/${ticketId}/close`, {
            ...(reason ? { reason } : {}),
            ...extra,
        })
    },

    /** Cierre especial: exige motivo y registra qué requisito faltó. */
    closeException(ticketId, reason) {
        return apiClient.post(`/support/${ticketId}/close-exception`, { reason })
    },

    reopen(ticketId, reason) {
        return apiClient.post(`/support/${ticketId}/reopen`, { reason })
    },
    generateCharge(ticketId, data) {
        return apiClient.post(`/support/${ticketId}/charge`, data)
    },
    // PR #3 · Historial inalterable. Sólo lectura: no hay create/update/delete
    // aquí ni en el backend, y el modelo lanza si alguien lo intenta.
    // PR F1 · Intervenciones tecnicas (seccion 14).
    //
    // Aqui NO hay metodo de borrado, y no es un olvido: una intervencion no se
    // borra. Si esta mal se reabre con motivo y se corrige, y la correccion
    // queda en el historial del ticket.
    getInterventions(ticketId) {
        return apiClient.get(`/support/${ticketId}/interventions`)
    },
    createIntervention(ticketId, data) {
        return apiClient.post(`/support/${ticketId}/interventions`, data)
    },
    updateIntervention(ticketId, interventionId, data) {
        return apiClient.put(`/support/${ticketId}/interventions/${interventionId}`, data)
    },
    reopenIntervention(ticketId, interventionId, reason) {
        return apiClient.post(`/support/${ticketId}/interventions/${interventionId}/reopen`, { reason })
    },
    linkInterventionEvidence(ticketId, interventionId, data) {
        return apiClient.post(`/support/${ticketId}/interventions/${interventionId}/evidence`, data)
    },
    // PR F2 - mediciones tecnicas (secciones 12 y 13).
    //
    // Aqui tampoco hay metodo de borrado: una medicion es la constancia de lo
    // que se leyo, y el parrafo 15.5 la hace requisito de cierre. Si el valor
    // esta mal se corrige, y la correccion queda en el historial.
    getMeasurements(ticketId) {
        return apiClient.get(`/support/${ticketId}/measurements`)
    },
    createMeasurement(ticketId, data) {
        return apiClient.post(`/support/${ticketId}/measurements`, data)
    },
    updateMeasurement(ticketId, measurementId, data) {
        return apiClient.put(`/support/${ticketId}/measurements/${measurementId}`, data)
    },
    getHistory(ticketId, page = 1) {
        return apiClient.get(`/support/${ticketId}/history`, { params: { page } })
    },
    getCharges(ticketId) {
        return apiClient.get(`/support/${ticketId}/charges`)
    },
}
