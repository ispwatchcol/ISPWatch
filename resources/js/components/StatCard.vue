<template>
  <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border-l-4 transition-all hover:shadow-lg"
       :class="getBorderColor()">
    <div class="flex items-center justify-between">
      <div class="flex-1">
        <p class="text-sm font-medium text-gray-600 dark:text-gray-400 uppercase tracking-wide">
          {{ title }}
        </p>
        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100 mt-2">
          {{ value }}
        </p>
        <p v-if="trend" class="text-sm mt-2"
           :class="trend.startsWith('+') ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
          {{ trend }} vs mes anterior
        </p>
      </div>
      <div class="p-4 rounded-full"
           :class="getIconBgClass()">
        <v-icon :name="icon" class="w-8 h-8" :class="getIconColor()" />
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  title: String,
  value: [String, Number],
  icon: String,
  color: {
    type: String,
    default: 'blue'
  },
  trend: String
})

const getBorderColor = () => {
  const colors = {
    blue: 'border-blue-500',
    green: 'border-green-500',
    yellow: 'border-yellow-500',
    red: 'border-red-500',
    purple: 'border-purple-500'
  }
  return colors[props.color] || colors.blue
}

const getIconBgClass = () => {
  const colors = {
    blue: 'bg-blue-100 dark:bg-blue-900/30',
    green: 'bg-green-100 dark:bg-green-900/30',
    yellow: 'bg-yellow-100 dark:bg-yellow-900/30',
    red: 'bg-red-100 dark:bg-red-900/30',
    purple: 'bg-purple-100 dark:bg-purple-900/30'
  }
  return colors[props.color] || colors.blue
}

const getIconColor = () => {
  const colors = {
    blue: 'text-blue-600 dark:text-blue-400',
    green: 'text-green-600 dark:text-green-400',
    yellow: 'text-yellow-600 dark:text-yellow-400',
    red: 'text-red-600 dark:text-red-400',
    purple: 'text-purple-600 dark:text-purple-400'
  }
  return colors[props.color] || colors.blue
}
</script>
