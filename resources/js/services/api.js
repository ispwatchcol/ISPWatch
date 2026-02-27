import axios from 'axios'

// =========================
// AXIOS INSTANCE
// =========================
export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: true,
})

// =========================
// INTERCEPTOR: AUTO-INJECT TENANT
// =========================
apiClient.interceptors.request.use(
  config => {
    const userData =
      JSON.parse(localStorage.getItem('userData')) ||
      JSON.parse(sessionStorage.getItem('userData'))

    if (userData?.tenant_id) {
      config.params = {
        ...(config.params || {}),
        tenant: userData.tenant_id,
        tenant_id: userData.tenant_id,
      }
    }

    return config
  },
  error => Promise.reject(error)
)

// =========================
// RE-EXPORT ALL API MODULES
// =========================
export { default as authApi } from './api/auth'
export { default as customersApi } from './api/customers'
export { default as routersApi } from './api/routers'
export { default as plansApi } from './api/plans'
export { default as staffApi } from './api/staff'
export { default as supportApi } from './api/support'
export { default as inventoryApi } from './api/inventory'
export { default as sectorialsApi } from './api/sectorials'
export { default as tenantApi } from './api/tenant'
export { default as rolesApi } from './api/roles'

// =========================
// LEGACY DEFAULT EXPORT (backward-compatible)
// =========================
import authApi from './api/auth'
import customersApi from './api/customers'
import routersApi from './api/routers'
import plansApi from './api/plans'
import staffApi from './api/staff'
import supportApi from './api/support'
import inventoryApi from './api/inventory'
import sectorialsApi from './api/sectorials'
import tenantApi from './api/tenant'
import rolesApi from './api/roles'

export default {
  auth: authApi,
  customers: customersApi,
  routers: routersApi,
  plan: plansApi,
  plans: { getAll: plansApi.getAll },
  staff: staffApi,
  support: supportApi,
  inventory: inventoryApi,
  sectorials: sectorialsApi,
  tenant: tenantApi,
  roles: rolesApi,
}
