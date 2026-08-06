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
    // Devuelve la existencia a quien la aportó.
    remove(installationId, itemId) {
        return apiClient.delete(`/installations/${installationId}/equipment/${itemId}`)
    },
}
