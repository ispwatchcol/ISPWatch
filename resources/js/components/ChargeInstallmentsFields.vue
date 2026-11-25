<template>
  <!-- Acuerdo de pago de un cargo: número de cuotas, cómo se cobran y, si van
       en facturas aparte, cuándo vence la primera. Mismos modos que la cartera
       de la instalación. v-model: { count, mode, first_due_date }. -->
  <div class="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-3 space-y-3"
    data-testid="charge-installments">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div>
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Número de cuotas</label>
        <input :value="modelValue.count" @input="set('count', Number($event.target.value) || 0)"
          type="number" min="1" :max="MAX_CUOTAS" step="1" data-testid="installments-count"
          class="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
      </div>

      <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Cómo se cobran</label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <label v-for="m in MODOS" :key="m.value"
            class="flex items-start gap-2 rounded-lg border p-2 cursor-pointer transition"
            :class="modelValue.mode === m.value
              ? 'border-blue-500 bg-white dark:bg-gray-800'
              : 'border-gray-200 dark:border-gray-600 bg-white/60 dark:bg-gray-800/60'">
            <input type="radio" :value="m.value" :checked="modelValue.mode === m.value" @change="set('mode', m.value)"
              :disabled="lockedMode && lockedMode !== m.value"
              class="mt-0.5 text-blue-600 focus:ring-blue-500" />
            <span>
              <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ m.label }}</span>
              <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ m.help }}</span>
            </span>
          </label>
        </div>
      </div>

      <div v-if="modelValue.mode === 'separate'">
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Vence la 1.ª cuota</label>
        <input :value="modelValue.first_due_date" @input="set('first_due_date', $event.target.value)" type="date"
          class="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">Las demás, mes a mes. Vacío: dentro de un mes.</p>
      </div>
    </div>

    <p v-if="lockedMode" class="text-[11px] text-amber-700 dark:text-amber-400">
      Ya hay cuotas cobradas: el modo no se puede cambiar, y no puede quedar en menos cuotas de las cobradas.
    </p>

    <p v-if="total > 0 && preview.length" class="text-xs text-blue-900 dark:text-blue-200">
      {{ fmt(total) }} en <strong>{{ preview.length }} cuota{{ preview.length === 1 ? '' : 's' }}</strong>
      de {{ fmt(preview[0]) }}<template v-if="preview.length > 1 && preview.at(-1) !== preview[0]"> (la última de {{ fmt(preview.at(-1)) }})</template>,
      {{ modelValue.mode === 'separate' ? 'cada una en su propia factura.' : 'una en cada factura mensual a partir de la próxima.' }}
      El cargo queda saldado y no cuenta para mora ni corte; las cuotas sí, cuando venzan.
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: { type: Object, required: true },
  /** Lo que se va a repartir (para la vista previa). */
  total: { type: Number, default: 0 },
  /** Modo de un plan con cuotas ya cobradas: el otro queda deshabilitado. */
  lockedMode: { type: String, default: null },
})
const emit = defineEmits(['update:modelValue'])

// Mismo tope y mismos modos que CustomerInstallation (backend).
const MAX_CUOTAS = 24
const MODOS = [
  { value: 'monthly',  label: 'Dentro de la mensualidad', help: 'Cada factura mensual trae la siguiente cuota.' },
  { value: 'separate', label: 'Facturas aparte', help: 'Una factura por cuota, con vencimiento mes a mes.' },
]

const set = (campo, valor) => emit('update:modelValue', { ...props.modelValue, [campo]: valor })

// Igual que el reparto del servidor: pesos enteros y la última lleva el resto.
const preview = computed(() => {
  const n = Math.min(Math.max(Number(props.modelValue.count) || 0, 0), MAX_CUOTAS)
  if (!n || props.total <= 0) return []
  const base = Math.floor(props.total / n)
  return [...Array(n - 1).fill(base), Math.round((props.total - base * (n - 1)) * 100) / 100]
})

const fmt = (v) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(v || 0)
</script>
