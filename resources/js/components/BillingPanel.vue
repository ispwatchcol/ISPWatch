<template>
  <div v-if="active" class="mt-0 col-span-2">
    <div class="border border-gray-300 dark:border-gray-700 rounded-xl p-4 sm:p-6
                bg-white dark:bg-gray-900 shadow-md w-full transition-colors">

      <!-- Título -->
      <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">
        Facturación del Router
      </h3>

      <!-- SUBGRID: cada evento (factura/corte/recordatorio) apila su Día + Hora
           en una celda; en móvil colapsa a 1 columna. -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-5">

        <!-- Emisión de la factura -->
        <div>
          <label class="flex items-center gap-2 text-gray-800 dark:text-gray-300 font-medium mb-1">
            <span class="h-2.5 w-2.5 rounded-full bg-blue-500 shrink-0"></span>
            Se emite la factura — Día
          </label>
          <DayPicker v-model="billing.create_invoice" class="w-full" />

          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1 mt-3">
            Hora de creación
          </label>
          <input
            type="time"
            v-model="billing.create_invoice_time"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200
                   border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2
                   focus:ring focus:ring-blue-500 transition-colors"
          />

          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            Día y hora en que se <strong>genera</strong> la factura (se evalúa cada hora).
            El periodo que cubre lo define «Modo de facturación», más abajo.
          </p>
        </div>

        <!-- Vencimiento -->
        <div>
          <label class="flex items-center gap-2 text-gray-800 dark:text-gray-300 font-medium mb-1">
            <span class="h-2.5 w-2.5 rounded-full bg-indigo-500 shrink-0"></span>
            Vence la factura — Día límite de pago
          </label>
          <DayPicker v-model="billing.pay_day" class="w-full" />

          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            Último día para pagar sin quedar en mora. Pasado ese día la factura cuenta como
            <strong>vencida</strong>, pero por sí sola <strong>no corta</strong> el servicio:
            el corte lo deciden el día de corte y el número de facturas vencidas.
          </p>
        </div>

        <!-- Recordatorio -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="flex items-center gap-2 text-gray-800 dark:text-gray-300 font-medium">
              <span class="h-2.5 w-2.5 rounded-full bg-amber-500 shrink-0"></span>
              Recordatorio de pago
            </label>

            <button
              type="button"
              @click="billing.payment_reminder_enabled = !billing.payment_reminder_enabled"
              :class="billing.payment_reminder_enabled ? 'bg-blue-600' : 'bg-gray-400 dark:bg-gray-600'"
              class="relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-300"
              :title="billing.payment_reminder_enabled ? 'Recordatorio activo' : 'Recordatorio desactivado'"
            >
              <span
                class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transform transition-transform duration-300"
                :class="billing.payment_reminder_enabled ? 'translate-x-5' : ''"
              ></span>
            </button>
          </div>

          <DayPicker
            v-model="billing.remember_day"
            :disabled="!billing.payment_reminder_enabled"
            class="w-full"
          />

          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1 mt-3">
            Hora del recordatorio
          </label>
          <input
            type="time"
            v-model="billing.remember_time"
            :disabled="!billing.payment_reminder_enabled"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200
                   border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2
                   focus:ring focus:ring-blue-500 transition-colors disabled:opacity-50"
          />

          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            {{ billing.payment_reminder_enabled
              ? 'Aviso al cliente con sus facturas pendientes. Se envía una vez por ciclo, el día y hora seleccionados.'
              : 'No se enviarán recordatorios de pago.' }}
          </p>
        </div>

        <!-- Corte del servicio -->
        <div>
          <label class="flex items-center gap-2 text-gray-800 dark:text-gray-300 font-medium mb-1">
            <span class="h-2.5 w-2.5 rounded-full bg-red-500 shrink-0"></span>
            Se corta el servicio — Día
          </label>
          <DayPicker v-model="billing.cut_day" class="w-full" />

          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1 mt-3">
            Hora de corte
          </label>
          <input
            type="time"
            v-model="billing.cut_time"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200
                   border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2
                   focus:ring focus:ring-blue-500 transition-colors"
          />

          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            <strong>Desde</strong> este día y hasta fin de mes se revisa cada hora y se suspende a
            quien acumule las facturas vencidas indicadas más abajo. Sin día de corte, el corte
            automático queda apagado.
          </p>
        </div>

        <!-- Resumen del ciclo — traduce la configuración a fechas reales -->
        <div class="col-span-1 sm:col-span-2 rounded-xl border border-blue-200 dark:border-blue-900/60
                    bg-blue-50/70 dark:bg-blue-900/10 p-4">
          <div class="flex items-center justify-between gap-2 mb-3">
            <h4 class="font-semibold text-gray-800 dark:text-gray-100">
              Así queda el ciclo
            </h4>
            <span class="text-[11px] text-gray-500 dark:text-gray-400">
              Ejemplo con {{ ciclo.mesActual }}
            </span>
          </div>

          <ul class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
            <li class="flex gap-2">
              <span class="mt-1.5 h-2.5 w-2.5 rounded-full bg-blue-500 shrink-0"></span>
              <span>
                <strong>Se emite</strong> el {{ ciclo.emision }} y la factura cubre el periodo
                <strong>{{ ciclo.periodo }}</strong>.
              </span>
            </li>
            <li v-if="billing.payment_reminder_enabled" class="flex gap-2">
              <span class="mt-1.5 h-2.5 w-2.5 rounded-full bg-amber-500 shrink-0"></span>
              <span>
                <strong>Recordatorio</strong> el {{ ciclo.recordatorio }}.
              </span>
            </li>
            <li class="flex gap-2">
              <span class="mt-1.5 h-2.5 w-2.5 rounded-full bg-indigo-500 shrink-0"></span>
              <span>
                <strong>Vence</strong> el {{ ciclo.vencimiento }}. Desde el día siguiente esa factura
                cuenta como vencida, pero el servicio sigue activo.
              </span>
            </li>
            <li class="flex gap-2">
              <span class="mt-1.5 h-2.5 w-2.5 rounded-full bg-red-500 shrink-0"></span>
              <span>
                <strong>Corte</strong> {{ ciclo.corte }}: se suspende a quien acumule
                {{ ciclo.umbral }} factura{{ ciclo.umbral === 1 ? '' : 's' }} vencida{{ ciclo.umbral === 1 ? '' : 's' }}.
                {{ ciclo.corteExplicacion }}
              </span>
            </li>
            <li class="flex gap-2">
              <span class="mt-1.5 h-2.5 w-2.5 rounded-full bg-gray-400 shrink-0"></span>
              <span>
                <strong>Tope</strong>: {{ ciclo.tope }}
              </span>
            </li>
          </ul>

          <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
            El periodo facturado es siempre el mes calendario completo (del 1 al último día), sin importar
            el día de emisión; la única excepción es el prorrateo de la primera factura. Los tres eventos
            se reintentan cada hora hasta fin de mes, así que un día caído no se pierde.
          </p>

          <div v-if="avisos.length" class="mt-3 space-y-1.5">
            <p
              v-for="(aviso, i) in avisos"
              :key="i"
              class="flex gap-2 text-xs text-amber-700 dark:text-amber-400"
            >
              <span class="shrink-0">⚠</span>
              <span>{{ aviso }}</span>
            </p>
          </div>
        </div>

        <!-- Tipo de Aviso al Crear Facturas -->
        <div class="col-span-1 sm:col-span-2">
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-2">
            Tipo de Aviso al Crear Facturas
          </label>
          
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <!-- Email Option -->
            <button
              type="button"
              @click="billing.notification_type = 'email'"
              :class="[
                'px-4 py-3 rounded-lg border-2 transition-all text-sm font-medium',
                billing.notification_type === 'email'
                  ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                  : 'border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:border-blue-400 dark:hover:border-blue-500'
              ]"
            >
              <div class="flex flex-col items-center gap-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                <span>Correo</span>
              </div>
            </button>

            <!-- WhatsApp Option -->
            <button
              type="button"
              @click="billing.notification_type = 'whatsapp'"
              :class="[
                'px-4 py-3 rounded-lg border-2 transition-all text-sm font-medium',
                billing.notification_type === 'whatsapp'
                  ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                  : 'border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:border-blue-400 dark:hover:border-blue-500'
              ]"
            >
              <div class="flex flex-col items-center gap-2">
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                <span>WhatsApp</span>
              </div>
            </button>

            <!-- Both Option -->
            <button
              type="button"
              @click="billing.notification_type = 'both'"
              :class="[
                'px-4 py-3 rounded-lg border-2 transition-all text-sm font-medium',
                billing.notification_type === 'both'
                  ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                  : 'border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:border-blue-400 dark:hover:border-blue-500'
              ]"
            >
              <div class="flex flex-col items-center gap-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                <span>Ambos</span>
              </div>
            </button>

            <!-- Disabled Option -->
            <button
              type="button"
              @click="billing.notification_type = 'none'"
              :class="[
                'px-4 py-3 rounded-lg border-2 transition-all text-sm font-medium',
                billing.notification_type === 'none'
                  ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                  : 'border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:border-blue-400 dark:hover:border-blue-500'
              ]"
            >
              <div class="flex flex-col items-center gap-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
                <span>No enviar</span>
              </div>
            </button>
          </div>
          
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
            Selecciona cómo deseas notificar al cliente cuando se cree una factura. Usa "No enviar" para desactivar el envío por ahora.
          </p>
        </div>

        <!-- Facturas vencidas -->
        <div>
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Suspender tras X facturas vencidas
          </label>
          <input 
            v-model="billing.overdue_invoices"
            type="number"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2
                  focus:ring focus:ring-blue-500 transition-colors"
            placeholder="Ej: 2"
          />
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            Es la condición real del corte: el día de corte sólo abre la ventana, pero se suspende
            únicamente a quien llegue a este número de facturas vencidas. Con 2, el cliente aguanta
            un ciclo completo antes de que lo corten.
          </p>
        </div>

        <!-- Tope de facturación -->
        <div>
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Dejar de facturar al moroso
          </label>
          <select
            v-model="billing.stop_invoicing_extra"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2
                  focus:ring focus:ring-blue-500 transition-colors"
          >
            <option :value="null">Nunca — se le sigue facturando indefinidamente</option>
            <option v-for="op in topeOpciones" :key="op.value" :value="op.value">
              {{ op.label }}
            </option>
          </select>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            Al cliente cortado se le siguen emitiendo mensualidades (la deuda refleja los meses
            transcurridos) hasta llegar a este número de facturas pendientes; a partir de ahí
            <strong>no se le genera ninguna más</strong> y la deuda queda congelada. Cuenta todas las
            facturas con saldo, no sólo las vencidas.
          </p>
        </div>

        <!-- Método de cobro -->
        <div>
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Método de cobro
          </label>
          <select
            v-model="billing.metodo"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 
                  focus:ring focus:ring-blue-500 transition-colors"
          >
            <option value="" disabled>Seleccione…</option>
            <option 
              v-for="t in types"
              :key="t.id"
              :value="t.id"
            >
              {{ t.type }}
            </option>
          </select>
        </div>

        <!-- Modo de facturación -->
        <div class="col-span-1 sm:col-span-2">
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Modo de facturación
          </label>
          <select
            v-model="billing.billing_mode"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2
                  focus:ring focus:ring-blue-500 transition-colors"
          >
            <option value="anticipado">Anticipado — la factura cubre el mes en curso</option>
            <option value="vencido">Vencido — la factura cubre el mes anterior (ya consumido)</option>
          </select>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            Anticipado: al crear la factura el día configurado, el periodo es el mes actual.
            Vencido: el periodo es el mes anterior.
          </p>
        </div>

        <!-- Primera factura (altas a mitad de mes) -->
        <div class="col-span-1 sm:col-span-2">
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Primera factura — clientes que entran a mitad de mes
          </label>
          <select
            v-model="billing.first_invoice_policy"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2
                  focus:ring focus:ring-blue-500 transition-colors"
          >
            <option value="none">No cobrar el mes en curso — su primera factura sale el próximo ciclo</option>
            <option value="prorated">Cobrar proporcional a los días restantes del mes</option>
            <option value="full">Cobrar el mes completo</option>
          </select>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            Se aplica sólo a clientes cuyo servicio empieza después de que el mes ya se facturó
            (ej. instalado el día 20 con día de facturación 1). Proporcional: si el mes tiene 30 días
            y se instaló el 20, se cobran 10 días. Es el valor por defecto: el plan y la ficha del
            cliente pueden sobreescribirlo.
          </p>
        </div>

        <!-- Meses de cortesía posteriores a la instalación -->
        <div class="col-span-1 sm:col-span-2">
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Meses de cortesía después de la instalación
          </label>
          <select
            v-model.number="billing.first_invoice_free_months"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2
                  focus:ring focus:ring-blue-500 transition-colors"
          >
            <option :value="0">Ninguno — se cobra todos los meses</option>
            <option :value="1">1 mes gratis (el siguiente a la instalación)</option>
            <option :value="2">2 meses gratis</option>
            <option :value="3">3 meses gratis</option>
          </select>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            Meses que van en cero <strong>después</strong> del de instalación, para promociones del
            tipo "paga el prorrateo y el mes siguiente lo cubre la instalación". La factura igual se
            emite, en $0, para que quede constancia. Normalmente esto se configura en el plan.
          </p>
        </div>

        <!-- Comentarios -->
        <div class="col-span-1 sm:col-span-2 mt-0">
          <label class="block text-gray-800 dark:text-gray-300 font-medium mb-1">
            Comentarios
          </label>
          <textarea 
            v-model="billing.comentarios"
            rows="3"
            class="w-full bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 
                  border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 
                  focus:ring focus:ring-blue-500 transition-colors"
            placeholder="Notas sobre la instalación, configuración especial, etc."
          ></textarea>
        </div>

      </div> 
    </div>
  </div>
</template>

<script setup>
import { computed } from "vue"
import DayPicker from "@/components/DayPicker.vue"

const props = defineProps({
  active: Boolean,
  billing: Object,
  types: Array
})

const MESES = [
  'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
  'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'
]

const HOY = new Date()

/** Día de mes válido (1–31) o null. Los DayPicker guardan "01".."31". */
const dayOf = (value) => {
  const n = parseInt(value, 10)
  return Number.isInteger(n) && n >= 1 && n <= 31 ? n : null
}

const lastDayOf = (year, month) => new Date(year, month + 1, 0).getDate()

/** Espejo de Billing::clampDayToMonth: el día 31 cae al último día en meses cortos. */
const clampDay = (day, year, month) => Math.min(day, lastDayOf(year, month))

const fmtDate = (year, month, day) => `${clampDay(day, year, month)} de ${MESES[month]}`

/** "14:30" → "2:30 p. m." (mismo formato que muestra el input de hora). */
const fmtTime = (value) => {
  const [h, m] = String(value || '00:00').split(':').map((n) => parseInt(n, 10))
  if (!Number.isInteger(h) || !Number.isInteger(m)) return '12:00 a. m.'
  const suffix = h < 12 ? 'a. m.' : 'p. m.'
  const h12 = h % 12 === 0 ? 12 : h % 12
  return `${h12}:${String(m).padStart(2, '0')} ${suffix}`
}

const cfg = computed(() => ({
  emision: dayOf(props.billing?.create_invoice),
  vence: dayOf(props.billing?.pay_day),
  recordatorio: dayOf(props.billing?.remember_day),
  corte: dayOf(props.billing?.cut_day),
  umbral: Math.max(1, parseInt(props.billing?.overdue_invoices, 10) || 1),
  // null = sin tope de facturación (se factura siempre)
  topeExtra: props.billing?.stop_invoicing_extra === null || props.billing?.stop_invoicing_extra === undefined
    ? null
    : Math.max(0, parseInt(props.billing.stop_invoicing_extra, 10) || 0),
  modo: props.billing?.billing_mode || 'anticipado'
}))

/**
 * Opciones del tope de facturación, expresadas en el número final de facturas
 * pendientes (que es como lo piensa el operador), no en el margen suelto.
 * Espejo de Billing::invoiceStopThreshold: umbral de corte + margen.
 */
const topeOpciones = computed(() =>
  [0, 1, 2, 3, 4, 5, 6].map((extra) => {
    const total = cfg.value.umbral + extra
    return {
      value: extra,
      label: `Al acumular ${total} facturas pendientes (corte en ${cfg.value.umbral} + ${extra})`
    }
  })
)

/**
 * Traduce la configuración a fechas reales del mes en curso. Es sólo un espejo
 * de lo que hace el backend (BillingService / OverdueSuspensionService /
 * PaymentReminderService); no decide nada.
 */
const ciclo = computed(() => {
  const c = cfg.value
  const year = HOY.getFullYear()
  const month = HOY.getMonth()

  // Periodo cubierto: anticipado = mes de emisión, vencido = mes anterior.
  const periodoMonth = c.modo === 'vencido' ? month - 1 : month
  const pYear = periodoMonth < 0 ? year - 1 : year
  const pMonth = (periodoMonth + 12) % 12
  const periodo = `1 al ${lastDayOf(pYear, pMonth)} de ${MESES[pMonth]}`

  // Vencimiento: si el día ya pasó respecto de la emisión, se corre al mes siguiente.
  let vencimiento = '—'
  if (c.vence !== null) {
    const emitido = c.emision !== null ? clampDay(c.emision, year, month) : 1
    const vence = clampDay(c.vence, year, month)
    vencimiento = vence < emitido
      ? `${fmtDate(year, (month + 1) % 12, c.vence)}${month === 11 ? ` de ${year + 1}` : ''} (mes siguiente)`
      : fmtDate(year, month, c.vence)
  }

  const corteExplicacion = c.umbral <= 1
    ? 'Con 1, el primer impago ya provoca el corte.'
    : `Con ${c.umbral}, en la práctica el corte llega alrededor de ${c.umbral - 1} ciclo${c.umbral - 1 === 1 ? '' : 's'} después del primer impago.`

  // Tope de facturación: al cortado se le sigue facturando hasta este número
  // de facturas pendientes; después la deuda se congela.
  const tope = c.topeExtra === null
    ? 'sin tope — al moroso se le sigue facturando indefinidamente, aunque ya esté cortado.'
    : `deja de emitirse la mensualidad cuando el cliente acumula ${c.umbral + c.topeExtra} facturas pendientes`
      + `${c.topeExtra === 0 ? ' (justo al llegar al corte)' : `, es decir ${c.topeExtra} ciclo${c.topeExtra === 1 ? '' : 's'} después del corte`}`
      + '. La deuda queda congelada ahí.'

  return {
    mesActual: MESES[month],
    periodo,
    vencimiento,
    umbral: c.umbral,
    corteExplicacion,
    tope,
    emision: c.emision !== null
      ? `${fmtDate(year, month, c.emision)} a las ${fmtTime(props.billing?.create_invoice_time)}`
      : 'sin día configurado (no se emiten facturas automáticas)',
    recordatorio: c.recordatorio !== null
      ? `${fmtDate(year, month, c.recordatorio)} a las ${fmtTime(props.billing?.remember_time)}`
      : 'sin día configurado',
    corte: c.corte !== null
      ? `desde el ${fmtDate(year, month, c.corte)}, ${fmtTime(props.billing?.cut_time)}`
      : 'sin día configurado (corte automático apagado)'
  }
})

/** Combinaciones que suelen ser un error de configuración. Sólo avisan. */
const avisos = computed(() => {
  const c = cfg.value
  const out = []

  if (props.billing?.payment_reminder_enabled && c.recordatorio !== null && c.vence !== null && c.recordatorio >= c.vence) {
    out.push(`El recordatorio (día ${c.recordatorio}) sale el mismo día o después del vencimiento (día ${c.vence}); normalmente se avisa antes.`)
  }

  if (c.emision !== null && c.vence !== null && c.vence < c.emision) {
    out.push(`El día límite de pago (${c.vence}) es anterior al de emisión (${c.emision}): el vencimiento se corre al mes siguiente.`)
  }

  if (c.corte !== null && c.vence !== null && c.corte <= c.vence) {
    out.push(`El día de corte (${c.corte}) no es posterior al vencimiento (${c.vence}): las facturas impagas de este ciclo no se cortarán hasta el mes siguiente.`)
  }

  return out
})
</script>
