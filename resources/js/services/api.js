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
    let userData = null
    try {
      userData =
        JSON.parse(localStorage.getItem('userData') ?? 'null') ||
        JSON.parse(sessionStorage.getItem('userData') ?? 'null')
    } catch {
      userData = null
    }

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
// INTERCEPTOR: HANDLE EXPIRED SESSION (401)
// =========================
// If a request comes back 401 (Sanctum cookie expired / invalidated), wipe
// the cached userData and bounce to /login so the user can re-authenticate.
// Without this, pages silently render their empty state because the failed
// promise has no global handler — which looks like "nothing loads".
apiClient.interceptors.response.use(
  response => response,
  error => {
    const status = error?.response?.status
    const url    = error?.config?.url || ''
    const isLogin = url.includes('/login')

    if (status === 401 && !isLogin && typeof window !== 'undefined') {
      try { localStorage.removeItem('userData') } catch {}
      try { sessionStorage.removeItem('userData') } catch {}
      // The Vue login page is mounted at "/", not "/login".
      if (window.location.pathname !== '/') {
        window.location.assign('/')
      }
    }
    return Promise.reject(error)
  }
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
export { default as helpCenterApi } from './api/help-center'

// =========================
// LEGACY DEFAULT EXPORT (backward-compatible)
// =========================
import authApi from './api/auth'
import customersApi from './api/customers'
import prospectsApi from './api/prospects'
import routersApi from './api/routers'
import plansApi from './api/plans'
import staffApi from './api/staff'
import supportApi from './api/support'
import inventoryApi from './api/inventory'
import sectorialsApi from './api/sectorials'
import tenantApi from './api/tenant'
import rolesApi from './api/roles'
import helpCenterApi from './api/help-center'

export default {
  auth: authApi,
  customers: customersApi,
  prospects: prospectsApi,
  routers: routersApi,
  plan: plansApi,
  plans: { getAll: plansApi.getAll },
  staff: staffApi,
  support: supportApi,
  inventory: inventoryApi,
  sectorials: sectorialsApi,
  tenant: tenantApi,
  roles: rolesApi,
  helpCenter: helpCenterApi,
}
