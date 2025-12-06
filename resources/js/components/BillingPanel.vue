<template>
  <div v-if="active" class="mt-0 col-span-2">
    <div class="border border-gray-300 dark:border-gray-700 rounded-xl p-6 
                bg-white dark:bg-gray-900 shadow-md w-full transition-colors">

      <!-- Título -->
      <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">
        Facturación del Router
      </h3>

      <!-- SUBGRID -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- Crear factura automáticamente -->
        <div class="col-span-2">
          <div class="flex items-center justify-between">
            <label class="text-gray-800 dark:text-gray-300 font-medium">
              Crear Factura Automáticamente
            </label>

            <DayPicker
              v-model="billing.create_invoice"
              class="w-full"
            />
          </div>

          <p class="text-xs text-gray-500 mt-1">
            Selecciona el día del mes en el que se generará la factura automáticamente.
          </p>
        </div>

        <!-- Día de corte -->
        <div>
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Día de corte
          </label>
          <DayPicker v-model="billing.cut_day" />
        </div>

        <!-- Día límite -->
        <div>
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Día límite de pago
          </label>
          <DayPicker v-model="billing.pay_day" />
        </div>

        <!-- Recordatorio -->
        <div class="w-full">
          <div class="flex items-center justify-between mb-1">
            <label class="text-gray-800 dark:text-gray-300 font-medium">
              Recordatorio de pago
            </label>

            <button
              type="button"
              @click="billing.notificar_wpp = !billing.notificar_wpp"
              :class="billing.notificar_wpp ? 'bg-blue-600' : 'bg-gray-400 dark:bg-gray-600'"
              class="relative inline-flex h-6 w-11 rounded-full transition-colors duration-300"
            >
              <span
                class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transform transition-transform duration-300"
                :class="billing.notificar_wpp ? 'translate-x-5' : ''"
              ></span>
            </button>
          </div>

          <DayPicker v-model="billing.remember_day" />
        </div>

        <!-- Facturas vencidas -->
        <div>
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Suspender tras X facturas vencidas
          </label>
          <input 
            v-model="billing.overdue_invoices"
            type="number"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 
                  focus:ring focus:ring-blue-500 transition-colors"
            placeholder="Ej: 2"
          />
        </div>

        <!-- Monto base -->
        <div>
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Monto base mensual
          </label>
          <input 
            v-model="billing.amount"
            type="number"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 
                  focus:ring focus:ring-blue-500 transition-colors"
            placeholder="Ej: 25000"
          />
        </div>

        <!-- Método de cobro -->
        <div>
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Método de cobro
          </label>
          <select
            v-model="billing.metodo"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 
                  focus:ring focus:ring-blue-500 transition-colors"
          >
            <option value="" disabled>Seleccione…</option>
            <option 
              v-for="t in types"
              :key="t.id"
              :value="t.id"
            >
              {{ t.type }}
            </option>
          </select>
        </div>

        <!-- Comentarios -->
        <div class="col-span-1 md:col-span-2 mt-0">
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Comentarios
          </label>
          <textarea 
            v-model="billing.comentarios"
            rows="3"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 
                  focus:ring focus:ring-blue-500 transition-colors"
            placeholder="Notas sobre la instalación, configuración especial, etc."
          ></textarea>
        </div>

      </div> 
    </div>
  </div>
</template>

<script setup>
import DayPicker from "@/components/DayPicker.vue"

defineProps({
  active: Boolean,
  billing: Object,
  types: Array
})
</script>
