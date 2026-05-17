import { ref, computed, watch, unref } from 'vue'

/**
 * Ordenamiento + paginación en cliente, reutilizable para tablas.
 *
 * Encapsula la misma lógica que ya usaba PlanList (perPage 10/100/500/1000/todos,
 * orden asc/desc por columna, paginado) para no duplicarla en cada vista.
 *
 * Uso:
 *   const ctrl = useTableControls(filteredCustomers, {
 *     sortAccessors: { name: c => c.name, status: c => !!c.status },
 *     defaultSort: 'num',
 *   })
 *   // en template: v-for="(row, idx) in ctrl.paginatedItems"
 *
 * @param {import('vue').Ref|import('vue').ComputedRef|Array} source  Lista YA filtrada.
 * @param {Object}   options
 * @param {Object}   options.sortAccessors  Mapa columna -> fn(item) que devuelve el valor a comparar.
 * @param {string}   [options.defaultSort]  Columna inicial de orden.
 * @param {'asc'|'desc'} [options.defaultOrder='asc']
 * @param {number}   [options.defaultPerPage=10]
 */
export function useTableControls(source, options = {}) {
    const {
        sortAccessors = {},
        defaultSort = null,
        defaultOrder = 'asc',
        defaultPerPage = 10,
    } = options

    const perPage = ref(defaultPerPage)
    const currentPage = ref(1)
    const sortBy = ref(defaultSort)
    const sortOrder = ref(defaultOrder)

    // Compara respetando tipo: números, booleanos y strings con orden natural
    // (numeric:true hace que "10" > "9" y ordena IPs/velocidades de forma esperada).
    const compareValues = (a, b) => {
        if (typeof a === 'number' && typeof b === 'number') return a - b
        if (typeof a === 'boolean' || typeof b === 'boolean') return (a ? 1 : 0) - (b ? 1 : 0)
        return String(a ?? '').localeCompare(String(b ?? ''), 'es', {
            numeric: true,
            sensitivity: 'base',
        })
    }

    const sortedItems = computed(() => {
        const items = [...(unref(source) ?? [])]
        const accessor = sortAccessors[sortBy.value]
        if (!accessor) return items
        const dir = sortOrder.value === 'asc' ? 1 : -1
        return items.sort((a, b) => dir * compareValues(accessor(a), accessor(b)))
    })

    const isAll = computed(() => perPage.value === 'todos')

    const perPageNum = computed(() =>
        isAll.value ? (sortedItems.value.length || 1) : (parseInt(perPage.value) || 10)
    )

    const totalPages = computed(() => {
        if (isAll.value) return 1
        return Math.max(1, Math.ceil(sortedItems.value.length / perPageNum.value))
    })

    // Índice 0-based del primer registro de la página actual (para numerar globalmente).
    const pageStart = computed(() =>
        isAll.value ? 0 : (currentPage.value - 1) * perPageNum.value
    )

    const paginatedItems = computed(() => {
        if (isAll.value) return sortedItems.value
        return sortedItems.value.slice(pageStart.value, pageStart.value + perPageNum.value)
    })

    const paginationInfo = computed(() => {
        const total = sortedItems.value.length
        if (isAll.value) return `Total: ${total} registros`
        const start = total === 0 ? 0 : pageStart.value + 1
        const end = Math.min(pageStart.value + perPageNum.value, total)
        return `${start}-${end} de ${total}`
    })

    const toggleSort = (column) => {
        if (!sortAccessors[column]) return
        if (sortBy.value === column) {
            sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
        } else {
            sortBy.value = column
            sortOrder.value = 'asc'
        }
        currentPage.value = 1
    }

    const nextPage = () => {
        if (currentPage.value < totalPages.value) currentPage.value++
    }
    const prevPage = () => {
        if (currentPage.value > 1) currentPage.value--
    }
    const goToPage = (page) => {
        if (page >= 1 && page <= totalPages.value) currentPage.value = page
    }
    const resetPagination = () => {
        currentPage.value = 1
    }

    // Cambiar el tamaño de página vuelve a la primera.
    watch(perPage, () => { currentPage.value = 1 })

    // Si al filtrar la lista se encoge y la página actual queda fuera de rango,
    // se reajusta automáticamente (evita mostrar una página vacía).
    watch(totalPages, (tp) => {
        if (currentPage.value > tp) currentPage.value = tp
    })

    return {
        perPage,
        currentPage,
        sortBy,
        sortOrder,
        sortedItems,
        paginatedItems,
        totalPages,
        paginationInfo,
        pageStart,
        toggleSort,
        nextPage,
        prevPage,
        goToPage,
        resetPagination,
    }
}
