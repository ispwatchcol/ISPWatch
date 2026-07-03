<template>
    <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
      <main class="flex-1 p-4 md:p-8">
        <NotificationToast ref="toast" />

        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
          <div>
            <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
              <div class="inline-flex items-center justify-center p-2 bg-blue-100 dark:bg-blue-900/30 rounded-xl">
                <v-icon name="fa-user-edit" class="text-blue-600 dark:text-blue-400 w-6 h-6 md:w-7 md:h-7" />
              </div>
              Editar Cliente
            </h1>
            <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
              Modifica los datos del cliente, su configuración de servicio y credenciales
            </p>
          </div>

          <button
            @click="goBack"
            class="bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300
                   px-4 py-2.5 rounded-xl flex items-center gap-2 hover:bg-gray-300
                   dark:hover:bg-gray-600 transition-all shadow-md w-full sm:w-auto justify-center"
          >
            <v-icon name="md-arrowback" class="w-4 h-4" />
            Volver
          </button>
        </div>

        <!-- Tabs -->
        <div class="max-w-5xl mx-auto mb-6">
          <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700 overflow-x-auto">
            <button
              v-for="tab in tabs" :key="tab.key"
              @click="activeTab = tab.key"
              type="button"
              :class="[
                'px-4 sm:px-6 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition',
                activeTab === tab.key
                  ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'
              ]"
            >
              {{ tab.label }}
            </button>
          </div>
        </div>

        <!-- Loading (solo pestaña Datos) -->
        <div v-if="loadingData && activeTab === 'datos'" class="text-center py-12">
          <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
          <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando datos...</p>
        </div>

        <!-- Form Card -->
        <div v-else-if="activeTab === 'datos'" class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden max-w-5xl mx-auto">

          <!-- Progress Header -->
          <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-6 text-white">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-medium opacity-90">Formulario de Cliente</span>
              <span class="text-xs opacity-75">* Campos obligatorios</span>
            </div>
            <div class="h-1 bg-blue-500/30 rounded-full overflow-hidden">
              <div class="h-full bg-white rounded-full" style="width: 100%"></div>
            </div>
          </div>

          <form @submit.prevent="handleSubmit(true)" @keydown="onFormKeydown" class="p-6 md:p-8">

            <!-- Sección 1: Datos de Acceso -->
            <div class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                    <span class="text-blue-600 dark:text-blue-400 font-bold text-sm">1</span>
                </div>
                Datos de Acceso
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                <label class="label">
                    <v-icon name="md-email" class="w-4 h-4 mr-1 inline" />
                    Correo personal (contacto) *
                </label>
                <input v-model="form.email" type="email" required class="input"
                    placeholder="ejemplo@empresa.com" />
                <p class="hint">Correo del cliente para contacto/notificaciones. No se usa para iniciar sesión.</p>
                </div>

                <div>
                <label class="label">
                    <v-icon name="md-lock" class="w-4 h-4 mr-1 inline" />
                    Correo de acceso (inicio de sesión)
                </label>
                <input :value="emailTenant" type="text" readonly tabindex="-1"
                    class="input bg-gray-100 dark:bg-gray-900 text-gray-600 dark:text-gray-300 cursor-not-allowed"
                    placeholder="—" />
                <p class="hint">Con este correo el cliente inicia sesión. No se puede editar para no romper su acceso.</p>
                </div>

                <div>
                <label class="label">
                    <v-icon name="md-lock" class="w-4 h-4 mr-1 inline" />
                    Nueva contraseña <span class="text-gray-400 font-normal text-xs">(opcional)</span>
                </label>
                <input v-model="form.password" type="password" class="input"
                    placeholder="Dejar vacío para no cambiar" />
                <p class="hint">Déjalo en blanco para conservar la contraseña actual.</p>
                </div>

                <div>
                <label class="label">
                    <v-icon name="bi-headset" class="w-4 h-4 mr-1 inline" />
                    Teléfono
                </label>
                <input v-model="form.tel" type="tel" class="input"
                    placeholder="+57 300 123 4567" />
                <p class="hint">Número de contacto del cliente (opcional).</p>
                </div>
            </div>
            </div>

            <!-- Sección 2: Información del Cliente -->
            <div class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <div class="w-8 h-8 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                    <span class="text-purple-600 dark:text-purple-400 font-bold text-sm">2</span>
                </div>
                Información del Cliente
            </h3>

            <!-- ¿Es empresa? Si lo es, el apellido deja de ser obligatorio -->
            <div class="flex items-start gap-3 mb-5 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40 border border-gray-200 dark:border-gray-600">
                <button type="button" role="switch" :aria-checked="form.is_company"
                    @click="form.is_company = !form.is_company"
                    :class="form.is_company ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors mt-0.5">
                    <span :class="form.is_company ? 'translate-x-6' : 'translate-x-1'"
                        class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"></span>
                </button>
                <div>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                        <v-icon name="bi-building" class="w-4 h-4" /> ¿Es empresa?
                    </span>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        Marca esta opción si el cliente es una empresa. El apellido dejará de ser obligatorio.
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                <label class="label">
                    <v-icon name="bi-person" class="w-4 h-4 mr-1 inline" />
                    {{ form.is_company ? 'Nombre / Razón social *' : 'Nombre *' }}
                </label>
                <input v-model="form.name" type="text" required class="input"
                    :placeholder="form.is_company ? 'Ej: Comercializadora XYZ S.A.S.' : 'Ej: Juan'" />
                </div>

                <div>
                <label class="label">
                    <v-icon name="bi-person" class="w-4 h-4 mr-1 inline" />
                    Apellidos <span v-if="!form.is_company">*</span>
                    <span v-else class="text-gray-400 font-normal text-xs">(opcional)</span>
                </label>
                <input v-model="form.last_name" type="text" :required="!form.is_company" class="input"
                    :disabled="form.is_company"
                    placeholder="Ej: Pérez" />
                </div>

                <div>
                <label class="label">
                    <v-icon name="bi-person-badge" class="w-4 h-4 mr-1 inline" />
                    Cédula
                </label>
                <input v-model="form.cedula" type="text" class="input"
                    placeholder="Ej: 1234567890" />
                </div>

                <div>
                <label class="label">
                    <v-icon name="md-locationon" class="w-4 h-4 mr-1 inline" />
                    Ciudad
                </label>
                <input v-model="form.city" type="text" class="input"
                    placeholder="Ej: La Vega" />
                </div>

                <div class="md:col-span-2">
                <label class="label">
                    <v-icon name="md-locationon" class="w-4 h-4 mr-1 inline" />
                    Departamento
                </label>
                <input v-model="form.state" type="text" class="input"
                    placeholder="Ej: Cundinamarca" />
                </div>

                <div class="md:col-span-2">
                <label class="label">
                    <v-icon name="md-locationon" class="w-4 h-4 mr-1 inline" />
                    Dirección
                </label>
                <input v-model="form.address" type="text" class="input"
                    placeholder="Ej: Calle 10 #5-20, Barrio El Centro" />
                </div>

                <div>
                <label class="label">
                    <v-icon name="bi-paperclip" class="w-4 h-4 mr-1 inline" />
                    Precinto <span class="text-gray-400 font-normal text-xs">(fibra óptica)</span>
                </label>
                <input v-model="form.precinto" type="text" class="input"
                    placeholder="Ej: PR-00123" />
                </div>

                <div>
                <label class="label">
                    <v-icon name="bi-calendar" class="w-4 h-4 mr-1 inline" />
                    Fecha de instalación
                </label>
                <DatePicker v-model="form.installation_date" placeholder="dd/mm/aaaa" />
                <p class="hint">Fecha en que se instaló el servicio (opcional).</p>
                </div>

                <div>
                <label class="label">
                    <v-icon name="md-payments-outlined" class="w-4 h-4 mr-1 inline" />
                    Estrato <span class="text-gray-400 font-normal text-xs">(facturación)</span>
                </label>
                <input v-model.number="form.estrato" type="number" min="1" class="input"
                    placeholder="Ej: 3" />
                </div>

                <!-- No facturar: deja al cliente fuera del ciclo automático -->
                <div class="md:col-span-2 flex items-start gap-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                <button type="button" role="switch" :aria-checked="form.exclude_from_billing"
                    @click="form.exclude_from_billing = !form.exclude_from_billing"
                    :class="form.exclude_from_billing ? 'bg-amber-600' : 'bg-gray-300 dark:bg-gray-600'"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors mt-0.5">
                    <span :class="form.exclude_from_billing ? 'translate-x-6' : 'translate-x-1'"
                        class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"></span>
                </button>
                <div>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                        <v-icon name="md-moneyoff" class="w-4 h-4" /> No facturar a este cliente
                    </span>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        No se le generarán facturas mensuales, no recibirá recordatorios ni notificaciones
                        (correo/WhatsApp) y el corte automático por mora lo ignorará. Úsalo para clientes
                        de facturación manual o especiales.
                    </p>
                </div>
                </div>

                <div class="md:col-span-2">
                <label class="label">
                    <v-icon name="md-description" class="w-4 h-4 mr-1 inline" />
                    Comentario / Observaciones
                </label>
                <textarea v-model="form.comments" rows="3" class="input resize-y"
                    placeholder="Notas internas sobre el cliente (opcional)"></textarea>
                <p class="hint">Información adicional visible solo para el equipo (no se muestra al cliente).</p>
                </div>
            </div>

            <!-- Ubicación en mapa -->
            <div class="mt-6 p-4 rounded-xl bg-gray-50 dark:bg-gray-700/40 border border-gray-200 dark:border-gray-600">
                <h4 class="text-sm font-semibold text-gray-600 dark:text-gray-300 mb-3 flex items-center gap-1.5">
                    <v-icon name="md-locationon" class="w-4 h-4" /> Ubicación en Mapa
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="label">Latitud</label>
                    <input v-model="form.latitude" type="number" step="any" class="input"
                        placeholder="Ej: 4.710989" />
                </div>
                <div>
                    <label class="label">Longitud</label>
                    <input v-model="form.longitude" type="number" step="any" class="input"
                        placeholder="Ej: -74.072092" />
                </div>
                </div>
                <p class="hint">
                    Las coordenadas permiten mostrar al cliente en el mapa de cobertura. Puedes obtenerlas haciendo clic derecho en Google Maps.
                </p>
            </div>
            </div>

            <!-- Sección 3: Configuración del Servicio -->
            <div class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <div class="w-8 h-8 bg-orange-100 dark:bg-orange-900/30 rounded-lg flex items-center justify-center">
                    <span class="text-orange-600 dark:text-orange-400 font-bold text-sm">3</span>
                </div>
                Configuración del Servicio
            </h3>

            <!-- ¿Es fibra? Habilita el selector de OLT y convierte el sectorial en la caja (NAP) -->
            <div class="flex items-start gap-3 mb-3 p-3 rounded-xl bg-cyan-50 dark:bg-cyan-900/20 border border-cyan-200 dark:border-cyan-800">
                <button type="button" role="switch" :aria-checked="form.is_fiber"
                    @click="toggleFiber"
                    :class="form.is_fiber ? 'bg-cyan-600' : 'bg-gray-300 dark:bg-gray-600'"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors mt-0.5">
                    <span :class="form.is_fiber ? 'translate-x-6' : 'translate-x-1'"
                        class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"></span>
                </button>
                <div>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                        <v-icon name="bi-ethernet" class="w-4 h-4" /> ¿Es fibra (FTTH)?
                    </span>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        Si el servicio es por fibra, elige la <strong>OLT</strong> y la <strong>caja (NAP)</strong> a la que se conecta el cliente.
                    </p>
                </div>
            </div>

            <div :class="['grid gap-4', serviceGridClass]">
                <!-- IP del Usuario -->
                <div>
                <label class="label">
                    <v-icon name="bi-hdd-network" class="w-4 h-4 mr-1 inline" />
                    IP del Usuario
                    <span v-if="loadingFreeIps" class="ml-1 text-xs text-blue-400 animate-pulse">cargando...</span>
                    <span v-else-if="ipStats.free > 0" class="ml-1 text-xs text-green-500">{{ ipStats.free }} libres</span>
                </label>
                <input v-model="form.ip_user" type="text" class="svc-input"
                    placeholder="192.168.1.100" />
                </div>

                <div>
                <label class="label">
                    <v-icon name="bi-speedometer2" class="w-4 h-4 mr-1 inline" />
                    Plan de Servicio
                </label>
                <SearchableSelect
                    :model-value="form.service_id"
                    @update:model-value="form.service_id = $event || null"
                    :items="filteredPlans"
                    item-key="id"
                    item-label="name"
                    item-icon="md-speed"
                    :placeholder="planPlaceholder"
                    search-placeholder="Buscar plan..."
                    :clearable="true"
                    clear-label="Sin plan"
                    :disabled="!form.router_id"
                />
                <!-- El plan depende del router: la lista solo muestra planes del
                     modo de control del router seleccionado. Sin router no hay opciones. -->
                <p v-if="planHint" class="mt-1 text-xs text-amber-400">{{ planHint }}</p>
                </div>

                <!-- OLT: solo en fibra, lista únicamente elementos OLT -->
                <div v-if="form.is_fiber">
                <label class="label">
                    <v-icon name="md-router" class="w-4 h-4 mr-1 inline" />
                    OLT
                </label>
                <SearchableSelect
                    :model-value="form.olt_id"
                    @update:model-value="form.olt_id = $event || null"
                    :items="oltSectorials"
                    item-key="id"
                    item-label="name"
                    item-icon="md-router"
                    placeholder="Seleccionar OLT..."
                    search-placeholder="Buscar OLT..."
                    :clearable="true"
                    clear-label="Sin OLT"
                />
                <p class="hint">Solo se listan elementos de red de tipo OLT.</p>
                </div>

                <div>
                <label class="label">
                    <v-icon name="bi-broadcast-pin" class="w-4 h-4 mr-1 inline" />
                    {{ form.is_fiber ? 'Caja (NAP)' : 'Sectorial' }}
                </label>
                <SearchableSelect
                    :model-value="form.sectorial_id"
                    @update:model-value="form.sectorial_id = $event || null"
                    :items="sectorialItems"
                    item-key="id"
                    item-label="name"
                    item-icon="md-celltower"
                    :placeholder="form.is_fiber ? 'Seleccionar caja...' : 'Seleccionar sectorial...'"
                    :search-placeholder="form.is_fiber ? 'Buscar caja...' : 'Buscar sectorial...'"
                    :clearable="true"
                    :clear-label="form.is_fiber ? 'Sin caja' : 'Sin sectorial'"
                />
                </div>

                <!-- Puerto NAP: solo cuando el elemento seleccionado es una caja NAP -->
                <div v-if="selectedSectorialIsNap">
                <label class="label">
                    <v-icon name="bi-ethernet" class="w-4 h-4 mr-1 inline" />
                    Puerto NAP
                </label>
                <input v-model="form.nap_port" type="text" class="svc-input"
                    placeholder="Ej: 3" />
                <p class="hint">Puerto de la caja NAP que ocupa el cliente.</p>
                </div>

                <div>
                <label class="label">
                    <v-icon name="bi-router" class="w-4 h-4 mr-1 inline" />
                    Router
                </label>
                <SearchableSelect
                    :model-value="form.router_id"
                    @update:model-value="form.router_id = $event || null"
                    :items="routers"
                    item-key="id"
                    item-label="name"
                    item-icon="bi-hdd-network"
                    placeholder="Seleccionar router..."
                    search-placeholder="Buscar router..."
                    :clearable="true"
                    clear-label="Sin router"
                />
                </div>
            </div>

            <!-- Estado del servicio -->
            <div class="mt-5">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <label class="label mb-0">
                        <v-icon name="bi-activity" class="w-4 h-4 mr-1 inline" />
                        Estado del servicio
                    </label>
                    <span v-if="isCourtesyPlan" class="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 font-medium">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Plan de cortesía — fijado en Gratis automáticamente
                    </span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 max-w-2xl">
                    <button
                        v-for="opt in statusOptions"
                        :key="opt.value"
                        type="button"
                        :disabled="isStatusDisabled(opt.value)"
                        :title="opt.value === 'gratis' && !isCourtesyPlan ? 'El estado Gratis solo aplica a planes de cortesía' : ''"
                        @click="selectStatus(opt.value)"
                        :class="[
                            'flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg border text-sm font-medium transition-all',
                            form.service_status === opt.value
                                ? opt.activeClass + ' shadow-sm'
                                : 'bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-600 hover:border-gray-300 dark:hover:border-gray-500',
                            isStatusDisabled(opt.value) ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'
                        ]"
                    >
                        <span class="w-2 h-2 rounded-full" :class="form.service_status === opt.value ? 'bg-white' : opt.dotClass"></span>
                        {{ opt.label }}
                    </button>
                </div>
            </div>

            <!-- IP RANGE ANALYZER -->
            <div v-if="freeIpsLoaded && parsedRanges.length === 0 && form.router_id" class="mt-4 flex items-center gap-2 text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg px-3 py-2">
              <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              El router seleccionado no tiene rangos IP configurados. Agrégalos en <strong class="mx-1">Editar Router → Rangos IP</strong> para usar el analizador.
            </div>
            <div v-if="parsedRanges.length > 0 || loadingFreeIps" class="mt-4">
              <div class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden bg-white dark:bg-gray-800 shadow-sm">
                <!-- Header -->
                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-3 flex items-center justify-between">
                  <div class="flex items-center gap-2 text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <span class="font-semibold text-sm">Analizador de IPs</span>
                  </div>
                  <button
                    v-if="!loadingFreeIps && parsedRanges.length > 0"
                    type="button"
                    @click="toggleAll"
                    class="text-white/90 hover:text-white text-xs font-medium underline-offset-2 hover:underline"
                  >{{ allExpanded ? 'Colapsar todo' : 'Expandir todo' }}</button>
                  <div v-if="loadingFreeIps" class="flex items-center gap-1.5 text-white/80 text-xs">
                    <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Consultando...
                  </div>
                </div>
                <!-- Stats -->
                <div class="grid grid-cols-3 divide-x divide-gray-200 dark:divide-gray-700 border-b border-gray-200 dark:border-gray-700">
                  <div class="px-4 py-3 text-center">
                    <p class="text-xl font-bold text-gray-800 dark:text-white">{{ ipStats.total }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total hosts</p>
                  </div>
                  <div class="px-4 py-3 text-center">
                    <p class="text-xl font-bold text-green-600 dark:text-green-400">{{ ipStats.free }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Libres</p>
                  </div>
                  <div class="px-4 py-3 text-center">
                    <p class="text-xl font-bold text-red-500 dark:text-red-400">{{ ipStats.used }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Ocupadas</p>
                  </div>
                </div>
                <!-- Progress bar -->
                <div class="px-4 py-2 bg-gray-50 dark:bg-gray-900/50">
                  <div class="flex items-center gap-2">
                    <div class="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                      <div
                        class="h-full rounded-full transition-all duration-500"
                        :class="ipStats.usagePercent > 80 ? 'bg-red-500' : ipStats.usagePercent > 50 ? 'bg-amber-500' : 'bg-green-500'"
                        :style="{ width: ipStats.usagePercent + '%' }"
                      ></div>
                    </div>
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-400 w-10 text-right">{{ ipStats.usagePercent }}%</span>
                  </div>
                </div>
                <!-- IP Grid per range (acordeón) -->
                <div v-for="(range, idx) in parsedRanges" :key="idx" class="border-t border-gray-200 dark:border-gray-700">
                  <button
                    type="button"
                    @click="toggleRange(idx)"
                    class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-900/30 flex items-center justify-between text-left hover:bg-gray-100 dark:hover:bg-gray-900/50 transition-colors"
                  >
                    <span class="flex items-center gap-2">
                      <svg
                        class="w-3.5 h-3.5 text-gray-400 transition-transform shrink-0"
                        :class="expandedRanges.has(idx) ? 'rotate-90' : ''"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                      ><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                      <span class="text-xs font-mono font-semibold text-gray-700 dark:text-gray-300">🌐 {{ range.cidr }}</span>
                    </span>
                    <span class="text-xs text-gray-500">
                      {{ range.hosts.length }} hosts ·
                      <span class="text-green-600 dark:text-green-400 font-medium">{{ range.freeHosts.length }} libres</span>
                    </span>
                  </button>
                  <div v-if="expandedRanges.has(idx)" class="px-4 py-3 max-h-48 overflow-y-auto">
                    <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-1">
                      <button
                        v-for="ip in range.hosts"
                        :key="ip"
                        type="button"
                        @click="range.freeSet.has(ip) && (form.ip_user = ip)"
                        :class="[
                          'px-1 py-1 text-[10px] font-mono rounded transition-all truncate',
                          !range.freeSet.has(ip)
                            ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 cursor-not-allowed line-through'
                            : form.ip_user === ip
                              ? 'bg-blue-500 text-white cursor-pointer ring-2 ring-blue-400'
                              : 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 hover:bg-green-200 dark:hover:bg-green-900/40 cursor-pointer'
                        ]"
                        :title="!range.freeSet.has(ip) ? 'IP en uso' : 'Click para asignar'"
                        :disabled="!range.freeSet.has(ip)"
                      >{{ ip.split('.').pop() }}</button>
                    </div>
                  </div>
                </div>
                <!-- Legend -->
                <div class="px-4 py-2 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 flex items-center gap-4 flex-wrap">
                  <div class="flex items-center gap-1.5">
                    <div class="w-3 h-3 rounded bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-700"></div>
                    <span class="text-[10px] text-gray-500 dark:text-gray-400">Libre (click = asignar)</span>
                  </div>
                  <div class="flex items-center gap-1.5">
                    <div class="w-3 h-3 rounded bg-blue-500 border border-blue-400"></div>
                    <span class="text-[10px] text-gray-500 dark:text-gray-400">Seleccionada</span>
                  </div>
                  <div class="flex items-center gap-1.5">
                    <div class="w-3 h-3 rounded bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-700"></div>
                    <span class="text-[10px] text-gray-500 dark:text-gray-400">En uso</span>
                  </div>
                  <div v-if="form.ip_user" class="ml-auto text-[10px] text-blue-600 dark:text-blue-400 font-medium">
                    ✓ {{ form.ip_user }} seleccionada
                  </div>
                </div>
              </div>
            </div>

            <!-- Alerta: plan PPPoE pero router sin Control PPPOE -->
            <div v-if="pppoeMismatch" class="mt-4 flex items-start gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 rounded-lg px-4 py-3">
                <span class="text-amber-500 text-lg leading-none mt-0.5">⚠</span>
                <p class="text-sm text-amber-800 dark:text-amber-300">
                El plan seleccionado es <strong>PPPoE</strong> pero el router
                <strong>{{ selectedRouter?.name }}</strong> no tiene habilitado el
                <strong>Control PPPOE</strong>. Activa esa opción en el router o selecciona uno compatible.
                </p>
            </div>
            </div>

            <!-- Sección: Credenciales PPPoE (obligatorio cuando el router tiene Control PPPOE activo) -->
            <div v-if="showPppoeSection" class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
                <div class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg flex items-center justify-center">
                    <v-icon name="bi-broadcast-pin" class="text-indigo-600 dark:text-indigo-400 w-4 h-4" />
                </div>
                Credenciales PPPoE
                <span class="text-sm font-normal text-indigo-600 dark:text-indigo-400 ml-1">(requerido — el router usa Control PPPOE)</span>
            </h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 ml-10">
                El secret PPPoE se creará / actualizará automáticamente en <strong>{{ selectedRouter?.name }}</strong> al guardar.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                <label class="label">Usuario PPPoE *</label>
                <input v-model="form.pppoe_username" type="text"
                    :class="pppoeUserError ? '!border-red-500 focus:!ring-red-500' : ''"
                    class="input"
                    placeholder="juan.perez" />
                <p v-if="pppoeUserError" class="mt-1 text-xs text-red-500">{{ pppoeUserError }}</p>
                </div>

                <div>
                <label class="label">Contraseña PPPoE *</label>
                <input v-model="form.pppoe_password" type="text"
                    :class="pppoePassError ? '!border-red-500 focus:!ring-red-500' : ''"
                    class="input"
                    placeholder="Contraseña del servicio PPPoE" />
                <p v-if="pppoePassError" class="mt-1 text-xs text-red-500">{{ pppoePassError }}</p>
                </div>

                <div class="md:col-span-2">
                <label class="label">IP Local <span class="text-gray-400 font-normal text-xs">(opcional)</span></label>
                <input v-model="form.pppoe_local_address" type="text" class="input"
                    placeholder="Ej: 10.0.0.1" />
                <p class="hint">Es el local-address del secret PPPoE. Déjalo vacío para que lo defina el perfil/router.</p>
                </div>
            </div>
            </div>

            <!-- Sección: Credenciales HotSpot (obligatorio cuando el router usa Control HotSpot) -->
            <div v-if="showHotspotSection" class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
                <div class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg flex items-center justify-center">
                    <v-icon name="bi-wifi" class="text-indigo-600 dark:text-indigo-400 w-4 h-4" />
                </div>
                Credenciales HotSpot
                <span class="text-sm font-normal text-indigo-600 dark:text-indigo-400 ml-1">(requerido — el router usa Control HotSpot)</span>
            </h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 ml-10">
                El usuario HotSpot se creará / actualizará automáticamente en <strong>{{ selectedRouter?.name }}</strong> al guardar.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                <label class="label">Usuario HotSpot *</label>
                <input v-model="form.hotspot_username" type="text" class="input"
                    placeholder="juan.perez" />
                </div>

                <div>
                <label class="label">Contraseña HotSpot *</label>
                <input v-model="form.hotspot_password" type="text" class="input"
                    placeholder="Contraseña del acceso HotSpot" />
                </div>
            </div>
            </div>

            <!-- Sección: MAC del cliente (obligatorio cuando el router usa DHCP Leases) -->
            <div v-if="showDhcpSection" class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
                <div class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg flex items-center justify-center">
                    <v-icon name="bi-hdd-network" class="text-indigo-600 dark:text-indigo-400 w-4 h-4" />
                </div>
                Dirección MAC
                <span class="text-sm font-normal text-indigo-600 dark:text-indigo-400 ml-1">(requerido — el router usa DHCP Leases)</span>
            </h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 ml-10">
                Se creará / actualizará un lease DHCP estático que enlaza la IP <strong>{{ form.ip_user || '—' }}</strong> a esta MAC en <strong>{{ selectedRouter?.name }}</strong>.
            </p>

            <div>
                <label class="label">MAC del equipo *</label>
                <input v-model="form.mac_address" type="text" class="input font-mono"
                    placeholder="AA:BB:CC:DD:EE:FF" />
                <p class="hint">Formato AA:BB:CC:DD:EE:FF.</p>
            </div>
            </div>

            <!-- Error inline general -->
            <div v-if="errorMsg" class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg text-sm">
            {{ errorMsg }}
            </div>

            <!-- Botones -->
            <!-- Dos acciones: "Guardar" persiste solo en la base de datos; "Guardar y
                 cargar a RB" re-sincroniza además la configuración en el router. -->
            <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t border-gray-200 dark:border-gray-700">
            <button type="button" @click="goBack"
                class="py-3 px-6 border-2 border-gray-300 dark:border-gray-600 rounded-xl
                       text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700
                       transition-all font-medium flex items-center justify-center gap-2 sm:w-auto"
                :disabled="loading">
                <v-icon name="md-close" class="w-5 h-5" />
                Cancelar
            </button>
            <button type="button" @click="handleSubmit(false)" :disabled="loading || pppoeMismatch"
                class="flex-1 py-3 px-6 border-2 border-blue-600 dark:border-blue-500
                       text-blue-700 dark:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/20
                       rounded-xl transition-all font-medium
                       disabled:opacity-50 disabled:cursor-not-allowed
                       flex items-center justify-center gap-2">
                <v-icon v-if="loading && loadingMode === 'db'" name="bi-arrow-repeat" animation="spin" class="w-5 h-5" />
                <v-icon v-else name="md-save" class="w-5 h-5" />
                {{ loading && loadingMode === 'db' ? 'Guardando...' : 'Guardar' }}
            </button>
            <button type="submit" :disabled="loading || pppoeMismatch"
                class="flex-1 py-3 px-6 bg-gradient-to-r from-blue-600 to-blue-700
                       hover:from-blue-700 hover:to-blue-800 text-white rounded-xl
                       transition-all font-medium shadow-lg hover:shadow-xl
                       transform hover:-translate-y-0.5
                       disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none
                       flex items-center justify-center gap-2">
                <v-icon v-if="loading && loadingMode === 'rb'" name="bi-arrow-repeat" animation="spin" class="w-5 h-5" />
                <v-icon v-else name="bi-hdd-network" class="w-5 h-5" />
                {{ loading && loadingMode === 'rb' ? 'Guardando...' : 'Guardar y cargar a RB' }}
            </button>
            </div>
        </form>
        </div>

        <!-- Pestaña: Facturación -->
        <div v-if="activeTab === 'facturacion'" class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-6 md:p-8 max-w-5xl mx-auto">
          <CustomerBilling :customer-id="route.params.id" @notify="onNotify" />
        </div>

        <!-- Pestaña: Documentos -->
        <div v-if="activeTab === 'documentos'" class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-6 md:p-8 max-w-5xl mx-auto">
          <CustomerDocuments :customer-id="route.params.id" @notify="onNotify" />
        </div>

        <!-- Pestaña: Tickets -->
        <div v-if="activeTab === 'tickets'" class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-6 md:p-8 max-w-5xl mx-auto">
          <CustomerTickets :customer-id="route.params.id" />
        </div>

      </main>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'
import NotificationToast from '@/components/NotificationToast.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'
import CustomerBilling from '@/components/customer/CustomerBilling.vue'
import CustomerDocuments from '@/components/customer/CustomerDocuments.vue'
import CustomerTickets from '@/components/customer/CustomerTickets.vue'
import DatePicker from '@/components/DatePicker.vue'

const router = useRouter()
const route  = useRoute()
const toast  = ref(null)

const tabs = [
  { key: 'datos',       label: 'Datos del Cliente' },
  { key: 'facturacion', label: 'Facturación' },
  { key: 'documentos',  label: 'Documentos' },
  { key: 'tickets',     label: 'Tickets' },
]
const activeTab = ref('datos')

const onNotify = ({ type, title, message }) => {
  const fn = toast.value?.[type] || toast.value?.info
  fn?.(title, message)
}

const form = ref({
    email: '',
    password: '',
    tel: '',
    name: '',
    last_name: '',
    is_company: false,
    cedula: '',
    city: '',
    state: '',
    address: '',
    precinto: '',
    installation_date: '',
    estrato: null,
    exclude_from_billing: false,
    comments: '',
    latitude: '',
    longitude: '',
    ip_user: '',
    service_id: null,
    sectorial_id: null,
    olt_id: null,
    nap_port: '',
    is_fiber: false,
    router_id: null,
    service_status: 'activo',
    create_pppoe_secret: false,
    pppoe_username: '',
    pppoe_password: '',
    pppoe_local_address: '',
    hotspot_username: '',
    hotspot_password: '',
    mac_address: '',
})

const loading        = ref(false)
// Qué acción está en curso: 'rb' = guardar + cargar a RB, 'db' = solo guardar.
const loadingMode    = ref(null)
const loadingData    = ref(true)
const errorMsg       = ref('')
const pppoeUserError = ref('')
const pppoePassError = ref('')
const emailTenant    = ref('')
// Mientras carga el cliente + catálogos, evita que los watchers de "limpieza"
// (p. ej. el reset del plan al cambiar de router) borren los valores recién
// cargados antes de que filteredPlans tenga datos. Ver onMounted.
const initializing   = ref(true)

const plans      = ref([])
const sectorials = ref([])
const routers    = ref([])

// ¿El elemento de red seleccionado es una caja NAP? (para pedir el puerto NAP)
const selectedSectorialIsNap = computed(() => {
    const s = sectorials.value.find(el => el.id === form.value.sectorial_id)
    return s?.element_type === 'nap'
})

// Si el elemento deja de ser NAP, descartar el puerto para no guardar datos obsoletos.
watch(selectedSectorialIsNap, (isNap) => {
    if (!isNap) form.value.nap_port = ''
})

// ── Fibra: el selector de OLT lista solo elementos OLT; el de "sectorial" pasa
// a representar la caja (NAP). En modo inalámbrico se muestran los elementos no
// pertenecientes a la planta de fibra.
const FIBER_ELEMENT_TYPES = ['olt', 'splitter', 'nap', 'mufa']
const oltSectorials      = computed(() => sectorials.value.filter(s => s.element_type === 'olt'))
const cajaSectorials     = computed(() => sectorials.value.filter(s => s.element_type === 'nap'))
const wirelessSectorials = computed(() => sectorials.value.filter(s => !FIBER_ELEMENT_TYPES.includes(s.element_type)))
const sectorialItems     = computed(() => form.value.is_fiber ? cajaSectorials.value : wirelessSectorials.value)

// Columnas del grid de "Configuración del Servicio" según los controles visibles:
// base 4 (IP/Plan/Sectorial-Caja/Router) + OLT (fibra) + Puerto NAP (caja seleccionada).
// Se ajusta para que nunca quede una sola tarjeta huérfana en la última fila.
const serviceColCount = computed(() => {
    let n = 4
    if (form.value.is_fiber) n++
    if (selectedSectorialIsNap.value) n++
    return n
})
const serviceGridClass = computed(() => ({
    4: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4',
    5: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-5',
    6: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
}[serviceColCount.value] || 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4'))

// Alternar fibra: al desactivarla limpia OLT y la caja; al activarla descarta el
// sectorial inalámbrico previo (solo se permiten cajas NAP).
const toggleFiber = () => {
    form.value.is_fiber = !form.value.is_fiber
    if (!form.value.is_fiber) {
        form.value.olt_id = null
        if (selectedSectorialIsNap.value) form.value.sectorial_id = null
    } else if (form.value.sectorial_id && !selectedSectorialIsNap.value) {
        form.value.sectorial_id = null
    }
}

// ── IP Range Analyzer ────────────────────────────────────────────────────────
const rangosIpStr    = ref('')
const usedIpsSet     = ref(new Set())
const loadingFreeIps  = ref(false)
const freeIpsLoaded   = ref(false)
const expandedRanges  = ref(new Set())   // índices de segmentos abiertos (acordeón)

const parseCIDR = (cidr, usedSet) => {
    const m = cidr.match(/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/)
    if (!m) return null
    const prefix = parseInt(m[2])
    if (prefix < 20 || prefix > 30) return null
    const parts = m[1].split('.').map(Number)
    const ipLong    = ((parts[0] << 24) | (parts[1] << 16) | (parts[2] << 8) | parts[3]) >>> 0
    const mask      = (0xFFFFFFFF << (32 - prefix)) >>> 0
    const network   = (ipLong & mask) >>> 0
    const broadcast = (network | (~mask >>> 0)) >>> 0
    const hosts = [], freeHosts = []
    for (let i = network + 1; i < broadcast; i++) {
        const ip = [(i >>> 24) & 255, (i >>> 16) & 255, (i >>> 8) & 255, i & 255].join('.')
        hosts.push(ip)
        if (!usedSet.has(ip)) freeHosts.push(ip)
    }
    return { cidr, hosts, freeHosts, freeSet: new Set(freeHosts) }
}

const parsedRanges = computed(() => {
    if (!rangosIpStr.value) return []
    return rangosIpStr.value.split('\n').map(l => l.trim()).filter(Boolean)
        .map(cidr => parseCIDR(cidr, usedIpsSet.value)).filter(Boolean)
})

// ── Acordeón de segmentos ────────────────────────────────────────────────────
const toggleRange = (idx) => {
    const s = new Set(expandedRanges.value)
    s.has(idx) ? s.delete(idx) : s.add(idx)
    expandedRanges.value = s
}

const allExpanded = computed(() =>
    parsedRanges.value.length > 0 && expandedRanges.value.size === parsedRanges.value.length
)

const toggleAll = () => {
    expandedRanges.value = allExpanded.value
        ? new Set()
        : new Set(parsedRanges.value.map((_, i) => i))
}

const ipStats = computed(() => {
    const total = parsedRanges.value.reduce((s, r) => s + r.hosts.length, 0)
    const free  = parsedRanges.value.reduce((s, r) => s + r.freeHosts.length, 0)
    const used  = total - free
    return { total, free, used, usagePercent: total > 0 ? Math.round((used / total) * 100) : 0 }
})

const loadFreeIps = async (routerId) => {
    rangosIpStr.value = ''
    usedIpsSet.value  = new Set()
    expandedRanges.value = new Set()
    freeIpsLoaded.value = false
    if (!routerId) return
    loadingFreeIps.value = true
    try {
        const res = await api.routers.getFreeIps(routerId)
        rangosIpStr.value = res.data.rangos_ip ?? ''
        usedIpsSet.value  = new Set(res.data.used_ips ?? [])
        // Abrir automáticamente el segmento que contiene la IP ya asignada
        const currentIp = form.value.ip_user
        if (currentIp) {
            const idx = parsedRanges.value.findIndex(r => r.hosts.includes(currentIp))
            if (idx !== -1) expandedRanges.value = new Set([idx])
        }
    } catch (e) {
        console.warn('No se pudieron cargar IPs libres:', e)
    } finally {
        loadingFreeIps.value = false
        freeIpsLoaded.value  = true
    }
}

watch(() => form.value.router_id, (id) => { if (id) loadFreeIps(id) })

const selectedPlan   = computed(() => plans.value.find(p => p.id === form.value.service_id))
const selectedRouter = computed(() => routers.value.find(r => r.id === form.value.router_id))

const filteredPlans = computed(() => {
    const r = selectedRouter.value
    if (!r) return []
    let code = null
    if (r.pppoe)             code = 'pppoe'
    else if (r.hotspot)      code = 'hotspot'
    else if (r.control_pcq)  code = 'pcq'
    else if (r.simple_queue) code = 'queue'
    if (!code) return []
    return plans.value.filter(p => (p.type_plan?.code ?? '') === code)
})

// Placeholder y nota del campo Plan: como la lista depende del router, hay que
// guiar al operador para que no parezca que "no hay planes".
const planPlaceholder = computed(() =>
    !form.value.router_id ? 'Seleccioná un router primero' : 'Seleccionar plan...'
)
const planHint = computed(() => {
    if (!form.value.router_id) return 'Elegí un router para ver sus planes disponibles.'
    if (filteredPlans.value.length === 0) {
        return 'Este router no tiene planes compatibles con su modo de control.'
    }
    return ''
})

watch(() => form.value.router_id, () => {
    // No tocar el plan durante la carga inicial: en ese momento filteredPlans aún
    // puede estar vacío (catálogos sin llegar) y borraría el plan ya guardado.
    if (initializing.value) return
    if (form.value.service_id && !filteredPlans.value.find(p => p.id === form.value.service_id)) {
        form.value.service_id = null
    }
})

// Courtesy plans force the 'gratis' state automatically.
const isCourtesyPlan = computed(() => !!selectedPlan.value?.is_courtesy)
watch(isCourtesyPlan, (courtesy) => {
    if (courtesy) {
        form.value.service_status = 'gratis'
    } else if (form.value.service_status === 'gratis') {
        form.value.service_status = 'activo'
    }
})

const statusOptions = [
    { value: 'activo',     label: 'Activo',     activeClass: 'bg-green-500 text-white border-green-500',     dotClass: 'bg-green-500' },
    { value: 'suspendido', label: 'Suspendido', activeClass: 'bg-amber-500 text-white border-amber-500',     dotClass: 'bg-amber-500' },
    { value: 'cancelado',  label: 'Cancelado',  activeClass: 'bg-red-500 text-white border-red-500',         dotClass: 'bg-red-500' },
    { value: 'gratis',     label: 'Gratis',     activeClass: 'bg-indigo-500 text-white border-indigo-500', dotClass: 'bg-indigo-500' },
]

// 'Gratis' está reservado para planes de cortesía (se aplica solo). En un plan
// de cortesía solo se puede elegir 'gratis'; en un plan normal 'gratis' se
// bloquea (antes era clickeable pero el backend lo revertía a 'activo', así que
// "no se guardaba"). El resto de estados se bloquean en planes de cortesía.
const isStatusDisabled = (value) =>
    isCourtesyPlan.value ? value !== 'gratis' : value === 'gratis'

const selectStatus = (value) => {
    if (isStatusDisabled(value)) return
    form.value.service_status = value
}

// Detect PPPoE plan by type_plan name, plan name, or pppoe_pool field
const isPppoePlan = computed(() => {
    if (!selectedPlan.value) return false
    const typeName = (selectedPlan.value.type_plan?.name ?? '').toLowerCase()
    const planName = (selectedPlan.value.name ?? '').toLowerCase()
    return typeName.includes('pppoe') || planName.includes('pppoe') || !!selectedPlan.value.pppoe_pool
})

// PPPoE section is shown (and mandatory) when the router has Control PPPOE active
const showPppoeSection = computed(() => !!selectedRouter.value?.pppoe)

// HotSpot / DHCP sections mirror the PPPoE one, driven by the router control mode
const showHotspotSection = computed(() => !!selectedRouter.value?.hotspot)
const showDhcpSection = computed(() => !!selectedRouter.value?.dhcp_leases)

watch(showHotspotSection, (visible) => {
    if (visible && !form.value.hotspot_username) {
        const n = form.value.name.toLowerCase().replace(/\s+/g, '')
        const l = form.value.last_name.toLowerCase().replace(/\s+/g, '')
        if (n && l) form.value.hotspot_username = `${n}.${l}`
    }
})

// Mismatch: PPPoE plan selected but router doesn't support PPPoE
const pppoeMismatch = computed(() =>
    isPppoePlan.value && !!selectedRouter.value && !selectedRouter.value.pppoe
)

// Sync create_pppoe_secret flag with router PPPoE state
watch(showPppoeSection, (visible) => {
    form.value.create_pppoe_secret = visible
    if (visible && !form.value.pppoe_username) {
        const n = form.value.name.toLowerCase().replace(/\s+/g, '')
        const l = form.value.last_name.toLowerCase().replace(/\s+/g, '')
        if (n && l) form.value.pppoe_username = `${n}.${l}`
    }
})

const loadCatalogs = async () => {
    try {
        const [plansRes, sectorialsRes, routersRes] = await Promise.all([
            api.plans.getAll(),
            api.sectorials.getAll(),
            api.routers.getAll(),
        ])
        plans.value      = plansRes.data.data || []
        sectorials.value = sectorialsRes.data || []
        routers.value    = routersRes.data || []
    } catch (err) {
        console.error('Error al cargar catálogos:', err)
    }
}

const loadCustomer = async () => {
    try {
        const { data: d } = await api.customers.getOne(route.params.id)
        form.value = {
            email:        d.email,
            password:     '',
            tel:          d.tel || '',
            name:         d.name,
            last_name:    d.last_name,
            is_company:   !!d.is_company,
            cedula:       d.cedula || '',
            city:         d.city || '',
            state:        d.state || '',
            address:      d.address || '',
            precinto:     d.precinto || '',
            installation_date: (d.installation_date || '').slice(0, 10),
            estrato:      d.estrato ?? null,
            exclude_from_billing: !!d.exclude_from_billing,
            comments:     d.comments || '',
            latitude:     d.latitude ?? '',
            longitude:    d.longitude ?? '',
            ip_user:      d.ip_user || '',
            service_id:   d.service_id || null,
            sectorial_id: d.sectorial_id || null,
            olt_id:       d.olt_id || null,
            nap_port:     d.nap_port || '',
            is_fiber:     !!d.is_fiber,
            router_id:    d.router_id || null,
            service_status: d.service_status || 'activo',
            create_pppoe_secret: false,
            pppoe_username: d.pppoe_username || '',
            pppoe_password: d.pppoe_password || '',
            pppoe_local_address: d.pppoe_local_address || '',
            hotspot_username: d.hotspot_username || '',
            hotspot_password: d.hotspot_password || '',
            mac_address: d.mac_address || '',
        }
        emailTenant.value = d.email_tenant || ''
    } catch (err) {
        console.error('Error al cargar cliente:', err)
        toast.value?.error('Error de carga', 'No se pudieron cargar los datos del cliente.')
        errorMsg.value = 'Error al cargar los datos del cliente.'
    } finally {
        loadingData.value = false
    }
}

// After catalogs and customer load, sync the PPPoE flag based on the loaded router
watch([() => form.value.router_id, routers], () => {
    if (selectedRouter.value) {
        form.value.create_pppoe_secret = !!selectedRouter.value.pppoe
        if (selectedRouter.value.pppoe && !form.value.pppoe_username) {
            const n = form.value.name.toLowerCase().replace(/\s+/g, '')
            const l = form.value.last_name.toLowerCase().replace(/\s+/g, '')
            if (n && l) form.value.pppoe_username = `${n}.${l}`
        }
    }
}, { immediate: false })

// pushToRouter=true -> "Guardar y cargar a RB" (flujo completo: BD + re-sincroniza).
// pushToRouter=false -> "Guardar": persiste solo en la base de datos.
const handleSubmit = async (pushToRouter = true) => {
    errorMsg.value       = ''
    pppoeUserError.value = ''
    pppoePassError.value = ''

    // Hard block: router asignado pero sin plan. El plan se filtra por el modo de
    // control del router y un watcher lo limpia al cambiar de router, así que es
    // fácil terminar guardando un cliente con router y sin plan sin darse cuenta.
    if (form.value.router_id && !form.value.service_id) {
        toast.value?.error('Falta el plan',
            'Seleccioná un plan de servicio para este router antes de guardar.')
        return
    }

    // Hard block: PPPoE plan assigned to non-PPPoE router
    if (pppoeMismatch.value) {
        toast.value?.error('Configuración inválida',
            `El plan PPPoE requiere un router con Control PPPOE activo. Actívalo en la configuración del router "${selectedRouter.value?.name}" primero.`)
        return
    }

    // PPPoE credentials required when section is visible
    if (showPppoeSection.value) {
        let valid = true
        if (!form.value.pppoe_username.trim()) {
            pppoeUserError.value = 'El usuario PPPoE es obligatorio.'
            valid = false
        }
        if (!form.value.pppoe_password.trim()) {
            pppoePassError.value = 'La contraseña PPPoE es obligatoria.'
            valid = false
        }
        if (!valid) return
    }

    // HotSpot credentials required when its section is visible
    if (showHotspotSection.value) {
        if (!form.value.hotspot_username.trim() || !form.value.hotspot_password.trim()) {
            toast.value?.error('Credenciales HotSpot', 'El usuario y la contraseña HotSpot son obligatorios.')
            return
        }
    }

    // MAC required (and valid) when the DHCP section is visible
    if (showDhcpSection.value) {
        const mac = form.value.mac_address.trim()
        if (!/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/.test(mac)) {
            toast.value?.error('MAC inválida', 'Ingresa una MAC válida (AA:BB:CC:DD:EE:FF) para el lease DHCP.')
            return
        }
    }

    loading.value = true
    loadingMode.value = pushToRouter ? 'rb' : 'db'

    try {
        const dataToSend = { ...form.value, push_to_router: pushToRouter }
        if (!dataToSend.password) delete dataToSend.password

        const res   = await api.customers.update(route.params.id, dataToSend)
        const pppoe = res.data?.pppoe_provisioned

        if (!pushToRouter) {
            toast.value?.success('Cliente guardado', 'Los datos se guardaron en la base de datos (no se cargó a la RB).')
            setTimeout(() => router.push('/customers'), 1500)
        } else if (showPppoeSection.value && pppoe && !pppoe.success) {
            toast.value?.warning(
                'Cliente actualizado con advertencia',
                `Datos guardados, pero el secret PPPoE no se pudo actualizar en ${selectedRouter.value?.name}: ${pppoe.message}`
            )
            setTimeout(() => router.push('/customers'), 2500)
        } else if (showPppoeSection.value && pppoe?.success) {
            toast.value?.success(
                'Cliente actualizado',
                `Datos guardados y secret PPPoE actualizado en ${selectedRouter.value?.name} correctamente.`
            )
            setTimeout(() => router.push('/customers'), 1500)
        } else {
            toast.value?.success('Cliente actualizado', 'Los datos fueron actualizados y cargados a la RB correctamente.')
            setTimeout(() => router.push('/customers'), 1500)
        }
    } catch (err) {
        console.error('Error al actualizar cliente:', err)
        const msg = err.response?.data?.message || 'Error al actualizar el cliente.'
        errorMsg.value = msg
        toast.value?.error('Error al actualizar', msg)
    } finally {
        loading.value = false
        loadingMode.value = null
    }
}

const goBack = () => router.push({ name: 'Customers' })

// Evita el submit implícito del formulario: al confirmar el datepicker nativo con
// Enter (u otra tecla Enter dentro de un input) el navegador disparaba el submit.
// Solo el botón "Actualizar Cliente" debe enviar el formulario.
const onFormKeydown = (e) => {
    if (e.key === 'Enter' && e.target?.tagName === 'INPUT' && e.target.type !== 'submit') {
        e.preventDefault()
    }
}

onMounted(async () => {
    // Esperar catálogos y cliente juntos: así, cuando se asigna el form, filteredPlans
    // ya tiene los planes del router y el plan guardado se muestra. nextTick deja correr
    // los watchers disparados por la asignación (protegidos por initializing) antes de
    // habilitar la limpieza automática del plan.
    await Promise.all([loadCatalogs(), loadCustomer()])

    // Auto-detección de fibra: clientes con una OLT o una caja NAP ya asignada pero con
    // is_fiber=false (datos antiguos, imports, o guardados previos a la columna) deben
    // editarse en modo fibra. Sin esto el toggle sale apagado y la caja queda fuera de la
    // lista inalámbrica (que excluye NAPs), así que el operador la ve vacía. Al corregir
    // el modo aquí, los selects de OLT/Caja muestran sus valores y al guardar persiste
    // is_fiber=true (auto-repara el registro). Los catálogos ya están cargados (Promise.all).
    if (!form.value.is_fiber) {
        const assignedSectorial = sectorials.value.find(s => s.id === form.value.sectorial_id)
        const sectorialIsNap = assignedSectorial?.element_type === 'nap'
        if (form.value.olt_id || sectorialIsNap) {
            form.value.is_fiber = true
        }
    }

    await nextTick()
    initializing.value = false
})
</script>

<style scoped>
.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2;
}
.input {
  @apply w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
         focus:ring-2 focus:ring-blue-500 focus:border-transparent
         disabled:opacity-50 disabled:cursor-not-allowed transition-all
         placeholder:text-gray-400 dark:placeholder:text-gray-500;
}
/* Igual tamaño que SearchableSelect (px-3 py-2 rounded-lg) para que los controles
   de la sección "Configuración del Servicio" queden uniformes en altura/forma. */
.svc-input {
  @apply w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
         bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100
         focus:outline-none focus:ring-2 focus:ring-blue-500 transition
         disabled:opacity-50 disabled:cursor-not-allowed
         placeholder:text-gray-400 dark:placeholder:text-gray-500;
}
.hint {
  @apply mt-2 text-xs text-gray-500 dark:text-gray-400 flex items-start gap-1;
}
.hint::before {
  content: '💡';
  flex-shrink: 0;
}
</style>
