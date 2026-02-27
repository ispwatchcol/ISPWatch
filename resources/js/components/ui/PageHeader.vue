<template>
  <div class="flex items-center justify-between mb-8">
    <div>
      <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
        <slot name="icon">
          <v-icon v-if="icon" :name="icon" class="w-7 h-7 text-blue-600" />
        </slot>
        {{ title }}
      </h1>
      <p v-if="subtitle" class="text-gray-500 dark:text-gray-400 text-sm mt-1">
        {{ subtitle }}
      </p>
    </div>
    <div class="flex items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  icon: { type: String, default: '' },
})
</script>
