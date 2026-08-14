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
            <p v-if="doc.contract_number"
              class="text-[11px] font-mono font-semibold text-gray-800 dark:text-white mb-0.5">
              {{ doc.contract_number }}
            </p>
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
        El contrato se genera automáticamente con los datos del cliente. Firme abajo y guarde — se generará un PDF firmado
        con su número consecutivo impreso.
      </p>

      <!-- Un cliente = UN contrato firmado vigente: si ya existe, no se ofrece
           firmar otro (el backend también lo rechaza). -->
      <div v-if="signedContract"
        class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4 text-sm text-amber-800 dark:text-amber-300">
        Este cliente ya tiene un contrato firmado
        <strong>{{ signedContract.contract_number || signedContract.file_name }}</strong>.
        Para generar uno nuevo, elimina primero el anterior en <strong>Documentos del cliente</strong>, arriba.
      </div>

      <template v-else>

      <p v-if="contract?.next_contract_number" class="text-sm mb-4">
        <span class="text-gray-500 dark:text-gray-400">Se numerará como</span>
        <span class="ml-1 font-mono font-semibold text-gray-800 dark:text-white">{{ contract.next_contract_number }}</span>
        <span class="text-gray-400 dark:text-gray-500"> (si otro usuario firma antes, tomará el siguiente).</span>
      </p>

      <div v-if="contract" class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-4 text-sm grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
        <p><span class="text-gray-500 dark:text-gray-400">Cliente:</span> <strong class="text-gray-800 dark:text-white">{{ contract.customer.name }} {{ contract.customer.last_name }}</strong></p>
        <p><span class="text-gray-500 dark:text-gray-400">Cédula:</span> <strong class="text-gray-800 dark:text-white">{{ contract.customer.cedula || '—' }}</strong></p>
        <p><span class="text-gray-500 dark:text-gray-400">Plan:</span> <strong class="text-gray-800 dark:text-white">{{ contract.plan?.name || 'Sin plan' }}</strong></p>
        <p><span class="text-gray-500 dark:text-gray-400">Valor mensual:</span> <strong class="text-gray-800 dark:text-white">${{ Number(contract.plan?.cost_product || 0).toLocaleString('es-CO') }}</strong></p>
      </div>

      <!-- ── Firma remota: mandarle el link al cliente ── -->
      <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-5">
        <h4 class="text-sm font-bold text-gray-800 dark:text-white mb-1">Que lo firme el cliente desde su celular</h4>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
          Genera un enlace personal para que lea y firme el contrato por su cuenta. Vence en 72 horas, sirve una sola
          vez y le pide los últimos 4 dígitos de su cédula.
        </p>

        <div class="flex flex-wrap gap-2">
          <button @click="createLink('email')" :disabled="creatingLink"
            class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition">
            {{ creatingLink === 'email' ? 'Enviando...' : 'Enviar por correo' }}
          </button>
          <button @click="createLink('whatsapp')" :disabled="creatingLink"
            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition">
            {{ creatingLink === 'whatsapp' ? 'Generando...' : 'Enviar por WhatsApp' }}
          </button>
          <button @click="createLink('manual')" :disabled="creatingLink"
            class="px-4 py-2 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-800 dark:text-white text-xs font-medium rounded-lg transition">
            {{ creatingLink === 'manual' ? 'Generando...' : 'Solo copiar enlace' }}
          </button>
        </div>

        <!-- El enlace recién generado. Se muestra SIEMPRE, incluso cuando se
             envió por correo: si el correo no llega, esta es la única copia
             que va a existir (el token no se guarda, sólo su hash). -->
        <div v-if="issuedUrl" class="mt-4 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-lg p-3">
          <p class="text-[11px] font-semibold text-indigo-700 dark:text-indigo-300 uppercase mb-1.5">Enlace generado</p>
          <div class="flex items-center gap-2">
            <input :value="issuedUrl" readonly
              class="flex-1 bg-white dark:bg-gray-800 border border-indigo-200 dark:border-indigo-700 rounded px-2 py-1.5 text-xs font-mono text-gray-700 dark:text-gray-200" />
            <button @click="copyLink" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs rounded transition shrink-0">
              {{ copied ? '¡Copiado!' : 'Copiar' }}
            </button>
          </div>
          <a v-if="issuedWhatsappUrl" :href="issuedWhatsappUrl" target="_blank" rel="noopener"
            class="inline-block mt-2 text-xs text-emerald-700 dark:text-emerald-400 font-medium hover:underline">
            Abrir WhatsApp con el mensaje listo →
          </a>
          <p class="mt-2 text-[11px] text-indigo-600 dark:text-indigo-400">
            Guárdalo si lo vas a mandar por otro medio: por seguridad no se puede volver a consultar.
          </p>
        </div>

        <!-- Historial: sirve para responder "¿ya lo abrió?" sin llamar al cliente. -->
        <div v-if="links.length" class="mt-4 border-t border-gray-100 dark:border-gray-700 pt-3">
          <p class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2">Enlaces enviados</p>
          <div v-for="link in links" :key="link.id"
            class="flex items-center justify-between gap-3 py-1.5 text-xs border-b border-gray-50 dark:border-gray-700/50 last:border-0">
            <div class="min-w-0">
              <span :class="linkBadge(link.status)" class="px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase mr-2">{{ linkLabel(link.status) }}</span>
              <span class="text-gray-500 dark:text-gray-400">{{ formatDate(link.created_at) }}</span>
              <span v-if="link.sent_to" class="text-gray-400 dark:text-gray-500"> · {{ link.sent_to }}</span>
              <span v-if="link.opened_at" class="text-emerald-600 dark:text-emerald-400"> · abierto</span>
            </div>
            <button v-if="link.status === 'pending'" @click="revokeLink(link)"
              class="text-rose-600 dark:text-rose-400 hover:underline shrink-0">Anular</button>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-3 mb-5">
        <div class="flex-1 h-px bg-gray-200 dark:bg-gray-700"></div>
        <span class="text-[11px] text-gray-400 dark:text-gray-500 uppercase font-semibold">o fírmalo aquí mismo</span>
        <div class="flex-1 h-px bg-gray-200 dark:bg-gray-700"></div>
      </div>

      <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Firma del cliente</label>
      <SignaturePad ref="pad" :height="200" @change="hasSignature = $event" />
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

      </template>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import SignaturePad from '@/components/SignaturePad.vue'

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
// Sólo puede haber un contrato firmado vigente por cliente: mientras exista,
// la sección de firma se sustituye por el aviso de eliminar el anterior.
const signedContract = computed(() => documents.value.find(d => d.type === 'contrato' && d.signed))
const pad = ref(null)
const signing = ref(false)
const hasSignature = ref(false)

// ── Firma remota ──
const links = ref([])
const creatingLink = ref('')
const issuedUrl = ref('')
const issuedWhatsappUrl = ref('')
const copied = ref(false)

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

// ── Firma presencial ──
// El recuadro y su detección de tinta viven en SignaturePad.vue, compartido
// con la página pública de firma remota: las dos producen el MISMO PDF, así
// que no pueden diferir en cuándo consideran que hay una firma trazada.
const clearSignature = () => {
  pad.value?.clear()
  hasSignature.value = false
}

const signContract = async () => {
  if (!pad.value?.hasInk()) {
    hasSignature.value = false
    emit('notify', { type: 'error', title: 'Falta firma', message: 'Traza la firma del cliente en el recuadro.' })
    return
  }
  signing.value = true
  try {
    const signature = pad.value.toDataURL()
    const res = await api.customers.signContract(props.customerId, { signature })
    clearSignature()
    const number = res?.data?.document?.contract_number
    emit('notify', {
      type: 'success',
      title: 'Contrato firmado',
      message: number
        ? `Contrato ${number} generado y guardado.`
        : 'El contrato firmado fue generado y guardado.',
    })
    // Recarga también el preview: el consecutivo siguiente ya cambió.
    await Promise.all([fetchDocuments(), fetchContractData()])
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

// ── Firma remota (links) ──

const LINK_LABELS = {
  pending: 'Pendiente', signed: 'Firmado', expired: 'Vencido', revoked: 'Anulado', locked: 'Bloqueado',
}
const linkLabel = (s) => LINK_LABELS[s] || s

const linkBadge = (s) => ({
  pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  signed:  'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  locked:  'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
}[s] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300')

const formatDate = (iso) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
}

const fetchLinks = async () => {
  try {
    const res = await api.customers.getContractLinks(props.customerId)
    links.value = res.data ?? []
  } catch (e) {
    // non-blocking — el historial es informativo, no bloquea generar un link
  }
}

const createLink = async (channel) => {
  creatingLink.value = channel
  issuedUrl.value = ''
  issuedWhatsappUrl.value = ''
  copied.value = false

  try {
    const res = await api.customers.createContractLink(props.customerId, { channel })
    issuedUrl.value = res.data.url
    issuedWhatsappUrl.value = res.data.whatsapp_url || ''

    // El enlace de WhatsApp se abre solo: el operador ya dijo por dónde quiere
    // mandarlo, hacerle dar un segundo clic no aporta nada.
    if (channel === 'whatsapp' && res.data.whatsapp_url) {
      window.open(res.data.whatsapp_url, '_blank', 'noopener')
    }

    emit('notify', {
      type: res.data.mail_error ? 'error' : 'success',
      title: res.data.mail_error ? 'Enlace generado, correo no enviado' : 'Enlace generado',
      message: res.data.message,
    })
    await fetchLinks()
  } catch (e) {
    emit('notify', { type: 'error', title: 'Error', message: e.response?.data?.message || 'No se pudo generar el enlace.' })
  } finally {
    creatingLink.value = ''
  }
}

const copyLink = async () => {
  try {
    await navigator.clipboard.writeText(issuedUrl.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    emit('notify', { type: 'error', title: 'No se pudo copiar', message: 'Selecciona el enlace y cópialo a mano.' })
  }
}

const revokeLink = async (link) => {
  if (!confirm('¿Anular este enlace? El cliente ya no podrá firmar con él.')) return
  try {
    await api.customers.revokeContractLink(link.id)
    emit('notify', { type: 'success', title: 'Anulado', message: 'El enlace quedó sin efecto.' })
    if (issuedUrl.value) { issuedUrl.value = ''; issuedWhatsappUrl.value = '' }
    await fetchLinks()
  } catch (e) {
    emit('notify', { type: 'error', title: 'Error', message: e.response?.data?.message || 'No se pudo anular el enlace.' })
  }
}

onMounted(async () => {
  await fetchDocuments()
  await Promise.all([fetchContractData(), fetchLinks()])
})
</script>
