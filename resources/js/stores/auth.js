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

    function hasPermission(permission) {
        if (!user.value) return false
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
        // Actions
        loadFromStorage,
        setUser,
        logout,
        hasPermission,
        hasStaffProfile,
        refreshUserPermissions,
    }
})
