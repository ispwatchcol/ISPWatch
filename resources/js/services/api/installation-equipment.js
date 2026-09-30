import { apiClient } from '../api'

// Equipos y materiales descargados en una orden de instalación.
// Cada alta descuenta existencias del custodio y queda en el kardex, así que
// no hay forma de "cargar" un equipo sin que el inventario lo refleje.
export default {
    // Lo ya cargado en la orden.
    list(installationId) {
        return apiClient.get(`/installations/${installationId}/equipment`)
    },
    // Lo que el usuario puede tomar: lo suyo, lo del técnico asignado y las
    // bodegas si administra inventario.
    available(installationId) {
        return apiClient.get(`/installations/${installationId}/equipment/available`)
    },
    add(installationId, payload) {
        return apiClient.post(`/installations/${installationId}/equipment`, payload)
    },
    // Quita una línea capturada por error (antes de firmar) y devuelve la
    // existencia a quien la aportó. No es la devolución de material gastado.
    remove(installationId, itemId) {
        return apiClient.delete(`/installations/${installationId}/equipment/${itemId}`)
    },
    // Catálogo para PLANIFICAR la orden: productos del tenant con su
    // disponibilidad agregada. Sólo lectura; planificar no reserva nada.
    planningCatalog() {
        return apiClient.get('/installations/planning-catalog')
    },
}
