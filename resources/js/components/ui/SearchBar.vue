<template>
  <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
    <div class="relative flex-1 sm:flex-none">
      <v-icon
        name="io-search"
        class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"
      />
      <input
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
        type="text"
        :placeholder="placeholder"
        class="border border-gray-300 dark:border-gray-700 rounded-xl pl-10 pr-4 py-2 w-full sm:w-80 focus:ring-2 focus:ring-blue-300 outline-none dark:bg-gray-900 dark:text-white text-sm"
      />
    </div>
    <button
      v-if="modelValue"
      @click="$emit('update:modelValue', '')"
      class="text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-3 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all whitespace-nowrap"
    >
      Limpiar
    </button>
  </div>
</template>

<script setup>
defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Buscar...' },
})

defineEmits(['update:modelValue'])
</script>
