<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Notification Toast -->
    <NotificationToast ref="toast" />
    
    <main class="flex-1 p-8">

      <!-- Header -->
      <div class="flex items-center justify-between mb-10">
        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
          <v-icon name="bi-hdd-rack" class="text-blue-600 w-7 h-7" />
          Agregar Router
        </h1>
        
        <button
          @click="goBack"
          class="bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 
                 px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-gray-300 
                 dark:hover:bg-gray-600 transition-all"
        >
          <icon-lucide-arrow-left class="w-4 h-4" />
          Volver
        </button>
      </div>

      <!-- Form Card -->
      <div class="bg-white dark:bg-gray-800 shadow rounded-2xl p-8 w-full max-w-5xl mx-auto">

        <form @submit.prevent="saveRouter" class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- NOMBRE -->
            <div class="col-span-2">
                <label class="label">Nombre</label>
                <input v-model="form.nombre" type="text" placeholder="Ej: RB 2011" class="input"/>
            </div>

            <!-- IPv4 / IPv6 -->
            <div class="col-span-2">
                <label class="label">IPv4 / IPv6</label>
                <input v-model="form.ip" type="text" placeholder="Ej: IP Pública..." class="input"/>
            </div>

            <!-- IPv6 -->
            <div class="col-span-2">
                <label class="label">IPv6</label>
                <input v-model="form.ipv6" type="text" placeholder="Ej: 2800:abcd::1" class="input"/>
            </div>

            <!-- FAILOVER -->
            <div class="col-span-2">
              <label class="label flex items-center justify-between">
                <span class="flex items-center gap-2">
                  Failover
                  <icon-lucide-help-circle class="w-4 h-4 text-gray-500" />
                  <icon-lucide-refresh-cw class="w-4 h-4 text-blue-600 cursor-pointer" />
                </span>
              </label>

              <input v-model="form.failover" type="text"
                placeholder="Ej: IP Mikrotik Cloud"
                class="input" />

              <p class="hint">Para usar esta función debes agregar las IP de tus servidores.</p>
            </div>

            <!-- COORDENADAS -->
            <div class="col-span-2">
                <label class="label">Coordenadas</label>
                <input v-model="form.coordenadas" type="text" placeholder="Ej: 21.150168,-86.875023" class="input"/>
            </div>
            <!-- VERSION -->
            <div>
            <label class="label text-gray-700 dark:text-gray-200">
              Versión del firmware
            </label>

            <div class="relative">
              <select v-model="form.version" 
                class="input appearance-none bg-white dark:bg-gray-800 cursor-pointer pr-10"
                style="color-scheme: light dark;">
                <option :value="null" disabled class="bg-white dark:bg-gray-800 text-gray-400 dark:text-gray-500">
                  Seleccione una versión…
                </option>

                <option
                  v-for="v in scriptVersions"
                  :key="v.id"
                  :value="v.id"
                  class="bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 py-2"
                >
                  {{ v.version }}
                </option>
              </select>
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                <icon-lucide-chevron-down class="w-5 h-5 text-gray-400 dark:text-gray-500" />
              </div>
            </div>
          </div>

            <!-- EXTERNAL ID -->
            <div>
              <label class="label">External ID</label>
              <input v-model="form.external_id" class="input" placeholder="Ej: 000123" />
            </div>

            <!-- USUARIO RB -->
            <div>
              <label class="label">Usuario del RB</label>
              <input v-model="form.usuario" type="text" placeholder="Ej: admin" class="input" />
            </div>

            <!-- PASSWORD RB -->
            <div>
              <label class="label">Password del RB</label>
              <div class="relative">
                <input 
                  v-model="form.password" 
                  :type="showPassword ? 'text' : 'password'" 
                  placeholder="Ej: 123456" 
                  class="input pr-10" 
                />
                <button 
                  type="button"
                  @click="showPassword = !showPassword"
                  class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"
                  tabindex="-1"
                >
                  <icon-lucide-eye v-if="!showPassword" class="w-5 h-5" />
                  <icon-lucide-eye-off v-else class="w-5 h-5" />
                </button>
              </div>
            </div>

            <!-- PUERTO API -->
            <div>
              <label class="label">Puerto API</label>
              <input v-model="form.puerto_api" type="number" placeholder="8728" class="input" />
            </div>

            <!-- PUERTO WWW -->
            <div>
              <label class="label">Puerto WWW</label>
              <input v-model="form.puerto_www" type="number" placeholder="80" class="input" />
            </div>

            <!-- INTERFAZ LAN -->
            <div class="col-span-2">
                <label class="label">Interfaz LAN</label>
                <input v-model="form.interfaz_lan" type="text" placeholder="Ej: ether2" class="input"/>
            </div>

            <!-- RANGOS IP -->
            <div class="col-span-2">
                <label class="label">Rangos IP</label>
                <textarea v-model="form.rangos_ip" rows="3" placeholder="Ej. 192.168.1.0/24 uno por línea" class="textarea"></textarea>
                <p class="hint">Ingresa subredes en formato CIDR (ej: 192.168.1.0/24). Soporta /20 a /30.</p>
            </div>

            <!-- IP RANGE ANALYZER -->
            <div v-if="parsedRanges.length > 0" class="col-span-2">
              <div class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden bg-white dark:bg-gray-800 shadow-sm">
                
                <!-- Header -->
                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-3 flex items-center justify-between">
                  <div class="flex items-center gap-2 text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <span class="font-semibold text-sm">Analizador de IPs</span>
                  </div>
                  <div v-if="loadingIps" class="flex items-center gap-1.5 text-white/80 text-xs">
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
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-400 w-10 text-right">
                      {{ ipStats.usagePercent }}%
                    </span>
                  </div>
                </div>

                <!-- IP Grid (per range) -->
                <div v-for="(range, idx) in parsedRanges" :key="idx" class="border-t border-gray-200 dark:border-gray-700">
                  <div class="px-4 py-2 bg-gray-50 dark:bg-gray-900/30 flex items-center justify-between">
                    <span class="text-xs font-mono font-semibold text-gray-700 dark:text-gray-300">
                      🌐 {{ range.cidr }}
                    </span>
                    <span class="text-xs text-gray-500">
                      {{ range.hosts.length }} hosts · {{ range.freeHosts.length }} libres
                    </span>
                  </div>

                  <div class="px-4 py-3 max-h-48 overflow-y-auto">
                    <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-1">
                      <button
                        v-for="ip in range.hosts"
                        :key="ip"
                        type="button"
                        @click="copyIp(ip)"
                        :class="[
                          'px-1 py-1 text-[10px] font-mono rounded transition-all truncate',
                          usedIpsSet.has(ip)
                            ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 cursor-not-allowed line-through'
                            : 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 hover:bg-green-200 dark:hover:bg-green-900/40 cursor-pointer'
                        ]"
                        :title="usedIpsSet.has(ip) ? 'IP en uso' : 'Click para copiar'"
                        :disabled="usedIpsSet.has(ip)"
                      >
                        {{ ip.split('.').pop() }}
                      </button>
                    </div>
                  </div>
                </div>

                <!-- Legend -->
                <div class="px-4 py-2 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 flex items-center gap-4">
                  <div class="flex items-center gap-1.5">
                    <div class="w-3 h-3 rounded bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-700"></div>
                    <span class="text-[10px] text-gray-500 dark:text-gray-400">Libre (click = copiar)</span>
                  </div>
                  <div class="flex items-center gap-1.5">
                    <div class="w-3 h-3 rounded bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-700"></div>
                    <span class="text-[10px] text-gray-500 dark:text-gray-400">En uso</span>
                  </div>
                  <div v-if="copiedIp" class="ml-auto text-[10px] text-green-600 dark:text-green-400 font-medium animate-pulse">
                    ✓ {{ copiedIp }} copiada
                  </div>
                </div>
              </div>
            </div>

            <!-- FACTURACIÓN -->
            <div class="flex items-center mb-4">
              <span class="font-medium text-gray-700 dark:text-gray-200">
                Facturación del Router
              </span>

              <label class="relative inline-flex items-center cursor-pointer ml-4">
                <input type="checkbox" v-model="form.facturacion_activa" class="sr-only peer" />
                <div class="w-11 h-6 bg-gray-300 dark:bg-gray-700 rounded-full peer-checked:bg-blue-600 transition">
                  <div class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
                </div>
              </label>
            </div>

            <!-- GRID PRINCIPAL -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Aquí tus otros campos -->
            </div>

            <!-- PANEL DE FACTURACIÓN MODULARIZADO -->
            <BillingPanel
              :active="form.facturacion_activa"
              :billing="form.billing"
              :types="types"
            />

            <!-- SELECT: TIPO DE CORTE -->
            <div class="col-span-2 mb-2">
              <label class="label text-gray-700 dark:text-gray-200">
                Tipo de corte de servicio
              </label>

              <select v-model="form.tipo_corte" class="input">
                <!-- placeholder visible en light/dark; value null para que Vue lo seleccione si modelo es null -->
                <option :value="null" disabled class="text-gray-400 dark:text-gray-300">
                  Seleccione una opción
                </option>

                <!-- usamos :value="t.id" (número) — form.tipo_corte debe ser null o número -->
                <option
                  v-for="t in tiposCorte"
                  :key="t.id"
                  :value="t.id"
                >
                  {{ t.name }}
                </option>
              </select>
            </div>

            <div class="col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4">

              <!-- Agregar Cliente en Mikrotik -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">Agregar Cliente en Mikrotik</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">Añadir clientes automáticamente al RB</div>
                </div>

                <input type="checkbox" v-model="form.agregar_cliente_mkt" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.agregar_cliente_mkt
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.agregar_cliente_mkt ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

              <!-- Historial de Tráfico -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">Historial de Tráfico</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">Guardar métricas de tráfico</div>
                </div>

                <input type="checkbox" v-model="form.historial_trafico" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.historial_trafico
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.historial_trafico ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

              <!-- Simple Queue -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">Control Simple Queue</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">Habilitar control por queues</div>
                </div>

                <input type="checkbox" v-model="form.simple_queue" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.simple_queue
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.simple_queue ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

              <!-- Control PCQ -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">Control PCQ + Address-list</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">PCQ y listas de direcciones</div>
                </div>

                <input type="checkbox" v-model="form.control_pcq" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.control_pcq
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.control_pcq ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

              <!-- HotSpot -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">Control HotSpot</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">Gestionar usuarios HotSpot</div>
                </div>

                <input type="checkbox" v-model="form.hotspot" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.hotspot
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.hotspot ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

              <!-- PPPOE -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">Control PPPOE</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">Gestión de PPPOE</div>
                </div>

                <input type="checkbox" v-model="form.pppoe" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.pppoe
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.pppoe ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

              <!-- IP Bindings -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">IP Bindings</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">Forzar IP a MAC</div>
                </div>

                <input type="checkbox" v-model="form.ip_bindings" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.ip_bindings
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.ip_bindings ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

              <!-- Amarre -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">Amarre IP/MAC</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">Bloqueo por pares IP-MAC</div>
                </div>

                <input type="checkbox" v-model="form.amarre" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.amarre
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.amarre ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

              <!-- DHCP Leases -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">DHCP Leases</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">Control de leases DHCP</div>
                </div>

                <input type="checkbox" v-model="form.dhcp_leases" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.dhcp_leases
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.dhcp_leases ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

              <!-- Falla General -->
              <label class="flex items-center justify-between gap-4 p-3 rounded-xl border
                            border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="text-sm">
                  <div class="font-medium text-gray-700 dark:text-gray-200">Falla General</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">Activar estado de falla</div>
                </div>

                <input type="checkbox" v-model="form.falla_general" class="sr-only" />

                <span
                  class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                  :class="form.falla_general
                    ? 'bg-blue-600'
                    : 'bg-gray-300 dark:bg-gray-600'"
                >
                  <span
                    class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                    :style="{ transform: form.falla_general ? 'translateX(20px)' : 'translateX(0)' }"
                  ></span>
                </span>
              </label>

            </div>


            <!-- COMENTARIOS -->
            <div class="col-span-2">
                <label class="label">Comentarios</label>
                <textarea v-model="form.comentarios_router" rows="3" class="textarea"></textarea>
            </div>

              <!-- ACTIVO -->
              <div class="col-span-2 flex items-center gap-3">
                <span class="label">Activo</span>

                <label class="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" v-model="form.activo" class="sr-only peer" />
                  <div
                    class="w-11 h-6 bg-gray-300 peer dark:bg-gray-700 peer-checked:bg-blue-600 rounded-full transition"
                  ></div>
                  <div
                    class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full
                          peer-checked:translate-x-5 transition"
                  ></div>
                </label>
              </div>

            <!-- BOTÓN -->
            <div class="col-span-2 mt-4">
                <button type="submit" class="btn-primary w-full">
                Guardar Router
                </button>
            </div>

        </form>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed, watch } from "vue"
import { useRouter } from "vue-router"
import { supabase } from "@/supabase.js"
import DayPicker from "@/components/DayPicker.vue"
import BillingPanel from "@/components/BillingPanel.vue"
import NotificationToast from "@/components/NotificationToast.vue"

const router = useRouter()
const toast = ref(null)
const showPassword = ref(false)

/* ============================
   FUNCIONES DE LIMPIEZA
============================ */
const convertDay = (v) => {
  if (!v) return null
  const num = Number(v)
  if (isNaN(num)) return null
  if (num < 1 || num > 31) return null
  return num
}

const toNumberOrNull = (v) => {
  if (v === "" || v === null || v === undefined) return null
  const n = Number(v)
  return isNaN(n) ? null : n
}

/* ============================
   DATOS DESDE DB
============================ */
const tiposCorte = ref([])
const scriptVersions = ref([])
const types = ref([])

onMounted(async () => {
  const { data: cortes } = await supabase.from("cut_type").select("*")
  tiposCorte.value = cortes ?? []

  const { data: versions } = await supabase.from("script_version").select("*")
  scriptVersions.value = versions ?? []

  const { data: tipos } = await supabase.from("type_billing").select("id, type")
  types.value = tipos ?? []
})

/* ============================
        FORMULARIO
============================ */
const form = reactive({
  nombre: "",
  ip: "",
  coordenadas: "",
  version: null,
  usuario: "",
  password: "",
  puerto_api: 8728,
  puerto_www: 80,
  interfaz_lan: "",
  rangos_ip: "",
  tipo_corte: null,
  agregar_cliente_mkt: false,
  historial_trafico: false,
  simple_queue: false,
  control_pcq: false,
  hotspot: false,
  pppoe: false,
  ip_bindings: false,
  amarre: false,
  dhcp_leases: false,
  falla_general: false,
  comentarios_router: "",
  activo: true,

  facturacion_activa: false,
  billing: {
    create_invoice: null,
    payment_day: null,
    cut_day: null,
    overdue_invoices: "",
    amount: null,
    comentarios: "",
    metodo: "",
    notificar_wpp: false,
    remember_day: null,
    pay_day: null,
    notification_type: 'email',
    billing_mode: 'anticipado',
  }
})

/* ============================
   IP RANGE ANALYZER
============================ */
const usedIps = ref([])
const loadingIps = ref(false)
const copiedIp = ref('')
const usedIpsSet = computed(() => new Set(usedIps.value))

// Parse CIDR notation to array of host IPs
const parseCIDR = (cidr) => {
  const match = cidr.trim().match(/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/(\d{1,2})$/)
  if (!match) return null
  
  const ip = match[1]
  const prefix = parseInt(match[2])
  if (prefix < 20 || prefix > 30) return null // only /20 to /30
  
  const parts = ip.split('.').map(Number)
  if (parts.some(p => p < 0 || p > 255)) return null
  
  const ipNum = ((parts[0] << 24) | (parts[1] << 16) | (parts[2] << 8) | parts[3]) >>> 0
  const mask = (~((1 << (32 - prefix)) - 1)) >>> 0
  const network = (ipNum & mask) >>> 0
  const broadcast = (network | (~mask >>> 0)) >>> 0
  
  const hosts = []
  for (let i = network + 1; i < broadcast; i++) {
    hosts.push(
      ((i >>> 24) & 255) + '.' +
      ((i >>> 16) & 255) + '.' +
      ((i >>> 8) & 255) + '.' +
      (i & 255)
    )
  }
  return { cidr: cidr.trim(), hosts }
}

const parsedRanges = computed(() => {
  if (!form.rangos_ip) return []
  const lines = form.rangos_ip.split('\n').map(l => l.trim()).filter(Boolean)
  const ranges = []
  for (const line of lines) {
    const parsed = parseCIDR(line)
    if (parsed) {
      parsed.freeHosts = parsed.hosts.filter(ip => !usedIpsSet.value.has(ip))
      ranges.push(parsed)
    }
  }
  return ranges
})

const ipStats = computed(() => {
  const allHosts = parsedRanges.value.flatMap(r => r.hosts)
  const total = allHosts.length
  const used = allHosts.filter(ip => usedIpsSet.value.has(ip)).length
  const free = total - used
  const usagePercent = total > 0 ? Math.round((used / total) * 100) : 0
  return { total, used, free, usagePercent }
})

const loadUsedIps = async () => {
  loadingIps.value = true
  try {
    const userData = JSON.parse(localStorage.getItem('userData')) ?? JSON.parse(sessionStorage.getItem('userData'))
    const tenantId = userData?.tenant_id
    if (!tenantId) return
    
    const { data, error } = await supabase
      .from('customer_profile')
      .select('ip_user')
      .eq('tenant_id', tenantId)
      .not('ip_user', 'is', null)
    
    if (!error && data) {
      usedIps.value = data.map(d => d.ip_user).filter(Boolean)
    }
  } catch (e) {
    console.error('Error loading used IPs:', e)
  } finally {
    loadingIps.value = false
  }
}

const copyIp = async (ip) => {
  try {
    await navigator.clipboard.writeText(ip)
    copiedIp.value = ip
    setTimeout(() => { copiedIp.value = '' }, 2000)
  } catch (e) {
    console.error('Copy failed:', e)
  }
}

// Load used IPs when ranges change
let ipDebounce = null
watch(() => form.rangos_ip, (val) => {
  clearTimeout(ipDebounce)
  if (val && val.includes('/')) {
    ipDebounce = setTimeout(loadUsedIps, 500)
  }
})

/* ============================
      INSERT EN BILLING
============================ */
const cleanInt = (val) => {
  if (val === undefined || val === null || val === "" || val === false || val === "false") {
    return null
  }
  const n = Number(val)
  return isNaN(n) ? null : n
}

const cleanDay = (val) => {
  if (!val || val === "false" || val === false) return null
  return String(val).padStart(2, "0")
}

const saveBilling = async () => {
  // Helper: convierte un día (1-31) a fecha YYYY-MM-DD del mes actual
  // Clamp: si el mes no tiene ese día, usa el último día válido
  // Ej: día 31 en febrero → 28 (o 29 en bisiesto)
  const dayToDate = (day) => {
    const num = cleanInt(day)
    if (!num || num < 1 || num > 31) return null
    const now = new Date()
    const year = now.getFullYear()
    const month = now.getMonth() + 1 // 1-based
    const lastDay = new Date(year, month, 0).getDate() // último día del mes
    const clampedDay = Math.min(num, lastDay)
    const m = String(month).padStart(2, '0')
    const d = String(clampedDay).padStart(2, '0')
    return `${year}-${m}-${d}`
  }

  // Obtener tenant_id del usuario logueado
  const userData =
    JSON.parse(localStorage.getItem("userData")) ??
    JSON.parse(sessionStorage.getItem("userData"))
  const tenantId = userData?.tenant_id

  const now = new Date().toISOString()
  
  const payload = {
    create_invoice: dayToDate(form.billing.create_invoice),
    cut_day: dayToDate(form.billing.cut_day),
    payment_day: dayToDate(form.billing.pay_day),
    payment_reminder: dayToDate(form.billing.remember_day),
    overdue_invoices: cleanInt(form.billing.overdue_invoices) ?? 0,
    amount: cleanInt(form.billing.amount),
    id_type: cleanInt(form.billing.metodo),
    status: 'pending',
    notificar_wpp: form.billing.notificar_wpp || false,
    notification_type: form.billing.notification_type || 'email',
    billing_mode: form.billing.billing_mode || 'anticipado',
    comments: form.billing.comentarios || null,
    tenant_id: tenantId,
    created_at: now,
    updated_at: now,
  }

  const { data, error } = await supabase
    .from("billing")
    .insert(payload)
    .select()
    .single()

  if (error) {
    console.error("❌ Error insertando billing:", error)
    return null
  }

  return data
}


/* ============================
  INSERT EN ROUTER PRINCIPAL
============================ */
const saveRouter = async () => {
  const userData =
    JSON.parse(localStorage.getItem("userData")) ??
    JSON.parse(sessionStorage.getItem("userData"))

  const tenantId = userData?.tenant_id
  if (!tenantId) {
    toast.value?.error(
      'Error de sesión',
      'No se encontró información del tenant. Por favor, inicia sesión nuevamente.'
    )
    return
  }

  let coordinates = null
  if (form.coordenadas) {
    const [lat, lng] = form.coordenadas.split(",").map(v => parseFloat(v.trim()))
    if (!isNaN(lat) && !isNaN(lng)) {
      coordinates = `SRID=4326;POINT(${lng} ${lat})`
    }
  }

  // === SIEMPRE crear billing primero ===
  const billingRow = await saveBilling()
  if (!billingRow?.id) {
    toast.value?.error(
      'Error al guardar facturación',
      'No se pudo crear el registro de facturación. Verifica los datos e intenta nuevamente.'
    )
    return
  }
  const billingId = billingRow.id

  const now = new Date().toISOString()

  const payload = {
    name: form.nombre,
    ip: form.ip,
    ipv6: form.ipv6 || null,
    failover: form.failover || null,
    external_id: form.external_id || null,
    user_rb: form.usuario,
    password_rb: form.password,
    puerto_api: form.puerto_api || 8728,
    puerto_www: form.puerto_www || 80,
    lan_interface: form.interfaz_lan,
    cut_type_id: form.tipo_corte,
    firmware_version: form.version,
    billing_router_id: billingId,
    comments: form.comentarios_router,
    coordinates,
    status: form.activo ? 'active' : 'inactive',
    tenant_id: tenantId,
    agregar_cliente_mkt: form.agregar_cliente_mkt || false,
    historial_trafico: form.historial_trafico || false,
    simple_queue: form.simple_queue || false,
    control_pcq: form.control_pcq || false,
    hotspot: form.hotspot || false,
    pppoe: form.pppoe || false,
    ip_bindings: form.ip_bindings || false,
    amarre: form.amarre || false,
    dhcp_leases: form.dhcp_leases || false,
    falla_general: form.falla_general || false,
    rangos_ip: form.rangos_ip || null,
    created_at: now,
    updated_at: now,
  }

  const { error } = await supabase.from("router").insert([payload])

  if (error) {
    console.error("❌ Error guardando router:", error)
    toast.value?.error(
      'Error al guardar router',
      error.message || 'Ocurrió un error inesperado. Intenta nuevamente.'
    )
    return
  }

  toast.value?.success(
    'Router creado exitosamente',
    'El router se ha guardado correctamente en la base de datos.'
  )
  
  setTimeout(() => {
    router.push("/routers")
  }, 1500)
}

const goBack = () => router.back()
</script>


<style scoped>
/* ✅ Placeholders blancos en dark mode */
.dark ::placeholder {
  color: rgb(220 220 220 / 0.7) !important;
}

/* ✅ Label */
.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1;
}

/* ✅ Inputs */
.input {
  @apply w-full px-4 py-2.5 rounded-xl border 
         border-gray-300 dark:border-gray-700
         bg-white dark:bg-gray-800
         text-gray-800 dark:text-gray-100
         placeholder-gray-400 dark:placeholder-gray-300
         focus:ring-2 focus:ring-blue-500 outline-none;
}

/* ✅ Textareas */
.textarea {
  @apply w-full px-4 py-2.5 rounded-xl border
         border-gray-300 dark:border-gray-700
         bg-white dark:bg-gray-800
         text-gray-800 dark:text-gray-100
         placeholder-gray-400 dark:placeholder-gray-300
         focus:ring-2 focus:ring-blue-500 outline-none;
}

/* ✅ Tip */
.hint {
  @apply text-xs text-gray-500 dark:text-gray-400 mt-1;
}

/* ✅ Botón primario */
.btn-primary {
  @apply bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl shadow transition-all;
}
</style>


