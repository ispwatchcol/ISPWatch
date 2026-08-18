<template>
  <div class="space-y-5">

    <!-- ── Resumen financiero ─────────────────────────────────────────── -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

      <!-- Saldo pendiente neto -->
      <div :class="netBalance > 0
          ? 'bg-gradient-to-br from-rose-500 to-red-600 text-white'
          : 'bg-gradient-to-br from-emerald-500 to-teal-600 text-white'"
        class="rounded-2xl p-5 shadow-lg">
        <p class="text-xs font-semibold uppercase tracking-widest opacity-80">Saldo Pendiente</p>
        <p class="text-3xl font-bold mt-2">${{ fmt(netBalance) }}</p>
        <p class="text-xs mt-1 opacity-70">
          {{ netBalance > 0 ? 'Monto que el cliente aún debe' : 'El cliente está al día' }}
        </p>
        <!-- Arrastre: no se debe hoy, se cobra en la próxima factura. -->
        <p v-if="carryoverBalance > 0" class="text-xs mt-2 bg-white/20 rounded-lg px-2 py-1 inline-block">
          ↷ ${{ fmt(carryoverBalance) }} para la próxima factura
        </p>
      </div>

      <!-- Saldo a favor -->
      <div :class="creditBalance > 0
          ? 'bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg'
          : 'bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700'"
        class="rounded-2xl p-5 relative">
        <div class="flex items-start justify-between">
          <p class="text-xs font-semibold uppercase tracking-widest"
            :class="creditBalance > 0 ? 'opacity-80' : 'text-gray-500 dark:text-gray-400'">
            Saldo a Favor
          </p>
          <button @click="openCreditModal"
            title="Ajustar saldo a favor"
            :class="creditBalance > 0
              ? 'text-white/70 hover:text-white'
              : 'text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400'"
            class="p-1 rounded-lg transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
              <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/>
            </svg>
          </button>
        </div>
        <p class="text-3xl font-bold mt-2"
          :class="creditBalance > 0 ? '' : 'text-gray-800 dark:text-white'">
          ${{ fmt(creditBalance) }}
        </p>
        <p class="text-xs mt-1"
          :class="creditBalance > 0 ? 'opacity-70' : 'text-gray-400 dark:text-gray-500'">
          {{ creditBalance > 0 ? 'Se aplicará a la próxima factura' : 'Sin crédito acumulado' }}
        </p>
        <button @click="openMovementsModal"
          class="mt-2 text-xs underline"
          :class="creditBalance > 0 ? 'opacity-80 hover:opacity-100' : 'text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400'">
          Ver movimientos
        </button>
      </div>

      <!-- Facturas abiertas -->
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">Facturas Abiertas</p>
        <p class="text-3xl font-bold mt-2 text-gray-800 dark:text-white">{{ openInvoices.length }}</p>
        <p class="text-xs mt-1 text-gray-400 dark:text-gray-500">
          {{ openInvoices.length === 1 ? 'factura con saldo pendiente' : 'facturas con saldo pendiente' }}
        </p>
      </div>

      <!-- Acción -->
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 flex flex-col justify-between">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">Acción</p>
        <button @click="openPaymentModal(null)"
          class="mt-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-2.5 rounded-xl transition">
          + Registrar Pago
        </button>
      </div>
    </div>

    <!-- ── Banner: saldo a favor ──────────────────────────────────────── -->
    <Transition name="slide-down">
      <div v-if="creditBalance > 0"
        class="flex items-start gap-4 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 rounded-2xl px-5 py-4">
        <div class="shrink-0 w-10 h-10 bg-indigo-100 dark:bg-indigo-800 rounded-xl flex items-center justify-center text-indigo-600 dark:text-indigo-300 text-xl font-bold">
          ✦
        </div>
        <div>
          <p class="font-semibold text-indigo-800 dark:text-indigo-200">Este cliente tiene ${{ fmt(creditBalance) }} a su favor</p>
          <p class="text-sm text-indigo-600 dark:text-indigo-400 mt-0.5">
            Este crédito proviene de un pago anterior mayor al saldo que debía.
            Se descontará automáticamente de la próxima factura que se genere.
            <template v-if="netBalance > 0">
              El saldo real a cobrar hoy es <strong>${{ fmt(netBalance) }}</strong>
              ({{ fmt(grossBalance) }} de facturas − {{ fmt(creditBalance) }} de crédito).
            </template>
            <template v-else>
              En este momento el cliente no debe nada; el crédito cubre todas sus facturas abiertas.
            </template>
          </p>
        </div>
      </div>
    </Transition>

    <!-- ── Banner: saldo arrastrado de abonos parciales ───────────────── -->
    <Transition name="slide-down">
      <div v-if="carryoverBalance > 0"
        class="flex items-start gap-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-2xl px-5 py-4">
        <div class="shrink-0 w-10 h-10 bg-amber-100 dark:bg-amber-800 rounded-xl flex items-center justify-center text-amber-600 dark:text-amber-300 text-xl font-bold">
          ↷
        </div>
        <div>
          <p class="font-semibold text-amber-800 dark:text-amber-200">
            ${{ fmt(carryoverBalance) }} de saldo pendiente arrastrado
          </p>
          <p class="text-sm text-amber-700 dark:text-amber-400 mt-0.5">
            El cliente abonó menos del total en una o más facturas: esas facturas quedaron cerradas como pagadas
            y el faltante se sumará automáticamente a la próxima factura mensual. Hoy no cuenta como mora
            ni provoca corte.
          </p>
        </div>
      </div>
    </Transition>

    <!-- ── Banner: facturas vencidas ─────────────────────────────────── -->
    <Transition name="slide-down">
      <div v-if="overdueInvoices.length > 0"
        class="flex items-start gap-4 bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-700 rounded-2xl px-5 py-4">
        <div class="shrink-0 w-10 h-10 bg-rose-100 dark:bg-rose-800 rounded-xl flex items-center justify-center text-rose-600 dark:text-rose-300 text-xl font-bold">
          !
        </div>
        <div>
          <p class="font-semibold text-rose-800 dark:text-rose-200">
            {{ overdueInvoices.length }} factura{{ overdueInvoices.length > 1 ? 's' : '' }} vencida{{ overdueInvoices.length > 1 ? 's' : '' }}
          </p>
          <p class="text-sm text-rose-600 dark:text-rose-400 mt-0.5">
            Saldo vencido acumulado: <strong>${{ fmt(overdueInvoices.reduce((s, i) => s + Number(i.balance_due), 0)) }}</strong>.
            Se recomienda gestionar el cobro o suspender el servicio.
          </p>
        </div>
      </div>
    </Transition>

    <!-- ── Loading ────────────────────────────────────────────────────── -->
    <div v-if="loading" class="text-center py-10 text-gray-500 dark:text-gray-400">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-indigo-500 border-t-transparent"></div>
      <p class="mt-3 text-sm">Cargando facturas...</p>
    </div>

    <!-- ── Sin facturas ───────────────────────────────────────────────── -->
    <div v-else-if="invoices.length === 0"
      class="text-center py-12 bg-gray-50 dark:bg-gray-900 rounded-2xl border border-dashed border-gray-300 dark:border-gray-700">
      <p class="text-gray-500 dark:text-gray-400">Este cliente no tiene facturas registradas.</p>
    </div>

    <!-- ── Tabla de facturas ──────────────────────────────────────────── -->
    <div v-else class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-2xl">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wider">
          <tr>
            <th class="px-4 py-3 text-left">Factura</th>
            <th class="px-4 py-3 text-left">Periodo</th>
            <th class="px-4 py-3 text-left">Vencimiento</th>
            <th class="px-4 py-3 text-right">Total</th>
            <th class="px-4 py-3 text-right">Saldo</th>
            <th class="px-4 py-3 text-center">Estado</th>
            <th class="px-4 py-3 text-center">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <tr v-for="inv in invoices" :key="inv.id"
            class="bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900/40 transition">
            <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">#{{ inv.number }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ fmtDate(inv.period_start) }} — {{ fmtDate(inv.period_end) }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
              <span :class="isOverdue(inv) ? 'text-rose-600 dark:text-rose-400 font-medium' : ''">
                {{ fmtDate(inv.due_date) }}
              </span>
            </td>
            <td class="px-4 py-3 text-right text-gray-800 dark:text-white">${{ fmt(inv.total) }}</td>
            <td class="px-4 py-3 text-right font-semibold"
              :class="Number(inv.balance_due) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">
              ${{ fmt(inv.balance_due) }}
            </td>
            <td class="px-4 py-3 text-center">
              <span :class="statusClass(inv.status)" class="px-2.5 py-1 rounded-full text-[11px] font-semibold uppercase">
                {{ statusLabel(inv.status) }}
              </span>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-center gap-2">
                <button v-if="Number(inv.balance_due) > 0"
                  @click="openPaymentModal(inv)"
                  class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-medium transition">
                  Cargar pago
                </button>
                <button @click="downloadPdf(inv)"
                  class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-xs font-medium transition">
                  PDF
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Modal extracto de saldo a favor ──────────────────────────────── -->
    <!-- Es lo que le faltaba a quien cobra en el mostrador: poder responder
         "¿por qué la factura dice 60.000 y me cobran 36.000?" en el momento. -->
    <Teleport to="body">
      <Transition name="modal">
        <div v-if="showMovementsModal"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
          @click.self="showMovementsModal = false">
          <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl p-6 max-h-[80vh] overflow-y-auto">

            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">Movimientos del saldo a favor</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
              De dónde salió el saldo y en qué facturas se gastó.
            </p>

            <!-- Si el libro y el saldo guardado no coinciden hay un problema y
                 es mejor que se vea aquí que en el mostrador. -->
            <div v-if="Math.abs(movementsDiscrepancy) >= 0.01"
              class="mb-4 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-4 py-3">
              <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">
                Descuadre de ${{ fmt(Math.abs(movementsDiscrepancy)) }}
              </p>
              <p class="text-xs text-amber-700 dark:text-amber-300 mt-0.5">
                La suma de los movimientos (${{ fmt(movementsLedger) }}) no coincide con el saldo
                guardado (${{ fmt(movementsCached) }}). Reportar al administrador.
              </p>
            </div>

            <div v-if="movementsLoading" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
              Cargando…
            </div>

            <div v-else-if="!movements.length" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
              Este cliente no tiene movimientos de saldo registrados.
            </div>

            <table v-else class="min-w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                  <th class="py-2 pr-3 font-medium whitespace-nowrap">Fecha</th>
                  <th class="py-2 pr-3 font-medium">Concepto</th>
                  <th class="py-2 pr-3 font-medium text-right whitespace-nowrap">Monto</th>
                  <th class="py-2 font-medium text-right whitespace-nowrap">Saldo</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <tr v-for="m in movements" :key="m.id">
                  <td class="py-2.5 pr-3 whitespace-nowrap text-gray-600 dark:text-gray-400">
                    {{ new Date(m.created_at).toLocaleDateString('es-CO') }}
                  </td>
                  <td class="py-2.5 pr-3 text-gray-800 dark:text-gray-200">
                    {{ m.reason || MOVEMENT_LABELS[m.type] || m.type }}
                  </td>
                  <td class="py-2.5 pr-3 text-right whitespace-nowrap font-medium"
                    :class="Number(m.amount) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                    {{ Number(m.amount) >= 0 ? '+' : '−' }}${{ fmt(Math.abs(Number(m.amount))) }}
                  </td>
                  <td class="py-2.5 text-right whitespace-nowrap text-gray-600 dark:text-gray-400">
                    ${{ fmt(Number(m.balance_after)) }}
                  </td>
                </tr>
              </tbody>
            </table>

            <div class="flex justify-end pt-5">
              <button type="button" @click="showMovementsModal = false"
                class="px-5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 rounded-xl transition">
                Cerrar
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ── Modal ajuste saldo a favor ───────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="modal">
        <div v-if="showCreditModal"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
          @click.self="showCreditModal = false">
          <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-sm p-6">

            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">Ajustar Saldo a Favor</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
              Saldo actual: <strong>${{ fmt(creditBalance) }}</strong>
            </p>

            <form @submit.prevent="submitCreditUpdate" class="space-y-4">
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Nuevo Saldo a Favor</label>
                <input v-model.number="creditForm.amount" type="number" step="0.01" min="0" required
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Razón del ajuste</label>
                <input v-model="creditForm.reason" type="text" placeholder="Ej: corrección de pago duplicado"
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>

              <p v-if="creditError" class="text-sm text-rose-600 dark:text-rose-400">{{ creditError }}</p>

              <div class="flex gap-3 pt-1">
                <button type="submit" :disabled="creditSubmitting"
                  class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-medium transition">
                  {{ creditSubmitting ? 'Guardando...' : 'Guardar' }}
                </button>
                <button type="button" @click="showCreditModal = false"
                  class="px-5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 rounded-xl transition">
                  Cancelar
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ── Modal de pago ──────────────────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="modal">
        <div v-if="showModal"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
          @click.self="showModal = false">
          <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">

            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">Registrar Pago</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
              <template v-if="targetInvoice">
                Factura #{{ targetInvoice.number }} — saldo ${{ fmt(targetInvoice.balance_due) }}
              </template>
              <template v-else>
                El pago se aplicará a las facturas más antiguas (FIFO).
              </template>
            </p>

            <!-- Cliente cortado: se avisa antes de cobrar, no después -->
            <div v-if="isSuspended"
              class="flex items-start gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl px-4 py-3 mb-4">
              <span class="text-lg leading-none">⚠</span>
              <div class="text-sm">
                <p class="font-semibold text-amber-800 dark:text-amber-300">Este cliente está SUSPENDIDO</p>
                <p class="text-amber-700 dark:text-amber-400 mt-0.5">
                  Al registrar el pago se reactivará automáticamente si queda sin saldo vencido.
                </p>
              </div>
            </div>

            <form @submit.prevent="submitPayment" class="space-y-4">
              <!-- Monto -->
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Valor</label>
                <input v-model.number="payForm.amount" type="number" step="0.01" min="0.01" required
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />

                <!-- Badge tipo de pago -->
                <Transition name="fade">
                  <div v-if="modalPaymentType" class="mt-2 flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-xl border w-fit"
                    :class="modalPaymentTypeMeta.classes">
                    <span>{{ modalPaymentTypeMeta.icon }}</span>
                    <span>{{ modalPaymentTypeMeta.label }}</span>
                  </div>
                </Transition>
              </div>

              <!-- Info exceso -->
              <Transition name="slide-down">
                <div v-if="modalPaymentType === 'excess'"
                  class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 rounded-xl px-4 py-3 text-sm text-indigo-700 dark:text-indigo-300">
                  El excedente de <strong>${{ fmt(payForm.amount - (targetInvoice ? Number(targetInvoice.balance_due) : netBalance)) }}</strong>
                  se guardará como saldo a favor y se descontará automáticamente de la próxima factura.
                </div>
              </Transition>

              <!-- Info pago parcial -->
              <Transition name="slide-down">
                <div v-if="modalPaymentType === 'partial'"
                  class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
                  La factura quedará <strong>pagada</strong> y los
                  <strong>${{ fmt((targetInvoice ? Number(targetInvoice.balance_due) : netBalance) - payForm.amount) }}</strong>
                  restantes se cobrarán en la próxima factura del cliente.
                  Al no quedar saldo vencido, el cliente sale de mora y, si estaba cortado, se reconecta.
                </div>
              </Transition>

              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Forma de Pago</label>
                  <select v-model="payForm.method"
                    class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.name">{{ pm.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha</label>
                  <input v-model="payForm.payment_date" type="date" required
                    class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                </div>
              </div>

              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Referencia</label>
                <input v-model="payForm.reference" type="text" placeholder="No. comprobante / transacción"
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>

              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Notas</label>
                <textarea v-model="payForm.notes" rows="2"
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
              </div>

              <p v-if="modalError" class="text-sm text-rose-600 dark:text-rose-400">{{ modalError }}</p>

              <div class="flex gap-3 pt-1">
                <button type="submit" :disabled="submitting"
                  class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-medium transition">
                  {{ submitting ? 'Procesando...' : 'Confirmar Pago' }}
                </button>
                <button type="button" @click="showModal = false"
                  class="px-5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 rounded-xl transition">
                  Cancelar
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import billingService from '@/services/billing'
import { apiClient } from '@/services/api'

const props = defineProps({
  customerId: { type: [String, Number], required: true },
})
const emit = defineEmits(['notify'])

const invoices       = ref([])
const grossBalance   = ref(0)   // sum of invoice balance_due
const creditBalance  = ref(0)   // credit_balance from overpayments
const netBalance     = ref(0)   // what they actually owe
// Faltante de abonos parciales cuyas facturas ya se cerraron: se cobra en la
// próxima factura, así que NO entra en el saldo pendiente de hoy.
const carryoverBalance = ref(0)
// Estado de corte del cliente: se avisa antes de cobrar, porque registrar el
// pago lo reconecta automáticamente.
const suspension     = ref(null)
const isSuspended    = computed(() => suspension.value?.is_suspended === true)
const loading        = ref(true)
const showModal      = ref(false)
const submitting     = ref(false)
const modalError     = ref('')
const targetInvoice  = ref(null)
const tenantId         = ref(null)
const paymentMethods   = ref([])
const showCreditModal  = ref(false)
const creditSubmitting = ref(false)
const creditError      = ref('')
const creditForm       = ref({ amount: 0, reason: '' })

// Extracto del saldo a favor
const showMovementsModal   = ref(false)
const movementsLoading     = ref(false)
const movements            = ref([])
const movementsLedger      = ref(0)
const movementsCached      = ref(0)
const movementsDiscrepancy = ref(0)

const MOVEMENT_LABELS = {
  earned:   'Excedente de un pago',
  applied:  'Aplicado a una factura',
  adjusted: 'Ajuste manual',
  reversed: 'Pago anulado',
}

async function openMovementsModal () {
  showMovementsModal.value = true
  movementsLoading.value = true

  try {
    const { data } = await billingService.getCreditMovements(props.customerId)
    movements.value            = data.movements?.data ?? []
    movementsLedger.value      = data.ledger_balance ?? 0
    movementsCached.value      = data.cached_balance ?? 0
    movementsDiscrepancy.value = data.discrepancy ?? 0
  } catch (e) {
    movements.value = []
    movementsDiscrepancy.value = 0
  } finally {
    movementsLoading.value = false
  }
}

const payForm = ref({
  amount: 0,
  method: '',
  payment_date: new Date().toISOString().split('T')[0],
  reference: '',
  notes: '',
})

const openInvoices    = computed(() => invoices.value.filter(i => Number(i.balance_due) > 0))
const overdueInvoices = computed(() => invoices.value.filter(i => i.status === 'overdue'))

const isOverdue = (inv) => inv.status === 'overdue'
const fmt       = (n) => Number(n || 0).toLocaleString('es-CO')
const fmtDate   = (d) => (d ? String(d).split('T')[0] : '—')

const statusLabel = (s) => ({
  paid: 'Pagada', partial: 'Parcial', overdue: 'Vencida',
  issued: 'Emitida', void: 'Anulada', cancelled: 'Cancelada',
}[s] || s)

const statusClass = (s) => ({
  paid:     'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  partial:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  overdue:  'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
  issued:   'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
}[s] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300')

// Payment type for the modal badge
const modalPaymentType = computed(() => {
  const amt = Number(payForm.value.amount)
  const ref = targetInvoice.value ? Number(targetInvoice.value.balance_due) : netBalance.value
  if (!amt || !ref) return null
  if (amt === ref) return 'exact'
  if (amt < ref)   return 'partial'
  return 'excess'
})

const modalPaymentTypeMeta = computed(() => ({
  exact:   { label: 'Pago exacto',    classes: 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-700', icon: '✓' },
  partial: { label: 'Pago parcial',   classes: 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-700',           icon: '⬇' },
  excess:  { label: 'Pago en exceso', classes: 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-400 border-indigo-200 dark:border-indigo-700',     icon: '⬆' },
}[modalPaymentType.value] ?? { label: '', classes: '', icon: '' }))

const fetchData = async () => {
  loading.value = true
  try {
    const [invRes, balRes] = await Promise.all([
      billingService.getInvoices({ customer_id: props.customerId }),
      billingService.getBalance(props.customerId),
    ])
    invoices.value      = invRes.data.data ?? invRes.data ?? []
    grossBalance.value  = balRes.data.balance        ?? 0
    creditBalance.value = balRes.data.credit_balance ?? 0
    netBalance.value    = balRes.data.net_balance    ?? balRes.data.balance ?? 0
    carryoverBalance.value = balRes.data.carryover_balance ?? 0
    suspension.value    = balRes.data.suspension ?? null
  } catch (e) {
    console.error('Error cargando facturación:', e)
    emit('notify', { type: 'error', title: 'Error', message: 'No se pudo cargar la facturación del cliente.' })
  } finally {
    loading.value = false
  }
}

const loadPaymentMethods = async () => {
  try {
    const { data } = await apiClient.get('/billing/payment-methods')
    paymentMethods.value = data.filter(m => m.is_active)
  } catch (e) {
    console.error('Error cargando formas de pago:', e)
  }
}

const openPaymentModal = (inv) => {
  targetInvoice.value = inv
  modalError.value    = ''
  payForm.value = {
    amount: inv ? Number(inv.balance_due) : Number(netBalance.value) || 0,
    method: paymentMethods.value[0]?.name ?? '',
    payment_date: new Date().toISOString().split('T')[0],
    reference: '',
    notes: '',
  }
  showModal.value = true
}

const submitPayment = async () => {
  modalError.value = ''
  if (!payForm.value.amount || payForm.value.amount <= 0) {
    modalError.value = 'Ingrese un valor válido.'
    return
  }
  submitting.value = true
  try {
    const payload = {
      tenant_id:    tenantId.value,
      customer_id:  props.customerId,
      amount:       payForm.value.amount,
      payment_date: payForm.value.payment_date,
      method:       payForm.value.method,
      reference:    payForm.value.reference || null,
      notes:        payForm.value.notes || null,
    }
    if (targetInvoice.value) {
      payload.allocations = [{ invoice_id: targetInvoice.value.id, amount: payForm.value.amount }]
    }
    const res = await billingService.registerPayment(payload)
    showModal.value = false

    // Si el cliente estaba cortado, el desenlace de la reconexión es la noticia
    // importante del recaudo — no que el pago se guardó.
    const r = res?.data?.reactivation
    if (r?.was_suspended && r?.reactivated && r?.router_ok) {
      emit('notify', { type: 'success', title: 'Pago registrado y cliente reactivado', message: r.message })
    } else if (r?.was_suspended && r?.reactivated) {
      emit('notify', { type: 'error', title: 'Pago registrado — revisar reconexión', message: r.message })
    } else if (r?.was_suspended) {
      emit('notify', { type: 'warning', title: 'Pago registrado — sigue suspendido', message: r.message })
    } else {
      emit('notify', { type: 'success', title: 'Pago registrado', message: 'El pago fue aplicado correctamente.' })
    }
    await fetchData()
  } catch (e) {
    modalError.value = e.response?.data?.message || 'Error al registrar el pago.'
  } finally {
    submitting.value = false
  }
}

const downloadPdf = async (inv) => {
  try {
    const res = await billingService.downloadPdf(inv.id)
    const url  = window.URL.createObjectURL(new Blob([res.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `Factura-${inv.number}.pdf`)
    document.body.appendChild(link)
    link.click()
    link.remove()
  } catch (e) {
    emit('notify', { type: 'error', title: 'Error', message: 'No se pudo descargar el PDF.' })
  }
}

const openCreditModal = () => {
  creditForm.value = { amount: creditBalance.value, reason: '' }
  creditError.value = ''
  showCreditModal.value = true
}

const submitCreditUpdate = async () => {
  creditError.value = ''
  creditSubmitting.value = true
  try {
    await billingService.updateCredit(props.customerId, creditForm.value.amount, creditForm.value.reason)
    showCreditModal.value = false
    emit('notify', { type: 'success', title: 'Saldo actualizado', message: 'El saldo a favor fue ajustado correctamente.' })
    await fetchData()
  } catch (e) {
    creditError.value = e.response?.data?.message || 'Error al actualizar el saldo.'
  } finally {
    creditSubmitting.value = false
  }
}

onMounted(() => {
  const stored = localStorage.getItem('userData') || sessionStorage.getItem('userData')
  if (stored) tenantId.value = JSON.parse(stored).tenant_id
  fetchData()
  loadPaymentMethods()
})
</script>

<style scoped>
.slide-down-enter-active, .slide-down-leave-active { transition: all 0.25s ease; }
.slide-down-enter-from, .slide-down-leave-to       { opacity: 0; transform: translateY(-8px); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.fade-enter-from, .fade-leave-to       { opacity: 0; transform: translateY(-4px); }

.modal-enter-active, .modal-leave-active { transition: opacity 0.2s ease; }
.modal-enter-from, .modal-leave-to       { opacity: 0; }
</style>
