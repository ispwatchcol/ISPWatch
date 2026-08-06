import { apiClient } from '../api'

// Global reference catalogs (cut_type, script_version, type_billing).
// Read-only; previously fetched directly from Supabase with the anon key.
export default {
    getCutTypes() {
        return apiClient.get('/catalogs/cut-types')
    },
    getScriptVersions() {
        return apiClient.get('/catalogs/script-versions')
    },
    getTypeBillings() {
        return apiClient.get('/catalogs/type-billings')
    },
    // `{ staff: 1 }` deja fuera a los clientes: sólo personas del ISP.
    getUsers(params = {}) {
        return apiClient.get('/catalogs/users', { params })
    },
}
