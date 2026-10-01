<template>
  <div ref="root" class="space-y-2">
    <div>
      <p class="text-[11px] font-medium uppercase text-gray-600 dark:text-gray-300">Equipos con serial (una unidad concreta)</p>
      <p class="text-[11px] text-gray-500 dark:text-gray-400">
        Elige la unidad exacta: su serial y su MAC quedan en la línea. Sólo aparecen las unidades que tú puedes
        registrar en {{ context }}.
      </p>
    </div>

    <!-- Filtros: por modelo y por serial/MAC. Se ven siempre que haya algo que
         filtrar, o un modelo ya elegido desde «Elegir serial». -->
    <div v-if="devices.length || model" class="flex flex-wrap items-center gap-2">
      <select :value="model" @change="setModel($event.target.value)" :disabled="busy"
        aria-label="Filtrar por modelo"
        class="flex-1 min-w-[10rem] bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm disabled:opacity-50">
        <option value="">Todos los modelos ({{ devices.length }} a tu alcance)</option>
        <option v-for="m in models" :key="m.stock_id" :value="String(m.stock_id)">
          {{ m.label }} ({{ m.count }} a tu alcance)
        </option>
      </select>
      <input v-model="search" type="search" placeholder="Buscar por serial o MAC" :disabled="busy"
        aria-label="Buscar por serial o MAC"
        class="flex-1 min-w-[10rem] bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm disabled:opacity-50" />
    </div>

    <div v-if="filtered.length" class="flex flex-wrap items-center gap-2">
      <select ref="unitSelect" v-model.number="pick" :disabled="busy" aria-label="Unidad con serial"
        class="flex-1 min-w-[14rem] bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm disabled:opacity-50">
        <option :value="null">Elige la unidad… ({{ filtered.length }} a tu alcance)</option>
        <optgroup v-for="group in groups" :key="group.key" :label="group.label">
          <option v-for="d in group.items" :key="d.id" :value="d.id">{{ deviceFullLabel(d) }}</option>
        </optgroup>
      </select>
      <!-- Registrar es siempre un gesto aparte: elegir en la lista no descuenta nada. -->
      <button type="button" @click="confirm" :disabled="!pickedDevice || busy"
        class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-xs font-medium px-4 py-2 rounded-lg transition">
        {{ actionLabel }}
      </button>
    </div>
    <p v-if="pickedDevice" class="text-[11px] text-gray-600 dark:text-gray-300">
      Vas a registrar {{ deviceFullLabel(pickedDevice) }}, de {{ pickedDevice.source_label || 'inventario' }}.
      Pulsa «{{ actionLabel }}» para confirmarlo.
    </p>

    <!-- Vacíos: se dice por qué y cuál es el siguiente paso, sin nombrar
         unidades ni custodios que este usuario no puede consultar. -->
    <p v-if="emptyReason" data-testid="serial-empty"
      class="text-xs text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2">
      {{ emptyReason }}
      <template v-if="!search.trim() || !modelDevices.length"> {{ nextStep }}</template>
    </p>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { deviceFullLabel, deviceMatchesSearch, deviceModelText } from '@/utils/deviceLabels'

const props = defineProps({
  /** Unidades que ESTE usuario puede registrar, tal como las da /equipment/available. */
  devices: { type: Array, default: () => [] },
  /** Filtro de modelo (stock_id) o null. v-model:model. */
  model: { type: Number, default: null },
  /** Modelos conocidos aunque no haya unidades a mano (p. ej. los del plan), para nombrarlos. */
  knownModels: { type: Array, default: () => [] },
  loaded: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  actionLabel: { type: String, default: 'Agregar' },
  context: { type: String, default: 'esta orden' },
  nextStep: {
    type: String,
    default: 'Siguiente paso: pide que te entreguen la unidad en Inventario → Entregas, o que la registre quien la tenga asignada.',
  },
})

const emit = defineEmits(['update:model', 'pick'])

const root = ref(null)
const unitSelect = ref(null)
const search = ref('')
const pick = ref(null)

const setModel = (value) => emit('update:model', value === '' ? null : Number(value))

// Modelos con unidades a tu alcance, y el del filtro aunque no tenga ninguna:
// así «Elegir serial» de un modelo sin unidades muestra el vacío explicado.
const models = computed(() => {
  const map = new Map()
  for (const d of props.devices) {
    if (!map.has(d.stock_id)) map.set(d.stock_id, { stock_id: d.stock_id, label: deviceModelText(d), count: 0 })
    map.get(d.stock_id).count++
  }
  if (props.model != null && !map.has(props.model)) {
    map.set(props.model, { stock_id: props.model, label: modelLabel.value, count: 0 })
  }
  return [...map.values()].sort((a, b) => a.label.localeCompare(b.label))
})

const modelLabel = computed(() => {
  if (props.model == null) return ''
  const fromDevices = props.devices.find(d => d.stock_id === props.model)
  if (fromDevices) return deviceModelText(fromDevices)
  return props.knownModels.find(m => m.stock_id === props.model)?.label || 'el modelo elegido'
})

const modelDevices = computed(() =>
  props.model == null ? props.devices : props.devices.filter(d => d.stock_id === props.model)
)
const filtered = computed(() => modelDevices.value.filter(d => deviceMatchesSearch(d, search.value)))

const groups = computed(() => {
  const map = new Map()
  for (const d of filtered.value) {
    const key = `${d.source_type}:${d.source_id}`
    if (!map.has(key)) map.set(key, { key, label: d.source_label || 'Inventario', items: [] })
    map.get(key).items.push(d)
  }
  return [...map.values()]
})

const pickedDevice = computed(() => filtered.value.find(d => d.id === pick.value) ?? null)

// Si el filtro deja fuera la unidad elegida, se suelta: nunca se registra algo
// que ya no se está viendo.
watch(filtered, () => { if (pick.value != null && !pickedDevice.value) pick.value = null })

const emptyReason = computed(() => {
  if (!props.loaded) return ''
  if (!props.devices.length) return `No tienes a tu alcance ninguna unidad con serial para registrar en ${props.context}.`
  if (!modelDevices.value.length) return `No tienes a tu alcance ninguna unidad de ${modelLabel.value}.`
  if (!filtered.value.length) {
    return `Ninguna unidad${props.model != null ? ` de ${modelLabel.value}` : ''} coincide con «${search.value.trim()}» en su serial o su MAC.`
  }
  return ''
})

const confirm = () => {
  const device = pickedDevice.value
  if (!device || props.busy) return
  pick.value = null
  // La búsqueda era para ESTA unidad: se limpia para que la siguiente no
  // arranque con la lista vacía.
  search.value = ''
  emit('pick', device)
}

/** Lleva la vista al selector y le da el foco. No elige ni registra nada. */
const focus = async () => {
  search.value = ''
  await nextTick()
  root.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  unitSelect.value?.focus({ preventScroll: true })
}

defineExpose({ focus })
</script>
