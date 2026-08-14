<template>
  <div>
    <div
      class="bg-white rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 overflow-hidden"
      style="touch-action: none;"
    >
      <canvas
        ref="canvas"
        :width="width"
        :height="height"
        class="w-full cursor-crosshair"
        :style="{ height: height + 'px' }"
        @pointerdown="startDraw"
        @pointermove="draw"
        @pointerup="endDraw"
        @pointerleave="endDraw"
      ></canvas>
    </div>
    <p v-if="hint" class="mt-1.5 text-xs text-gray-400 dark:text-gray-500 text-center">{{ hint }}</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

/**
 * Recuadro de firma trazada a mano. Extraído de CustomerDocuments.vue para que
 * la firma presencial y la remota (PublicContractSign.vue) usen exactamente el
 * mismo trazo: las dos acaban en el MISMO PDF con el mismo valor legal, así que
 * una diferencia sutil entre ambas —la detección de tinta, sobre todo— sería un
 * contrato firmado en blanco según por dónde entró el cliente.
 */
const props = defineProps({
  width:  { type: Number, default: 600 },
  height: { type: Number, default: 200 },
  hint:   { type: String, default: '' },
})

const emit = defineEmits(['change'])

const canvas = ref(null)
let drawing = false

// El contexto se cachea POR ELEMENTO y se obtiene de forma perezosa: el pad se
// monta y desmonta (al eliminar el contrato anterior vuelve a aparecer), y un
// contexto guardado aparte quedaría apuntando a un canvas ya desconectado — el
// trazo se dibujaría donde nadie lo ve y toDataURL() devolvería un PNG
// transparente.
const ctxByCanvas = new WeakMap()

const getCtx = () => {
  const c = canvas.value
  if (!c) return null
  let context = ctxByCanvas.get(c)
  if (!context) {
    context = c.getContext('2d')
    context.lineWidth = 2.5
    context.lineCap = 'round'
    context.lineJoin = 'round'
    context.strokeStyle = '#111827'
    ctxByCanvas.set(c, context)
  }
  return context
}

/** ¿Hay tinta real? Se barre el canal alfa: una bandera reactiva miente si el canvas se re-montó. */
const hasInk = () => {
  const c = canvas.value
  if (!c) return false
  try {
    const { data } = c.getContext('2d').getImageData(0, 0, c.width, c.height)
    for (let i = 3; i < data.length; i += 4) {
      if (data[i] !== 0) return true
    }
  } catch {
    return true
  }
  return false
}

const pointerPos = (e) => {
  const rect = canvas.value.getBoundingClientRect()
  return {
    x: (e.clientX - rect.left) * (canvas.value.width / rect.width),
    y: (e.clientY - rect.top) * (canvas.value.height / rect.height),
  }
}

const startDraw = (e) => {
  const ctx = getCtx()
  if (!ctx) return
  drawing = true
  const { x, y } = pointerPos(e)
  ctx.beginPath()
  ctx.moveTo(x, y)
  // Punto visible también en un toque sin arrastre.
  ctx.lineTo(x + 0.1, y + 0.1)
  ctx.stroke()
  emit('change', true)
}

const draw = (e) => {
  if (!drawing) return
  const ctx = getCtx()
  if (!ctx) return
  const { x, y } = pointerPos(e)
  ctx.lineTo(x, y)
  ctx.stroke()
  emit('change', true)
}

const endDraw = () => { drawing = false }

const clear = () => {
  const ctx = getCtx()
  if (!ctx) return
  ctx.clearRect(0, 0, canvas.value.width, canvas.value.height)
  emit('change', false)
}

const toDataURL = () => canvas.value?.toDataURL('image/png') ?? null

defineExpose({ clear, hasInk, toDataURL })

onMounted(() => { getCtx() })
</script>
