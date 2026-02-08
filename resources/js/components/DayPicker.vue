<template>
  <div class="relative inline-block w-full" @click.stop="toggle">
    <!-- INPUT -->
    <div
      class="bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 
             text-gray-800 dark:text-gray-200 px-3 py-2 h-11 rounded-lg cursor-pointer 
             flex justify-between items-center hover:bg-gray-100 dark:hover:bg-gray-750 transition"
    >
      <span>{{ modelValue || "Seleccione…" }}</span>

      <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </div>

    <!-- POPUP -->
    <transition name="daypicker">
      <div
        v-if="open"
        class="absolute left-0 right-0 z-20 mt-2 
               bg-white/95 dark:bg-gray-900/95 backdrop-blur-lg 
               border border-gray-300 dark:border-gray-700 
               rounded-2xl shadow-2xl p-4 
               grid grid-cols-5 gap-3"
      >
        <button
          v-for="d in days"
          :key="d"
          type="button"
          @click.stop="selectDay(d)"
          :class="[
            'h-11 w-full rounded-full flex items-center justify-center text-sm font-medium transition',
            modelValue === d
              ? 'bg-blue-600 text-white shadow-lg'
              : 'bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-white'
          ]"
        >
          {{ d }}
        </button>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue"

const props = defineProps({
  modelValue: String
})

const emit = defineEmits(["update:modelValue"])

const open = ref(false)
const days = Array.from({ length: 31 }, (_, i) => String(i + 1).padStart(2, "0"))

const toggle = () => (open.value = !open.value)

const selectDay = day => {
  emit("update:modelValue", day)
  open.value = false
}

const close = () => (open.value = false)

onMounted(() => document.addEventListener("click", close))
onBeforeUnmount(() => document.removeEventListener("click", close))
</script>

<style>
.daypicker-enter-active,
.daypicker-leave-active {
  transition: all 0.18s ease;
}
.daypicker-enter-from,
.daypicker-leave-to {
  opacity: 0;
  transform: scale(0.95);
}
</style>
