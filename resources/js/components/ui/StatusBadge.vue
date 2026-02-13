<template>
  <span
    class="inline-block px-3 py-1 text-xs font-semibold rounded-full"
    :class="badgeClasses"
  >
    {{ label }}
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, required: true },
  /** Custom label; defaults to the status value */
  label: { type: String, default: '' },
  /** Map of status -> variant */
  variants: {
    type: Object,
    default: () => ({
      active: 'success',
      inactive: 'danger',
      pending: 'warning',
      paid: 'success',
      overdue: 'danger',
      draft: 'neutral',
      open: 'warning',
      closed: 'neutral',
      resolved: 'success',
      true: 'success',
      false: 'danger',
    }),
  },
})

// If no custom label, use status
const label = computed(() => props.label || props.status || '—')

const variant = computed(() => {
  const key = String(props.status).toLowerCase()
  return props.variants[key] || 'neutral'
})

const badgeClasses = computed(() => ({
  success: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
  danger: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
  warning: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
  neutral: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
  info: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
}[variant.value] || 'bg-gray-100 text-gray-600'))
</script>
