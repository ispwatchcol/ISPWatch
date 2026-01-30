<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Notification Toast -->
    <NotificationToast ref="toast" />
    
    <main class="flex-1 p-8">

      <!-- Header -->
      <div class="flex items-center justify-between mb-10">
        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
          <v-icon name="pr-server" class="text-blue-600 w-7 h-7" />
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
                  @click.stop="form.agregar_cliente_mkt = !form.agregar_cliente_mkt"
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
                  @click.stop="form.historial_trafico = !form.historial_trafico"
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
                  @click.stop="form.simple_queue = !form.simple_queue"
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
                  @click.stop="form.control_pcq = !form.control_pcq"
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
                  @click.stop="form.hotspot = !form.hotspot"
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
                  @click.stop="form.pppoe = !form.pppoe"
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
                  @click.stop="form.ip_bindings = !form.ip_bindings"
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
                  @click.stop="form.amarre = !form.amarre"
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
                  @click.stop="form.dhcp_leases = !form.dhcp_leases"
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
                  @click.stop="form.falla_general = !form.falla_general"
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
import { supabase } from "@/supabase.js"
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

/* ============================
   PARSEO HEX ROBUSTO (EWKB PostGIS)
============================ */
const parseWKB = (hex) => {
    if (!hex || typeof hex !== 'string') return null;
    
    // Limpieza básica
    hex = hex.trim();
    
    try {
        // 1. Convertir string hex a DataView
        // Cada 2 caracteres hex son 1 byte.
        const buffer = new Uint8Array(hex.match(/[\da-f]{2}/gi).map(h => parseInt(h, 16))).buffer;
        const view = new DataView(buffer);
        
        // Verificamos si es Little Endian (01)
        const isLittleEndian = view.getUint8(0) === 1;
        
        // Leemos el tipo de geometría (bytes 1-4)
        const geomType = view.getUint32(1, isLittleEndian);
        
        // Posición donde empiezan las coordenadas X (Longitud)
        let offset = 5; 
        
        // Si el flag SRID está activo (0x20000000), hay 4 bytes extra para el SRID
        if ((geomType & 0x20000000) !== 0) { 
            // Tiene SRID, saltamos 4 bytes más
            offset += 4; 
        }
        
        // Si por alguna razón no detectamos flags pero el string es largo,
        // intentamos forzar el offset estándar de PostGIS (byte 9)
        if (offset === 5 && hex.length > 40) offset = 9;

        // Leemos coordenadas
        const lng = view.getFloat64(offset, isLittleEndian);
        const lat = view.getFloat64(offset + 8, isLittleEndian);
        
        // Validar que sean números reales y dentro de rangos terrestres lógicos
        if (isNaN(lat) || isNaN(lng)) return null;
        if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
             console.warn("Coordenadas fuera de rango, posible error de parseo", {lat, lng});
             return null;
        }

        return { lat: lat.toFixed(6), lng: lng.toFixed(6) };

    } catch (e) {
        console.warn("Fallo parseWKB:", e, hex);
        return null;
    }
}

/* ============================
   DATOS DESDE DB (Selects)
============================ */
const tiposCorte = ref([])
const scriptVersions = ref([])
const types = ref([])

const loadInitialData = async () => {
  const { data: cortes } = await supabase.from("cut_type").select("*")
  tiposCorte.value = cortes ?? []

  const { data: versions } = await supabase.from("script_version").select("*")
  scriptVersions.value = versions ?? []

  const { data: tipos } = await supabase.from("type_billing").select("id, type")
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
    pay_day: null,
    cut_day: null,
    overdue_invoices: "",
    amount: null,
    comentarios: "",
    metodo: "",
    notificar_wpp: false,
    remember_day: null,
  }
})

/* ============================
   CARGAR DATOS DEL ROUTER (EDIT)
============================ */
const loadRouterData = async () => {
  loading.value = true
  try {
    const { data, error } = await supabase
      .from('router')
      .select(`*, billing:billing_router_id (*)`)
      .eq('id', routerId)
      .single()

    if (error) throw error

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

    // 👇 COORDENADAS
    if (data.coordinates) {
        const coords = parseWKB(data.coordinates);
        if (coords) {
            form.coordenadas = `${coords.lat}, ${coords.lng}`
            console.log("✅ Coordenadas parseadas:", form.coordenadas)
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
        form.billing.create_invoice = extractDay(data.billing.create_invoice)
        form.billing.cut_day = extractDay(data.billing.cut_day)
        form.billing.pay_day = extractDay(data.billing.payment_day)
        form.billing.remember_day = extractDay(data.billing.payment_reminder)
        form.billing.overdue_invoices = data.billing.overdue_invoices
        form.billing.amount = data.billing.amount
        form.billing.metodo = data.billing.id_type
        form.billing.comentarios = data.billing.comments || ''
        form.billing.notificar_wpp = !!data.billing.notificar_wpp
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
   GUARDAR BILLING
============================ */
const saveBilling = async () => {
  // Helper: convierte un día (1-31) a fecha YYYY-MM-DD del mes actual
  const dayToDate = (day) => {
    const num = cleanInt(day)
    if (!num || num < 1 || num > 31) return null
    const now = new Date()
    const year = now.getFullYear()
    const month = String(now.getMonth() + 1).padStart(2, '0')
    const d = String(num).padStart(2, '0')
    return `${year}-${month}-${d}`
  }

  // Obtener tenant_id del usuario logueado
  const userData = JSON.parse(localStorage.getItem("userData")) ?? JSON.parse(sessionStorage.getItem("userData"))
  const tenantId = userData?.tenant_id

  // Usar los mismos nombres de columna que en la BD
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
    comments: form.billing.comentarios || null,
    tenant_id: tenantId,
    updated_at: new Date().toISOString(),
  }

  let result = null

  if (form.billing.id) {
      const { data, error } = await supabase
        .from("billing")
        .update(payload)
        .eq('id', form.billing.id)
        .select()
        .single()
      if (error) {
        console.error("❌ Error actualizando billing:", error)
      }
      if (!error) result = data
  } else {
      // Si es nuevo billing, agregar created_at
      payload.created_at = new Date().toISOString()
      
      const { data, error } = await supabase
        .from("billing")
        .insert(payload)
        .select()
        .single()
      if (error) {
        console.error("❌ Error insertando billing:", error)
      }
      if (!error) result = data
  }
  return result
}

/* ============================
   GUARDAR ROUTER (UPDATE)
============================ */
const saveRouter = async () => {
  const userData = JSON.parse(localStorage.getItem("userData")) ?? JSON.parse(sessionStorage.getItem("userData"))
  
  let coordinates = null
  if (form.coordenadas) {
    const [lat, lng] = form.coordenadas.split(",").map(v => parseFloat(v.trim()))
    if (!isNaN(lat) && !isNaN(lng)) {
      coordinates = `SRID=4326;POINT(${lng} ${lat})`
    }
  }

  let billingId = form.billing.id || null 
  if (form.facturacion_activa) {
    const billingRow = await saveBilling()
    if (billingRow?.id) billingId = billingRow.id
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
    billing_router_id: billingId,
    comments: form.comentarios_router,
    coordinates,
    status: form.activo ? 'active' : 'inactive',
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
    updated_at: new Date().toISOString(),
  }


  const { error } = await supabase
    .from("router")
    .update(payload)
    .eq('id', routerId)

  if (error) {
    console.error("❌ Error actualizando router:", error)
    toast.value?.error(
      'Error al actualizar router',
      error.message || 'Ocurrió un error inesperado. Intenta nuevamente.'
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
  try {
    const response = await fetch(`/api/routers/${routerId}/vpn-script`)
    const data = await response.json()
    
    if (data.success) {
      vpnScript.value = data.script
    } else {
      vpnScript.value = ""
      console.error("Error loading VPN script")
    }
  } catch (error) {
    console.error("Error fetching VPN script:", error)
    vpnScript.value = ""
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
    const response = await fetch(`/api/routers/${routerId}/verify-vpn`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
    })
    const data = await response.json()
    
    connectionStatus.value = {
      connected: data.connected,
      message: data.message,
      assigned_ip: data.assigned_ip,
    }
    
    // Si la conexión fue exitosa, actualizar todos los datos del router
    if (data.connected && data.assigned_ip) {
      // Actualizar IP
      form.ip = data.assigned_ip
      
      // Actualizar credenciales del RB
      if (data.user_rb) {
        form.usuario = data.user_rb
      }
      if (data.password_rb) {
        form.password = data.password_rb
      }
      
      // Recargar datos completos del router para asegurar sincronización
      await loadRouterData()
      
      // Notificar al usuario sobre los cambios
      toast.value?.success(
        'Conexión VPN verificada',
        `Datos actualizados: IP: ${data.assigned_ip}, Usuario: ${data.user_rb || 'N/A'}`
      )
    }
    
  } catch (error) {
    console.error("Error verifying connection:", error)
    connectionStatus.value = {
      connected: false,
      message: "Error al verificar la conexión",
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
