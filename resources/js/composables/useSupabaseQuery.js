import { ref } from 'vue'
import { supabase } from '@/supabase'
import { useAuthStore } from '@/stores/auth'

/**
 * Composable for Supabase queries with loading/error state and automatic tenant filtering.
 *
 * Usage:
 *   const { data, loading, error, execute } = useSupabaseQuery()
 *   await execute('router', { select: 'id, name, ip', orderBy: 'name' })
 */
export function useSupabaseQuery() {
    const data = ref([])
    const loading = ref(false)
    const error = ref(null)

    /**
     * Execute a tenant-scoped Supabase query.
     * @param {string} table - Table name
     * @param {object} options
     * @param {string} [options.select='*'] - Columns to select
     * @param {string} [options.orderBy] - Column to order by
     * @param {boolean} [options.ascending=true] - Order direction
     * @param {boolean} [options.filterTenant=true] - Whether to filter by tenant_id
     * @param {Array} [options.filters=[]] - Additional filters as [{column, operator, value}]
     * @param {number} [options.limit] - Max rows to return
     */
    async function execute(table, options = {}) {
        const {
            select = '*',
            orderBy,
            ascending = true,
            filterTenant = true,
            filters = [],
            limit,
        } = options

        loading.value = true
        error.value = null

        try {
            const auth = useAuthStore()
            let query = supabase.from(table).select(select)

            // Auto-filter by tenant
            if (filterTenant && auth.tenantId) {
                query = query.eq('tenant_id', auth.tenantId)
            }

            // Apply additional filters
            for (const f of filters) {
                query = query[f.operator || 'eq'](f.column, f.value)
            }

            // Ordering
            if (orderBy) {
                query = query.order(orderBy, { ascending })
            }

            // Limit
            if (limit) {
                query = query.limit(limit)
            }

            const { data: result, error: queryError } = await query

            if (queryError) {
                error.value = queryError.message
                console.error(`[useSupabaseQuery] Error on "${table}":`, queryError.message)
                return
            }

            data.value = result || []
        } catch (e) {
            error.value = e.message
            console.error(`[useSupabaseQuery] Exception on "${table}":`, e)
        } finally {
            loading.value = false
        }
    }

    return { data, loading, error, execute }
}
