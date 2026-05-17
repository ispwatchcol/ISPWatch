<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <NotificationToast ref="toast" />

        <!-- Header -->
        <div class="flex items-center gap-3 sm:gap-4 mb-4 sm:mb-6">
        <button
            @click="router.push({ name: 'Customers' })"
            class="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-white transition"
        >
            <icon-mdi-arrow-left class="w-5 h-5 sm:w-6 sm:h-6" />
        </button>
        <div>
            <h1 class="text-xl sm:text-2xl md:text-3xl font-bold text-gray-800 dark:text-gray-100">Nuevo Cliente</h1>
            <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">Registra un nuevo cliente con sus credenciales</p>
        </div>
        </div>

        <!-- Formulario -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 sm:p-6 md:p-8 max-w-7xl mx-auto border border-gray-100 dark:border-gray-700">
        <form @submit.prevent="handleSubmit">

            <!-- Sección: Datos de Acceso -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Datos de Acceso
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Email <span class="text-red-500">*</span>
                </label>
                <input v-model="form.email" type="email" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="ejemplo@empresa.com" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Contraseña <span class="text-red-500">*</span>
                </label>
                <input v-model="form.password" type="password" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Mínimo 6 caracteres" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Teléfono</label>
                <input v-model="form.tel" type="tel"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="+57 300 123 4567" />
                </div>
            </div>
            </div>

            <!-- Sección: Información del Cliente -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Información del Cliente
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Nombre <span class="text-red-500">*</span>
                </label>
                <input v-model="form.name" type="text" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Juan" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Apellido <span class="text-red-500">*</span>
                </label>
                <input v-model="form.last_name" type="text" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Pérez" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Cédula <span class="text-red-500">*</span>
                </label>
                <input v-model="form.cedula" type="text" required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: 1234567890" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Ciudad</label>
                <input v-model="form.city" type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: La Vega" />
                </div>

                <div class="md:col-span-2">
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Departamento</label>
                <input v-model="form.state" type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Cundinamarca" />
                </div>
            </div>
            </div>

            <!-- Sección: Configuración del Servicio -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Configuración del Servicio
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- IP del Usuario -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    IP del Usuario
                    <span v-if="loadingFreeIps" class="ml-1 text-xs text-blue-400 animate-pulse">cargando...</span>
                    <span v-else-if="ipStats.free > 0" class="ml-1 text-xs text-green-500">{{ ipStats.free }} libres</span>
                </label>
                <input v-model="form.ip_user" type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="192.168.1.100" />
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Plan de Servicio</label>
                <select v-model="form.service_id"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option :value="null">Seleccionar plan...</option>
                    <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                </select>
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Sectorial</label>
                <select v-model="form.sectorial_id"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option :value="null">Seleccionar sectorial...</option>
                    <option v-for="s in sectorials" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Router</label>
                <select v-model="form.router_id"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option :value="null">Seleccionar router...</option>
                    <option v-for="rb in routers" :key="rb.id" :value="rb.id">{{ rb.name }}</option>
                </select>
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
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-1 border-b border-blue-200 dark:border-blue-700 pb-2 flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                Credenciales PPPoE
                <span class="text-sm font-normal text-blue-600 dark:text-blue-400 ml-1">(requerido — el router usa Control PPPOE)</span>
            </h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                El secret PPPoE se creará automáticamente en <strong>{{ selectedRouter?.name }}</strong> al guardar.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Usuario PPPoE <span class="text-red-500">*</span>
                </label>
                <input v-model="form.pppoe_username" type="text"
                    :class="pppoeUserError ? 'border-red-500 focus:ring-red-500' : 'border-gray-200 dark:border-gray-600 focus:ring-blue-500'"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border focus:outline-none focus:ring-2"
                    placeholder="juan.perez" />
                <p v-if="pppoeUserError" class="mt-1 text-xs text-red-500">{{ pppoeUserError }}</p>
                </div>

                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Contraseña PPPoE <span class="text-red-500">*</span>
                </label>
                <input v-model="form.pppoe_password" type="text"
                    :class="pppoePassError ? 'border-red-500 focus:ring-red-500' : 'border-gray-200 dark:border-gray-600 focus:ring-blue-500'"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border focus:outline-none focus:ring-2"
                    placeholder="Contraseña del servicio PPPoE" />
                <p v-if="pppoePassError" class="mt-1 text-xs text-red-500">{{ pppoePassError }}</p>
                </div>
            </div>
            </div>

            <!-- Error inline general -->
            <div v-if="errorMsg" class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg text-sm">
            {{ errorMsg }}
            </div>

            <!-- Botones -->
            <div class="flex flex-col sm:flex-row gap-3 sm:gap-4">
            <button type="submit" :disabled="loading || pppoeMismatch"
                class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white py-2.5 sm:py-3 rounded-lg font-medium transition text-sm sm:text-base">
                {{ loading ? 'Guardando...' : 'Guardar Cliente' }}
            </button>
            <button type="button" @click="router.push({ name: 'Customers' })"
                class="px-6 sm:px-8 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 sm:py-3 rounded-lg transition text-sm sm:text-base">
                Cancelar
            </button>
            </div>
        </form>
        </div>

        <!-- Modal: Límite de clientes del plan alcanzado -->
        <div v-if="showLimitModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
            @click.self="showLimitModal = false">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-md w-full overflow-hidden border border-gray-100 dark:border-gray-700">
                <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-6 text-center">
                    <div class="mx-auto w-14 h-14 rounded-full bg-white/20 flex items-center justify-center mb-3">
                        <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h3 class="text-lg sm:text-xl font-bold text-white">Límite de plan alcanzado</h3>
                </div>

                <div class="px-6 py-5 text-center">
                    <p class="text-gray-700 dark:text-gray-200 text-sm sm:text-base">
                        Ya tienes <strong>{{ limitInfo.current }}</strong> de
                        <strong>{{ limitInfo.limit }}</strong> clientes registrados en tu plan actual.
                    </p>
                    <p class="text-gray-500 dark:text-gray-400 text-sm mt-2">
                        Amplía tu plan para poder agregar más clientes.
                    </p>

                    <div class="mt-4">
                        <div class="h-2.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-amber-500 to-orange-500 rounded-full" style="width:100%"></div>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">Capacidad del plan utilizada</p>
                    </div>
                </div>

                <div class="px-6 pb-6 flex flex-col gap-2">
                    <button type="button" @click="handleUpgrade"
                        class="w-full bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white py-3 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Ampliar plan
                    </button>
                    <button type="button" @click="showLimitModal = false"
                        class="w-full text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 py-2 text-sm transition">
                        Entendido
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import NotificationToast from '@/components/NotificationToast.vue'

const router = useRouter()
const toast  = ref(null)

const form = ref({
    email: '',
    password: '',
    tel: '',
    name: '',
    last_name: '',
    cedula: '',
    city: '',
    state: '',
    ip_user: '',
    service_id: null,
    sectorial_id: null,
    router_id: null,
    create_pppoe_secret: false,
    pppoe_username: '',
    pppoe_password: '',
})

const loading        = ref(false)
const errorMsg       = ref('')
const showLimitModal = ref(false)
const limitInfo      = ref({ limit: 0, current: 0, message: '' })
const pppoeUserError = ref('')
const pppoePassError = ref('')
const plans          = ref([])
const sectorials     = ref([])
const routers        = ref([])

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
    } catch (e) {
        console.warn('No se pudieron cargar IPs libres:', e)
    } finally {
        loadingFreeIps.value = false
        freeIpsLoaded.value  = true
    }
}

watch(() => form.value.router_id, (id) => loadFreeIps(id))

const selectedPlan   = computed(() => plans.value.find(p => p.id === form.value.service_id))
const selectedRouter = computed(() => routers.value.find(r => r.id === form.value.router_id))

// Detect PPPoE plan by type_plan name, plan name, or pppoe_pool field
const isPppoePlan = computed(() => {
    if (!selectedPlan.value) return false
    const typeName = (selectedPlan.value.type_plan?.name ?? '').toLowerCase()
    const planName = (selectedPlan.value.name ?? '').toLowerCase()
    return typeName.includes('pppoe') || planName.includes('pppoe') || !!selectedPlan.value.pppoe_pool
})

// PPPoE section is shown (and mandatory) when the router has Control PPPOE active
const showPppoeSection = computed(() => !!selectedRouter.value?.pppoe)

// Mismatch: PPPoE plan selected but router doesn't support PPPoE
const pppoeMismatch = computed(() =>
    isPppoePlan.value && !!selectedRouter.value && !selectedRouter.value.pppoe
)

// Auto-fill credentials and toggle create_pppoe_secret when section appears/disappears
watch(showPppoeSection, (visible) => {
    form.value.create_pppoe_secret = visible
    if (visible && !form.value.pppoe_username) {
        const n = form.value.name.toLowerCase().replace(/\s+/g, '')
        const l = form.value.last_name.toLowerCase().replace(/\s+/g, '')
        if (n && l) form.value.pppoe_username = `${n}.${l}`
    }
})

// Re-fill username when name/last_name change while section is visible
watch([() => form.value.name, () => form.value.last_name], ([n, l]) => {
    if (!showPppoeSection.value) return
    const username = n.toLowerCase().replace(/\s+/g, '') + '.' + l.toLowerCase().replace(/\s+/g, '')
    if (username !== '.') form.value.pppoe_username = username
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

onMounted(loadCatalogs)

const handleSubmit = async () => {
    errorMsg.value     = ''
    pppoeUserError.value = ''
    pppoePassError.value = ''

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

    loading.value = true

    try {
        const res   = await api.customers.create(form.value)
        const pppoe = res.data?.pppoe_provisioned

        if (showPppoeSection.value && pppoe && !pppoe.success) {
            toast.value?.warning(
                'Cliente creado con advertencia',
                `Datos guardados, pero el secret PPPoE no se pudo crear en ${selectedRouter.value?.name}: ${pppoe.message}`
            )
            setTimeout(() => router.push('/customers'), 2500)
        } else {
            const extra = showPppoeSection.value ? ` Secret PPPoE creado en ${selectedRouter.value?.name}.` : ''
            toast.value?.success('Cliente creado', `El cliente fue registrado correctamente.${extra}`)
            setTimeout(() => router.push('/customers'), 1500)
        }
    } catch (err) {
        console.error('Error al crear cliente:', err)
        const data = err.response?.data

        if (err.response?.status === 403 && data?.upgrade_required) {
            limitInfo.value = {
                limit: data.limit ?? 0,
                current: data.current ?? 0,
                message: data.message ?? '',
            }
            showLimitModal.value = true
            return
        }

        const msg = data?.message || 'Error al crear el cliente.'
        errorMsg.value = msg
        toast.value?.error('Error al crear', msg)
    } finally {
        loading.value = false
    }
}

// Placeholder de ampliación de plan: por ahora solo informa, el modal sigue abierto
const handleUpgrade = () => {
    toast.value?.info('Función disponible próximamente', 'La ampliación de plan estará disponible pronto.')
}
</script>
