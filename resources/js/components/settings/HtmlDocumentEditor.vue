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
  /**
   * Geometría REAL de la hoja, calculada por el backend
   * (App\Services\Templates\PdfPageGeometry, expuesta en
   * GET /document-templates/{type} → page_metrics). Este componente ya no
   * calcula milímetros ni adivina el margen de dompdf: tenía sus propias
   * constantes copiadas a ojo (1,27 cm en vez de 1,2 cm) y por eso dibujaba
   * los cortes de página 5 px fuera de sitio por lado, que es justo la clase
   * de mentira que el editor existe para no contar.
   *
   * El default es A4 vertical, sólo para que el componente sea usable antes
   * de que llegue la respuesta del servidor.
   */
  pageMetrics: {
    type: Object,
    default: () => ({ printable_width_px: 703, printable_height_px: 1032 }),
  },
  /**
   * CSS con el que el navegador imita los defaults de dompdf (margen del
   * body, familia, tamaño y line-height). Viene del servidor por la misma
   * razón: una sola definición, no dos que puedan separarse. Se inyecta
   * ANTES del <style> del tenant, así que él sigue ganando en todo lo suyo.
   */
  baseCss: { type: String, default: '' },
  /**
   * Tipografía del shell fijo, para cuando lo que se edita es un FRAGMENTO
   * (modo seguro). Ahí el contenido no es el documento: va dentro de
   * `.custom-block` del shell, con su letra y su tamaño. Sin esto el editor
   * mostraba Times 13px mientras la factura salía en DejaVu Sans 9px.
   */
  fragmentCss: { type: String, default: '' },
  /**
   * Marcadores que el servidor sustituye por una imagen al generar el PDF,
   * mapeados a esa imagen: { 'empresa.logo': 'https://…/logo.png' }. En el
   * editor se muestran como la imagen real en vez de como el texto
   * `{{empresa.logo}}`, y se vuelven a convertir en texto al leer el valor,
   * así que nunca se guarda la URL.
   */
  tokenPreviews: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue', 'fit'])

const printable = computed(() => ({
  width: props.pageMetrics?.printable_width_px || 703,
  height: props.pageMetrics?.printable_height_px || 1032,
}))

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

// Hojas de estilo que sólo existen MIENTRAS se edita: nunca se guardan. Se
// inyectan con ids conocidos y readValue() las quita antes de serializar.
//   - BASE va primero en el <head>: imita los defaults de dompdf y pierde
//     contra cualquier cosa que escriba el tenant.
//   - ONLY va al final: es el papel gris, la caja de la hoja y las guías de
//     corte, que tienen que ganarle a todo.
const EDITOR_BASE_STYLE_ID = '__ispwatch_editor_base'
const EDITOR_STYLE_ID = '__ispwatch_editor_only'

// Atributo que marca un marcador dibujado como imagen. Es la única pista para
// devolverlo a texto al leer, así que no puede cambiar sin cambiar los dos
// lados (withTokenPreviews / restoreTokens).
const TOKEN_ATTR = 'data-ispwatch-token'

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
    /* La caja de la hoja. Se fuerzan sólo el ancho y el centrado; el relleno
       y los márgenes verticales se dejan al tenant a propósito, porque dompdf
       SÍ se los aplica al body y forzarlos aquí a cero era otra forma de que
       el editor mostrara un documento que el PDF no iba a reproducir.
       border-box: en dompdf el body ocupa el área imprimible y su padding va
       por dentro; con content-box el relleno se sumaba al ancho y la hoja
       crecía en pantalla pero no en el PDF. */
    body {
      box-sizing: border-box !important;
      width: ${width}px !important;
      margin-left: auto !important;
      margin-right: auto !important;
      min-height: ${height}px;
      outline: none;
      box-shadow: 0 0 0 1px #6b7280;
    }
    /* El navegador SÍ descarga una imagen de internet y dompdf NO
       (enable_remote = false): sin marcarlas, el editor prometería una imagen
       que en el PDF sale rota. Se marcan aquí para que la diferencia se vea
       antes de generar el PDF, no después.
       Las imágenes de un marcador (${TOKEN_ATTR}) se excluyen: ésas las
       resuelve el servidor contra un archivo local, sí salen en el PDF.
       Las data: URI tampoco entran aquí — dompdf las acepta tal cual. */
    img[src^="http://"]:not([${TOKEN_ATTR}]),
    img[src^="https://"]:not([${TOKEN_ATTR}]) {
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

/**
 * Cambia `{{empresa.logo}}` por la imagen que el servidor va a poner ahí.
 * Antes el editor mostraba el texto del marcador y el logo sólo aparecía al
 * abrir el PDF, así que era imposible ver si quedaba demasiado grande, torcido
 * o encima de otra cosa hasta después de generarlo.
 *
 * La sustitución se hace SÓLO en las posiciones de texto: el HTML se parte en
 * etiquetas y contenido, y las etiquetas se devuelven intactas. Un marcador
 * dentro de un atributo (`alt="{{empresa.logo}}"`) se quedaría como está — si
 * se sustituyera ahí, se metería una etiqueta dentro de otra y el documento
 * del tenant quedaría corrupto. Es además el mismo criterio del servidor:
 * BlockMarkerInjector tampoco inserta bloques dentro de atributos.
 *
 * <style> y <script> se consumen enteros por el mismo motivo, y con una
 * consecuencia peor: un <img> dentro de un <style> no es una etiqueta para el
 * navegador, es texto CSS, así que restoreTokens() no lo encontraría al leer
 * y el marcador se perdería para siempre.
 */
function withTokenPreviews(html) {
  const previews = props.tokenPreviews || {}
  if (!html || Object.keys(previews).length === 0) return html

  const CHUNKS = /<style\b[^>]*>[\s\S]*?<\/style\s*>|<script\b[^>]*>[\s\S]*?<\/script\s*>|<[^>]*>|[^<]+/gi

  return html.replace(CHUNKS, (chunk) => {
    if (chunk.startsWith('<')) return chunk

    return chunk.replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (match, token) => {
      const url = previews[token]
      if (!url) return match

      // Mismas medidas que documents/blocks/logo.blade.php, que es lo que el
      // servidor inserta de verdad: si aquí se viera a otro tamaño, el editor
      // volvería a mentir sobre cuánto ocupa.
      return `<img ${TOKEN_ATTR}="${token}" src="${url}" alt="${token}" contenteditable="false"`
        + ' style="max-height:80px; max-width:220px;">'
    })
  })
}

/**
 * Inversa exacta de withTokenPreviews(), aplicada sobre la COPIA que se
 * serializa. Sin esto se guardaría la URL del logo en la plantilla, y el día
 * que el tenant cambie de logo los documentos seguirían saliendo con el viejo.
 */
function restoreTokens(root) {
  const doc = root.ownerDocument || document

  root.querySelectorAll(`[${TOKEN_ATTR}]`).forEach((el) => {
    el.replaceWith(doc.createTextNode(`{{${el.getAttribute(TOKEN_ATTR)}}}`))
  })
}

function doc() {
  return frame.value?.contentDocument || null
}

function writeDocument(html) {
  const d = doc()
  if (!d) return

  valueWasFullDocument = isFullDocument(html)
  const safe = withTokenPreviews(stripActiveContent(html))
  const source = valueWasFullDocument
    ? safe
    : `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>${safe}</body></html>`

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
 * Inserta (o actualiza) las dos hojas de estilo de edición. Se actualizan en
 * vez de reescribir el documento cuando cambia el tamaño de página:
 * reescribirlo mandaría el cursor al principio a mitad de la frase que estás
 * escribiendo.
 */
function applyEditorStyles() {
  const d = doc()
  if (!d?.head) return

  // Al PRINCIPIO del head: son los defaults, tienen que perder contra el
  // <style> del tenant y contra cualquier style inline.
  //
  // CUÁL de las dos bases depende de lo que se esté editando, y son
  // distintas de verdad: un documento completo (modo avanzado) se imprime
  // tal cual y hereda los defaults de dompdf; un fragmento (modo seguro) va
  // dentro del shell fijo y hereda la letra del shell.
  let base = d.getElementById(EDITOR_BASE_STYLE_ID)
  if (!base) {
    base = d.createElement('style')
    base.id = EDITOR_BASE_STYLE_ID
    d.head.insertBefore(base, d.head.firstChild)
  }
  base.textContent = (valueWasFullDocument ? props.baseCss : props.fragmentCss) || ''

  // Al FINAL: es el papel y las guías, tienen que ganarle a todo.
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
  if (!d?.documentElement) return ''

  // Se serializa sobre una COPIA para quitar lo que sólo existe mientras se
  // edita: las hojas de estilo del editor, el contentEditable del body y las
  // imágenes de marcador. Si se guardaran, el PDF saldría con el fondo gris y
  // las guías de página encima, y el logo quedaría congelado como una URL.
  const clone = d.documentElement.cloneNode(true)
  clone.querySelector('#' + EDITOR_BASE_STYLE_ID)?.remove()
  clone.querySelector('#' + EDITOR_STYLE_ID)?.remove()
  clone.querySelector('body')?.removeAttribute('contenteditable')
  restoreTokens(clone)

  if (!valueWasFullDocument) {
    return clone.querySelector('body')?.innerHTML || ''
  }

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
 *
 * Si el marcador tiene vista previa de imagen (el logo), se inserta ya como
 * imagen: es lo que va a salir en el PDF y lo que hace falta ver para saber
 * si queda bien puesto.
 */
function insertToken(token, { ownParagraph = false } = {}) {
  const d = doc()
  if (!d) return
  frame.value.contentWindow.focus()

  const preview = (props.tokenPreviews || {})[token]
  const text = preview
    ? withTokenPreviews(`{{${token}}}`)
    : `<span style="background:#eef2ff;color:#4338ca;">{{${token}}}</span>`

  try {
    if (ownParagraph) {
      d.execCommand('insertHTML', false, `<p>${text}</p><p><br></p>`)
    } else if (preview) {
      d.execCommand('insertHTML', false, text)
    } else {
      d.execCommand('insertText', false, `{{${token}}}`)
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
// cursor): sólo se recalculan las hojas del editor y se vuelve a medir.
watch([printable, () => props.baseCss, () => props.fragmentCss], () => {
  applyEditorStyles()
  reportFit()
})

// Subir un logo nuevo sí exige repintar: las imágenes de marcador ya escritas
// apuntan a la URL vieja. Pasa una vez cada mucho, así que perder la posición
// del cursor aquí es aceptable.
watch(
  () => props.tokenPreviews,
  () => {
    writeDocument(lastEmitted ?? props.modelValue)
    lastEmitted = readValue()
    reportFit()
  },
  { deep: true }
)

defineExpose({ insertToken })
</script>
