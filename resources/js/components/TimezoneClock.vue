<template>
  <div class="relative overflow-hidden bg-white dark:bg-black/20 rounded-xl p-3 border border-gray-200 dark:border-gray-700/30 shadow-sm dark:shadow-none backdrop-blur-sm group hover:border-indigo-300 dark:hover:border-gray-600 transition-all duration-300">
    <!-- Gradient accent -->
    <div class="absolute top-0 right-0 w-16 h-16 bg-gradient-to-br from-indigo-500/10 to-purple-500/10 dark:from-indigo-500/20 dark:to-purple-500/20 blur-xl rounded-full -mr-8 -mt-8 pointer-events-none group-hover:scale-110 transition-all duration-500"></div>

    <div class="flex items-center justify-between gap-3 relative z-10">
      <!-- Icon & Timezone -->
      <div class="flex items-center gap-2.5 min-w-0">
        <div class="p-1.5 bg-indigo-50 dark:bg-indigo-500/10 rounded-lg group-hover:bg-indigo-100 dark:group-hover:bg-indigo-500/20 transition-colors">
          <v-icon name="md-schedule" class="w-3.5 h-3.5 text-indigo-500 dark:text-indigo-400" />
        </div>
        <div class="flex flex-col min-w-0">
          <span class="text-[10px] font-semibold tracking-wider uppercase text-gray-500 dark:text-gray-400 truncate">
            {{ timezoneDisplay }}
          </span>
          <span class="text-xs font-medium text-gray-700 dark:text-gray-300 truncate">
             {{ currentDate }}
          </span>
        </div>
      </div>

      <!-- Time -->
      <div class="text-right flex-shrink-0">
        <div class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 to-purple-600 dark:from-indigo-400 dark:to-purple-400 tabular-nums tracking-tight leading-none group-hover:scale-105 transition-transform origin-right">
          {{ currentTime }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'

const props = defineProps({
  timezone: {
    type: String,
    default: 'America/Bogota'
  }
})

const currentTime = ref('')
const currentDate = ref('')
let intervalId = null

const timezoneDisplay = computed(() => {
  const tzMap = {
    'America/Bogota': 'COT',
    'America/Mexico_City': 'CST',
    'America/Lima': 'PET',
    'America/Santiago': 'CLT',
    'America/Buenos_Aires': 'ART',
  }
  return tzMap[props.timezone] || 'LOC'
})

const updateTime = () => {
  try {
    const now = new Date()
    
    // Format time
    currentTime.value = now.toLocaleTimeString('es-ES', {
      timeZone: props.timezone,
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false
    })
    
    // Format date
    currentDate.value = now.toLocaleDateString('es-ES', {
      timeZone: props.timezone,
      weekday: 'short',
      day: 'numeric',
      month: 'short'
    }).replace('.', '')
  } catch (error) {
    console.error('Error updating time:', error)
    currentTime.value = '--:--:--'
    currentDate.value = 'Error de zona horaria'
  }
}

onMounted(() => {
  updateTime()
  intervalId = setInterval(updateTime, 1000)
})

onUnmounted(() => {
  if (intervalId) {
    clearInterval(intervalId)
  }
})
</script>

<style scoped>
/* Smooth number transitions */
.tabular-nums {
  font-variant-numeric: tabular-nums;
}
</style>
