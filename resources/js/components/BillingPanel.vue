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

        <!-- Tipo de Aviso al Crear Facturas -->
        <div class="col-span-2">
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-2">
            Tipo de Aviso al Crear Facturas
          </label>
          
          <div class="grid grid-cols-3 gap-4">
            <!-- Email Option -->
            <button
              type="button"
              @click="billing.notification_type = 'email'"
              :class="[
                'px-4 py-3 rounded-lg border-2 transition-all text-sm font-medium',
                billing.notification_type === 'email'
                  ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                  : 'border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:border-blue-400 dark:hover:border-blue-500'
              ]"
            >
              <div class="flex flex-col items-center gap-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                <span>Correo</span>
              </div>
            </button>

            <!-- WhatsApp Option (Próximamente) -->
            <button
              type="button"
              disabled
              title="Próximamente"
              class="relative px-4 py-3 rounded-lg border-2 border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-gray-400 dark:text-gray-500 text-sm font-medium cursor-not-allowed opacity-70"
            >
              <span class="absolute -top-2 right-2 bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-[10px] font-semibold px-2 py-0.5 rounded-full border border-amber-200 dark:border-amber-800">
                Próximamente
              </span>
              <div class="flex flex-col items-center gap-2">
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                <span>WhatsApp</span>
              </div>
            </button>

            <!-- Both Option (Próximamente) -->
            <button
              type="button"
              disabled
              title="Próximamente"
              class="relative px-4 py-3 rounded-lg border-2 border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-gray-400 dark:text-gray-500 text-sm font-medium cursor-not-allowed opacity-70"
            >
              <span class="absolute -top-2 right-2 bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-[10px] font-semibold px-2 py-0.5 rounded-full border border-amber-200 dark:border-amber-800">
                Próximamente
              </span>
              <div class="flex flex-col items-center gap-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                <span>Ambos</span>
              </div>
            </button>
          </div>
          
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
            Selecciona cómo deseas notificar al cliente cuando se cree una factura.
          </p>
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
import { watch } from "vue"
import DayPicker from "@/components/DayPicker.vue"

const props = defineProps({
  active: Boolean,
  billing: Object,
  types: Array
})

// WhatsApp / Ambos are not implemented yet — coerce any legacy value to 'email'.
watch(
  () => props.billing?.notification_type,
  (val) => {
    if (val && val !== 'email') {
      props.billing.notification_type = 'email'
    }
  },
  { immediate: true }
)
</script>
