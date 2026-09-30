<template>
  <div>
    <!-- Selector: productos del inventario del tenant, separados en equipos
         (se eligen por serial al usarlos) y materiales (se descuentan por
         cantidad). La cifra es la disponibilidad agregada de toda la empresa,
         y se dice así: no es lo que quien planifica puede registrar. -->
    <div v-if="!disabled" class="flex flex-wrap items-center gap-2">
      <select v-model="pick" :disabled="!catalog.length"
        class="flex-1 min-w-[12rem] bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm disabled:opacity-50">
        <option :value="null">{{ catalog.length ? '+ Agregar producto del inventario…' : (loading ? 'Cargando inventario…' : 'No hay productos en el inventario') }}</option>
        <optgroup v-if="materials.length" label="Materiales (por cantidad)">
          <option v-for="p in materials" :key="p.id" :value="p.id">{{ p.label }} — {{ availableText(p) }}</option>
        </optgroup>
        <optgroup v-if="devices.length" label="Equipos por serial (la unidad se elige al registrar)">
          <option v-for="p in devices" :key="p.id" :value="p.id">{{ p.label }} — {{ availableText(p) }}</option>
        </optgroup>
      </select>
      <input v-model.number="qty" type="number" :min="pickedProduct?.is_serialized ? 1 : 0.01"
        :step="pickedProduct?.is_serialized ? 1 : 0.01" placeholder="Cant." onwheel="this.blur()"
        class="w-24 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
      <span v-if="pickedProduct" class="text-xs text-gray-500 dark:text-gray-400">{{ unitOf(pickedProduct) }}</span>
      <button type="button" @click="add" :disabled="!pick || !(qty > 0)"
        class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-xs font-medium px-4 py-2 rounded-lg transition">
        Agregar
      </button>
    </div>
    <p v-if="loadError" class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ loadError }}</p>

    <ul v-if="modelValue.length" class="mt-2 space-y-2">
      <li v-for="(line, idx) in modelValue" :key="line.id ?? `n-${line.stock_id}-${idx}`"
        class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2">
        <div class="flex flex-wrap items-center gap-2">
          <div class="flex-1 min-w-[10rem]">
            <p class="text-sm text-gray-800 dark:text-white">
              {{ line.label }}
              <span class="ml-1 text-[10px] uppercase px-1.5 py-0.5 rounded"
                :class="line.is_serialized
                  ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'
                  : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'">
                {{ line.is_serialized ? 'Equipo' : 'Material' }}
              </span>
            </p>
            <p v-if="line.is_serialized" class="text-[11px] text-purple-700 dark:text-purple-300">
              Aquí se planifica el modelo y la cantidad. La unidad concreta (serial y MAC) se elige en la hoja de
              la orden al registrar la entrega.
            </p>
            <p v-if="!line.stock_id" class="text-[11px] text-gray-500 dark:text-gray-400">
              El producto ya no existe en el inventario; se conserva lo que se planificó.
            </p>
            <p v-else-if="productOf(line)" class="text-[11px] text-gray-500 dark:text-gray-400">
              Para planificar: {{ availableText(productOf(line)) }}
              <template v-if="canViewDetails && productOf(line).holders?.length"> · Dónde hay: {{ holdersText(productOf(line)) }}</template>
            </p>
          </div>
          <input :value="line.quantity" @input="setField(idx, 'quantity', Number($event.target.value))"
            type="number" :min="line.is_serialized ? 1 : 0.01" :step="line.is_serialized ? 1 : 0.01"
            :disabled="disabled" onwheel="this.blur()"
            class="w-24 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-2 py-1.5 text-gray-800 dark:text-white text-sm disabled:opacity-60" />
          <span class="text-xs text-gray-500 dark:text-gray-400 w-12">{{ line.unit || (line.is_serialized ? 'und.' : '') }}</span>
          <button v-if="!disabled" type="button" @click="remove(idx)" title="Quitar del plan"
            class="shrink-0 text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 px-2 py-1 rounded-lg transition text-sm">
            ✕
          </button>
        </div>
        <input :value="line.notes || ''" @input="setField(idx, 'notes', $event.target.value)" :disabled="disabled"
          type="text" maxlength="255" placeholder="Nota (opcional)"
          class="mt-1.5 w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-2 py-1 text-gray-700 dark:text-gray-200 text-xs disabled:opacity-60" />
        <p v-if="exceeds(line)" class="mt-1 text-[11px] text-amber-700 dark:text-amber-400">
          Supera lo disponible en la empresa ({{ fmtQty(productOf(line).available) }}{{ line.unit ? ` ${line.unit}` : '' }}).
          Se puede planificar igual: no reserva nada, y al usarlo se valida el saldo de quien lo aporte.
        </p>
      </li>
    </ul>
    <p v-else class="mt-2 text-xs text-gray-400 dark:text-gray-500">Sin productos planificados.</p>

    <p v-if="disabled && disabledReason" class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ disabledReason }}</p>
    <p class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
      Las cifras son lo disponible en toda la empresa, para planificar; no son lo que tú puedes registrar.
      Planificar no descuenta ni reserva inventario. Lo que realmente se usa se registra en la hoja de la
      orden, con lo que tenga a su alcance quien la registra, y eso sí descuenta.
    </p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import installationEquipmentApi from '@/services/api/installation-equipment'

/**
 * Plan previsto de una orden de instalación: productos del inventario del
 * tenant con cantidad, unidad y disponibilidad.
 *
 * Sólo edita la lista: quien la guarda es el formulario de la orden (va en
 * `planned_items`). Las líneas ya guardadas conservan su `id`, y así el
 * servidor mantiene la etiqueta y la unidad con que se planificaron aunque el
 * producto se haya renombrado después.
 */
const props = defineProps({
  modelValue:     { type: Array, default: () => [] },
  disabled:       { type: Boolean, default: false },
  disabledReason: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const catalog        = ref([])
const canViewDetails = ref(false)
const loading        = ref(false)
const loadError      = ref('')
const pick           = ref(null)
const qty            = ref(1)

const materials = computed(() => catalog.value.filter(p => !p.is_serialized))
const devices   = computed(() => catalog.value.filter(p => p.is_serialized))
const pickedProduct = computed(() => catalog.value.find(p => p.id === pick.value) ?? null)

const fmtQty = (n) => {
  const value = Number(n) || 0
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace('.', ',')
}

const unitOf = (p) => p.unit || (p.is_serialized ? 'und.' : '')

const availableText = (p) => (Number(p.available) > 0
  ? `${fmtQty(p.available)}${unitOf(p) ? ` ${unitOf(p)}` : ''} en la empresa`
  : 'sin existencias en la empresa')

const holdersText = (p) => (p.holders ?? [])
  .map(h => `${h.label} ${fmtQty(h.quantity)}${p.unit ? ` ${p.unit}` : ''}`)
  .join(' · ')

const productOf = (line) => (line.stock_id ? catalog.value.find(p => p.id === line.stock_id) : null)

// Suma de todas las líneas del mismo producto: dos líneas de 20 m contra 30 m
// disponibles también superan.
const exceeds = (line) => {
  const product = productOf(line)
  if (!product) return false
  const planned = props.modelValue
    .filter(l => l.stock_id === line.stock_id)
    .reduce((sum, l) => sum + (Number(l.quantity) || 0), 0)
  return planned > Number(product.available || 0)
}

const emitLines = (lines) => emit('update:modelValue', lines)

const add = () => {
  const product = pickedProduct.value
  const quantity = Number(qty.value) || 0
  if (!product || quantity <= 0) return

  const lines = props.modelValue.map(l => ({ ...l }))
  const existing = lines.find(l => l.stock_id === product.id)

  // El mismo producto dos veces se suma en una sola línea: el plan se lee
  // mejor, y la línea ya guardada conserva su etiqueta congelada.
  if (existing) {
    existing.quantity = (Number(existing.quantity) || 0) + quantity
  } else {
    lines.push({
      id: null,
      stock_id: product.id,
      label: product.label,
      unit: product.unit,
      is_serialized: product.is_serialized,
      quantity: product.is_serialized ? Math.round(quantity) : quantity,
      notes: '',
    })
  }

  emitLines(lines)
  pick.value = null
  qty.value = 1
}

const remove = (idx) => {
  const lines = props.modelValue.map(l => ({ ...l }))
  lines.splice(idx, 1)
  emitLines(lines)
}

const setField = (idx, field, value) => {
  const lines = props.modelValue.map(l => ({ ...l }))
  lines[idx][field] = value
  emitLines(lines)
}

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await installationEquipmentApi.planningCatalog()
    catalog.value = data?.products ?? []
    canViewDetails.value = !!data?.can_view_details
  } catch {
    loadError.value = 'No se pudo cargar el inventario para planificar. Puedes anotar el equipo en el texto libre.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
