<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
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
              <input v-model="form.password" type="password" placeholder="Ej: 123456" class="input" />
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
import { ref, reactive, onMounted } from "vue"
import { useRouter, useRoute } from "vue-router"
import { supabase } from "@/supabase.js"
import BillingPanel from "@/components/BillingPanel.vue"

const router = useRouter()
const route = useRoute()
const routerId = route.params.id // ID del router a editar
const loading = ref(false)

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
    form.activo = data.status === 1
    
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
    if (data.billing) {
        form.facturacion_activa = true
        form.billing.id = data.billing.id
        form.billing.create_invoice = Number(data.billing.create_invoice) || null
        form.billing.cut_day = Number(data.billing.cut_day) || null
        form.billing.pay_day = Number(data.billing.payment_day) || null
        form.billing.remember_day = Number(data.billing.remember_day) || null
        form.billing.overdue_invoices = data.billing.overdue_invoices
        form.billing.amount = data.billing.amount
        form.billing.metodo = data.billing.type 
        form.billing.comentarios = data.billing.commit 
    }

  } catch (e) {
    console.error("Error cargando router:", e)
    alert("Error al cargar datos")
    router.push('/routers')
  } finally {
    loading.value = false
  }
}

/* ============================
   GUARDAR BILLING
============================ */
const saveBilling = async () => {
  const payload = {
    create_invoice: cleanDay(form.billing.create_invoice),
    cut_day: cleanDay(form.billing.cut_day),
    payment_day: cleanDay(form.billing.pay_day),
    remember_day: cleanDay(form.billing.remember_day),
    overdue_invoices: cleanInt(form.billing.overdue_invoices),
    amount: cleanInt(form.billing.amount),
    type: cleanInt(form.billing.metodo),
    commit: form.billing.comentarios,
  }

  let result = null

  if (form.billing.id) {
      const { data, error } = await supabase
        .from("billing")
        .update(payload)
        .eq('id', form.billing.id)
        .select()
        .single()
      if (!error) result = data
  } else {
      const { data, error } = await supabase
        .from("billing")
        .insert(payload)
        .select()
        .single()
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
    user_rb: form.usuario,
    password_rb: form.password,
    lan_interface: form.interfaz_lan,
    cut_type_id: form.tipo_corte,
    firmware_version: form.version,
    billing_router_id: billingId,
    comments: form.comentarios_router,
    coordinates,
    status: form.activo ? 1 : 0,
  }


  const { error } = await supabase
    .from("router")
    .update(payload)
    .eq('id', routerId)

  if (error) {
    console.error("❌ Error actualizando router:", error)
    alert("Error al actualizar router: " + error.message)
    return
  }

  alert("Router actualizado correctamente")
  router.push("/routers")
}

const goBack = () => router.back()

onMounted(async () => {
  await loadInitialData()
  await loadRouterData()
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
