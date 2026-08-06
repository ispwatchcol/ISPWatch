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

          <!-- Plantillas base. El editor arrancaba en blanco y no había forma
               de ver ni partir del formato que el sistema ya usa por defecto:
               el tenant tenía que escribir un documento entero desde cero o
               pegar el de otro sistema. Estas son ese formato y los regulados,
               ya editables. -->
          <div v-if="current.starters.length">
            <label class="label">Empezar desde una plantilla base</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <button
                v-for="s in current.starters"
                :key="s.slug"
                type="button"
                :disabled="loadingStarter === s.slug"
                @click="askLoadStarter(s)"
                class="text-left flex items-start gap-2 text-xs bg-indigo-50 dark:bg-indigo-900/20 hover:bg-indigo-100 dark:hover:bg-indigo-900/40 border border-indigo-200 dark:border-indigo-800 text-indigo-800 dark:text-indigo-300 px-3 py-2 rounded-lg transition-colors disabled:opacity-50"
              >
                <v-icon name="md-description" class="w-4 h-4 mt-0.5 shrink-0" />
                <span>
                  <span class="font-semibold block">
                    {{ s.name }}
                    <span v-if="loadingStarter === s.slug" class="font-normal opacity-70">— cargando…</span>
                  </span>
                  <span class="opacity-80">{{ s.description }}</span>
                </span>
              </button>
            </div>
            <p class="text-xs text-gray-400 mt-1.5">
              Son un punto de partida con la estructura del formato: revísalas y complétalas con las
              condiciones de tu empresa antes de usarlas con clientes reales.
            </p>
          </div>

          <!-- Aviso especial para contrato -->
          <div v-if="activeType === 'contract'" class="text-sm bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 rounded-lg p-3">
            Las cláusulas 1 a 3.5 del contrato (datos del cliente, plan, condiciones generales) son fijas y no se editan aquí.
            Lo que escribas abajo se agrega como <strong>"4. Condiciones Adicionales del Proveedor"</strong>, después de esas cláusulas.
          </div>

          <!-- Tamaño y orientación de página. Un contrato a dos columnas
               (formato CRC) necesita ~950px de ancho: A4 vertical solo da
               ~698px útiles y dompdf lo aprieta, A4 horizontal da ~1027px. -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="label">Tamaño de página</label>
              <select v-model="current.page_size" class="input">
                <option value="a4">A4 (210 × 297 mm)</option>
                <option value="letter">Carta (216 × 279 mm)</option>
                <option value="legal">Oficio (216 × 356 mm)</option>
              </select>
            </div>
            <div>
              <label class="label">Orientación</label>
              <select v-model="current.page_orientation" class="input">
                <option value="portrait">Vertical</option>
                <option value="landscape">Horizontal</option>
              </select>
              <p class="text-xs text-gray-400 mt-1">
                Usa <strong>Horizontal</strong> si tu diseño es a dos columnas (ancho
                mayor a ~700&nbsp;px): en vertical no cabe y el PDF sale descuadrado.
                <template v-if="activeMetrics.printable_width_px">
                  Ahora mismo caben
                  <strong>{{ activeMetrics.printable_width_px }}&nbsp;×&nbsp;{{ activeMetrics.printable_height_px }}&nbsp;px</strong>
                  por página.
                </template>
              </p>
            </div>
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

          <!-- El interruptor ya no pierde el contenido al cambiar de modo,
               pero el modo seguro sí sanea con un allowlist estrecho AL
               GUARDAR (sin tablas, sin <img>, sin <style>). Avisar aquí es la
               diferencia entre "lo decides tú" y "se borró y no sé por qué". -->
          <div
            v-if="!current.is_advanced_mode && contentNeedsAdvancedMode"
            class="text-sm bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-800 text-red-800 dark:text-red-300 rounded-lg p-3"
          >
            Este contenido usa tablas, imágenes o estilos propios. El modo normal los
            <strong>elimina al guardar</strong> (sólo admite texto con formato básico, porque se
            inserta dentro de la plantilla base del sistema).
            <button type="button" @click="current.is_advanced_mode = true" class="underline font-semibold">
              Activa el modo avanzado
            </button>
            para conservarlo tal cual.
          </div>

          <!-- Desbordamiento horizontal: la causa nº1 de que el PDF salga con
               los textos montados unos sobre otros. dompdf no encoge una tabla
               con ancho fijo — la deja salirse sobre lo que tenga al lado. -->
          <div
            v-if="fit.overflows"
            class="text-sm bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-800 text-red-800 dark:text-red-300 rounded-lg p-3"
          >
            <strong>Tu diseño no cabe a lo ancho de la hoja.</strong>
            Necesita <strong>{{ fit.contentWidth }} px</strong> y
            {{ pageLabel }} sólo deja <strong>{{ fit.printableWidth }} px</strong>.
            En el PDF eso sale con los textos y las cajas montados unos sobre otros.
            <template v-if="current.page_orientation === 'portrait'">
              <button type="button" @click="current.page_orientation = 'landscape'" class="underline font-semibold">
                Cambia a horizontal
              </button>
              ({{ landscapeWidth }} px) o reduce el ancho de tus tablas.
            </template>
            <template v-else>
              Reduce el ancho de tus tablas: ya estás en horizontal, que es lo más ancho disponible.
            </template>
          </div>

          <!-- Editor + PDF real, lado a lado. El editor es un navegador
               imitando a dompdf y siempre va a tener diferencias; el panel de
               la derecha no imita nada: es el PDF que se va a imprimir. -->
          <div class="grid grid-cols-1 gap-4" :class="showPdfPane ? 'xl:grid-cols-2' : ''">
            <div class="flex flex-col min-h-[280px]">
              <div class="flex items-center justify-between gap-2 mb-2">
                <label class="label !mb-0">
                  {{ activeType === 'contract' ? 'Condiciones adicionales' : 'Contenido' }}
                </label>
                <button
                  type="button"
                  @click="togglePdfPane"
                  class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline shrink-0"
                >
                  {{ showPdfPane ? 'Ocultar el PDF' : 'Ver el PDF al lado' }}
                </button>
              </div>
              <textarea
                v-if="current.is_advanced_mode"
                v-model="draftHtml"
                spellcheck="false"
                class="flex-1 min-h-[280px] font-mono text-xs bg-white dark:bg-gray-900 dark:text-white border border-gray-300 dark:border-gray-600 rounded-xl p-3 resize-y"
                placeholder="<html><head><style>...</style></head><body>...</body></html>"
              ></textarea>
              <!-- Mismo draftHtml que el textarea: el interruptor de modo
                   avanzado sólo cambia CÓMO se edita, nunca QUÉ se edita. -->
              <HtmlDocumentEditor
                v-else
                ref="visualEditorRef"
                v-model="draftHtml"
                height="560px"
                :page-metrics="activeMetrics"
                :base-css="current.editor_base_css"
                :fragment-css="current.editor_fragment_css"
                :token-previews="tokenPreviews"
                @fit="onEditorFit"
              />
            </div>

            <!-- ══ PDF REAL ══ -->
            <div v-if="showPdfPane" class="flex flex-col min-h-[280px]">
              <div class="flex items-center justify-between gap-2 mb-2">
                <label class="label !mb-0 flex items-center gap-1.5">
                  PDF real
                  <span class="text-xs font-normal text-gray-400">— esto es exactamente lo que se imprime</span>
                </label>
                <button
                  type="button"
                  :disabled="pdfLoading"
                  @click="refreshPdf"
                  class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline shrink-0 disabled:opacity-50 disabled:no-underline"
                >
                  {{ pdfLoading ? 'Generando…' : 'Actualizar ahora' }}
                </button>
              </div>

              <div class="flex-1 border border-gray-300 dark:border-gray-600 rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-900 relative" style="min-height: 560px">
                <iframe
                  v-if="pdfUrl"
                  :src="`${pdfUrl}#view=FitH`"
                  title="Vista previa del PDF"
                  class="w-full h-full"
                  style="min-height: 560px"
                ></iframe>
                <div v-else class="absolute inset-0 flex items-center justify-center text-center text-sm text-gray-400 p-6">
                  <span v-if="pdfError" class="text-red-500 dark:text-red-400">{{ pdfError }}</span>
                  <span v-else-if="pdfLoading">Generando el PDF…</span>
                  <span v-else>Escribe algo en el editor y aquí aparecerá el PDF.</span>
                </div>

                <!-- Estado sobre el PDF, no en lugar de él: mientras se
                     regenera se sigue viendo el anterior, que es lo que
                     permite comparar antes/después de un cambio. -->
                <div
                  v-if="pdfUrl && (pdfLoading || pdfStale || pdfError)"
                  class="absolute top-2 right-2 text-[11px] px-2 py-1 rounded-md shadow-sm"
                  :class="pdfError
                    ? 'bg-red-600 text-white'
                    : 'bg-gray-900/80 text-white dark:bg-gray-100/90 dark:text-gray-900'"
                >
                  <span v-if="pdfError">No se pudo actualizar</span>
                  <span v-else-if="pdfLoading">Actualizando…</span>
                  <span v-else>Hay cambios sin reflejar</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Diagnóstico de la plantilla. Aparece al previsualizar o guardar
               (X-Template-Warnings / warnings de la respuesta) y explica por
               qué un marcador va a salir en blanco: la plantilla se ve bien y
               los datos no aparecen, que desde aquí es indistinguible de "el
               sistema no funciona". Panel y no toast a propósito: la vista
               previa abre otra pestaña, y un toast se pierde mientras el
               usuario está mirando el PDF. -->
          <div
            v-if="templateWarnings.length"
            class="border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 rounded-lg p-3"
          >
            <div class="flex items-start justify-between gap-3 mb-2">
              <h5 class="text-sm font-semibold text-amber-900 dark:text-amber-300 flex items-center gap-2">
                <!-- oi-alert y no bi-exclamation-triangle: los iconos se
                     registran uno por uno en app.js y uno no registrado no
                     falla, simplemente no se dibuja. -->
                <v-icon name="oi-alert" class="w-4 h-4 shrink-0" />
                Revisa {{ templateWarnings.length }}
                {{ templateWarnings.length === 1 ? 'marcador' : 'marcadores' }} de esta plantilla
              </h5>
              <button
                type="button"
                @click="templateWarnings = []"
                class="text-xs text-amber-700 dark:text-amber-400 hover:underline shrink-0"
              >
                Ocultar
              </button>
            </div>
            <ul class="space-y-2">
              <li v-for="w in templateWarnings" :key="`${w.kind}|${w.token}`" class="text-xs">
                <span class="font-mono font-semibold text-amber-900 dark:text-amber-200 break-all">
                  {{ warningToken(w) }}
                </span>
                <span class="ml-1.5 text-amber-700/70 dark:text-amber-400/70">— {{ w.label }}</span>
                <p class="text-amber-800 dark:text-amber-300/90 mt-0.5">{{ w.message }}</p>
              </li>
            </ul>
            <p class="text-xs text-amber-700/80 dark:text-amber-400/70 mt-2 pt-2 border-t border-amber-200 dark:border-amber-800">
              El documento se genera igual: lo que no se reconoce sale en blanco, nunca rompe el PDF.
            </p>
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
              {{ previewing ? 'Generando...' : 'Abrir el PDF aparte' }}
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
      :visible="previewNeedsAdvanced"
      variant="warning"
      title="El PDF no se va a parecer al editor"
      message="Tu plantilla usa anchos, imágenes o estilos propios. Con el modo normal, el servidor los elimina y mete el resto dentro de la plantilla base del sistema: el PDF saldrá con otro diseño. Activa el modo avanzado para que salga como lo ves aquí."
      confirm-text="Activar modo avanzado y previsualizar"
      cancel-text="Previsualizar así de todos modos"
      @confirm="previewInAdvancedMode"
      @cancel="runPreview"
    />

    <ConfirmModal
      :visible="starterToLoad !== null"
      variant="warning"
      title="Reemplazar el contenido del editor"
      :message="`Se va a cargar «${starterToLoad?.name}» y el contenido actual del editor se pierde. Lo que ya está guardado y activo no se toca hasta que le des a «Guardar y activar».`"
      confirm-text="Sí, cargar"
      cancel-text="Cancelar"
      @confirm="loadStarter(starterToLoad)"
      @cancel="starterToLoad = null"
    />

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
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'
import NotificationToast from '@/components/NotificationToast.vue'
import HtmlDocumentEditor from '@/components/settings/HtmlDocumentEditor.vue'
import { documentTemplatesApi, tenantApi } from '@/services/api'

const documentTypes = [
  { id: 'invoice', label: 'Factura' },
  { id: 'contract', label: 'Contrato' },
  { id: 'installation', label: 'Hoja de Instalación' },
]

const toast = ref(null)
const visualEditorRef = ref(null)
const logoInput = ref(null)

const activeType = ref('invoice')
const loadingType = ref(false)
const saving = ref(false)
const resetting = ref(false)
const previewing = ref(false)
const showResetModal = ref(false)
// Hallazgos de App\Services\Templates\TemplateDiagnostics: {kind, token,
// label, message}. Se llena al previsualizar o guardar, y se vacía al
// cambiar de tipo de documento — pertenecen al borrador que se inspeccionó,
// no a la pantalla.
const templateWarnings = ref([])

const draftHtml = ref('')

// Marca lo que el allowlist del modo seguro (TemplateSanitizer) descarta al
// guardar: documento completo, tablas, imágenes o bloques <style>.
const contentNeedsAdvancedMode = computed(() =>
  /<html[\s>]|<body[\s>]|<!doctype|<table[\s>]|<img[\s>]|<style[\s>]/i.test(draftHtml.value || '')
)

const current = reactive({
  is_active: false,
  is_advanced_mode: false,
  page_size: 'a4',
  page_orientation: 'portrait',
  has_draft: false,
  placeholders: {},
  block_placeholders: {},
  starters: [],
  // Geometría real de dompdf y CSS de normalización del editor: los calcula
  // el backend (App\Services\Templates\PdfPageGeometry). Aquí NO se calcula
  // ni un milímetro — tener las medidas de la hoja en dos sitios es lo que
  // hacía que el editor dibujara los cortes de página donde no eran.
  page_metrics: {},
  editor_base_css: '',
  editor_fragment_css: '',
})

/** Medidas de la hoja que el tenant tiene seleccionada ahora mismo. */
const activeMetrics = computed(
  () => current.page_metrics?.[`${current.page_size}:${current.page_orientation}`] || {}
)

/**
 * Marcadores que el editor visual debe dibujar como imagen en vez de como
 * texto. Es el mismo archivo que el servidor inserta en el PDF
 * (App\Services\Templates\BlockPlaceholderResolver::resolveLogo), así que lo
 * que se ve colocado en el editor es lo que se imprime. Sin logo subido el
 * mapa va vacío y el marcador se queda como texto, que es honesto: en el PDF
 * tampoco saldría nada.
 */
const tokenPreviews = computed(() => (logoUrl.value ? { 'empresa.logo': logoUrl.value } : {}))

const loadingStarter = ref(null)
const starterToLoad = ref(null)
const previewNeedsAdvanced = ref(false)

// Medición que reporta el editor visual: cuánto ancho pide el contenido
// frente a cuánto deja la hoja elegida.
const fit = reactive({ contentWidth: 0, printableWidth: 0, overflows: false })

function onEditorFit(measurement) {
  Object.assign(fit, measurement)
}

const PAGE_LABELS = { a4: 'A4', letter: 'Carta', legal: 'Oficio' }
const pageLabel = computed(
  () => `${PAGE_LABELS[current.page_size] || current.page_size} ${current.page_orientation === 'landscape' ? 'horizontal' : 'vertical'}`
)
// Cuánto ancho ganaría girando la hoja. Sale de la misma tabla del servidor
// que usa el editor, no de una fórmula repetida aquí.
const landscapeWidth = computed(
  () => current.page_metrics?.[`${current.page_size}:landscape`]?.printable_width_px || 0
)

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
  templateWarnings.value = []
  try {
    const { data } = await documentTemplatesApi.show(type)
    current.is_active = data.is_active
    current.is_advanced_mode = data.is_advanced_mode || false
    current.page_size = data.page_size || 'a4'
    current.page_orientation = data.page_orientation || 'portrait'
    current.has_draft = data.has_draft
    current.placeholders = data.placeholders || {}
    current.block_placeholders = data.block_placeholders || {}
    current.starters = data.starters || []
    current.page_metrics = data.page_metrics || {}
    current.editor_base_css = data.editor_base_css || ''
    current.editor_fragment_css = data.editor_fragment_css || ''
    // El logo llega también por aquí (además de por loadTenantBranding) para
    // que el editor pueda dibujar {{empresa.logo}} sin depender del orden en
    // que terminen las dos peticiones.
    if (data.logo_url) logoUrl.value = data.logo_url
    draftHtml.value = data.body_html || ''
    // El PDF que estuviera en el panel es el del tipo ANTERIOR: se limpia ya
    // y se pide el nuevo por el mismo camino con debounce, que además
    // absorbe el disparo del watch de draftHtml en vez de renderizar dos veces.
    setPdfUrl(null)
    pdfError.value = null
    schedulePdfRefresh()
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

/**
 * En modo avanzado se edita en un <textarea> y desde aquí no hay cursor que
 * consultar, así que el marcador se añade al final del contenido — pero
 * dentro del <body> si el documento lo tiene, porque pegarlo detrás de
 * </html> lo dejaría fuera del documento y no se renderizaría nunca.
 */
function appendToSource(snippet) {
  const html = draftHtml.value || ''
  const closingBody = html.lastIndexOf('</body>')
  draftHtml.value = closingBody === -1
    ? html + snippet
    : html.slice(0, closingBody) + snippet + html.slice(closingBody)
}

function insertPlaceholder(token) {
  if (current.is_advanced_mode || !visualEditorRef.value) {
    appendToSource(`{{${token}}}`)
    return
  }
  visualEditorRef.value.insertToken(token)
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
  if (current.is_advanced_mode || !visualEditorRef.value) {
    appendToSource(`\n<p>{{${token}}}</p>\n`)
    return
  }
  visualEditorRef.value.insertToken(token, { ownParagraph: true })
}

/**
 * Cargar una plantilla base reemplaza todo el editor. Si ya hay contenido se
 * pregunta primero: es trabajo del tenant y no se pisa sin avisar. Con el
 * editor vacío no hay nada que perder, así que se carga directo.
 */
function askLoadStarter(starter) {
  if ((draftHtml.value || '').trim() === '') {
    loadStarter(starter)
    return
  }
  starterToLoad.value = starter
}

async function loadStarter(starter) {
  starterToLoad.value = null
  loadingStarter.value = starter.slug
  try {
    const { data } = await documentTemplatesApi.starter(activeType.value, starter.slug)
    draftHtml.value = data.data.body_html
    // La plantilla base trae el modo y el papel con los que está diseñada: un
    // contrato CRC a dos columnas en A4 vertical sale descuadrado, y en modo
    // seguro el servidor le quitaría las tablas al guardar.
    current.is_advanced_mode = data.data.advanced
    current.page_size = data.data.page_size
    current.page_orientation = data.data.page_orientation
    templateWarnings.value = []
    toast.value?.success(
      'Plantilla base cargada',
      `Se cargó "${data.data.name}". Todavía no se guardó: edítala y usa "Guardar y activar" cuando esté lista.`
    )
  } catch (e) {
    toast.value?.error('No se pudo cargar la plantilla base', e.response?.data?.message || 'Intenta de nuevo.')
  } finally {
    loadingStarter.value = null
  }
}

async function save() {
  saving.value = true
  try {
    const { data } = await documentTemplatesApi.update(
      activeType.value,
      draftHtml.value,
      current.is_advanced_mode,
      current.page_size,
      current.page_orientation
    )
    current.is_active = data.data.is_active
    current.is_advanced_mode = data.data.is_advanced_mode
    current.page_size = data.data.page_size
    current.page_orientation = data.data.page_orientation
    current.has_draft = data.data.has_draft
    // Guardar activa la plantilla de inmediato: si trae marcadores que no se
    // reconocen, los documentos reales ya van a salir con esos datos en
    // blanco. Por eso el aviso también va aquí y no sólo en la vista previa.
    templateWarnings.value = data.warnings || []
    if (templateWarnings.value.length) {
      toast.value?.warning(
        'Plantilla guardada, pero revisa los marcadores',
        `Se guardó y activó, pero ${templateWarnings.value.length} marcador(es) no se reconocen y van a salir en blanco. Revisa el detalle abajo del editor.`
      )
    } else {
      toast.value?.success('Plantilla guardada', 'Se guardó y activó correctamente.')
    }
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
 * Lee la cabecera X-Template-Warnings de la vista previa
 * (DocumentTemplateController::preview(): JSON array de
 * {kind, token, label, message}, ya ordenado por severidad y con tope en el
 * servidor). Los mensajes vienen armados del backend a propósito — se
 * verifican en las pruebas de PHP junto con la detección que los origina.
 */
function readWarningsHeader(response) {
  const header = response.headers?.['x-template-warnings']
  if (!header) return []

  try {
    const parsed = JSON.parse(header)
    return Array.isArray(parsed) ? parsed : []
  } catch (e) {
    return []
  }
}

/**
 * Los marcadores se muestran con llaves; un marcador ajeno sin llaves
 * (NUMERO_CONTRATO_TAG) o una URL de imagen remota se muestran tal cual,
 * que es exactamente como aparecen en la plantilla.
 */
function warningToken(warning) {
  const literal = warning.kind === 'foreign_marker' || warning.kind === 'remote_image'
  return literal ? warning.token : placeholderToken(warning.token)
}

// ── Vista previa PDF en vivo ──
//
// Es el único punto de la pantalla donde "lo que ves" y "lo que se imprime"
// no pueden separarse, porque no es una imitación: es el PDF, generado por el
// mismo dompdf y el mismo pipeline que los documentos reales. El editor
// visual, por bueno que sea, sigue siendo un navegador imitando a otro motor
// de maquetación — y ahí siempre va a haber diferencias (fuentes que dompdf
// no tiene, tablas que no encoge, saltos de página). Este panel es el que
// zanja la duda.
const showPdfPane = ref(true)
const pdfUrl = ref(null)
const pdfLoading = ref(false)
const pdfError = ref(null)
// Hay cambios en el editor que este PDF todavía no refleja.
const pdfStale = ref(false)

// Cada render cuesta una petición y un dompdf completo: se espera a que el
// tenant deje de escribir en vez de pedir uno por tecla.
const PDF_DEBOUNCE_MS = 1200
let pdfDebounce = null
// Las respuestas pueden llegar desordenadas (una plantilla grande tarda más
// que la siguiente edición pequeña). Sólo se pinta la del último pedido.
let pdfRequestId = 0

function setPdfUrl(url) {
  if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value)
  pdfUrl.value = url
}

async function refreshPdf() {
  if (!showPdfPane.value) return

  clearTimeout(pdfDebounce)

  // body_html es obligatorio en el endpoint: con el editor vacío no hay nada
  // que previsualizar y pedirlo sólo daría un 422.
  if (!(draftHtml.value || '').trim()) {
    setPdfUrl(null)
    pdfStale.value = false
    pdfError.value = null
    return
  }

  const requestId = ++pdfRequestId
  pdfLoading.value = true
  pdfError.value = null

  try {
    const response = await documentTemplatesApi.preview(
      activeType.value,
      draftHtml.value,
      current.is_advanced_mode,
      current.page_size,
      current.page_orientation
    )
    if (requestId !== pdfRequestId) return

    templateWarnings.value = readWarningsHeader(response)
    setPdfUrl(URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' })))
    pdfStale.value = false
  } catch (e) {
    if (requestId !== pdfRequestId) return
    pdfError.value = 'No se pudo generar el PDF con este contenido. Revísalo e inténtalo de nuevo.'
  } finally {
    if (requestId === pdfRequestId) pdfLoading.value = false
  }
}

function schedulePdfRefresh() {
  if (!showPdfPane.value) return
  pdfStale.value = true
  clearTimeout(pdfDebounce)
  pdfDebounce = setTimeout(refreshPdf, PDF_DEBOUNCE_MS)
}

function togglePdfPane() {
  showPdfPane.value = !showPdfPane.value
  if (showPdfPane.value) refreshPdf()
}

watch(
  [draftHtml, () => current.is_advanced_mode, () => current.page_size, () => current.page_orientation],
  schedulePdfRefresh
)

onBeforeUnmount(() => {
  clearTimeout(pdfDebounce)
  if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value)
})

async function preview() {
  // El editor visual es un navegador y entiende todo, pero el modo seguro
  // desarma el documento al renderizar: quita anchos, estilos e imágenes y
  // mete el resto dentro de la plantilla base del sistema. Previsualizar sin
  // avisar entrega un PDF que no se parece a lo que hay en pantalla, que es
  // justo la confusión que este editor existe para evitar.
  if (!current.is_advanced_mode && contentNeedsAdvancedMode.value) {
    previewNeedsAdvanced.value = true
    return
  }
  await runPreview()
}

async function previewInAdvancedMode() {
  previewNeedsAdvanced.value = false
  current.is_advanced_mode = true
  await runPreview()
}

async function runPreview() {
  previewNeedsAdvanced.value = false
  previewing.value = true
  try {
    const response = await documentTemplatesApi.preview(
      activeType.value,
      draftHtml.value,
      current.is_advanced_mode,
      current.page_size,
      current.page_orientation
    )
    templateWarnings.value = readWarningsHeader(response)
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
