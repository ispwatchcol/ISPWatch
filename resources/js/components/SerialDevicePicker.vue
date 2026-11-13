<template>
  <div ref="root" class="space-y-2">
    <div>
      <p class="text-[11px] font-medium uppercase text-gray-600 dark:text-gray-300">Equipos con serial (una unidad concreta)</p>
      <p class="text-[11px] text-gray-500 dark:text-gray-400">
        Elige la unidad exacta: su serial y su MAC quedan en la línea. Sólo aparecen las unidades que tú puedes
        registrar en {{ context }}.
      </p>
    </div>

    <!-- Un botón que abre el buscador: se escribe el serial, la MAC o el
         modelo y se elige. Antes eran dos listas desplegables y un filtro. -->
    <div v-if="devices.length || model != null" class="flex flex-wrap items-center gap-2">
      <button ref="openButton" type="button" @click="openPicker" :disabled="busy || !modelDevices.length"
        data-testid="serial-search"
        class="flex-1 min-w-[14rem] inline-flex items-center gap-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:border-blue-400 rounded-lg px-3 py-2 text-sm text-gray-700 dark:text-gray-200 disabled:opacity-50 transition">
        <span aria-hidden="true">🔍</span>
        Buscar por serial, MAC o modelo ({{ modelDevices.length }} a tu alcance)
      </button>
      <span v-if="model != null"
        class="inline-flex items-center gap-1 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-200 rounded-full pl-2.5 pr-1 py-1">
        Sólo {{ modelLabel }}
        <button type="button" @click="setModel('')" :disabled="busy" aria-label="Ver todos los modelos"
          class="px-1.5 rounded-full hover:bg-blue-200 dark:hover:bg-blue-800">✕</button>
      </span>
    </div>

    <!-- Registrar es siempre un gesto aparte: elegir en el buscador no descuenta nada. -->
    <div v-if="pickedDevice" data-testid="serial-picked"
      class="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50/60 dark:bg-blue-900/20 p-3">
      <p class="text-[11px] text-gray-600 dark:text-gray-300">
        Vas a registrar {{ deviceFullLabel(pickedDevice) }}, de {{ pickedDevice.source_label || 'inventario' }}.
        Pulsa «{{ actionLabel }}» para confirmarlo.
      </p>
      <div class="mt-2 flex flex-wrap items-center gap-2">
        <button type="button" @click="confirm" :disabled="busy || disabled"
          class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-xs font-medium px-4 py-2 rounded-lg transition">
          {{ actionLabel }}
        </button>
        <button type="button" @click="pick = null" :disabled="busy"
          class="text-xs text-gray-500 dark:text-gray-400 hover:underline px-1">Cambiar</button>
      </div>
    </div>

    <!-- Vacíos: se dice por qué y cuál es el siguiente paso, sin nombrar
         unidades ni custodios que este usuario no puede consultar. -->
    <p v-if="emptyReason" data-testid="serial-empty"
      class="text-xs text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2">
      {{ emptyReason }} {{ nextStep }}
    </p>

    <SearchPickerModal :visible="pickerOpen" title="Elegir equipo con serial"
      placeholder="Serial, MAC o modelo (no importan los dos puntos ni las mayúsculas)"
      :items="pickerItems" empty-text="No hay unidades a tu alcance."
      @select="onPicked" @close="pickerOpen = false" />
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import SearchPickerModal from '@/components/ui/SearchPickerModal.vue'
import { deviceFullLabel, deviceIdsText, deviceModelText } from '@/utils/deviceLabels'

const props = defineProps({
  /** Unidades que ESTE usuario puede registrar, tal como las da /equipment/available. */
  devices: { type: Array, default: () => [] },
  /** Filtro de modelo (stock_id) o null. v-model:model. */
  model: { type: Number, default: null },
  /** Modelos conocidos aunque no haya unidades a mano (p. ej. los del plan), para nombrarlos. */
  knownModels: { type: Array, default: () => [] },
  loaded: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  /** Bloquea el registro (p. ej. falta el motivo de un registro posterior a la firma). */
  disabled: { type: Boolean, default: false },
  actionLabel: { type: String, default: 'Agregar' },
  context: { type: String, default: 'esta orden' },
  nextStep: {
    type: String,
    default: 'Siguiente paso: pide que te entreguen la unidad en Inventarios → Entregas y traspasos, o que la registre quien la tenga asignada.',
  },
})

const emit = defineEmits(['update:model', 'pick'])

const root = ref(null)
const openButton = ref(null)
const pick = ref(null)
const pickerOpen = ref(false)

const setModel = (value) => emit('update:model', value === '' ? null : Number(value))

const modelLabel = computed(() => {
  if (props.model == null) return ''
  const fromDevices = props.devices.find(d => d.stock_id === props.model)
  if (fromDevices) return deviceModelText(fromDevices)
  return props.knownModels.find(m => m.stock_id === props.model)?.label || 'el modelo elegido'
})

const modelDevices = computed(() =>
  props.model == null ? props.devices : props.devices.filter(d => d.stock_id === props.model)
)

const pickerItems = computed(() => modelDevices.value.map(d => ({
  key: d.id,
  title: deviceModelText(d),
  subtitle: deviceIdsText(d),
  meta: d.source_label || 'Inventario',
  // Serial y MAC también sin prefijos, para que «48575443» y «aabbcc» encuentren.
  search: [d.serial, d.mac].filter(Boolean).join(' '),
})))

const pickedDevice = computed(() => modelDevices.value.find(d => d.id === pick.value) ?? null)

// Si el filtro o la recarga dejan fuera la unidad elegida, se suelta: nunca
// se registra algo que ya no se está viendo.
watch(modelDevices, () => { if (pick.value != null && !pickedDevice.value) pick.value = null })

const emptyReason = computed(() => {
  if (!props.loaded) return ''
  if (!props.devices.length) return `No tienes a tu alcance ninguna unidad con serial para registrar en ${props.context}.`
  if (!modelDevices.value.length) return `No tienes a tu alcance ninguna unidad de ${modelLabel.value}.`
  return ''
})

const openPicker = () => {
  if (props.busy || !modelDevices.value.length) return
  pickerOpen.value = true
}

const onPicked = (item) => {
  pick.value = item.key
  pickerOpen.value = false
}

const confirm = () => {
  const device = pickedDevice.value
  if (!device || props.busy || props.disabled) return
  pick.value = null
  emit('pick', device)
}

/**
 * Lleva la vista al selector y abre el buscador (ya filtrado por el modelo,
 * si se llegó desde «Elegir serial»). No elige ni registra nada.
 */
const focus = async () => {
  await nextTick()
  root.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  if (modelDevices.value.length) {
    pickerOpen.value = true
  } else {
    openButton.value?.focus({ preventScroll: true })
  }
}

defineExpose({ focus })
</script>
