import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

export const useAuthStore = defineStore('auth', () => {
    // ─── State ───
    const user = ref(null)

    // ─── Getters ───
    const isAuthenticated = computed(() => !!user.value)
    const tenantId = computed(() => user.value?.tenant_id ?? null)
    const userId = computed(() => user.value?.id ?? null)
    const userName = computed(() => {
        const u = user.value
        if (!u) return 'Usuario'
        return `${u.user_name || u.name || 'Usuario'} ${u.user_lastname || u.last_name || ''}`.trim()
    })
    const roleName = computed(() => user.value?.role_name || user.value?.role || '')
    const roleId = computed(() => user.value?.role_id ?? null)
    const roleCode = computed(() => user.value?.role_code ?? null)
    const permissions = computed(() => user.value?.permissions || [])
    const isStaffOrAdmin = computed(() => ['admin', 'staff'].includes(roleCode.value))
    const isAdmin = computed(() => roleCode.value === 'admin')

    // Sólo el tenant operador administra las llaves de la API pública. Lo
    // resuelve el backend (config/api_keys.php) y viaja en /auth/me: comparar
    // aquí contra un id fijo crearía una segunda fuente de verdad que se
    // rompería en silencio el día que el operador cambie.
    const isApiKeyOperator = computed(() => user.value?.is_api_key_operator === true)

    /**
     * ¿La plataforma permite hoy que un ISP emita sus propias llaves?
     *
     * Es distinto del permiso: `manage_own_api_keys` dice si ESTE usuario podría
     * emitir, y esto dice si el auto-servicio está encendido en el servidor
     * (config/api_keys.php → self_service.enabled). Sin las dos cosas la pestaña
     * saldría visible para acabar mostrando un 403 al abrirla.
     */
    const selfServiceApiKeys = computed(() => user.value?.self_service_api_keys === true)

    // ─── Actions ───
    function loadFromStorage() {
        const raw = localStorage.getItem('userData') || sessionStorage.getItem('userData')
        if (raw) {
            try {
                user.value = JSON.parse(raw)
            } catch (e) {
                console.error('Error parsing stored userData:', e)
                user.value = null
            }
        }
    }

    function setUser(userData, remember = false) {
        user.value = userData
        const storage = remember ? localStorage : sessionStorage
        storage.setItem('userData', JSON.stringify(userData))
        storage.setItem('isLoggedIn', 'true')
    }

    function logout() {
        user.value = null
        localStorage.removeItem('userData')
        localStorage.removeItem('isLoggedIn')
        sessionStorage.removeItem('userData')
        sessionStorage.removeItem('isLoggedIn')
    }

    /**
     * Espejo EXACTO de App\Http\Middleware\CheckPermission.
     *
     * Antes esta función no tenía el bypass de superadministrador y
     * services/auth.js sí. Como el guard de vue-router usa ESTA (el store), un
     * Administrador (role_id 1) cuyo array role.permissions no incluyera un
     * permiso concreto quedaba bloqueado en la navegación aunque el backend sí
     * le hubiera dado acceso. Fue exactamente el síntoma de
     * manage_document_templates: admins con 34 de 35 permisos que no veían la
     * pestaña Plantillas.
     *
     * Si cambias el criterio aquí, cámbialo también en CheckPermission.php.
     */
    function hasPermission(permission) {
        if (!user.value) return false
        // Superadmin: role_id == 1 (Administrador) tiene acceso total.
        if (Number(user.value.role_id) === 1) return true
        if (user.value.is_superadmin === true) return true
        if (permissions.value.includes('*')) return true
        return permissions.value.includes(permission)
    }

    function hasStaffProfile() {
        return user.value?.has_staff_profile === true
    }

    async function refreshUserPermissions() {
        if (!user.value) return

        try {
            const response = await api.auth.me()
            
            if (response.data?.success && response.data?.data) {
                const refreshedUser = response.data.data
                
                // Update the state
                user.value = {
                    ...user.value,
                    ...refreshedUser
                }
                
                // Update storage if needed
                const isRemembered = localStorage.getItem('isLoggedIn') === 'true'
                const storage = isRemembered ? localStorage : sessionStorage
                storage.setItem('userData', JSON.stringify(user.value))
            }
        } catch (error) {
            console.error('Error refreshing user permissions:', error)
        }
    }

    return {
        // State
        user,
        // Getters
        isAuthenticated,
        tenantId,
        userId,
        userName,
        roleName,
        roleId,
        roleCode,
        permissions,
        isStaffOrAdmin,
        isAdmin,
        isApiKeyOperator,
        selfServiceApiKeys,
        // Actions
        loadFromStorage,
        setUser,
        logout,
        hasPermission,
        hasStaffProfile,
        refreshUserPermissions,
    }
})
