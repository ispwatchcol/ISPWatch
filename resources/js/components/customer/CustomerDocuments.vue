<template>
  <div class="space-y-8">
    <!-- Subir documentos -->
    <section class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-5">
      <h3 class="text-base font-bold text-gray-800 dark:text-white mb-4">Subir documentos / fotos</h3>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Tipo</label>
          <select v-model="uploadType"
            class="w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="cedula">Cédula / Identificación</option>
            <option value="instalacion">Foto de instalación</option>
            <option value="contrato">Contrato</option>
            <option value="otros">Otros / General</option>
          </select>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Archivos</label>
          <input ref="fileInput" type="file" multiple
            accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx"
            @change="onFilesPicked"
            class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-indigo-600 file:text-white file:cursor-pointer hover:file:bg-indigo-700" />
        </div>
      </div>
      <div v-if="pendingFiles.length" class="mt-4 flex flex-wrap gap-2">
        <span v-for="(f, i) in pendingFiles" :key="i"
          class="inline-flex items-center gap-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300">
          {{ f.name }}
          <button @click="pendingFiles.splice(i, 1)" class="text-rose-500 hover:text-rose-700">&times;</button>
        </span>
      </div>
      <button
        @click="uploadFiles"
        :disabled="!pendingFiles.length || uploading"
        class="mt-4 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
        {{ uploading ? 'Subiendo...' : 'Subir' }}
      </button>
    </section>

    <!-- Lista de documentos -->
    <section>
      <h3 class="text-base font-bold text-gray-800 dark:text-white mb-4">Documentos del cliente</h3>
      <div v-if="loading" class="text-center py-8 text-gray-500 dark:text-gray-400">
        <div class="inline-block animate-spin rounded-full h-7 w-7 border-4 border-indigo-500 border-t-transparent"></div>
      </div>
      <div v-else-if="documents.length === 0" class="text-center py-10 bg-gray-50 dark:bg-gray-900 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 text-gray-500 dark:text-gray-400">
        Aún no hay documentos cargados.
      </div>
      <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
        <div v-for="doc in documents" :key="doc.id"
          class="group relative bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
          <a :href="doc.url" target="_blank" rel="noopener" class="block">
            <div class="h-32 bg-gray-100 dark:bg-gray-900 flex items-center justify-center overflow-hidden">
              <img v-if="isImage(doc)" :src="doc.url" class="object-cover w-full h-full" alt="" />
              <div v-else class="flex flex-col items-center text-gray-400">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                <span class="text-[10px] mt-1 uppercase">{{ ext(doc) }}</span>
              </div>
            </div>
          </a>
          <div class="p-3">
            <div class="flex items-center justify-between mb-1">
              <span :class="typeBadge(doc.type)" class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase">{{ typeLabel(doc.type) }}</span>
              <span v-if="doc.signed" class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold">✓ Firmado</span>
            </div>
            <p class="text-xs text-gray-600 dark:text-gray-300 truncate" :title="doc.file_name">{{ doc.file_name }}</p>
            <button @click="removeDoc(doc)"
              class="mt-2 w-full text-[11px] text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded py-1 transition">
              Eliminar
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- Contrato firmable -->
    <section class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-5">
      <h3 class="text-base font-bold text-gray-800 dark:text-white mb-1">Contrato de servicio</h3>
      <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        El contrato se genera automáticamente con los datos del cliente. Firme abajo y guarde — se generará un PDF firmado.
      </p>

      <div v-if="contract" class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-4 text-sm grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
        <p><span class="text-gray-500 dark:text-gray-400">Cliente:</span> <strong class="text-gray-800 dark:text-white">{{ contract.customer.name }} {{ contract.customer.last_name }}</strong></p>
        <p><span class="text-gray-500 dark:text-gray-400">Cédula:</span> <strong class="text-gray-800 dark:text-white">{{ contract.customer.cedula || '—' }}</strong></p>
        <p><span class="text-gray-500 dark:text-gray-400">Plan:</span> <strong class="text-gray-800 dark:text-white">{{ contract.plan?.name || 'Sin plan' }}</strong></p>
        <p><span class="text-gray-500 dark:text-gray-400">Valor mensual:</span> <strong class="text-gray-800 dark:text-white">${{ Number(contract.plan?.cost_product || 0).toLocaleString('es-CO') }}</strong></p>
      </div>

      <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Firma del cliente</label>
      <div class="bg-white rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 overflow-hidden" style="touch-action: none;">
        <canvas
          ref="canvas"
          width="600"
          height="200"
          class="w-full h-[200px] cursor-crosshair"
          @pointerdown="startDraw"
          @pointermove="draw"
          @pointerup="endDraw"
          @pointerleave="endDraw"
        ></canvas>
      </div>
      <div class="flex flex-wrap gap-3 mt-4">
        <button @click="clearSignature" type="button"
          class="px-4 py-2.5 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-800 dark:text-white text-sm rounded-lg transition">
          Limpiar firma
        </button>
        <button @click="signContract" type="button" :disabled="signing || !hasSignature"
          class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition">
          {{ signing ? 'Generando contrato...' : 'Firmar y guardar contrato' }}
        </button>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue'
import api from '@/services/api'

const props = defineProps({
  customerId: { type: [String, Number], required: true },
})
const emit = defineEmits(['notify'])

const documents = ref([])
const loading = ref(true)
const uploadType = ref('cedula')
const pendingFiles = ref([])
const uploading = ref(false)
const fileInput = ref(null)

const contract = ref(null)
const canvas = ref(null)
const signing = ref(false)
const hasSignature = ref(false)
let ctx = null
let drawing = false

const typeLabel = (t) => ({
  cedula: 'Cédula', instalacion: 'Instalación', contrato: 'Contrato', otros: 'Otros',
}[t] || t)

const typeBadge = (t) => ({
  cedula: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
  instalacion: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  contrato: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
}[t] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300')

const ext = (doc) => (doc.file_name?.split('.').pop() || '').toUpperCase()
const isImage = (doc) => /^image\//.test(doc.mime_type || '') || /\.(jpe?g|png|webp|gif)$/i.test(doc.file_name || '')

const fetchDocuments = async () => {
  loading.value = true
  try {
    const res = await api.customers.getDocuments(props.customerId)
    documents.value = res.data ?? []
  } catch (e) {
    emit('notify', { type: 'error', title: 'Error', message: 'No se pudieron cargar los documentos.' })
  } finally {
    loading.value = false
  }
}

const onFilesPicked = (e) => {
  pendingFiles.value = Array.from(e.target.files || [])
}

const uploadFiles = async () => {
  if (!pendingFiles.value.length) return
  uploading.value = true
  try {
    const fd = new FormData()
    fd.append('type', uploadType.value)
    pendingFiles.value.forEach(f => fd.append('files[]', f))
    await api.customers.uploadDocuments(props.customerId, fd)
    pendingFiles.value = []
    if (fileInput.value) fileInput.value.value = ''
    emit('notify', { type: 'success', title: 'Listo', message: 'Documentos subidos correctamente.' })
    await fetchDocuments()
  } catch (e) {
    emit('notify', { type: 'error', title: 'Error', message: e.response?.data?.message || 'No se pudieron subir los archivos.' })
  } finally {
    uploading.value = false
  }
}

const removeDoc = async (doc) => {
  if (!confirm(`¿Eliminar "${doc.file_name}"? Esta acción no se puede deshacer.`)) return
  try {
    await api.customers.deleteDocument(doc.id)
    documents.value = documents.value.filter(d => d.id !== doc.id)
    emit('notify', { type: 'success', title: 'Eliminado', message: 'Documento eliminado.' })
  } catch (e) {
    emit('notify', { type: 'error', title: 'Error', message: 'No se pudo eliminar el documento.' })
  }
}

// ── Firma (canvas) ──
const setupCanvas = () => {
  const c = canvas.value
  if (!c) return
  ctx = c.getContext('2d')
  ctx.lineWidth = 2.5
  ctx.lineCap = 'round'
  ctx.strokeStyle = '#111827'
}

const pointerPos = (e) => {
  const rect = canvas.value.getBoundingClientRect()
  return {
    x: (e.clientX - rect.left) * (canvas.value.width / rect.width),
    y: (e.clientY - rect.top) * (canvas.value.height / rect.height),
  }
}

const startDraw = (e) => {
  drawing = true
  const { x, y } = pointerPos(e)
  ctx.beginPath()
  ctx.moveTo(x, y)
}
const draw = (e) => {
  if (!drawing) return
  const { x, y } = pointerPos(e)
  ctx.lineTo(x, y)
  ctx.stroke()
  hasSignature.value = true
}
const endDraw = () => { drawing = false }

const clearSignature = () => {
  if (!ctx) return
  ctx.clearRect(0, 0, canvas.value.width, canvas.value.height)
  hasSignature.value = false
}

const signContract = async () => {
  if (!hasSignature.value) return
  signing.value = true
  try {
    const signature = canvas.value.toDataURL('image/png')
    await api.customers.signContract(props.customerId, { signature })
    clearSignature()
    emit('notify', { type: 'success', title: 'Contrato firmado', message: 'El contrato firmado fue generado y guardado.' })
    await fetchDocuments()
  } catch (e) {
    emit('notify', { type: 'error', title: 'Error', message: e.response?.data?.message || 'No se pudo generar el contrato.' })
  } finally {
    signing.value = false
  }
}

const fetchContractData = async () => {
  try {
    const res = await api.customers.getContractData(props.customerId)
    contract.value = res.data
  } catch (e) {
    // non-blocking — la firma sigue funcionando aunque falle el preview
  }
}

onMounted(async () => {
  await fetchDocuments()
  await fetchContractData()
  await nextTick()
  setupCanvas()
})
</script>
