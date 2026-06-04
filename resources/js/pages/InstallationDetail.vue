<template>
  <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
    <NotificationToast ref="toast" />

    <!-- Header -->
    <div class="max-w-5xl mx-auto mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3">
        <RouterLink to="/installations"
          class="text-sm text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 flex items-center gap-1">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
          </svg>
          Instalaciones
        </RouterLink>
        <span class="text-gray-400">/</span>
        <h1 class="text-xl font-bold text-gray-800 dark:text-gray-100">Orden #{{ installationId }}</h1>
        <span v-if="installation" :class="statusBadge(installation.status)"
          class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase">
          {{ installation.status }}
        </span>
      </div>
    </div>

    <div v-if="loading" class="text-center py-16">
      <div class="inline-block animate-spin rounded-full h-10 w-10 border-4 border-blue-500 border-t-transparent"></div>
    </div>

    <div v-else-if="!installation" class="max-w-5xl mx-auto bg-white dark:bg-gray-800 rounded-xl p-8 text-center text-gray-500 dark:text-gray-400">
      No se encontró la orden de instalación.
    </div>

    <div v-else class="max-w-5xl mx-auto space-y-6">

      <!-- Card cliente + datos orden -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <h2 class="text-base font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2">
          {{ installation.is_prospect ? 'Prospecto' : 'Cliente' }} y orden
          <span v-if="installation.is_prospect"
            class="text-[10px] uppercase bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 px-2 py-0.5 rounded">
            Pre-venta
          </span>
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
          <div>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ installation.is_prospect ? 'Prospecto' : 'Cliente' }}</p>
            <RouterLink v-if="installation.customer_id"
              :to="`/customers/${installation.customer_id}/edit`"
              class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
              {{ installation.customer_name || installation.customer_email }}
            </RouterLink>
            <RouterLink v-else-if="installation.prospect_id"
              :to="`/prospects`"
              class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
              {{ installation.customer_name || installation.prospect_name }}
            </RouterLink>
            <span v-else class="font-medium text-gray-800 dark:text-gray-100">—</span>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ installation.customer_email || '—' }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ installation.customer_tel || '—' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Fecha programada</p>
            <p class="text-gray-800 dark:text-gray-200">{{ formatDate(installation.scheduled_date) }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Técnico asignado</p>
            <p class="text-gray-800 dark:text-gray-200">{{ installation.technician_name || installation.technician || '— Sin asignar —' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Dirección</p>
            <p class="text-gray-800 dark:text-gray-200">{{ installation.address || '—' }}</p>
          </div>
          <div class="sm:col-span-2">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Equipo / Materiales previstos</p>
            <p class="text-gray-800 dark:text-gray-200">{{ installation.equipment || '—' }}</p>
          </div>
          <div class="sm:col-span-2" v-if="installation.notes">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Observaciones</p>
            <p class="text-gray-800 dark:text-gray-200 whitespace-pre-line">{{ installation.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Conexión / Red -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <h2 class="text-base font-bold text-gray-800 dark:text-white mb-4">Conexión / Red</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Sectorial / Switch / Nodo</label>
            <select v-model.number="sheet.sectorial_id"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
              <option :value="null">— Seleccionar —</option>
              <optgroup v-if="sectorialsByType.sectorial?.length" label="Sectoriales">
                <option v-for="s in sectorialsByType.sectorial" :key="`s-${s.id}`" :value="s.id">
                  {{ s.name }}{{ s.ip ? ` — ${s.ip}` : '' }}
                </option>
              </optgroup>
              <optgroup v-if="sectorialsByType.switch?.length" label="Switches">
                <option v-for="s in sectorialsByType.switch" :key="`w-${s.id}`" :value="s.id">
                  {{ s.name }}{{ s.ip ? ` — ${s.ip}` : '' }}
                </option>
              </optgroup>
              <optgroup v-if="sectorialsByType.nodo?.length" label="Nodos">
                <option v-for="s in sectorialsByType.nodo" :key="`n-${s.id}`" :value="s.id">
                  {{ s.name }}{{ s.ip ? ` — ${s.ip}` : '' }}
                </option>
              </optgroup>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Core / Router</label>
            <select v-model.number="sheet.router_id"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
              <option :value="null">— Seleccionar —</option>
              <option v-for="r in routers" :key="r.id" :value="r.id">
                {{ r.name }}{{ r.ip ? ` — ${r.ip}` : '' }}
                {{ r.pppoe ? ' · PPPoE' : '' }}
              </option>
            </select>
            <p v-if="selectedRouter" class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
              Autenticación: <strong>{{ selectedRouter.pppoe ? 'PPPoE' : 'IP estática / DHCP' }}</strong>
            </p>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Plan del cliente</label>
            <select v-model.number="sheet.plan_id"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
              <option :value="null">— Seleccionar —</option>
              <option v-for="p in plans" :key="p.id" :value="p.id">
                {{ p.name }}{{ p.speed_down ? ` — ${p.speed_down}${p.speed_up ? '/' + p.speed_up : ''} Mbps` : '' }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">VLAN (opcional)</label>
            <input v-model="sheet.vlan" type="text" placeholder="100"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
        </div>

        <!-- IP del cliente con analizador (solo cuando NO es PPPoE) -->
        <div v-if="!isPppoeRouter" class="mt-4">
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">
            IP del cliente
            <span v-if="!sheet.router_id" class="ml-1 text-[10px] normal-case text-gray-400">— selecciona un core para ver IPs libres</span>
          </label>
          <input v-model="sheet.client_ip" type="text" placeholder="192.168.1.100"
            class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm font-mono" />
          <IpRangeAnalyzer v-if="sheet.router_id" v-model="sheet.client_ip" :router-id="sheet.router_id" />
        </div>

        <!-- Sección PPPoE (solo cuando el core tiene PPPoE activo) -->
        <div v-if="isPppoeRouter" class="mt-4 border-t border-gray-100 dark:border-gray-700 pt-4">
          <h3 class="text-sm font-semibold text-blue-600 dark:text-blue-400 mb-3 flex items-center gap-2">
            <span class="inline-block w-2 h-2 rounded-full bg-blue-500"></span>
            Credenciales PPPoE
            <span class="text-[10px] font-normal text-gray-500">(el core usa Control PPPOE)</span>
          </h3>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Usuario PPPoE</label>
              <input v-model="sheet.pppoe_username" type="text" placeholder="juan.perez"
                class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Contraseña PPPoE</label>
              <input v-model="sheet.pppoe_password" type="text"
                class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm font-mono" />
            </div>
          </div>

          <div class="mt-4">
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="sheet.local_address_manual" type="checkbox"
                class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500" />
              <span class="text-sm text-gray-700 dark:text-gray-300">Asignar IP local manualmente</span>
            </label>
            <p class="ml-6 mt-1 text-[11px] text-gray-500 dark:text-gray-400">
              Si lo dejas desmarcado, la IP local se toma del plan
              <strong v-if="planLocalAddress">({{ planLocalAddress }})</strong>
              <strong v-else>(definida en el perfil PPPoE del plan)</strong>.
            </p>

            <div v-if="sheet.local_address_manual" class="mt-3">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">IP local PPPoE</label>
              <input v-model="sheet.pppoe_local_address" type="text" placeholder="10.0.0.1"
                class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm font-mono" />
              <IpRangeAnalyzer v-model="sheet.pppoe_local_address" :router-id="sheet.router_id" />
            </div>
          </div>
        </div>
      </div>

      <!-- Hoja de instalación -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <h2 class="text-base font-bold text-gray-800 dark:text-white mb-4">Hoja técnica de instalación</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Cable utilizado (metros)</label>
            <input v-model="sheet.cable_meters" type="number" min="0" step="0.5"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Nivel de señal</label>
            <input v-model="sheet.signal_level" type="text" placeholder="-25 dBm"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Marca del módem</label>
            <input v-model="sheet.modem_brand" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Modelo del módem</label>
            <input v-model="sheet.modem_model" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">MAC del módem</label>
            <input v-model="sheet.modem_mac" type="text" placeholder="AA:BB:CC:DD:EE:FF"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm font-mono" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Serial ONU</label>
            <input v-model="sheet.onu_serial" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm font-mono" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Modelo de antena (si aplica)</label>
            <input v-model="sheet.antenna_model" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Materiales utilizados</label>
            <textarea v-model="sheet.materials" rows="2"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm resize-none"></textarea>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Observaciones técnicas</label>
            <textarea v-model="sheet.observations" rows="3"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm resize-none"></textarea>
          </div>
        </div>
        <button @click="saveSheet" :disabled="savingSheet"
          class="mt-4 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
          {{ savingSheet ? 'Guardando...' : 'Guardar hoja' }}
        </button>
      </div>

      <!-- Fotos de la instalación -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <h2 class="text-base font-bold text-gray-800 dark:text-white mb-4">Fotos de la instalación</h2>
        <div class="flex flex-wrap items-center gap-3 mb-4">
          <input ref="fileInput" type="file" multiple
            accept=".jpg,.jpeg,.png,.webp"
            @change="onFilesPicked"
            class="text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-indigo-600 file:text-white file:cursor-pointer hover:file:bg-indigo-700" />
          <button @click="uploadFiles" :disabled="!pendingFiles.length || uploading"
            class="bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
            {{ uploading ? 'Subiendo...' : `Subir ${pendingFiles.length || ''}` }}
          </button>
        </div>

        <div v-if="!photos.length" class="text-center py-8 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-gray-500 dark:text-gray-400 text-sm">
          Aún no hay fotos subidas para esta instalación.
        </div>
        <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
          <div v-for="p in photos" :key="p.id"
            class="group relative bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
            <a :href="p.url" target="_blank" rel="noopener" class="block">
              <img :src="p.url" :alt="p.file_name" class="w-full h-32 object-cover" />
            </a>
            <div class="p-2">
              <p class="text-[11px] text-gray-600 dark:text-gray-300 truncate" :title="p.file_name">{{ p.file_name }}</p>
              <button @click="deletePhoto(p)"
                class="mt-1 w-full text-[11px] text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded py-1">
                Eliminar
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Información de Cartera (solo admin / staff / accounting) -->
      <div v-if="showBillingSection"
        class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <h2 class="text-base font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2">
          Información de Cartera
          <span class="text-[10px] uppercase bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400 px-2 py-0.5 rounded">
            Facturación
          </span>
        </h2>

        <!-- Factura vinculada (aparece tras el primer guardado) -->
        <div v-if="installation.invoice_id"
          class="mb-4 flex items-start sm:items-center justify-between gap-3 p-3 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 rounded-lg">
          <div>
            <p class="text-sm font-medium text-indigo-800 dark:text-indigo-200">
              Factura <span class="font-mono">#{{ installation.invoice_number }}</span>
            </p>
            <p class="text-xs text-indigo-600 dark:text-indigo-400 mt-0.5">
              Estado: <span :class="invoiceStatusClass(installation.invoice_status)">{{ invoiceStatusLabel(installation.invoice_status) }}</span>
            </p>
          </div>
          <RouterLink :to="`/billing/invoices/${installation.invoice_id}`"
            class="shrink-0 text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-200 underline whitespace-nowrap">
            Ver factura
          </RouterLink>
        </div>

        <!-- Advertencia de recálculo cuando ya existe factura -->
        <div v-if="installation.invoice_id"
          class="mb-4 flex items-start gap-2 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg">
          <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
          </svg>
          <p class="text-xs text-amber-800 dark:text-amber-300">
            Esta instalación ya tiene factura generada. Editar recalculará la factura automáticamente.
          </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

          <!-- Acuerdo de pago -->
          <div class="sm:col-span-2">
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="billing.payment_agreement" type="checkbox"
                class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500" />
              <span class="text-sm text-gray-700 dark:text-gray-300">Acuerdo de pago</span>
            </label>
          </div>

          <!-- Valor de instalación -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Valor de instalación</label>
            <input v-model="billing.installation_cost" type="number" min="0" step="0.01" placeholder="0"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm"
              :class="{ 'border-red-400 dark:border-red-500': billingErrors.installation_cost }" />
            <p v-if="billingErrors.installation_cost" class="mt-1 text-xs text-red-500">{{ billingErrors.installation_cost[0] }}</p>
          </div>

          <!-- Cargos adicionales -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Cargos adicionales</label>
            <input v-model="billing.additional_charges" type="number" min="0" step="0.01" placeholder="0"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm"
              :class="{ 'border-red-400 dark:border-red-500': billingErrors.additional_charges }" />
            <p v-if="billingErrors.additional_charges" class="mt-1 text-xs text-red-500">{{ billingErrors.additional_charges[0] }}</p>
          </div>

          <!-- Descuento -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Descuento</label>
            <input v-model="billing.discount" type="number" min="0" step="0.01" placeholder="0"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm"
              :class="{ 'border-red-400 dark:border-red-500': billingErrors.discount }" />
            <p v-if="billingErrors.discount" class="mt-1 text-xs text-red-500">{{ billingErrors.discount[0] }}</p>
          </div>

          <!-- Valor recibido -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Valor recibido</label>
            <input v-model="billing.payment_received" type="number" min="0" step="0.01" placeholder="0"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm"
              :class="{ 'border-red-400 dark:border-red-500': billingErrors.payment_received }" />
            <p v-if="billingErrors.payment_received" class="mt-1 text-xs text-red-500">{{ billingErrors.payment_received[0] }}</p>
          </div>

          <!-- Forma de pago -->
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Forma de pago</label>
            <input v-model="billing.payment_method" type="text" placeholder="Efectivo, transferencia, Nequi…"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm"
              :class="{ 'border-red-400 dark:border-red-500': billingErrors.payment_method }" />
            <p v-if="billingErrors.payment_method" class="mt-1 text-xs text-red-500">{{ billingErrors.payment_method[0] }}</p>
          </div>

          <!-- Motivo del descuento (solo visible si discount > 0) -->
          <div v-if="discountIsPositive" class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">
              Motivo del descuento <span class="text-red-500">*</span>
            </label>
            <textarea v-model="billing.discount_reason" rows="2"
              placeholder="Describe el motivo del descuento…"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm resize-none"
              :class="{ 'border-red-400 dark:border-red-500': billingErrors.discount_reason }"></textarea>
            <p v-if="billingErrors.discount_reason" class="mt-1 text-xs text-red-500">{{ billingErrors.discount_reason[0] }}</p>
          </div>

          <!-- Observaciones de pago -->
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Observaciones de pago</label>
            <textarea v-model="billing.payment_notes" rows="2"
              placeholder="Observaciones sobre el pago…"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm resize-none"
              :class="{ 'border-red-400 dark:border-red-500': billingErrors.payment_notes }"></textarea>
            <p v-if="billingErrors.payment_notes" class="mt-1 text-xs text-red-500">{{ billingErrors.payment_notes[0] }}</p>
          </div>

          <!-- Retención de cliente -->
          <div>
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="billing.customer_retention" type="checkbox"
                class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500" />
              <span class="text-sm text-gray-700 dark:text-gray-300">Retención de cliente</span>
            </label>
          </div>

          <!-- Atención especial -->
          <div>
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="billing.special_attention" type="checkbox"
                class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500" />
              <span class="text-sm text-gray-700 dark:text-gray-300">Atención especial</span>
            </label>
          </div>

          <!-- Notas de promoción (solo visible si special_attention activo) -->
          <div v-if="billing.special_attention" class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Notas de promoción</label>
            <textarea v-model="billing.promotion_notes" rows="2"
              placeholder="Detalles de la promoción aplicada…"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm resize-none"
              :class="{ 'border-red-400 dark:border-red-500': billingErrors.promotion_notes }"></textarea>
            <p v-if="billingErrors.promotion_notes" class="mt-1 text-xs text-red-500">{{ billingErrors.promotion_notes[0] }}</p>
          </div>

        </div>

        <button @click="saveBilling" :disabled="savingBilling"
          class="mt-4 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
          {{ savingBilling ? 'Guardando...' : 'Guardar cartera' }}
        </button>
      </div>

      <!-- Firmas y completar -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <h2 class="text-base font-bold text-gray-800 dark:text-white mb-2">Firmas y cierre de orden</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
          Al firmar se genera la hoja de instalación en PDF, se marca como completada y se almacena entre los documentos del cliente.
        </p>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Firma del cliente <span class="text-red-500">*</span></label>
            <div class="bg-white rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 overflow-hidden" style="touch-action: none;">
              <canvas ref="canvasCustomer" width="500" height="180"
                class="w-full h-[180px] cursor-crosshair"
                @pointerdown="(e) => startDraw(e, 'cust')"
                @pointermove="(e) => draw(e, 'cust')"
                @pointerup="endDraw"
                @pointerleave="endDraw"></canvas>
            </div>
            <button @click="clearSig('cust')" type="button"
              class="mt-2 text-xs px-3 py-1.5 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-800 dark:text-white rounded-lg">
              Limpiar
            </button>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Firma del técnico (opcional)</label>
            <div class="bg-white rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 overflow-hidden" style="touch-action: none;">
              <canvas ref="canvasTech" width="500" height="180"
                class="w-full h-[180px] cursor-crosshair"
                @pointerdown="(e) => startDraw(e, 'tech')"
                @pointermove="(e) => draw(e, 'tech')"
                @pointerup="endDraw"
                @pointerleave="endDraw"></canvas>
            </div>
            <button @click="clearSig('tech')" type="button"
              class="mt-2 text-xs px-3 py-1.5 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-800 dark:text-white rounded-lg">
              Limpiar
            </button>
          </div>
        </div>

        <div class="flex flex-wrap gap-3 mt-5">
          <button @click="sign" :disabled="signing || !hasCustSig"
            class="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
            {{ signing ? 'Generando hoja...' : 'Firmar y completar' }}
          </button>
          <button v-if="installation.customer_id" @click="goToContract" type="button"
            class="bg-amber-500 hover:bg-amber-600 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
            Generar / firmar contrato
          </button>
          <button v-else-if="installation.prospect_id && installation.status === 'completada'"
            @click="convertProspect" type="button"
            class="bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
            Convertir prospecto en cliente
          </button>
        </div>
        <p v-if="installation.signed_at" class="mt-3 text-xs text-emerald-600 dark:text-emerald-400">
          ✓ Firmado el {{ formatDate(installation.signed_at) }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import NotificationToast from '@/components/NotificationToast.vue'
import IpRangeAnalyzer from '@/components/IpRangeAnalyzer.vue'

const route  = useRoute()
const router = useRouter()
const toast  = ref(null)

const installationId = computed(() => Number(route.params.id))

const installation = ref(null)
const loading = ref(true)

const sheet = ref({
  // Conexión / red
  sectorial_id: null,
  router_id: null,
  plan_id: null,
  vlan: '',
  client_ip: '',
  pppoe_username: '',
  pppoe_password: '',
  pppoe_local_address: '',
  local_address_manual: false,
  // Hoja técnica
  cable_meters: '',
  signal_level: '',
  modem_brand: '',
  modem_model: '',
  modem_mac: '',
  onu_serial: '',
  antenna_model: '',
  materials: '',
  observations: '',
})
const savingSheet = ref(false)

const billing = ref({
  payment_agreement:  false,
  installation_cost:  null,
  additional_charges: null,
  discount:           null,
  discount_reason:    '',
  payment_method:     '',
  payment_received:   null,
  payment_notes:      '',
  customer_retention: false,
  special_attention:  false,
  promotion_notes:    '',
})
const savingBilling = ref(false)
const billingErrors = ref({})

const sectorials = ref([])
const routers    = ref([])
const plans      = ref([])

const sectorialsByType = computed(() => ({
  sectorial: sectorials.value.filter(s => (s.element_type || 'sectorial') === 'sectorial'),
  switch:    sectorials.value.filter(s => s.element_type === 'switch'),
  nodo:      sectorials.value.filter(s => s.element_type === 'nodo'),
}))

const selectedRouter = computed(() => routers.value.find(r => r.id === sheet.value.router_id))
const selectedPlan   = computed(() => plans.value.find(p => p.id === sheet.value.plan_id))
const isPppoeRouter  = computed(() => !!selectedRouter.value?.pppoe)
const planLocalAddress    = computed(() => selectedPlan.value?.local_address || selectedPlan.value?.pppoe_pool || '')
const showBillingSection  = computed(() => installation.value?.can_edit_billing === true)
const discountIsPositive  = computed(() => Number(billing.value.discount) > 0)

const photos = ref([])
const fileInput = ref(null)
const pendingFiles = ref([])
const uploading = ref(false)

const canvasCustomer = ref(null)
const canvasTech     = ref(null)
const hasCustSig = ref(false)
const hasTechSig = ref(false)
const signing = ref(false)
let ctxCust = null
let ctxTech = null
let drawing = null

const loadInstallation = async () => {
  loading.value = true
  try {
    const { data } = await api.customers.getInstallation(installationId.value)
    installation.value = data
    if (data.can_edit_billing) {
      billing.value = {
        payment_agreement:  data.payment_agreement  ?? false,
        installation_cost:  data.installation_cost  ?? null,
        additional_charges: data.additional_charges ?? null,
        discount:           data.discount           ?? null,
        discount_reason:    data.discount_reason    ?? '',
        payment_method:     data.payment_method     ?? '',
        payment_received:   data.payment_received   ?? null,
        payment_notes:      data.payment_notes      ?? '',
        customer_retention: data.customer_retention ?? false,
        special_attention:  data.special_attention  ?? false,
        promotion_notes:    data.promotion_notes    ?? '',
      }
    }
    if (data.sheet) sheet.value = { ...sheet.value, ...data.sheet }

    const profile = data.customer?.customer_profile
    if (profile) {
      if (sheet.value.sectorial_id == null && profile.sectorial_id) sheet.value.sectorial_id = Number(profile.sectorial_id)
      if (sheet.value.router_id    == null && profile.router_id)    sheet.value.router_id    = Number(profile.router_id)
      if (sheet.value.plan_id      == null && profile.service_id)   sheet.value.plan_id      = Number(profile.service_id)
      if (!sheet.value.client_ip       && profile.ip_user)              sheet.value.client_ip           = profile.ip_user
      if (!sheet.value.pppoe_username  && profile.pppoe_username)       sheet.value.pppoe_username      = profile.pppoe_username
      if (!sheet.value.pppoe_password  && profile.pppoe_password)       sheet.value.pppoe_password      = profile.pppoe_password
      if (!sheet.value.pppoe_local_address && profile.pppoe_local_address) {
        sheet.value.pppoe_local_address  = profile.pppoe_local_address
        sheet.value.local_address_manual = true
      }
    }

    photos.value = (data.documents || []).filter(d => d.type === 'instalacion' && /\.(jpe?g|png|webp)$/i.test(d.file_name))
  } catch {
    installation.value = null
    toast.value?.error('Error', 'No se pudo cargar la orden.')
  } finally {
    loading.value = false
  }
}

const unwrap = (res) => {
  const d = res?.data
  if (Array.isArray(d)) return d
  if (Array.isArray(d?.data)) return d.data
  if (Array.isArray(d?.items)) return d.items
  return []
}

const loadNetworkResources = async () => {
  const results = await Promise.allSettled([
    api.sectorials.getAll(),
    api.routers.getAll(),
    api.plans.getAll(),
  ])
  const [sectorialsRes, routersRes, plansRes] = results
  const errors = []

  if (sectorialsRes.status === 'fulfilled') sectorials.value = unwrap(sectorialsRes.value)
  else errors.push(`sectoriales (${sectorialsRes.reason?.response?.status || 'red'})`)

  if (routersRes.status === 'fulfilled') routers.value = unwrap(routersRes.value)
  else errors.push(`routers (${routersRes.reason?.response?.status || 'red'})`)

  if (plansRes.status === 'fulfilled') plans.value = unwrap(plansRes.value)
  else errors.push(`planes (${plansRes.reason?.response?.status || 'red'})`)

  console.log('[Conexión/Red] cargados:', {
    sectoriales: sectorials.value.length,
    routers: routers.value.length,
    planes: plans.value.length,
  })

  if (errors.length) {
    toast.value?.error('Sin permisos / error de red', `No se pudo cargar: ${errors.join(', ')}.`)
  } else if (!sectorials.value.length && !routers.value.length && !plans.value.length) {
    toast.value?.info('Catálogos vacíos', 'No hay sectoriales/routers/planes creados todavía en este tenant.')
  }
}

const saveSheet = async () => {
  savingSheet.value = true
  try {
    const payload = { ...sheet.value }
    if (payload.cable_meters === '') delete payload.cable_meters
    else payload.cable_meters = Number(payload.cable_meters)

    for (const k of ['sectorial_id', 'router_id', 'plan_id']) {
      if (payload[k] == null || payload[k] === '') delete payload[k]
      else payload[k] = Number(payload[k])
    }

    if (isPppoeRouter.value) {
      delete payload.client_ip
      if (!payload.local_address_manual) delete payload.pppoe_local_address
    } else {
      delete payload.pppoe_username
      delete payload.pppoe_password
      delete payload.pppoe_local_address
      delete payload.local_address_manual
    }

    await api.customers.saveInstallationSheet(installationId.value, payload)
    toast.value?.success('Guardado', 'Hoja de instalación actualizada.')
  } catch (e) {
    toast.value?.error('Error', e.response?.data?.message || 'No se pudo guardar la hoja.')
  } finally {
    savingSheet.value = false
  }
}

const saveBilling = async () => {
  savingBilling.value = true
  billingErrors.value = {}
  try {
    const toNum = (v) => (v !== null && v !== '' ? Number(v) : null)
    const payload = {
      payment_agreement:  billing.value.payment_agreement,
      installation_cost:  toNum(billing.value.installation_cost),
      additional_charges: toNum(billing.value.additional_charges),
      discount:           toNum(billing.value.discount),
      discount_reason:    billing.value.discount_reason || null,
      payment_method:     billing.value.payment_method  || null,
      payment_received:   toNum(billing.value.payment_received),
      payment_notes:      billing.value.payment_notes   || null,
      customer_retention: billing.value.customer_retention,
      special_attention:  billing.value.special_attention,
      promotion_notes:    billing.value.promotion_notes || null,
    }
    const { data } = await api.customers.updateInstallationBilling(installationId.value, payload)
    // Refresh installation with new invoice_id / invoice_number / invoice_status
    if (data.installation) installation.value = data.installation
    if (data.invoice_warning) {
      toast.value?.error('Sin factura', data.invoice_warning)
    } else if (data.invoice) {
      toast.value?.success('Cartera guardada', `Factura #${data.invoice.number} generada correctamente.`)
    } else {
      toast.value?.success('Cartera guardada', 'Información de cartera actualizada.')
    }
  } catch (e) {
    if (e.response?.status === 422) {
      billingErrors.value = e.response.data.errors ?? {}
      toast.value?.error('Validación', 'Revisa los campos marcados.')
    } else {
      toast.value?.error('Error', e.response?.data?.message || 'No se pudo guardar la información de cartera.')
    }
  } finally {
    savingBilling.value = false
  }
}

const invoiceStatusLabel = (status) => ({
  paid:    'Pagada',
  partial: 'Pago parcial',
  issued:  'Pendiente de pago',
  overdue: 'Vencida',
}[status] ?? status ?? '—')

const invoiceStatusClass = (status) => ({
  paid:    'text-emerald-600 dark:text-emerald-400 font-semibold',
  partial: 'text-amber-600 dark:text-amber-400 font-semibold',
  issued:  'text-gray-600 dark:text-gray-400 font-semibold',
  overdue: 'text-red-600 dark:text-red-400 font-semibold',
}[status] ?? '')

const onFilesPicked = (e) => {
  pendingFiles.value = Array.from(e.target.files || [])
}

const uploadFiles = async () => {
  if (!pendingFiles.value.length) return
  uploading.value = true
  try {
    const fd = new FormData()
    pendingFiles.value.forEach(f => fd.append('files[]', f))
    await api.customers.uploadInstallationPhotos(installationId.value, fd)
    pendingFiles.value = []
    if (fileInput.value) fileInput.value.value = ''
    toast.value?.success('Listo', 'Fotos subidas.')
    await loadInstallation()
  } catch (e) {
    toast.value?.error('Error', e.response?.data?.message || 'No se pudieron subir las fotos.')
  } finally {
    uploading.value = false
  }
}

const deletePhoto = async (p) => {
  if (!confirm(`¿Eliminar "${p.file_name}"?`)) return
  try {
    await api.customers.deleteDocument(p.id)
    photos.value = photos.value.filter(x => x.id !== p.id)
    toast.value?.success('Eliminada', 'Foto eliminada.')
  } catch {
    toast.value?.error('Error', 'No se pudo eliminar.')
  }
}

const setupCanvas = (refEl, ref2) => {
  if (!refEl) return null
  const ctx = refEl.getContext('2d')
  ctx.lineWidth = 2.5
  ctx.lineCap = 'round'
  ctx.strokeStyle = '#111827'
  return ctx
}

const pointerPos = (canvas, e) => {
  const rect = canvas.getBoundingClientRect()
  return {
    x: (e.clientX - rect.left) * (canvas.width / rect.width),
    y: (e.clientY - rect.top) * (canvas.height / rect.height),
  }
}

const startDraw = (e, who) => {
  drawing = who
  const canvas = who === 'cust' ? canvasCustomer.value : canvasTech.value
  const ctx    = who === 'cust' ? ctxCust : ctxTech
  if (!canvas || !ctx) return
  const { x, y } = pointerPos(canvas, e)
  ctx.beginPath()
  ctx.moveTo(x, y)
}

const draw = (e, who) => {
  if (drawing !== who) return
  const canvas = who === 'cust' ? canvasCustomer.value : canvasTech.value
  const ctx    = who === 'cust' ? ctxCust : ctxTech
  if (!canvas || !ctx) return
  const { x, y } = pointerPos(canvas, e)
  ctx.lineTo(x, y)
  ctx.stroke()
  if (who === 'cust') hasCustSig.value = true
  else hasTechSig.value = true
}

const endDraw = () => { drawing = null }

const clearSig = (who) => {
  const canvas = who === 'cust' ? canvasCustomer.value : canvasTech.value
  const ctx    = who === 'cust' ? ctxCust : ctxTech
  if (!canvas || !ctx) return
  ctx.clearRect(0, 0, canvas.width, canvas.height)
  if (who === 'cust') hasCustSig.value = false
  else hasTechSig.value = false
}

const sign = async () => {
  if (!hasCustSig.value) {
    toast.value?.error('Falta firma', 'La firma del cliente es obligatoria.')
    return
  }
  signing.value = true
  try {
    const payload = {
      customer_signature: canvasCustomer.value.toDataURL('image/png'),
    }
    if (hasTechSig.value) payload.technician_signature = canvasTech.value.toDataURL('image/png')

    await api.customers.signInstallation(installationId.value, payload)
    toast.value?.success('Completada', 'Instalación firmada y orden cerrada.')
    clearSig('cust'); clearSig('tech')
    await loadInstallation()
  } catch (e) {
    toast.value?.error('Error', e.response?.data?.message || 'No se pudo firmar.')
  } finally {
    signing.value = false
  }
}

const goToContract = () => {
  router.push({ path: `/customers/${installation.value.customer_id}/edit`, query: { tab: 'documents' } })
}

const convertProspect = () => {
  router.push({
    path: '/customers/create',
    query: {
      prospect_id: installation.value.prospect_id,
      return_to: `/installations/${installationId.value}`,
    },
  })
}

const statusBadge = (s) => ({
  pendiente:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  completada: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  cancelada:  'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
}[s] || 'bg-gray-100 text-gray-600')

const formatDate = (d) => {
  if (!d) return ''
  const date = new Date(d.includes && d.includes('T') ? d : d + 'T00:00:00')
  return date.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

onMounted(async () => {
  await Promise.all([loadInstallation(), loadNetworkResources()])
  await nextTick()
  ctxCust = setupCanvas(canvasCustomer.value)
  ctxTech = setupCanvas(canvasTech.value)
})
</script>
