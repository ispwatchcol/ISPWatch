<template>
  <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click.self="$emit('close')">
    <div class="bg-white dark:bg-gray-800 rounded-xl max-w-4xl w-full max-h-[80vh] overflow-auto p-6 shadow-2xl">
      <div class="flex justify-between items-center mb-6">
        <h3 class="text-xl font-bold text-red-600 dark:text-red-400 flex items-center gap-2">
          <v-icon name="md-error" class="w-7 h-7" />
          Errores de Importación
        </h3>
        <button @click="$emit('close')" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
          <v-icon name="md-close" class="w-6 h-6" />
        </button>
      </div>
      
      <div class="overflow-hidden border border-red-200 dark:border-red-900/50 rounded-lg">
        <table class="w-full">
          <thead class="bg-red-50 dark:bg-red-900/20">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-red-800 dark:text-red-300 uppercase tracking-wider">Fila</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-red-800 dark:text-red-300 uppercase tracking-wider">Campo</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-red-800 dark:text-red-300 uppercase tracking-wider">Error</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-red-100 dark:divide-red-900/30 bg-white dark:bg-gray-800">
            <tr v-for="(error, index) in errors" :key="index" class="hover:bg-red-50 dark:hover:bg-red-900/10 transition-colors">
              <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 font-mono">{{ error.row }}</td>
              <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 font-mono font-bold">{{ error.field }}</td>
              <td class="px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ error.error }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <div class="mt-6 flex justify-end">
        <button @click="$emit('close')" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-6 py-2 rounded-lg transition-colors font-medium">
          Cerrar
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  errors: Array,
});
</script>
