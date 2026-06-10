<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Notification Toast -->
    <NotificationToast ref="toast" />
    
    <main class="flex-1 p-8">

      <!-- Header -->
      <div class="flex items-center justify-between mb-10">
        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
          <v-icon name="bi-hdd-rack" class="text-blue-600 w-7 h-7" />
          Editar Router
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

              <p class="hint">Para usar esta función debes agregar las IP de los servidores de WispHub.</p>
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

            <select v-model="form.version" class="input">
              <option :value="null" disabled class="text-gray-400 dark:text-gray-300">
                Seleccione una versión…
              </option>

              <option
                v-for="v in scriptVersions"
                :key="v.id"
                :value="v.id"
              >
                {{ v.version }}
              </option>
            </select>
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
                <textarea v-model="form.rangos_ip" rows="5" placeholder="Ej. 192.168.1.0/24 uno por línea" class="textarea"></textarea>
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

            <!-- PANEL DE FACTURACIÓN MODULARIZADO -->
            <BillingPanel
              v-if="form.facturacion_activa"
              :active="form.facturacion_activa"
              :billing="form.billing"
              :types="types"
            />

            <!-- SCRIPT PANEL -->
            <div class="col-span-2 flex items-center mb-4">
              <span class="font-medium text-gray-700 dark:text-gray-200">
                Activar Script
              </span>

              <label class="relative inline-flex items-center cursor-pointer ml-4">
                <input type="checkbox" v-model="form.script_activo" class="sr-only peer" />
                <div class="w-11 h-6 bg-gray-300 dark:bg-gray-700 rounded-full peer-checked:bg-blue-600 transition">
                  <div class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
                </div>
              </label>
            </div>

            <!-- MINI TERMINAL -->
            <div v-if="form.script_activo" class="col-span-2 mb-6">
              <div class="bg-gray-900 rounded-lg shadow-lg overflow-hidden border border-gray-700">
                <!-- Terminal Header -->
                <div class="bg-gray-800 px-4 py-2 flex items-center justify-between border-b border-gray-700">
                  <div class="flex items-center gap-2">
                    <div class="flex gap-1.5">
                      <div class="w-3 h-3 rounded-full bg-red-500"></div>
                      <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                      <div class="w-3 h-3 rounded-full bg-green-500"></div>
                    </div>
                    <span class="text-xs text-gray-400 ml-2">VPN-L2TP-Client.rsc</span>
                  </div>
                  
                  <!-- Copy Button -->
                  <button
                    type="button"
                    @click.prevent="copyScript"
                    class="flex items-center gap-1.5 px-3 py-1 text-xs bg-blue-600 hover:bg-blue-700 
                           text-white rounded transition-colors"
                    :disabled="loadingScript"
                  >
                    <icon-lucide-copy v-if="!copied" class="w-3 h-3" />
                    <icon-lucide-check v-else class="w-3 h-3" />
                    {{ copied ? 'Copiado!' : 'Copiar Script' }}
                  </button>
                </div>
                
                <!-- Terminal Content -->
                <div class="p-4 font-mono text-sm">
                  <pre v-if="loadingScript" class="text-yellow-400">Cargando script...</pre>
                  <pre v-else-if="vpnScript" class="text-green-400"><code>{{ vpnScript }}</code></pre>
                  <pre v-else class="text-red-400">Error al cargar el script</pre>
                </div>

                <!-- Connection Status -->
                <div v-if="connectionStatus" class="px-4 py-3 border-t border-gray-700 bg-gray-800">
                  <div class="flex items-center gap-2">
                    <div 
                      class="w-2 h-2 rounded-full"
                      :class="{
                        'bg-green-500 animate-pulse': connectionStatus.connected,
                        'bg-red-500': !connectionStatus.connected && !verifyingConnection,
                        'bg-yellow-500 animate-pulse': verifyingConnection
                      }"
                    ></div>
                    <span class="text-xs text-gray-300">{{ connectionStatus.message }}</span>
                  </div>
                </div>

                <!-- Verify Connection Button -->
                <div class="px-4 py-3 border-t border-gray-700 bg-gray-800">
                  <button
                    type="button"
                    @click.prevent="verifyConnection"
                    class="w-full flex items-center justify-center gap-2 px-4 py-2 
                           bg-green-600 hover:bg-green-700 text-white rounded-lg 
                           transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="verifyingConnection || loadingScript"
                  >
                    <icon-lucide-wifi v-if="!verifyingConnection" class="w-4 h-4" />
                    <icon-lucide-loader-2 v-else class="w-4 h-4 animate-spin" />
                    {{ verifyingConnection ? 'Verificando...' : 'Comprobar Conexión' }}
                  </button>
                </div>
              </div>
            </div>

            <!-- SELECT: TIPO DE CORTE -->
            <div class="col-span-2 mb-2">
              <label class="label text-gray-700 dark:text-gray-200">
                Tipo de corte de servicio
              </label>

              <select v-model="form.tipo_corte" class="input">
                <option :value="null" disabled class="text-gray-400 dark:text-gray-300">
                  Seleccione una opción
                </option>

                <option
                  v-for="t in tiposCorte"
                  :key="t.id"
                  :value="t.id"
                >
                  {{ t.name }}
                </option>
              </select>
            </div>

            <!-- ============ MÉTODO DE CONTROL (uno solo) ============ -->
            <div class="col-span-2">
              <div class="mb-3">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Método de control en Mikrotik</h3>
                <p class="hint">Selecciona solo uno. Define cómo se gestiona y limita a cada cliente en el RB.</p>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <!-- Simple Queue -->
                <label
                  class="flex items-center justify-between gap-4 p-3 rounded-xl border bg-white dark:bg-gray-800 cursor-pointer transition-colors"
                  :class="form.simple_queue ? 'border-blue-500 ring-1 ring-blue-500/40' : 'border-gray-200 dark:border-gray-700'"
                >
                  <div class="text-sm">
                    <div class="font-medium text-gray-700 dark:text-gray-200">Control Simple Queue</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Habilitar control por queues</div>
                  </div>

                  <input type="checkbox" :checked="form.simple_queue" @change="setControlMode('simple_queue')" class="sr-only" />

                  <span
                    class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                    :class="form.simple_queue ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                  >
                    <span
                      class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                      :style="{ transform: form.simple_queue ? 'translateX(20px)' : 'translateX(0)' }"
                    ></span>
                  </span>
                </label>

                <!-- Control PCQ -->
                <label
                  class="flex items-center justify-between gap-4 p-3 rounded-xl border bg-white dark:bg-gray-800 cursor-pointer transition-colors"
                  :class="form.control_pcq ? 'border-blue-500 ring-1 ring-blue-500/40' : 'border-gray-200 dark:border-gray-700'"
                >
                  <div class="text-sm">
                    <div class="font-medium text-gray-700 dark:text-gray-200">Control PCQ + Address-list</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">PCQ y listas de direcciones</div>
                  </div>

                  <input type="checkbox" :checked="form.control_pcq" @change="setControlMode('control_pcq')" class="sr-only" />

                  <span
                    class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                    :class="form.control_pcq ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                  >
                    <span
                      class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                      :style="{ transform: form.control_pcq ? 'translateX(20px)' : 'translateX(0)' }"
                    ></span>
                  </span>
                </label>

                <!-- HotSpot -->
                <label
                  class="flex items-center justify-between gap-4 p-3 rounded-xl border bg-white dark:bg-gray-800 cursor-pointer transition-colors"
                  :class="form.hotspot ? 'border-blue-500 ring-1 ring-blue-500/40' : 'border-gray-200 dark:border-gray-700'"
                >
                  <div class="text-sm">
                    <div class="font-medium text-gray-700 dark:text-gray-200">Control HotSpot</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Gestionar usuarios HotSpot</div>
                  </div>

                  <input type="checkbox" :checked="form.hotspot" @change="setControlMode('hotspot')" class="sr-only" />

                  <span
                    class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                    :class="form.hotspot ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                  >
                    <span
                      class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                      :style="{ transform: form.hotspot ? 'translateX(20px)' : 'translateX(0)' }"
                    ></span>
                  </span>
                </label>

                <!-- PPPOE -->
                <label
                  class="flex items-center justify-between gap-4 p-3 rounded-xl border bg-white dark:bg-gray-800 cursor-pointer transition-colors"
                  :class="form.pppoe ? 'border-blue-500 ring-1 ring-blue-500/40' : 'border-gray-200 dark:border-gray-700'"
                >
                  <div class="text-sm">
                    <div class="font-medium text-gray-700 dark:text-gray-200">Control PPPOE</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Gestión de PPPOE</div>
                  </div>

                  <input type="checkbox" :checked="form.pppoe" @change="setControlMode('pppoe')" class="sr-only" />

                  <span
                    class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                    :class="form.pppoe ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                  >
                    <span
                      class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                      :style="{ transform: form.pppoe ? 'translateX(20px)' : 'translateX(0)' }"
                    ></span>
                  </span>
                </label>

                <!-- DHCP Leases — oculto del UI hasta documentar su uso (la carga DHCP existe en backend; quitar v-if para reactivar) -->
                <label
                  v-if="false"
                  class="flex items-center justify-between gap-4 p-3 rounded-xl border bg-white dark:bg-gray-800 cursor-pointer transition-colors"
                  :class="form.dhcp_leases ? 'border-blue-500 ring-1 ring-blue-500/40' : 'border-gray-200 dark:border-gray-700'"
                >
                  <div class="text-sm">
                    <div class="font-medium text-gray-700 dark:text-gray-200">DHCP Leases</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Control de leases DHCP</div>
                  </div>

                  <input type="checkbox" :checked="form.dhcp_leases" @change="setControlMode('dhcp_leases')" class="sr-only" />

                  <span
                    class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors duration-200"
                    :class="form.dhcp_leases ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                  >
                    <span
                      class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                      :style="{ transform: form.dhcp_leases ? 'translateX(20px)' : 'translateX(0)' }"
                    ></span>
                  </span>
                </label>
              </div>

              <!-- Sub-opción: Tipo de limitación PPPoE -->
              <div v-if="form.pppoe" class="mt-4 p-4 rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50/50 dark:bg-blue-900/10">
                <div class="text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Tipo de limitación PPPoE</div>
                <p class="hint mb-3">El secret PPPoE (usuario/contraseña) siempre se crea. Elige cómo se aplica el límite de velocidad.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <label
                    class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors bg-white dark:bg-gray-800"
                    :class="form.pppoe_limit_mode === 'dynamic' ? 'border-blue-500 ring-1 ring-blue-500/40' : 'border-gray-200 dark:border-gray-700'"
                  >
                    <input type="radio" value="dynamic" v-model="form.pppoe_limit_mode" class="mt-1 accent-blue-600" />
                    <div class="text-sm">
                      <div class="font-medium text-gray-700 dark:text-gray-200">Dinámica</div>
                      <div class="text-xs text-gray-500 dark:text-gray-400">Mikrotik limita con el rate-limit del perfil/plan. No crea Simple Queue.</div>
                    </div>
                  </label>

                  <label
                    class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors bg-white dark:bg-gray-800"
                    :class="form.pppoe_limit_mode === 'queue' ? 'border-blue-500 ring-1 ring-blue-500/40' : 'border-gray-200 dark:border-gray-700'"
                  >
                    <input type="radio" value="queue" v-model="form.pppoe_limit_mode" class="mt-1 accent-blue-600" />
                    <div class="text-sm">
                      <div class="font-medium text-gray-700 dark:text-gray-200">Por Simple Queue</div>
                      <div class="text-xs text-gray-500 dark:text-gray-400">Además del secret, crea una regla Simple Queue con el límite del plan.</div>
                    </div>
                  </label>
                </div>
              </div>
            </div>

            <!-- ============ OPCIONES ADICIONALES ============ -->
            <div class="col-span-2">
              <div class="mb-3">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Opciones adicionales</h3>
                <p class="hint">Se pueden combinar con el método de control.</p>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

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
                    :class="form.agregar_cliente_mkt ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
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
                    :class="form.historial_trafico ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                  >
                    <span
                      class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                      :style="{ transform: form.historial_trafico ? 'translateX(20px)' : 'translateX(0)' }"
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
                    :class="form.ip_bindings ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
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
                    :class="form.amarre ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                  >
                    <span
                      class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                      :style="{ transform: form.amarre ? 'translateX(20px)' : 'translateX(0)' }"
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
                    :class="form.falla_general ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                  >
                    <span
                      class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"
                      :style="{ transform: form.falla_general ? 'translateX(20px)' : 'translateX(0)' }"
                    ></span>
                  </span>
                </label>

              </div>
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
                  {{ loading ? 'Guardando...' : 'Guardar Cambios' }}
                </button>
            </div>

        </form>
      </div>
    </main>
  </div>
</template>
<script setup>
import { ref, reactive, onMounted, watch } from "vue"
import { useRouter, useRoute } from "vue-router"
import { apiClient } from "@/services/api"
import catalogsApi from "@/services/api/catalogs"
import routersApi from "@/services/api/routers"
import BillingPanel from "@/components/BillingPanel.vue"
import NotificationToast from "@/components/NotificationToast.vue"

const router = useRouter()
const route = useRoute()
const routerId = route.params.id // ID del router a editar
const loading = ref(false)
const toast = ref(null)
const showPassword = ref(false)

// VPN Script variables
const vpnScript = ref("")
const loadingScript = ref(false)
const copied = ref(false)
const verifyingConnection = ref(false)
const connectionStatus = ref(null)

/* ============================
   FUNCIONES DE LIMPIEZA (Billing)
============================ */
const cleanInt = (val) => {
  if (val === undefined || val === null || val === "" || val === false || val === "false") return null
  const n = Number(val)
  return isNaN(n) ? null : n
}

const cleanDay = (val) => {
  if (!val || val === "false" || val === false) return null
  return String(val).padStart(2, "0")
}

// Normaliza "HH:MM" (del <input type="time">) al formato TIME de Postgres
// "HH:MM:SS". Vacío → medianoche (conserva el comportamiento por fecha).
const timeToSql = (val) => {
  if (!val || typeof val !== "string") return "00:00:00"
  const [h = "0", m = "0", s = "0"] = val.split(":")
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`
}

// Inverso: "HH:MM:SS" (de la BD) → "HH:MM" para el <input type="time">.
const sqlToTime = (val) => {
  if (!val || typeof val !== "string") return "00:00"
  const [h = "00", m = "00"] = val.split(":")
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`
}

/* ============================
   DATOS DESDE DB (Selects)
============================ */
const tiposCorte = ref([])
const scriptVersions = ref([])
const types = ref([])

const loadInitialData = async () => {
  const { data: cortes } = await catalogsApi.getCutTypes()
  tiposCorte.value = cortes ?? []

  const { data: versions } = await catalogsApi.getScriptVersions()
  scriptVersions.value = versions ?? []

  const { data: tipos } = await catalogsApi.getTypeBillings()
  types.value = tipos ?? []
}

/* ============================
        FORMULARIO
============================ */
const form = reactive({
  nombre: "",
  ip: "",
  ipv6: "",
  failover: "",
  coordenadas: "",
  version: null,
  external_id: "",
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
  pppoe_limit_mode: 'dynamic',
  ip_bindings: false,
  amarre: false,
  dhcp_leases: false,
  falla_general: false,
  comentarios_router: "",
  activo: true,
  script_activo: false,

  facturacion_activa: false,
  billing: {
    id: null,
    create_invoice: null,
    create_invoice_time: '00:00',
    pay_day: null,
    cut_day: null,
    cut_time: '00:00',
    overdue_invoices: "",
    amount: null,
    comentarios: "",
    metodo: "",
    notificar_wpp: false,
    remember_day: null,
    remember_time: '00:00',
    payment_reminder_enabled: true,
    notification_type: 'email',
    billing_mode: 'anticipado',
  }
})

/* ============================
   MÉTODO DE CONTROL (exclusivo)
   Solo uno de estos puede estar activo a la vez.
============================ */
const CONTROL_MODES = ['simple_queue', 'control_pcq', 'hotspot', 'pppoe', 'dhcp_leases']

const setControlMode = (mode) => {
  const enable = !form[mode]
  CONTROL_MODES.forEach((m) => { form[m] = false })
  form[mode] = enable
  // Al activar PPPoE, asegurar un valor de limitación por defecto.
  if (mode === 'pppoe' && enable && !form.pppoe_limit_mode) {
    form.pppoe_limit_mode = 'dynamic'
  }
}

/* ============================
   CARGAR DATOS DEL ROUTER (EDIT)
============================ */
const loadRouterData = async () => {
  loading.value = true
  try {
    const { data } = await routersApi.getOne(routerId)

    form.nombre = data.name || ""
    form.ip = data.ip || ""
    form.ipv6 = data.ipv6 || ""
    form.failover = data.failover || ""
    form.external_id = data.external_id || ""
    form.usuario = data.user_rb || ""
    form.password = data.password_rb || ""
    form.puerto_api = data.puerto_api || 8728
    form.puerto_www = data.puerto_www || 80
    form.interfaz_lan = data.lan_interface || ""
    form.rangos_ip = data.rangos_ip || ""
    form.tipo_corte = data.cut_type_id || null
    form.version = data.firmware_version || null
    form.comentarios_router = data.comments || ""
    form.activo = data.status === 'active'
    
    // Mapeo de Checkboxes
    form.agregar_cliente_mkt = !!data.agregar_cliente_mkt
    form.historial_trafico = !!data.historial_trafico
    form.simple_queue = !!data.simple_queue
    form.control_pcq = !!data.control_pcq
    form.hotspot = !!data.hotspot
    form.pppoe = !!data.pppoe
    form.ip_bindings = !!data.ip_bindings
    form.amarre = !!data.amarre
    form.dhcp_leases = !!data.dhcp_leases
    form.falla_general = !!data.falla_general
    form.pppoe_limit_mode = data.pppoe_limit_mode || 'dynamic'

    // Normalizar: el método de control es excluyente. Si por datos legados
    // hubiera más de uno activo, conservar solo el primero por prioridad.
    const active = CONTROL_MODES.filter((m) => form[m])
    if (active.length > 1) {
      CONTROL_MODES.forEach((m) => { form[m] = false })
      form[active[0]] = true
    }

    // 👇 COORDENADAS - PostGIS returns WKT format like "SRID=4326;POINT(-75.8383 21.7484)"
    if (data.coordinates) {
        try {
            // Simple regex to extract coordinates from WKT POINT format
            const match = data.coordinates.match(/POINT\s*\(\s*([-\d.]+)\s+([-\d.]+)\s*\)/i);
            if (match) {
                const lng = parseFloat(match[1]);
                const lat = parseFloat(match[2]);
                
                // Validate coordinates are within Earth's range
                if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                    form.coordenadas = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                }
            }
        } catch (e) {
            console.error("Error parsing coordinates:", e);
        }
    }

    // Mapear Billing
    // Helper: extrae el día (1-31) de una fecha YYYY-MM-DD
    const extractDay = (dateStr) => {
      if (!dateStr) return null
      const parts = String(dateStr).split('-')
      if (parts.length === 3) {
        return parseInt(parts[2], 10)
      }
      return null
    }

    if (data.billing) {
        form.facturacion_activa = true
        form.billing.id = data.billing.id
        
        // Convert to strings since DayPicker expects string modelValue
        const dayCreate = extractDay(data.billing.create_invoice)
        const dayCut = extractDay(data.billing.cut_day)
        const dayPay = extractDay(data.billing.payment_day)
        const dayRemind = extractDay(data.billing.payment_reminder)
        
        form.billing.create_invoice = dayCreate !== null ? String(dayCreate) : null
        form.billing.cut_day = dayCut !== null ? String(dayCut) : null
        form.billing.pay_day = dayPay !== null ? String(dayPay) : null
        form.billing.remember_day = dayRemind !== null ? String(dayRemind) : null

        // Horas (TIME en BD → "HH:MM" para el input). Filas viejas sin columna
        // llegan undefined → medianoche.
        form.billing.create_invoice_time = sqlToTime(data.billing.create_invoice_time)
        form.billing.cut_time = sqlToTime(data.billing.cut_time)
        form.billing.remember_time = sqlToTime(data.billing.payment_reminder_time)
        
        form.billing.overdue_invoices = data.billing.overdue_invoices
        form.billing.amount = data.billing.amount
        form.billing.metodo = data.billing.id_type
        form.billing.comentarios = data.billing.comments || ''
        form.billing.notificar_wpp = !!data.billing.notificar_wpp
        // payment_reminder_enabled defaults to true when undefined/null for backwards compat
        // with rows created before the column existed.
        form.billing.payment_reminder_enabled = data.billing.payment_reminder_enabled === false ? false : true
        form.billing.notification_type = data.billing.notification_type || 'email'
        form.billing.billing_mode = data.billing.billing_mode || 'anticipado'
    }

  } catch (e) {
    console.error("Error cargando router:", e)
    toast.value?.error(
      'Error al cargar datos',
      'No se pudieron cargar los datos del router. Verifica tu conexión e intenta nuevamente.'
    )
    router.push('/routers')
  } finally {
    loading.value = false
  }
}

/* ============================
   CONSTRUIR PAYLOAD DE BILLING
   (el backend lo crea/actualiza transaccionalmente junto al router)
============================ */
const buildBillingPayload = () => {
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

  return {
    create_invoice: dayToDate(form.billing.create_invoice),
    create_invoice_time: timeToSql(form.billing.create_invoice_time),
    cut_day: dayToDate(form.billing.cut_day),
    cut_time: timeToSql(form.billing.cut_time),
    payment_day: dayToDate(form.billing.pay_day),
    payment_reminder: dayToDate(form.billing.remember_day),
    payment_reminder_time: timeToSql(form.billing.remember_time),
    payment_reminder_enabled: form.billing.payment_reminder_enabled !== false,
    overdue_invoices: cleanInt(form.billing.overdue_invoices) ?? 0,
    amount: cleanInt(form.billing.amount),
    id_type: cleanInt(form.billing.metodo),
    status: 'pending',
    notificar_wpp: form.billing.notificar_wpp || false,
    notification_type: form.billing.notification_type || 'email',
    billing_mode: form.billing.billing_mode || 'anticipado',
    comments: form.billing.comentarios || null,
  }
}

/* ============================
   GUARDAR ROUTER (UPDATE)
============================ */
const saveRouter = async () => {
  let coordinates = null
  if (form.coordenadas) {
    const [lat, lng] = form.coordenadas.split(",").map(v => parseFloat(v.trim()))
    if (!isNaN(lat) && !isNaN(lng)) {
      coordinates = `SRID=4326;POINT(${lng} ${lat})`
    }
  }

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
    comments: form.comentarios_router,
    coordinates,
    status: form.activo ? 'active' : 'inactive',
    agregar_cliente_mkt: form.agregar_cliente_mkt || false,
    historial_trafico: form.historial_trafico || false,
    simple_queue: form.simple_queue || false,
    control_pcq: form.control_pcq || false,
    hotspot: form.hotspot || false,
    pppoe: form.pppoe || false,
    pppoe_limit_mode: form.pppoe_limit_mode || 'dynamic',
    ip_bindings: form.ip_bindings || false,
    amarre: form.amarre || false,
    dhcp_leases: form.dhcp_leases || false,
    falla_general: form.falla_general || false,
    rangos_ip: form.rangos_ip || null,
  }

  // El backend crea/actualiza la config de facturación y enlaza billing_router_id.
  if (form.facturacion_activa) {
    payload.billing = buildBillingPayload()
  }

  try {
    await routersApi.update(routerId, payload)
  } catch (error) {
    console.error("❌ Error actualizando router:", error)
    toast.value?.error(
      'Error al actualizar router',
      error?.response?.data?.message || error?.message || 'Ocurrió un error inesperado. Intenta nuevamente.'
    )
    return
  }

  toast.value?.success(
    'Router actualizado exitosamente',
    'Los cambios se han guardado correctamente en la base de datos.'
  )
  
  setTimeout(() => {
    router.push("/routers")
  }, 1500)
}

/* ============================
   VPN SCRIPT FUNCTIONS
============================ */
const loadVpnScript = async () => {
  if (!form.script_activo) return
  
  loadingScript.value = true
  vpnScript.value = ""
  
  try {
    const { data } = await apiClient.get(`/routers/${routerId}/vpn-script`)
    
    if (data.success) {
      vpnScript.value = data.script
    } else {
      vpnScript.value = ""
      console.error("Error loading VPN script:", data.message || "Unknown error")
      toast.value?.error(
        'Error al cargar script VPN',
        data.message || 'No se pudo obtener el script. Intenta nuevamente.'
      )
    }
  } catch (error) {
    console.error("Error fetching VPN script:", error)
    vpnScript.value = ""
    
    const status = error.response?.status
    let errorMsg = 'Error de conexión al cargar el script.'
    
    if (status === 401) {
      errorMsg = 'Sesión expirada. Por favor, vuelve a iniciar sesión.'
    } else if (status === 404) {
      errorMsg = 'Router no encontrado.'
    } else if (status === 500) {
      errorMsg = 'Error del servidor al generar el script VPN.'
    } else if (error.response?.data?.message) {
      errorMsg = error.response.data.message
    }
    
    toast.value?.error('Error al cargar script VPN', errorMsg)
  } finally {
    loadingScript.value = false
  }
}


const copyScript = async () => {
  if (!vpnScript.value) return
  
  try {
    await navigator.clipboard.writeText(vpnScript.value)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (error) {
    console.error("Error copying to clipboard:", error)
    alert("Error al copiar el script")
  }
}

const verifyConnection = async () => {
  verifyingConnection.value = true
  connectionStatus.value = null
  
  try {
    const { data } = await apiClient.post(`/routers/${routerId}/verify-vpn`)
    
    connectionStatus.value = {
      connected: data.connected,
      message: data.message,
      assigned_ip: data.assigned_ip,
    }
    
    // Si la conexión fue exitosa, actualizar todos los datos del router
    if (data.connected && data.assigned_ip) {
      form.ip = data.assigned_ip
      
      if (data.user_rb) {
        form.usuario = data.user_rb
      }
      if (data.password_rb) {
        form.password = data.password_rb
      }
      
      await loadRouterData()
      
      toast.value?.success(
        'Conexión VPN verificada',
        `Datos actualizados: IP: ${data.assigned_ip}, Usuario: ${data.user_rb || 'N/A'}`
      )
    }
    
  } catch (error) {
    console.error("Error verifying connection:", error)
    connectionStatus.value = {
      connected: false,
      message: error.response?.data?.message || "Error al verificar la conexión",
      assigned_ip: null,
    }
  } finally {
    verifyingConnection.value = false
  }
}

const goBack = () => router.back()

// Watch for script activation to load VPN script
watch(() => form.script_activo, (newValue) => {
  if (newValue) {
    loadVpnScript()
  }
})

onMounted(async () => {
  await loadInitialData()
  await loadRouterData()
  
  // Load VPN script if already activated
  if (form.script_activo) {
    await loadVpnScript()
  }
})
</script>


<style scoped>
.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1;
}
.input, .textarea {
  @apply w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 
         text-gray-900 dark:text-gray-100 rounded-lg p-2.5 focus:ring-blue-500 focus:border-blue-500;
}
.btn-primary {
  @apply text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 
         font-medium rounded-lg text-sm px-5 py-2.5 dark:bg-blue-600 dark:hover:bg-blue-700 
         focus:outline-none dark:focus:ring-blue-800 transition-colors;
}
.hint {
    @apply mt-1 text-xs text-gray-500 dark:text-gray-400;
}
</style>
