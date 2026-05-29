<template>
  <div class="relative inline-block w-full" @click.stop="toggle">
    <!-- Trigger -->
    <div
      class="bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600
             text-gray-900 dark:text-gray-100 px-4 py-3 rounded-xl cursor-pointer
             flex justify-between items-center transition-all"
      :class="open ? 'ring-2 ring-blue-500 border-transparent' : 'hover:border-blue-400 dark:hover:border-blue-500'"
    >
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-blue-500 dark:text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <span :class="modelValue ? 'font-medium' : 'text-gray-400 dark:text-gray-500'">
          {{ displayValue }}
        </span>
      </div>
      <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 transition-transform duration-200"
           :class="{ 'rotate-180': open }"
           fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </div>

    <!-- Popup calendar -->
    <transition name="datepicker">
      <div
        v-if="open"
        class="absolute left-0 right-0 z-30 mt-2
               bg-white dark:bg-gray-900
               border border-gray-200 dark:border-gray-700
               rounded-xl shadow-xl p-4"
        @click.stop
      >
        <!-- Month/Year navigation -->
        <div class="flex items-center justify-between mb-3 px-1">
          <button type="button" @click.stop="prevMonth"
            class="p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
            <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <span class="text-sm font-semibold text-gray-700 dark:text-gray-300 capitalize select-none">
            📅 {{ currentMonthName }} {{ viewYear }}
          </span>
          <button type="button" @click.stop="nextMonth"
            class="p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
            <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </button>
        </div>

        <!-- Weekday labels -->
        <div class="grid grid-cols-7 gap-1 mb-1">
          <div v-for="label in weekLabels" :key="label.key"
               class="h-6 flex items-center justify-center text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase">
            {{ label.text }}
          </div>
        </div>

        <!-- Days grid -->
        <div class="grid grid-cols-7 gap-1">
          <div v-for="n in firstDayOffset" :key="'empty-' + n" class="h-9" />

          <button
            v-for="day in daysInMonth"
            :key="day"
            type="button"
            @click.stop="selectDay(day)"
            :class="[
              'h-9 w-full rounded-lg flex items-center justify-center text-sm transition-all',
              isSelected(day)
                ? 'bg-blue-600 text-white font-bold shadow-md ring-2 ring-blue-300 dark:ring-blue-700'
                : isToday(day)
                  ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 font-semibold border border-blue-200 dark:border-blue-700 hover:bg-blue-100 dark:hover:bg-blue-900/40'
                  : 'bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-200 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-white'
            ]"
          >
            {{ day }}
          </button>
        </div>

        <!-- Footer -->
        <div class="mt-3 flex items-center justify-between">
          <button type="button" @click.stop="goToToday"
            class="text-xs text-blue-500 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 px-2 py-1 rounded-lg transition-colors">
            Hoy
          </button>
          <button
            v-if="modelValue"
            type="button"
            @click.stop="clearDate"
            class="text-xs text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 px-2 py-1 rounded-lg transition-colors"
          >
            Limpiar
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
  modelValue: String,
  placeholder: {
    type: String,
    default: 'Selecciona una fecha...'
  }
})

const emit = defineEmits(['update:modelValue'])

const now = new Date()
const open = ref(false)
const viewYear = ref(now.getFullYear())
const viewMonth = ref(now.getMonth())

const weekLabels = [
  { key: 'L', text: 'L' }, { key: 'M1', text: 'M' }, { key: 'M2', text: 'M' },
  { key: 'J', text: 'J' }, { key: 'V', text: 'V' }, { key: 'S', text: 'S' }, { key: 'D', text: 'D' }
]

const displayValue = computed(() => {
  if (!props.modelValue) return props.placeholder
  const [y, m, d] = props.modelValue.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('es-CO', { day: '2-digit', month: 'long', year: 'numeric' })
})

const currentMonthName = computed(() =>
  new Date(viewYear.value, viewMonth.value, 1).toLocaleDateString('es-CO', { month: 'long' })
)

const daysInMonth = computed(() =>
  new Date(viewYear.value, viewMonth.value + 1, 0).getDate()
)

// Monday-based offset
const firstDayOffset = computed(() => {
  const dow = new Date(viewYear.value, viewMonth.value, 1).getDay()
  return dow === 0 ? 6 : dow - 1
})

const prevMonth = () => {
  if (viewMonth.value === 0) { viewYear.value--; viewMonth.value = 11 }
  else viewMonth.value--
}

const nextMonth = () => {
  if (viewMonth.value === 11) { viewYear.value++; viewMonth.value = 0 }
  else viewMonth.value++
}

const selectDay = (day) => {
  const m = String(viewMonth.value + 1).padStart(2, '0')
  const d = String(day).padStart(2, '0')
  emit('update:modelValue', `${viewYear.value}-${m}-${d}`)
  open.value = false
}

const clearDate = () => {
  emit('update:modelValue', '')
  open.value = false
}

const goToToday = () => {
  viewYear.value = now.getFullYear()
  viewMonth.value = now.getMonth()
  selectDay(now.getDate())
}

const isSelected = (day) => {
  if (!props.modelValue) return false
  const [y, m, d] = props.modelValue.split('-').map(Number)
  return y === viewYear.value && (m - 1) === viewMonth.value && d === day
}

const isToday = (day) => {
  return now.getFullYear() === viewYear.value &&
         now.getMonth() === viewMonth.value &&
         now.getDate() === day
}

watch(() => props.modelValue, (val) => {
  if (val) {
    const [y, m] = val.split('-').map(Number)
    viewYear.value = y
    viewMonth.value = m - 1
  }
}, { immediate: true })

const toggle = () => (open.value = !open.value)
const close = () => (open.value = false)

onMounted(() => document.addEventListener('click', close))
onBeforeUnmount(() => document.removeEventListener('click', close))
</script>

<style>
.datepicker-enter-active,
.datepicker-leave-active {
  transition: all 0.2s ease;
}
.datepicker-enter-from,
.datepicker-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.98);
}
</style>
