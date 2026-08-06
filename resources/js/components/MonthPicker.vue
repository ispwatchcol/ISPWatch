<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

/**
 * Selector de mes (`YYYY-MM`), gemelo de `DatePicker` pero con rejilla de meses.
 *
 * Existe porque el `<input type="month">` nativo abre un desplegable que dibuja
 * el navegador: no se puede estilar, sale en inglés ("August 2026", "This
 * month") y en modo oscuro aparece con fondo claro. En una pantalla donde el mes
 * es el filtro principal, eso rompe la lectura.
 */
const props = defineProps({
    modelValue:  { type: String, default: '' },
    placeholder: { type: String, default: 'Todos los meses' },
    disabled:    { type: Boolean, default: false },
    accent:      { type: String, default: 'emerald' },
})

const emit = defineEmits(['update:modelValue'])

const ACCENTS = {
    emerald: {
        ring:     'ring-2 ring-emerald-500 border-transparent',
        hover:    'hover:border-emerald-400 dark:hover:border-emerald-500',
        icon:     'text-emerald-500 dark:text-emerald-400',
        selected: 'bg-emerald-600 text-white font-semibold shadow-md',
        current:  'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400 font-semibold border border-emerald-200 dark:border-emerald-700',
        link:     'text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-900/20',
    },
    blue: {
        ring:     'ring-2 ring-blue-500 border-transparent',
        hover:    'hover:border-blue-400 dark:hover:border-blue-500',
        icon:     'text-blue-500 dark:text-blue-400',
        selected: 'bg-blue-600 text-white font-semibold shadow-md',
        current:  'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 font-semibold border border-blue-200 dark:border-blue-700',
        link:     'text-blue-500 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20',
    },
}

const a = computed(() => ACCENTS[props.accent] ?? ACCENTS.emerald)

const now = new Date()
const open = ref(false)
const viewYear = ref(now.getFullYear())

// Abreviados a tres letras: doce nombres completos no caben en la rejilla.
const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']

const selected = computed(() => {
    if (!props.modelValue) return null
    const [y, m] = props.modelValue.split('-').map(Number)
    return Number.isFinite(y) && Number.isFinite(m) ? { year: y, month: m - 1 } : null
})

const displayValue = computed(() => {
    if (!selected.value) return props.placeholder
    const label = new Date(selected.value.year, selected.value.month, 1)
        .toLocaleDateString('es-CO', { month: 'long', year: 'numeric' })
    return label.charAt(0).toUpperCase() + label.slice(1)
})

const isSelected = (i) => !!selected.value && selected.value.year === viewYear.value && selected.value.month === i
const isCurrent  = (i) => now.getFullYear() === viewYear.value && now.getMonth() === i

const selectMonth = (i) => {
    emit('update:modelValue', `${viewYear.value}-${String(i + 1).padStart(2, '0')}`)
    open.value = false
}

const clear = () => {
    emit('update:modelValue', '')
    open.value = false
}

const goToThisMonth = () => {
    viewYear.value = now.getFullYear()
    selectMonth(now.getMonth())
}

const toggle = () => {
    if (props.disabled) return
    open.value = !open.value
}
const close = () => (open.value = false)

// Al reabrir, la rejilla arranca en el año del valor elegido.
watch(() => props.modelValue, (val) => {
    if (val) {
        const [y] = val.split('-').map(Number)
        if (Number.isFinite(y)) viewYear.value = y
    }
}, { immediate: true })

// Un filtro deshabilitado no debe quedarse con el desplegable abierto.
watch(() => props.disabled, (isDisabled) => {
    if (isDisabled) open.value = false
})

onMounted(() => document.addEventListener('click', close))
onBeforeUnmount(() => document.removeEventListener('click', close))
</script>

<template>
    <div class="relative inline-block w-full" @click.stop="toggle">
        <!-- Disparador -->
        <div
            class="bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600
                   text-gray-900 dark:text-gray-100 px-4 py-2 rounded-xl
                   flex justify-between items-center gap-2 transition-all"
            :class="[
                disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer',
                open ? a.ring : (disabled ? '' : a.hover),
            ]"
            role="button"
            :aria-expanded="open"
            :aria-disabled="disabled"
        >
            <div class="flex items-center gap-2 min-w-0">
                <svg :class="a.icon" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <span class="truncate text-sm" :class="modelValue ? 'font-medium' : 'text-gray-400 dark:text-gray-500'">
                    {{ displayValue }}
                </span>
            </div>
            <svg class="w-4 h-4 shrink-0 text-gray-400 dark:text-gray-500 transition-transform duration-200"
                 :class="{ 'rotate-180': open }"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </div>

        <!-- Rejilla de meses -->
        <transition name="monthpicker">
            <div v-if="open"
                class="absolute right-0 z-30 mt-2 w-64 bg-white dark:bg-gray-900
                       border border-gray-200 dark:border-gray-700 rounded-xl shadow-xl p-4"
                @click.stop>
                <!-- Año -->
                <div class="flex items-center justify-between mb-3 px-1">
                    <button type="button" @click.stop="viewYear--"
                        class="p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        aria-label="Año anterior">
                        <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300 tabular-nums select-none">
                        {{ viewYear }}
                    </span>
                    <button type="button" @click.stop="viewYear++"
                        class="p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        aria-label="Año siguiente">
                        <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>

                <div class="grid grid-cols-3 gap-1.5">
                    <button
                        v-for="(name, i) in MONTHS"
                        :key="name"
                        type="button"
                        @click.stop="selectMonth(i)"
                        :class="[
                            'h-9 rounded-lg text-sm transition-all',
                            isSelected(i)
                                ? a.selected
                                : isCurrent(i)
                                    ? a.current
                                    : 'bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-200 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-white',
                        ]"
                    >
                        {{ name }}
                    </button>
                </div>

                <div class="mt-3 flex items-center justify-between">
                    <button type="button" @click.stop="goToThisMonth"
                        :class="a.link" class="text-xs px-2 py-1 rounded-lg transition-colors">
                        Este mes
                    </button>
                    <button v-if="modelValue" type="button" @click.stop="clear"
                        class="text-xs text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 px-2 py-1 rounded-lg transition-colors">
                        Todos los meses
                    </button>
                </div>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.monthpicker-enter-active,
.monthpicker-leave-active {
    transition: all 0.2s ease;
}
.monthpicker-enter-from,
.monthpicker-leave-to {
    opacity: 0;
    transform: translateY(-4px) scale(0.98);
}
@media (prefers-reduced-motion: reduce) {
    .monthpicker-enter-active,
    .monthpicker-leave-active {
        transition: none;
    }
}
</style>
