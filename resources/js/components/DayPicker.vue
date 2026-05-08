<template>
  <div class="relative inline-block w-full" @click.stop="toggle">
    <!-- INPUT -->
    <div
      class="bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 
             text-gray-800 dark:text-gray-200 px-3 py-2 h-11 rounded-lg cursor-pointer 
             flex justify-between items-center hover:bg-gray-50 dark:hover:bg-gray-750 
             transition-all group"
    >
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-blue-500 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <span :class="modelValue ? 'font-medium' : 'text-gray-400 dark:text-gray-500'">
          {{ modelValue ? `Día ${parseInt(modelValue)}` : 'Seleccione día…' }}
        </span>
      </div>

      <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 transition-transform duration-200"
           :class="{ 'rotate-180': open }"
           fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </div>

    <!-- POPUP -->
    <transition name="daypicker">
      <div
        v-if="open"
        class="absolute left-0 right-0 z-30 mt-2 
               bg-white dark:bg-gray-900 
               border border-gray-200 dark:border-gray-700 
               rounded-xl shadow-xl p-4"
      >
        <!-- Header -->
        <div class="flex items-center justify-between mb-3 px-1">
          <span class="text-xs font-semibold text-gray-600 dark:text-gray-300 capitalize">
            📅 {{ currentMonthName }}
          </span>
          <span class="text-xs text-gray-400 dark:text-gray-500">
            {{ lastDayOfMonth }} días
          </span>
        </div>

        <!-- Weekday labels -->
        <div class="grid grid-cols-7 gap-1 mb-1">
          <div v-for="label in ['L','M','M','J','V','S','D']" :key="label + Math.random()"
               class="h-6 flex items-center justify-center text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase">
            {{ label }}
          </div>
        </div>

        <!-- Days grid -->
        <div class="grid grid-cols-7 gap-1">
          <button
            v-for="d in 31"
            :key="d"
            type="button"
            @click.stop="selectDay(d)"
            :class="[
              'h-9 w-full rounded-lg flex items-center justify-center text-sm transition-all relative',
              modelValue == String(d).padStart(2, '0')
                ? 'bg-blue-600 text-white font-bold shadow-md ring-2 ring-blue-300 dark:ring-blue-700'
                : d > lastDayOfMonth
                  ? 'bg-gray-100 dark:bg-gray-800/40 text-gray-300 dark:text-gray-600 cursor-not-allowed opacity-50'
                  : d === today
                    ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 font-semibold border border-blue-200 dark:border-blue-700 hover:bg-blue-100 dark:hover:bg-blue-900/40'
                    : 'bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-200 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-white'
            ]"
            :disabled="d > lastDayOfMonth"
          >
            {{ d }}
            <!-- Dot indicator for days that may be clamped in short months -->
            <span 
              v-if="d > 28 && d <= lastDayOfMonth && modelValue != String(d).padStart(2, '0')"
              class="absolute bottom-0.5 w-1 h-1 rounded-full bg-amber-400 dark:bg-amber-500"
            ></span>
          </button>
        </div>

        <!-- Info + Clear -->
        <div class="mt-3 flex items-center justify-between">
          <p class="text-[10px] text-gray-400 dark:text-gray-500">
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-amber-400 mr-1 align-middle"></span>
            Se ajusta al último día en meses cortos
          </p>
          <button
            v-if="modelValue"
            type="button"
            @click.stop="clearDay"
            class="text-xs text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 
                   px-2 py-1 rounded-lg transition-colors"
          >
            Limpiar
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from "vue"

const props = defineProps({
  modelValue: String
})

const emit = defineEmits(["update:modelValue"])

const open = ref(false)
const today = new Date().getDate()

const currentMonthName = computed(() => {
  return new Date().toLocaleDateString('es-CO', { month: 'long' })
})

const lastDayOfMonth = computed(() => {
  const now = new Date()
  return new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate()
})

const toggle = () => (open.value = !open.value)

const selectDay = (day) => {
  if (day > lastDayOfMonth.value) return
  emit("update:modelValue", String(day).padStart(2, "0"))
  open.value = false
}

const clearDay = () => {
  emit("update:modelValue", null)
  open.value = false
}

const close = () => (open.value = false)

onMounted(() => document.addEventListener("click", close))
onBeforeUnmount(() => document.removeEventListener("click", close))
</script>

<style>
.daypicker-enter-active,
.daypicker-leave-active {
  transition: all 0.2s ease;
}
.daypicker-enter-from,
.daypicker-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.98);
}
</style>
