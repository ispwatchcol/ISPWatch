<template>
  <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
    <div class="p-4 md:p-6 border-b border-gray-200 dark:border-gray-700">
      <div class="flex items-center gap-3">
        <div class="p-2 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg">
          <v-icon name="md-description" class="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
        </div>
        <div>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Plantillas de Documentos</h3>
          <p class="text-sm text-gray-600 dark:text-gray-400">
            Personaliza factura, contrato y hoja de instalación. Si no personalizas nada, se sigue usando la plantilla base del sistema.
          </p>
        </div>
      </div>
    </div>

    <div class="p-4 md:p-6 space-y-8">
      <!-- ══ BRANDING ══ -->
      <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-4 md:p-5">
        <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
          <v-icon name="md-palette" class="w-4 h-4" /> Marca en los documentos
        </h4>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div>
            <label class="label">Logo</label>
            <div class="flex items-center gap-3">
              <div class="w-16 h-16 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 flex items-center justify-center overflow-hidden shrink-0">
                <img v-if="logoUrl" :src="logoUrl" alt="Logo" class="max-w-full max-h-full object-contain" />
                <v-icon v-else name="bi-images" class="w-6 h-6 text-gray-300 dark:text-gray-600" />
              </div>
              <div>
                <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="hidden" @change="handleLogoChange" />
                <button
                  type="button"
                  :disabled="uploadingLogo"
                  @click="$refs.logoInput.click()"
                  class="text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-3 py-1.5 rounded-lg transition-all disabled:opacity-50"
                >
                  {{ uploadingLogo ? 'Subiendo...' : 'Cambiar logo' }}
                </button>
                <p class="text-xs text-gray-400 mt-1">PNG, JPG, WEBP o SVG. Máx 2MB.</p>
              </div>
            </div>
          </div>

          <div>
            <label class="label">Color de marca</label>
            <div class="flex items-center gap-2">
              <input type="color" v-model="colorSwatch" class="w-10 h-10 rounded-lg border border-gray-300 dark:border-gray-600 cursor-pointer bg-transparent" />
              <input type="text" v-model="branding.brand_color" placeholder="#1e5fa8" class="input flex-1" maxlength="7" />
            </div>
          </div>

          <div>
            <label class="label">Texto de pie de página</label>
            <textarea v-model="branding.document_footer_text" rows="2" class="input resize-none" placeholder="Ej: Empresa vigilada por la Superintendencia..."></textarea>
          </div>

          <div>
            <label class="label">Prefijo del consecutivo de contratos</label>
            <input
              type="text"
              v-model="branding.contract_prefix"
              placeholder="CTR"
              maxlength="20"
              class="input"
            />
            <p class="text-xs text-gray-400 mt-1">
              Cada contrato firmado recibe un número consecutivo irrepetible.
              Próximo:
              <span class="font-mono text-gray-500 dark:text-gray-300">{{ nextContractNumberPreview }}</span>.
              Escribe el prefijo que quieras (<span class="font-mono">CNO/</span>,
              <span class="font-mono">Contrato N°&nbsp;</span>, <span class="font-mono">FIBRA_2026-</span>).
              El guion se agrega solo si terminas en letra o número; si lo dejas vacío se usa
              <span class="font-mono">CTR</span>.
            </p>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button
            type="button"
            :disabled="savingBranding"
            @click="saveBranding"
            class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition-all disabled:opacity-50 flex items-center gap-2"
          >
            <v-icon v-if="savingBranding" name="bi-arrow-repeat" class="w-4 h-4 animate-spin" />
            {{ savingBranding ? 'Guardando...' : 'Guardar marca' }}
          </button>
        </div>
      </div>

      <!-- ══ TIPO DE DOCUMENTO ══ -->
      <div>
        <div class="flex gap-2 border-b border-gray-200 dark:border-gray-700 mb-5">
          <button
            v-for="t in documentTypes"
            :key="t.id"
            @click="selectType(t.id)"
            :class="[
              'px-4 py-2.5 text-sm font-medium border-b-2 transition-all -mb-px',
              activeType === t.id
                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'
            ]"
          >
            {{ t.label }}
          </button>
        </div>

        <div v-if="loadingType" class="py-12 text-center text-gray-400">
          <v-icon name="bi-arrow-repeat" class="w-6 h-6 animate-spin mx-auto mb-2" />
          Cargando...
        </div>

        <div v-else class="space-y-4">
          <!-- Estado -->
          <div class="flex flex-wrap items-center gap-3">
            <span
              :class="[
                'text-xs font-medium px-2.5 py-1 rounded-full',
                current.is_active
                  ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                  : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
              ]"
            >
              {{ current.is_active ? 'Plantilla personalizada activa' : 'Usando la plantilla base del sistema' }}
            </span>
            <span v-if="current.has_draft && !current.is_active" class="text-xs text-gray-400">
              (tienes un borrador guardado sin activar)
            </span>
          </div>

          <!-- Aviso especial para contrato -->
          <div v-if="activeType === 'contract'" class="text-sm bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 rounded-lg p-3">
            Las cláusulas 1 a 3.5 del contrato (datos del cliente, plan, condiciones generales) son fijas y no se editan aquí.
            Lo que escribas abajo se agrega como <strong>"4. Condiciones Adicionales del Proveedor"</strong>, después de esas cláusulas.
          </div>

          <!-- Placeholders escalares -->
          <div>
            <label class="label">Placeholders disponibles (click para insertar)</label>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="(label, token) in current.placeholders"
                :key="token"
                type="button"
                :title="label"
                @click="insertPlaceholder(token)"
                class="text-xs font-mono bg-indigo-50 dark:bg-indigo-900/20 hover:bg-indigo-100 dark:hover:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 px-2 py-1 rounded-md transition-colors"
              >
                {{ placeholderToken(token) }}
              </button>
            </div>
          </div>

          <!-- Placeholders de bloque: insertan contenido de servidor (tabla,
               imágenes), no texto — por eso son tarjetas grandes y distintas,
               no pastillas como las de arriba. Se insertan siempre en su
               propio párrafo (ver insertBlockPlaceholder), nunca donde el
               cursor esté parado, para que nunca queden a mitad de una
               oración o dentro de un atributo. -->
          <div v-if="Object.keys(current.block_placeholders || {}).length">
            <label class="label">Bloques de contenido (se insertan en su propio párrafo)</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <button
                v-for="(label, token) in current.block_placeholders"
                :key="token"
                type="button"
                @click="insertBlockPlaceholder(token)"
                class="text-left flex items-start gap-2 text-xs bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100 dark:hover:bg-amber-900/40 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 px-3 py-2 rounded-lg transition-colors"
              >
                <v-icon :name="blockIcon(token)" class="w-4 h-4 mt-0.5 shrink-0" />
                <span>
                  <span class="font-mono font-semibold block">{{ placeholderToken(token) }}</span>
                  <span class="opacity-80">{{ label }}</span>
                </span>
              </button>
            </div>
          </div>

          <!-- Modo avanzado: HTML/CSS completo, sin shell fijo. V1 mínima
               a propósito (textarea, sin editor visual) — se mejora después
               si hace falta; lo que no se negocia es la sanitización server-
               side (AdvancedTemplateSanitizer), no el pulido del editor. -->
          <div class="flex items-center gap-2">
            <input
              id="advanced-mode-toggle"
              type="checkbox"
              v-model="current.is_advanced_mode"
              class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
            />
            <label for="advanced-mode-toggle" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
              Modo avanzado (HTML/CSS completo, sin plantilla base fija)
            </label>
          </div>
          <div v-if="current.is_advanced_mode" class="text-sm bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 rounded-lg p-3">
            Editas el documento completo (&lt;html&gt;&lt;style&gt;&lt;body&gt;). Se sigue saneando en el servidor
            (sin &lt;script&gt;, sin atributos on-*, sin url()/@import en CSS) — no todo lo que escribas va a sobrevivir.
            Usa "Vista previa" para confirmar antes de guardar.
          </div>

          <!-- Editor -->
          <div class="flex-1 flex flex-col min-h-[280px]">
            <label class="label">
              {{ activeType === 'contract' ? 'Condiciones adicionales' : 'Contenido' }}
            </label>
            <textarea
              v-if="current.is_advanced_mode"
              v-model="draftHtml"
              spellcheck="false"
              class="flex-1 min-h-[280px] font-mono text-xs bg-white dark:bg-gray-900 dark:text-white border border-gray-300 dark:border-gray-600 rounded-b-xl p-3 resize-y"
              placeholder="<html><head><style>...</style></head><body>...</body></html>"
            ></textarea>
            <QuillEditor
              v-else
              ref="quillRef"
              v-model:content="draftHtml"
              contentType="html"
              :toolbar="quillToolbar"
              theme="snow"
              class="bg-white dark:bg-gray-900 dark:text-white rounded-b-xl flex-1"
            />
          </div>

          <!-- Acciones -->
          <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
            <button
              type="button"
              :disabled="!current.has_draft || resetting"
              @click="showResetModal = true"
              class="text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 px-4 py-2 rounded-lg transition-all disabled:opacity-40 disabled:cursor-not-allowed"
            >
              Restaurar plantilla base
            </button>
            <button
              type="button"
              :disabled="previewing"
              @click="preview"
              class="text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-4 py-2 rounded-lg transition-all disabled:opacity-50 flex items-center gap-2"
            >
              <v-icon v-if="previewing" name="bi-arrow-repeat" class="w-4 h-4 animate-spin" />
              {{ previewing ? 'Generando...' : 'Vista previa' }}
            </button>
            <button
              type="button"
              :disabled="saving || !draftHtml || !draftHtml.trim()"
              @click="save"
              class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg transition-all disabled:opacity-50 flex items-center gap-2"
            >
              <v-icon v-if="saving" name="bi-arrow-repeat" class="w-4 h-4 animate-spin" />
              {{ saving ? 'Guardando...' : 'Guardar y activar' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <ConfirmModal
      :visible="showResetModal"
      variant="warning"
      title="Restaurar plantilla base"
      message="El documento volverá a generarse con la plantilla base del sistema. Tu borrador actual NO se borra: puedes volver a activarlo guardando de nuevo."
      confirm-text="Sí, restaurar"
      cancel-text="Cancelar"
      :loading="resetting"
      @confirm="confirmReset"
      @cancel="showResetModal = false"
    />

    <NotificationToast ref="toast" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'
import NotificationToast from '@/components/NotificationToast.vue'
import { documentTemplatesApi, tenantApi } from '@/services/api'

const documentTypes = [
  { id: 'invoice', label: 'Factura' },
  { id: 'contract', label: 'Contrato' },
  { id: 'installation', label: 'Hoja de Instalación' },
]

const quillToolbar = [
  [{ header: [1, 2, 3, 4, false] }],
  ['bold', 'italic', 'underline'],
  [{ list: 'ordered' }, { list: 'bullet' }],
  [{ color: [] }, { background: [] }],
  ['link'],
  ['clean'],
]

const toast = ref(null)
const quillRef = ref(null)
const logoInput = ref(null)

const activeType = ref('invoice')
const loadingType = ref(false)
const saving = ref(false)
const resetting = ref(false)
const previewing = ref(false)
const showResetModal = ref(false)

const draftHtml = ref('')
const current = reactive({
  is_active: false,
  is_advanced_mode: false,
  has_draft: false,
  placeholders: {},
  block_placeholders: {},
})

// ── Branding ──
const logoUrl = ref(null)
const uploadingLogo = ref(false)
const savingBranding = ref(false)
const branding = reactive({ brand_color: '', document_footer_text: '', contract_prefix: '' })
// Espejo de App\Services\ContractNumberService::format(): solo para mostrar
// cómo quedará el número mientras se escribe el prefijo. El número real lo
// reserva el backend al firmar.
// El prefijo es libre; el guion se añade solo si termina en letra o dígito,
// para no estropear un separador propio como "CNO/" o "Contrato N° ".
const nextContractNumber = ref(1)
const nextContractNumberPreview = computed(() => {
  const raw = (branding.contract_prefix || '').replace(/^\s+/, '')
  const prefix = raw.trim() === '' ? 'CTR' : raw
  const separator = /[\p{L}\p{N}]$/u.test(prefix) ? '-' : ''
  return `${prefix}${separator}${String(nextContractNumber.value).padStart(5, '0')}`
})
// <input type="color"> rejects an empty string ("" does not conform to
// #rrggbb"); the text field next to it can still be genuinely empty to mean
// "no custom color set", so the native swatch gets its own fallback view.
const colorSwatch = computed({
  get: () => branding.brand_color || '#1e5fa8',
  set: (val) => { branding.brand_color = val },
})

async function loadType(type) {
  loadingType.value = true
  try {
    const { data } = await documentTemplatesApi.show(type)
    current.is_active = data.is_active
    current.is_advanced_mode = data.is_advanced_mode || false
    current.has_draft = data.has_draft
    current.placeholders = data.placeholders || {}
    current.block_placeholders = data.block_placeholders || {}
    draftHtml.value = data.body_html || ''
  } catch (e) {
    toast.value?.error('No se pudo cargar', 'No se pudo cargar la plantilla. Intenta de nuevo.')
  } finally {
    loadingType.value = false
  }
}

function selectType(type) {
  if (type === activeType.value) return
  activeType.value = type
}

watch(activeType, (type) => loadType(type))

function placeholderToken(token) {
  return '{' + '{' + token + '}' + '}'
}

function insertPlaceholder(token) {
  const quill = quillRef.value?.getQuill?.()
  const text = `{{${token}}}`
  if (!quill) {
    draftHtml.value += `<p>${text}</p>`
    return
  }
  const range = quill.getSelection(true)
  const index = range ? range.index : quill.getLength()
  quill.insertText(index, text, 'user')
  quill.setSelection(index + text.length)
}

function blockIcon(token) {
  if (token.includes('foto')) return 'bi-images'
  if (token.includes('firma')) return 'bi-pen'
  return 'bi-table'
}

/**
 * A diferencia de insertPlaceholder(), esto NUNCA inserta donde el cursor
 * esté parado dentro de una oración — siempre fuerza el token a su propio
 * párrafo (salto de línea antes y después) y lo resalta visualmente, para
 * que el tenant no pueda pegarlo a mitad de texto y luego reportar "no
 * aparece nada" cuando el backend lo descarta por no poder insertarlo en esa
 * posición (ver App\Services\Templates\BlockMarkerInjector — solo posiciones
 * de contenido son alcanzables, nunca dentro de un atributo).
 */
function insertBlockPlaceholder(token) {
  const quill = quillRef.value?.getQuill?.()
  const text = `{{${token}}}`
  if (!quill) {
    draftHtml.value += `<p><span style="background:#eef2ff;color:#4338ca;">${text}</span></p>`
    return
  }

  const range = quill.getSelection(true)
  const index = range ? range.index : quill.getLength()

  quill.insertText(index, '\n', 'user')
  quill.insertText(index + 1, text, 'user')
  quill.formatText(index + 1, text.length, { background: '#eef2ff', color: '#4338ca' }, 'user')
  quill.insertText(index + 1 + text.length, '\n', 'user')
  quill.setSelection(index + 1 + text.length + 1)
}

async function save() {
  saving.value = true
  try {
    const { data } = await documentTemplatesApi.update(activeType.value, draftHtml.value, current.is_advanced_mode)
    current.is_active = data.data.is_active
    current.is_advanced_mode = data.data.is_advanced_mode
    current.has_draft = data.data.has_draft
    toast.value?.success('Plantilla guardada', 'Se guardó y activó correctamente.')
  } catch (e) {
    toast.value?.error('No se pudo guardar', e.response?.data?.message || 'Intenta de nuevo.')
  } finally {
    saving.value = false
  }
}

async function confirmReset() {
  resetting.value = true
  try {
    const { data } = await documentTemplatesApi.reset(activeType.value)
    current.is_active = data.data.is_active
    current.has_draft = data.data.has_draft
    toast.value?.success('Plantilla restaurada', 'Ahora se usa la plantilla base del sistema.')
    showResetModal.value = false
  } catch (e) {
    toast.value?.error('No se pudo restaurar', e.response?.data?.message || 'Intenta de nuevo.')
  } finally {
    resetting.value = false
  }
}

/**
 * Lee el header X-Template-Warnings (DocumentTemplateController::preview(),
 * JSON array de {token, label}) y avisa explícitamente qué bloque no se pudo
 * insertar, en vez de dejar que el tenant solo vea el PDF sin ese contenido
 * y no entienda por qué — nunca bloquea la vista previa, solo informa.
 */
function warnOnOrphanedBlocks(response) {
  const header = response.headers?.['x-template-warnings']
  if (!header) return

  let warnings = []
  try {
    warnings = JSON.parse(header)
  } catch (e) {
    return
  }
  if (!warnings.length) return

  const labels = warnings.map((w) => `"${w.label}"`).join(', ')
  toast.value?.warning(
    'Un bloque no se pudo insertar',
    `${labels} no se pudo insertar en esa posición del documento — verifica que esté en su propio párrafo, no dentro de un enlace o texto con formato.`
  )
}

async function preview() {
  previewing.value = true
  try {
    const response = await documentTemplatesApi.preview(activeType.value, draftHtml.value, current.is_advanced_mode)
    warnOnOrphanedBlocks(response)
    const url = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }))
    window.open(url, '_blank')
  } catch (e) {
    toast.value?.error('No se pudo generar la vista previa', 'Revisa el contenido e intenta de nuevo.')
  } finally {
    previewing.value = false
  }
}

function handleLogoChange(event) {
  const file = event.target.files?.[0]
  if (!file) return
  uploadLogo(file)
  event.target.value = ''
}

async function uploadLogo(file) {
  uploadingLogo.value = true
  try {
    const { data } = await tenantApi.uploadLogo(file)
    logoUrl.value = data.data.logo_url
    toast.value?.success('Logo actualizado', 'El logo se aplicará en los próximos documentos.')
  } catch (e) {
    toast.value?.error('No se pudo subir el logo', e.response?.data?.message || 'Revisa el formato y tamaño del archivo.')
  } finally {
    uploadingLogo.value = false
  }
}

async function saveBranding() {
  savingBranding.value = true
  try {
    await tenantApi.updateConfig({
      brand_color: branding.brand_color || null,
      document_footer_text: branding.document_footer_text || null,
      contract_prefix: branding.contract_prefix || null,
    })
    toast.value?.success('Marca guardada', 'Los cambios se aplicarán en los próximos documentos.')
  } catch (e) {
    toast.value?.error('No se pudo guardar', e.response?.data?.message || 'Revisa los datos e intenta de nuevo.')
  } finally {
    savingBranding.value = false
  }
}

async function loadTenantBranding() {
  try {
    const userData = JSON.parse(localStorage.getItem('userData') || sessionStorage.getItem('userData') || 'null')
    if (!userData?.tenant_id) {
      console.error('loadTenantBranding: no tenant_id in stored userData, skipping load.', userData)
      return
    }
    const { data } = await tenantApi.getOne(userData.tenant_id)
    const tenant = data.data || data
    branding.brand_color = tenant.brand_color || ''
    branding.document_footer_text = tenant.document_footer_text || ''
    branding.contract_prefix = tenant.contract_prefix || ''
    nextContractNumber.value = Number(tenant.next_contract_number) || 1
    logoUrl.value = tenant.logo ? `/storage/${tenant.logo}` : null
  } catch (e) {
    // Antes este catch no dejaba ningún rastro: una falla de red/permiso al
    // cargar se veía IDÉNTICO a "nunca se guardó nada" (auditoría 2026-08-04,
    // reporte de usuario: color/pie/logo "reseteados" en cada reingreso). Sí
    // sigue siendo no-fatal (el panel puede iniciar vacío), pero ahora es
    // diagnosticable en vez de silencioso.
    console.error('loadTenantBranding failed:', e)
    toast.value?.error('No se pudo cargar la marca guardada', e.response?.data?.message || 'Los cambios previos siguen guardados; intenta recargar la página.')
  }
}

onMounted(async () => {
  await loadTenantBranding()
  await loadType(activeType.value)
})
</script>

<style scoped>
.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2;
}
.input {
  @apply w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
       focus:ring-2 focus:ring-indigo-500 focus:border-transparent
       transition-all placeholder:text-gray-400 dark:placeholder:text-gray-500;
}
</style>
