import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { supabase } from '@/supabase'

/**
 * Composable for tenant-scoped Supabase queries.
 * Automatically injects tenant_id into queries.
 */
export function useTenant() {
    const auth = useAuthStore()

    const tenantId = computed(() => auth.tenantId)

    /**
     * Returns a Supabase query builder with tenant_id already filtered.
     * @param {string} table - The Supabase table name
     * @returns {object} Supabase query builder with .eq('tenant_id', tenantId)
     */
    function fromTenant(table) {
        const query = supabase.from(table).select()
        if (tenantId.value) {
            return query.eq('tenant_id', tenantId.value)
        }
        console.warn(`[useTenant] No tenant_id found for table "${table}"`)
        return query
    }

    /**
     * Inserts a row with tenant_id automatically set.
     * @param {string} table
     * @param {object} data
     */
    function insertWithTenant(table, data) {
        return supabase.from(table).insert({
            ...data,
            tenant_id: tenantId.value,
        })
    }

    return {
        tenantId,
        fromTenant,
        insertWithTenant,
    }
}
