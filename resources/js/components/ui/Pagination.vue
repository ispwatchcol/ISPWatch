<script setup>
import { computed } from 'vue'

/**
 * Paginación numérica reutilizable con elipsis, para reemplazar los bloques
 * "Anterior / Siguiente" repetidos en las distintas vistas del sistema.
 *
 * Uso:
 *   <Pagination :current-page="currentPage" :total-pages="totalPages" @change="goToPage" />
 */
const props = defineProps({
    currentPage: { type: Number, required: true },
    totalPages: { type: Number, required: true },
    siblingCount: { type: Number, default: 1 },
    boundaryCount: { type: Number, default: 1 },
    accent: { type: String, default: 'blue' }, // 'blue' | 'indigo'
})

const emit = defineEmits(['change'])

const range = (start, end) => Array.from({ length: Math.max(0, end - start + 1) }, (_, i) => start + i)

// Ventana de páginas centrada en la página actual, con bloques fijos al inicio/fin
// y elipsis cuando hay demasiadas páginas para mostrarlas todas.
const pages = computed(() => {
    const { currentPage, totalPages, siblingCount, boundaryCount } = props
    const totalPageNumbers = boundaryCount * 2 + siblingCount * 2 + 3

    if (totalPages <= totalPageNumbers) return range(1, totalPages)

    const leftSiblingIndex = Math.max(currentPage - siblingCount, boundaryCount + 2)
    const rightSiblingIndex = Math.min(currentPage + siblingCount, totalPages - boundaryCount - 1)

    const showLeftDots = leftSiblingIndex > boundaryCount + 2
    const showRightDots = rightSiblingIndex < totalPages - boundaryCount - 1

    if (!showLeftDots && showRightDots) {
        const leftItemCount = boundaryCount + siblingCount * 2 + 2
        return [...range(1, leftItemCount), 'dots-r', ...range(totalPages - boundaryCount + 1, totalPages)]
    }

    if (showLeftDots && !showRightDots) {
        const rightItemCount = boundaryCount + siblingCount * 2 + 2
        return [...range(1, boundaryCount), 'dots-l', ...range(totalPages - rightItemCount + 1, totalPages)]
    }

    return [
        ...range(1, boundaryCount),
        'dots-l',
        ...range(leftSiblingIndex, rightSiblingIndex),
        'dots-r',
        ...range(totalPages - boundaryCount + 1, totalPages),
    ]
})

const accentClasses = computed(() => props.accent === 'indigo'
    ? 'bg-indigo-600 text-white border-indigo-600'
    : 'bg-blue-600 text-white border-blue-600'
)

const goTo = (page) => {
    if (page < 1 || page > props.totalPages || page === props.currentPage) return
    emit('change', page)
}
</script>

<template>
    <nav v-if="totalPages > 1" class="flex items-center gap-1 flex-wrap" aria-label="Paginación">
        <button
            type="button"
            @click="goTo(currentPage - 1)"
            :disabled="currentPage === 1"
            class="px-3 py-1.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center gap-1"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            <span class="hidden sm:inline">Anterior</span>
        </button>

        <template v-for="(page, idx) in pages" :key="idx">
            <span v-if="typeof page === 'string'" class="px-1.5 text-gray-500 dark:text-gray-400 select-none">&hellip;</span>
            <button
                v-else
                type="button"
                @click="goTo(page)"
                :class="page === currentPage
                    ? accentClasses
                    : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700'"
                class="min-w-[2rem] h-8 px-2 rounded-lg font-medium text-sm border transition-all"
            >
                {{ page }}
            </button>
        </template>

        <button
            type="button"
            @click="goTo(currentPage + 1)"
            :disabled="currentPage === totalPages"
            class="px-3 py-1.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center gap-1"
        >
            <span class="hidden sm:inline">Siguiente</span>
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
        </button>
    </nav>
</template>
