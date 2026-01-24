<template>
  <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click.self="$emit('close')">
    <div class="bg-white dark:bg-gray-800 rounded-xl max-w-2xl w-full max-h-[80vh] overflow-auto p-6 shadow-2xl">
      <div class="flex justify-between items-center mb-6">
        <h3 class="text-xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
          <span class="text-2xl">📋</span> Campos Requeridos
        </h3>
        <button @click="$emit('close')" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
          <v-icon name="md-close" class="w-6 h-6" />
        </button>
      </div>
      
      <div class="overflow-hidden border border-gray-200 dark:border-gray-700 rounded-lg">
        <table class="w-full">
          <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Campo</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Obligatorio</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Descripción</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ejemplo</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
            <tr v-for="field in docs" :key="field.field" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
              <td class="px-4 py-3 font-mono text-sm text-indigo-600 dark:text-indigo-400 font-medium">{{ field.field }}</td>
              <td class="px-4 py-3 text-sm">
                <span v-if="field.required" class="bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 text-xs px-2 py-1 rounded-full font-medium">Sí</span>
                <span v-else class="bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-400 text-xs px-2 py-1 rounded-full font-medium">Opcional</span>
              </td>
              <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ field.description }}</td>
              <td class="px-4 py-3 font-mono text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/50 px-2 py-1 rounded">{{ field.example }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <p class="text-sm text-gray-500 dark:text-gray-400 mt-4 flex items-center gap-2">
        <span class="w-2 h-2 bg-red-500 rounded-full"></span>
        Campos obligatorios deben estar presentes en el archivo.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
  type: String,
});

const docs = ref([]);

onMounted(async () => {
  try {
    const response = await axios.get(`/api/import/docs/${props.type}`);
    docs.value = response.data;
  } catch (error) {
    console.error('Error loading field docs:', error);
  }
});
</script>
