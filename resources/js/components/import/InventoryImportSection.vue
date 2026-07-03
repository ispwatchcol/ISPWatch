<template>
  <div class="import-section bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 transition-all hover:shadow-md">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
      <div class="flex items-center gap-3">
        <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
          <v-icon name="bi-box-seam" class="w-6 h-6 text-purple-600 dark:text-purple-400" />
        </div>
        <div>
          <h3 class="text-lg font-bold text-gray-800 dark:text-white">Carga Masiva de Inventario</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            Un archivo con los equipos (serial/MAC). La marca-modelo, el proveedor y la sucursal se crean solos por nombre.
          </p>
        </div>
      </div>
      <button
        @click="showFieldDocs = true"
        class="text-sm bg-purple-50 hover:bg-purple-100 dark:bg-purple-900/20 dark:hover:bg-purple-900/30 text-purple-600 dark:text-purple-400 px-4 py-2 rounded-lg flex items-center gap-2 transition-colors font-medium"
      >
        <v-icon name="md-help-outlined" class="w-5 h-5" />
        Ver Campos
      </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      <!-- Left column: Steps 1 & 2 -->
      <div class="space-y-6">
        <!-- Step 1: Download template -->
        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-100 dark:border-gray-700/50">
          <div class="flex items-center gap-2 mb-3">
            <span class="w-6 h-6 flex items-center justify-center bg-purple-600 text-white rounded-full text-xs font-bold">1</span>
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Descargar plantilla de inventario</p>
          </div>
          <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 ml-8">
            Excel con una hoja "Inventario". Cada fila es un equipo físico.
          </p>
          <button @click="downloadTemplate" class="ml-8 text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-4 py-2 rounded-lg flex items-center gap-2 transition-all shadow-sm">
            <v-icon name="vi-file-type-excel" class="text-green-600 dark:text-green-500" />
            Descargar Plantilla
          </button>
        </div>

        <!-- Step 2: Upload file -->
        <div>
          <div class="flex items-center gap-2 mb-3">
            <span class="w-6 h-6 flex items-center justify-center bg-purple-600 text-white rounded-full text-xs font-bold">2</span>
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Subir archivo completado</p>
          </div>

          <div
            class="ml-8 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl p-8 text-center cursor-pointer hover:border-purple-500 dark:hover:border-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/10 transition-all group"
            @drop.prevent="handleDrop"
            @dragover.prevent
            @click="$refs.fileInput.click()"
          >
            <input
              ref="fileInput"
              type="file"
              accept=".xlsx,.xls"
              @change="handleFile"
              class="hidden"
            />

            <div v-if="!uploading" class="flex flex-col items-center gap-3">
              <div class="p-3 bg-gray-100 dark:bg-gray-700 rounded-full group-hover:bg-purple-100 dark:group-hover:bg-purple-900/30 transition-colors">
                <v-icon name="md-cloudupload" class="w-8 h-8 text-gray-400 group-hover:text-purple-600 dark:group-hover:text-purple-400 transition-colors" />
              </div>
              <div>
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Click para seleccionar o arrastra aquí</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Soporta .xlsx, .xls</p>
              </div>
            </div>

            <div v-else class="flex flex-col items-center gap-3">
              <v-icon name="ri-loader-4-line" class="w-8 h-8 text-purple-600 animate-spin" />
              <p class="text-sm font-medium text-purple-600 dark:text-purple-400">Procesando archivo...</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Right column: Step 3 (results) -->
      <div v-if="results || uploading" class="h-full">
        <div class="h-full bg-gray-50 dark:bg-gray-700/30 rounded-xl p-6 border border-gray-100 dark:border-gray-700/50 flex flex-col">
          <div class="flex items-center gap-2 mb-4">
            <span class="w-6 h-6 flex items-center justify-center bg-purple-600 text-white rounded-full text-xs font-bold">3</span>
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Resultados</p>
          </div>

          <div v-if="uploading" class="flex-1 flex flex-col items-center justify-center text-center p-8 opacity-50">
            <p class="text-sm text-gray-500">Esperando resultados...</p>
          </div>

          <div v-else-if="results" class="flex-1 flex flex-col animate-fade-in-up">
            <div class="flex flex-col items-center justify-center text-center mb-4">
              <div
                :class="[
                  'w-16 h-16 rounded-full flex items-center justify-center mb-3',
                  results.success
                    ? 'bg-green-100 dark:bg-green-900/30'
                    : results.partial
                      ? 'bg-yellow-100 dark:bg-yellow-900/30'
                      : 'bg-red-100 dark:bg-red-900/30'
                ]"
              >
                <v-icon
                  :name="results.success ? 'md-checkcircle' : results.partial ? 'md-warning' : 'md-error'"
                  :class="[
                    'w-10 h-10',
                    results.success
                      ? 'text-green-600 dark:text-green-500'
                      : results.partial
                        ? 'text-yellow-600 dark:text-yellow-500'
                        : 'text-red-600 dark:text-red-500'
                  ]"
                />
              </div>
              <h4
                :class="[
                  'text-lg font-bold mb-1',
                  results.success
                    ? 'text-green-700 dark:text-green-400'
                    : results.partial
                      ? 'text-yellow-700 dark:text-yellow-400'
                      : 'text-red-700 dark:text-red-400'
                ]"
              >
                {{
                  results.success
                    ? '¡Importación Exitosa!'
                    : results.partial
                      ? 'Importación Parcial'
                      : 'Atención Requerida'
                }}
              </h4>
              <p class="text-sm text-gray-600 dark:text-gray-300 px-2">
                {{ results.message }}
              </p>
            </div>

            <div v-if="results.summary" class="grid grid-cols-1 gap-2 mb-4">
              <div class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-xs text-gray-500 dark:text-gray-400">Equipos importados</p>
                <p class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ results.summary.equipos }}</p>
              </div>
            </div>

            <button
              v-if="results.errors && results.errors.length"
              @click="showErrors = true"
              class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg shadow-lg shadow-red-600/20 transition-all flex items-center justify-center gap-2"
            >
              <v-icon name="md-list" />
              Ver {{ results.errors.length }} Error{{ results.errors.length > 1 ? 'es' : '' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <FieldDocsModal
      v-if="showFieldDocs"
      url="/api/import/inventory-docs"
      @close="showFieldDocs = false"
    />

    <ErrorsModal
      v-if="showErrors"
      :errors="results.errors"
      @close="showErrors = false"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import FieldDocsModal from './FieldDocsModal.vue';
import ErrorsModal from './ErrorsModal.vue';

const uploading = ref(false);
const results = ref(null);
const showFieldDocs = ref(false);
const showErrors = ref(false);
const fileInput = ref(null);

const downloadTemplate = async () => {
  try {
    const response = await axios.get('/api/import/inventory-template', {
      responseType: 'blob',
    });

    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', 'plantilla_inventario.xlsx');
    document.body.appendChild(link);
    link.click();
    link.remove();
  } catch (error) {
    alert('Error al descargar plantilla: ' + (error.message || 'Error desconocido'));
  }
};

const handleFile = async (event) => {
  const file = event.target.files[0];
  if (file) await uploadFile(file);
  if (fileInput.value) fileInput.value.value = '';
};

const handleDrop = async (event) => {
  const file = event.dataTransfer.files[0];
  if (file) await uploadFile(file);
};

const uploadFile = async (file) => {
  const formData = new FormData();
  formData.append('file', file);

  uploading.value = true;
  results.value = null;

  try {
    const response = await axios.post('/api/import/inventory', formData);
    results.value = response.data;
  } catch (error) {
    if (error.response && error.response.data) {
      results.value = error.response.data;
    } else {
      alert('Error al importar: ' + error.message);
    }
  } finally {
    uploading.value = false;
  }
};
</script>

<style scoped>
.animate-fade-in-up {
  animation: fadeInUp 0.5s ease-out;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
