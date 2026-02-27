<template>
  <div class="animate-pulse">
    <!-- Card Skeleton -->
    <div v-if="type === 'card'" class="grid gap-6" :class="gridClasses">
      <div
        v-for="n in count"
        :key="n"
        class="rounded-xl bg-white dark:bg-gray-800 shadow-md p-5 border border-gray-100 dark:border-gray-700"
      >
        <div class="flex items-center justify-between">
          <div class="space-y-3 flex-1">
            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-24"></div>
            <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded w-16"></div>
            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-32"></div>
          </div>
          <div class="w-12 h-12 bg-gray-200 dark:bg-gray-700 rounded-full"></div>
        </div>
      </div>
    </div>

    <!-- Table Row Skeleton -->
    <div v-else-if="type === 'table'">
      <div
        v-for="n in count"
        :key="n"
        class="flex items-center gap-4 py-3 px-4 border-b border-gray-200 dark:border-gray-700"
      >
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded flex-1"></div>
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-24"></div>
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-20"></div>
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-16"></div>
      </div>
    </div>

    <!-- List Skeleton -->
    <div v-else class="space-y-4">
      <div
        v-for="n in count"
        :key="n"
        class="flex items-start space-x-3"
      >
        <div class="w-3 h-3 rounded-full mt-2 bg-gray-200 dark:bg-gray-700"></div>
        <div class="flex-1 space-y-2">
          <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
          <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  type: {
    type: String,
    default: 'list',
    validator: v => ['card', 'table', 'list'].includes(v),
  },
  count: { type: Number, default: 4 },
  columns: { type: Number, default: 4 },
})

const gridClasses = computed(() => ({
  1: 'grid-cols-1',
  2: 'grid-cols-1 md:grid-cols-2',
  3: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
  4: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
}[props.columns] || 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4'))
</script>
