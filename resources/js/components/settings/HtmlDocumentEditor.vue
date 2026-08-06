<template>
  <div class="border border-gray-300 dark:border-gray-600 rounded-xl overflow-hidden flex flex-col">
    <!-- Barra de herramientas. Actúa sobre el documento del iframe, no sobre
         la página: por eso cada acción enfoca primero el contentWindow. -->
    <div class="flex flex-wrap items-center gap-1 px-2 py-1.5 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
      <select
        :value="currentBlock"
        @change="applyBlock($event.target.value)"
        class="text-xs bg-white dark:bg-gray-700 dark:text-gray-100 border border-gray-300 dark:border-gray-600 rounded px-1.5 py-1"
      >
        <option value="p">Normal</option>
        <option value="h1">Título 1</option>
        <option value="h2">Título 2</option>
        <option value="h3">Título 3</option>
      </select>

      <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

      <button v-for="b in inlineButtons" :key="b.command" type="button" :title="b.title"
        @click="exec(b.command)"
        class="w-7 h-7 rounded hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-xs flex items-center justify-center"
        :class="b.class">
        {{ b.label }}
      </button>

      <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

      <button type="button" title="Lista numerada" @click="exec('insertOrderedList')"
        class="w-7 h-7 rounded hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-xs flex items-center justify-center">1.</button>
      <button type="button" title="Lista con viñetas" @click="exec('insertUnorderedList')"
        class="w-7 h-7 rounded hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-xs flex items-center justify-center">•</button>

      <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

      <label title="Color del texto" class="w-7 h-7 rounded hover:bg-gray-200 dark:hover:bg-gray-600 flex items-center justify-center cursor-pointer text-xs text-gray-700 dark:text-gray-200">
        A
        <input type="color" class="sr-only" @input="exec('foreColor', $event.target.value)" />
      </label>
      <label title="Color de fondo" class="w-7 h-7 rounded hover:bg-gray-200 dark:hover:bg-gray-600 flex items-center justify-center cursor-pointer text-xs text-gray-700 dark:text-gray-200">
        <span class="px-1 rounded bg-yellow-200 text-gray-900">A</span>
        <input type="color" class="sr-only" @input="exec('hiliteColor', $event.target.value)" />
      </label>

      <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

      <button type="button" title="Insertar enlace" @click="insertLink"
        class="w-7 h-7 rounded hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-xs flex items-center justify-center">🔗</button>
      <button type="button" title="Quitar formato" @click="exec('removeFormat')"
        class="w-7 h-7 rounded hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-xs flex items-center justify-center">✕</button>

      <span class="ml-auto text-[11px] text-gray-400 pr-1">
        Hoja real: {{ printable.width }}&nbsp;×&nbsp;{{ printable.height }}&nbsp;px · las líneas rojas son los cortes de página
      </span>
    </div>

    <!-- El contenido del tenant vive dentro de un iframe y no en un
         contenteditable normal por dos razones concretas:
           1. Sus plantillas son documentos COMPLETOS, con su propio <style>.
              En la misma página, ese <style> se aplicaría al panel de
              configuración y lo desmaquetaría entero.
           2. Es la única forma de que el editor visual muestre el documento
              tal como es (tablas, anchos, colores) sin normalizarlo a un
              modelo propio — que es exactamente lo que hacía Quill, y por lo
              que el contenido se perdía al cambiar de modo. -->
    <iframe
      ref="frame"
      title="Editor de la plantilla"
      class="w-full"
      :style="{ height: height }"
    ></iframe>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  height: { type: String, default: '420px' },
  pageSize: { type: String, default: 'a4' },
  pageOrientation: { type: String, default: 'portrait' },
})
const emit = defineEmits(['update:modelValue', 'fit'])

// Tamaños en píxeles a 96 dpi, que es el `dpi` que usa config/dompdf.php.
// Cambiar uno sin cambiar el otro haría que el editor mienta sobre lo que cabe.
const PAPER_MM = {
  a4:     { w: 210, h: 297 },
  letter: { w: 216, h: 279 },
  legal:  { w: 216, h: 356 },
}
// Margen por defecto de dompdf: 1.27 cm por lado = 48 px a 96 dpi. Es lo que
// hace que un A4 vertical deje ~698 px útiles y no 794 (medido el 2026-08-05).
const MARGIN_PX = 96

const printable = computed(() => {
  const paper = PAPER_MM[props.pageSize] || PAPER_MM.a4
  const landscape = props.pageOrientation === 'landscape'
  const mmW = landscape ? paper.h : paper.w
  const mmH = landscape ? paper.w : paper.h
  const toPx = (mm) => Math.round((mm / 25.4) * 96)

  return {
    width: toPx(mmW) - MARGIN_PX,
    height: toPx(mmH) - MARGIN_PX,
  }
})

const frame = ref(null)
const currentBlock = ref('p')

const inlineButtons = [
  { command: 'bold', label: 'B', title: 'Negrita', class: 'font-bold' },
  { command: 'italic', label: 'I', title: 'Cursiva', class: 'italic' },
  { command: 'underline', label: 'U', title: 'Subrayado', class: 'underline' },
]

// Lo último que este componente emitió. Sirve para distinguir "el valor
// cambió porque el usuario está escribiendo aquí" (no hay que reescribir el
// iframe, movería el cursor) de "el valor cambió desde afuera" (cargar otra
// plantilla, pegar HTML en modo avanzado), que sí exige repintar.
let lastEmitted = null
// Si el valor recibido era un documento completo hay que devolverlo completo;
// si era un fragmento (modo seguro, va dentro del shell fijo) hay que
// devolver sólo el contenido del body. Confundirlos rompería el render.
let valueWasFullDocument = false

const FRAGMENT_STYLES = `
  <style>
    body { font-family: 'Times New Roman', Times, serif; font-size: 13px; color: #1f2937;
           line-height: 1.5; background: #fff; }
    table { border-collapse: collapse; }
    td, th { padding: 4px 6px; }
    img { max-width: 100%; }
  </style>
`

// Hoja de estilos que sólo existe MIENTRAS se edita: nunca se guarda. Se
// inyecta con un id conocido y readValue() la quita antes de serializar.
const EDITOR_STYLE_ID = '__ispwatch_editor_only'

/**
 * Encierra el contenido en el ancho real del papel y dibuja los cortes de
 * página. Sin esto el editor es tan ancho como la pantalla, así que un diseño
 * de 950 px se veía perfecto aquí y en el PDF se desbordaba de la columna y se
 * montaba sobre la de al lado — que es exactamente el reporte del 2026-08-06.
 *
 * Las guías van en html::before, un pseudo-elemento: no existe en el DOM, así
 * que es imposible que acabe dentro del HTML guardado.
 */
function editorOnlyCss(width, height) {
  return `
    html { background: #9ca3af; position: relative; padding: 0 0 40px; }
    body {
      width: ${width}px !important;
      margin: 0 auto !important;
      padding: 0 !important;
      box-sizing: content-box;
      min-height: ${height}px;
      outline: none;
      box-shadow: 0 0 0 1px #6b7280;
    }
    /* El navegador SÍ descarga una imagen de internet y dompdf NO
       (enable_remote = false): sin marcarlas, el editor prometería una imagen
       que en el PDF sale rota. Se marcan aquí para que la diferencia se vea
       antes de generar el PDF, no después. */
    img[src^="http://"], img[src^="https://"] {
      outline: 3px dashed #dc2626 !important;
      outline-offset: -3px;
      opacity: .3 !important;
    }
    html::before {
      content: '';
      position: absolute;
      top: 0; left: 50%;
      margin-left: -${Math.round(width / 2)}px;
      width: ${width}px;
      height: 100%;
      pointer-events: none;
      z-index: 2147483647;
      background: repeating-linear-gradient(
        to bottom,
        rgba(0,0,0,0) 0,
        rgba(0,0,0,0) ${height - 2}px,
        rgba(220,38,38,.65) ${height - 2}px,
        rgba(220,38,38,.65) ${height}px
      );
    }
  `
}

function isFullDocument(html) {
  return /<html[\s>]|<body[\s>]|<!doctype/i.test(html || '')
}

/**
 * El HTML es del propio tenant y el servidor lo sanea al guardar, pero un
 * borrador escrito en modo avanzado todavía no pasó por ahí. Quitar <script>
 * y los atributos on-* antes de escribirlo evita que un borrador a medias
 * ejecute algo dentro del editor. No sustituye al saneado del servidor: es la
 * capa de esta pantalla.
 */
function stripActiveContent(html) {
  return (html || '')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '')
    .replace(/<script\b[^>]*\/?>/gi, '')
    .replace(/\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
    .replace(/javascript:/gi, '')
}

function doc() {
  return frame.value?.contentDocument || null
}

function writeDocument(html) {
  const d = doc()
  if (!d) return

  valueWasFullDocument = isFullDocument(html)
  const safe = stripActiveContent(html)
  const source = valueWasFullDocument
    ? safe
    : `<!DOCTYPE html><html><head><meta charset="UTF-8">${FRAGMENT_STYLES}</head><body>${safe}</body></html>`

  d.open()
  d.write(source)
  d.close()

  applyEditorStyles()
  d.body.contentEditable = 'true'
  d.designMode = 'off'

  // execCommand genera <span style> en vez de <font>, que es lo que el
  // sanitizer del servidor conserva (font no está en el allowlist).
  try { d.execCommand('styleWithCSS', false, true) } catch (e) { /* navegador viejo */ }

  d.addEventListener('input', handleInput)
  d.addEventListener('selectionchange', syncBlockState)
}

/**
 * Inserta (o actualiza) la hoja de estilos de edición. Se actualiza en vez de
 * reescribir el documento cuando cambia el tamaño de página: reescribirlo
 * mandaría el cursor al principio a mitad de la frase que estás escribiendo.
 */
function applyEditorStyles() {
  const d = doc()
  if (!d?.head) return

  let style = d.getElementById(EDITOR_STYLE_ID)
  if (!style) {
    style = d.createElement('style')
    style.id = EDITOR_STYLE_ID
    d.head.appendChild(style)
  }
  style.textContent = editorOnlyCss(printable.value.width, printable.value.height)
}

/**
 * Mide si el contenido se sale del ancho de la hoja. Es la causa nº 1 de que
 * el PDF salga con los textos montados: dompdf no reduce una tabla con ancho
 * fijo, la deja desbordarse sobre lo que tenga al lado.
 */
function reportFit() {
  const d = doc()
  if (!d?.body) return

  // Sólo el body: su ancho está fijado al de la hoja, así que scrollWidth es
  // exactamente lo que el contenido pide. documentElement.scrollWidth NO sirve
  // aquí — es el ancho del iframe (el del panel), siempre mayor que la hoja,
  // y daría "no cabe" incluso en un documento que cabe de sobra.
  const contentWidth = d.body.scrollWidth
  emit('fit', {
    contentWidth,
    printableWidth: printable.value.width,
    overflows: contentWidth > printable.value.width + 2,
  })
}

function readValue() {
  const d = doc()
  if (!d) return ''

  if (!valueWasFullDocument) {
    return d.body ? d.body.innerHTML : ''
  }

  // Se serializa sobre una COPIA para quitar lo que sólo existe mientras se
  // edita: la hoja de estilos del editor y el contentEditable del body. Si se
  // guardaran, el PDF saldría con el fondo gris y las guías de página encima.
  const clone = d.documentElement.cloneNode(true)
  clone.querySelector('#' + EDITOR_STYLE_ID)?.remove()
  clone.querySelector('body')?.removeAttribute('contenteditable')

  return '<!DOCTYPE html>' + clone.outerHTML
}

function handleInput() {
  lastEmitted = readValue()
  emit('update:modelValue', lastEmitted)
  reportFit()
}

function syncBlockState() {
  const d = doc()
  if (!d) return
  try {
    const block = (d.queryCommandValue('formatBlock') || 'p').toLowerCase()
    currentBlock.value = ['h1', 'h2', 'h3'].includes(block) ? block : 'p'
  } catch (e) {
    currentBlock.value = 'p'
  }
}

function exec(command, value = null) {
  const d = doc()
  if (!d) return
  frame.value.contentWindow.focus()
  try { d.execCommand(command, false, value) } catch (e) { /* comando no soportado */ }
  handleInput()
}

function applyBlock(tag) {
  currentBlock.value = tag
  exec('formatBlock', `<${tag}>`)
}

/**
 * Inserta un marcador donde esté el cursor. Los de bloque van forzados a su
 * propio párrafo: si quedan a mitad de una oración o dentro de un atributo,
 * el servidor no puede insertarlos y el tenant sólo ve que "no aparece nada"
 * (ver App\Services\Templates\BlockMarkerInjector).
 */
function insertToken(token, { ownParagraph = false } = {}) {
  const d = doc()
  if (!d) return
  frame.value.contentWindow.focus()

  const text = `{{${token}}}`
  try {
    if (ownParagraph) {
      d.execCommand('insertHTML', false,
        `<p><span style="background:#eef2ff;color:#4338ca;">${text}</span></p><p><br></p>`)
    } else {
      d.execCommand('insertText', false, text)
    }
  } catch (e) {
    d.body.insertAdjacentHTML('beforeend', ownParagraph ? `<p>${text}</p>` : text)
  }
  handleInput()
}

function insertLink() {
  const url = window.prompt('Dirección del enlace (https://…)')
  if (!url) return
  if (!/^https?:\/\//i.test(url)) {
    window.alert('El enlace debe empezar por http:// o https://')
    return
  }
  exec('createLink', url)
}

onMounted(() => {
  writeDocument(props.modelValue)
  lastEmitted = readValue()
  reportFit()
})

onBeforeUnmount(() => {
  const d = doc()
  if (!d) return
  d.removeEventListener('input', handleInput)
  d.removeEventListener('selectionchange', syncBlockState)
})

watch(
  () => props.modelValue,
  (value) => {
    if (value === lastEmitted) return
    writeDocument(value)
    lastEmitted = readValue()
    reportFit()
  }
)

// Cambiar de tamaño u orientación no reescribe el documento (perdería el
// cursor): sólo se recalcula la hoja del editor y se vuelve a medir.
watch(printable, () => {
  applyEditorStyles()
  reportFit()
})

defineExpose({ insertToken })
</script>
