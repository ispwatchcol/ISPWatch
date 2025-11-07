<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-8">

      <!-- Header -->
      <div class="flex items-center justify-between mb-10">
        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
          <v-icon name="pr-server" class="text-blue-600 w-7 h-7" />
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
                <label class="label flex items-center gap-2">
                Failover
                <icon-lucide-help-circle class="w-4 h-4 text-gray-500"/>
                <icon-lucide-refresh-cw class="w-4 h-4 text-blue-600 cursor-pointer"/>
                </label>
                <input v-model="form.failover" type="text" placeholder="Ej: IP Mikrotik Cloud" class="input"/>
                <p class="hint">Para usar esta función debes agregar las IP de los servidores de WispHub.</p>
            </div>

            <!-- COORDENADAS -->
            <div class="col-span-2">
                <label class="label">Coordenadas</label>
                <input v-model="form.coordenadas" type="text" placeholder="Ej: 21.150168,-86.875023" class="input"/>
            </div>

            <!-- VERSION -->
            <div>
                <label class="label">Versión</label>
                <select v-model="form.version" class="input">
                <option value="" disabled>Elija una opción</option>
                <option>v6</option>
                <option>v7</option>
                <option>Beta</option>
                <option>Otro</option>
                </select>
            </div>

            <!-- EXTERNAL ID -->
            <div>
                <label class="label">External ID</label>
                <input v-model="form.external_id" class="input" placeholder="Ej: 000123"/>
            </div>

            <!-- USUARIO RB -->
            <div>
                <label class="label">Usuario del RB</label>
                <input v-model="form.usuario" type="text" placeholder="Ej: admin" class="input"/>
            </div>

            <!-- PASSWORD RB -->
            <div>
                <label class="label">Password del RB</label>
                <input v-model="form.password" type="password" placeholder="Ej: 123456" class="input"/>
            </div>

            <!-- PUERTO API -->
            <div>
                <label class="label">Puerto API</label>
                <input v-model="form.puerto_api" type="number" placeholder="8728" class="input"/>
            </div>

            <!-- PUERTO WWW -->
            <div>
                <label class="label">Puerto WWW</label>
                <input v-model="form.puerto_www" type="number" placeholder="80" class="input"/>
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

            <!-- SELECT: TIPO DE CORTE -->
            <div class="col-span-2">
                <label class="label">Tipo de corte de servicio</label>
                <select v-model="form.tipo_corte" class="input">
                <option value="" disabled>Seleccione una opción</option>
                <option>Corte por Address List moroso</option>
                <option>Corte por simple queue</option>
                <option>Corte por hotspot</option>
                <option>Sin corte automático</option>
                </select>
            </div>

            <!-- SWITCHES DE SISTEMA -->
            <div class="col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                <Switch label="Agregar Cliente en Mikrotik" v-model="form.agregar_cliente_mkt"/>
                <Switch label="Historial de Tráfico" v-model="form.historial_trafico"/>
                <Switch label="Control Simple Queue" v-model="form.simple_queue"/>
                <Switch label="Control PCQ + Address-list" v-model="form.control_pcq"/>
                <Switch label="Control HotSpot" v-model="form.hotspot"/>
                <Switch label="Control PPPOE" v-model="form.pppoe"/>
                <Switch label="IP Bindings" v-model="form.ip_bindings"/>
                <Switch label="Amarre IP/MAC" v-model="form.amarre"/>
                <Switch label="DHCP Leases" v-model="form.dhcp_leases"/>
                <Switch label="Falla General" v-model="form.falla_general"/>
            </div>

            <!-- COMENTARIOS -->
            <div class="col-span-2">
                <label class="label">Comentarios</label>
                <textarea v-model="form.comentarios" rows="3" class="textarea"></textarea>
            </div>

            <!-- ACTIVO -->
            <div class="col-span-2 flex items-center gap-3">
                <span class="label">Activo</span>
                <Switch v-model="form.activo"/>
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
import { reactive } from "vue"
import { useRouter } from "vue-router"
import { supabase } from "@/supabase.js"

const router = useRouter()

const form = reactive({
  nombre: "",
  ip: "",
  ipv6: "",
  failover: "",
  coordenadas: "",
  version: "",
  external_id: "",
  usuario: "",
  password: "",
  puerto_api: 8728,
  puerto_www: 80,
  interfaz_lan: "",
  rangos_ip: "",
  tipo_corte: "",
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
  comentarios: "",
  activo: true
})

const saveRouter = async () => {
  const { error } = await supabase.from("router").insert([form])

  if (error) {
    alert("Error al guardar router: " + error.message)
    return
  }

  router.push("/dashboard/routers")
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


