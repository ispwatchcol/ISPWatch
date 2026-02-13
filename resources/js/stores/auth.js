import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

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
    const permissions = computed(() => user.value?.permissions || [])
    const isStaffOrAdmin = computed(() => [1, 2].includes(Number(roleId.value)))
    const isAdmin = computed(() => Number(roleId.value) === 1)

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
        if (isAdmin.value || permissions.value.includes('*')) return true
        return permissions.value.includes(permission)
    }

    function hasStaffProfile() {
        return user.value?.has_staff_profile === true
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
        permissions,
        isStaffOrAdmin,
        isAdmin,
        // Actions
        loadFromStorage,
        setUser,
        logout,
        hasPermission,
        hasStaffProfile,
    }
})
