<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 flex flex-col transition-colors duration-300">
    <!-- Notification Toast -->
    <NotificationToast ref="toast" />
    
    <!-- LOADING STATE (Overlay) -->
    <div v-if="fetching" class="fixed inset-0 bg-white/80 dark:bg-gray-900/80 z-50 flex items-center justify-center backdrop-blur-sm">
      <div class="flex flex-col items-center gap-3">
        <v-icon name="bi-arrow-repeat" animation="spin" class="w-8 h-8 text-blue-600" />
        <span class="text-gray-500 font-medium">Cargando datos del plan...</span>
      </div>
    </div>

    <!-- HEADER -->
    <header class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 sticky top-0 z-10">
      <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        <div class="flex items-center gap-4">
          <button 
            @click="router.back()" 
            class="p-2 -ml-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 dark:text-gray-400 transition"
            title="Volver"
          >
            <icon-lucide-arrow-left class="w-5 h-5" />
          </button>
          
          <div>
            <h1 class="text-lg sm:text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
              Editar Plan
              <span 
                v-if="currentConfig"
                class="px-2 py-0.5 rounded-md text-sm font-medium border bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800"
              >
                {{ currentConfig.label }}
              </span>
            </h1>
          </div>
        </div>

        <!-- Botones de Acción (Desktop) -->
        <div class="hidden sm:flex items-center gap-3">
          <button 
            @click="router.back()"
            class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition"
          >
            Cancelar
          </button>
          <button 
            @click="updatePlan" 
            :disabled="loading || fetching"
            class="px-5 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-md transition flex items-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed"
          >
            <v-icon v-if="loading" name="bi-arrow-repeat" animation="spin" class="w-4 h-4" />
            <icon-lucide-save v-else class="w-4 h-4" />
            Actualizar Plan
          </button>
        </div>
      </div>
    </header>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="flex-1 max-w-5xl w-full mx-auto p-4 sm:p-6 lg:p-8">
      
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 sm:gap-8">
        
        <!-- COLUMNA IZQUIERDA: INFORMACIÓN BÁSICA -->
        <div class="lg:col-span-2 space-y-6">
          
          <!-- Card: Datos Generales -->
          <section class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 sm:p-6">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-5 flex items-center gap-2">
              <icon-lucide-info class="w-4 h-4 text-gray-400" />
              Información General
            </h2>
            
            <div class="space-y-5">
              <!-- Nombre del Plan -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nombre del Plan</label>
                <input 
                  v-model="form.name"
                  type="text" 
                  placeholder="Ej: Plan Fibra 50 Megas" 
                  class="w-full h-11 px-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 
                         text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500
                         focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all outline-none"
                />
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <!-- Precio -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Precio Mensual</label>
                  <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 font-medium pointer-events-none">$</span>
                    <input 
                      v-model="form.cost_product"
                      type="number" 
                      placeholder="0" 
                      class="w-full h-11 pl-7 pr-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 
                             text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500
                             focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all outline-none"
                    />
                  </div>
                </div>

                <!-- Clientes Simultáneos (Hotspot) -->
                <div v-if="planType === 'hotspot'">
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Usuarios Compartidos</label>
                  <input 
                    v-model="form.shared_users"
                    type="number" 
                    class="w-full h-11 px-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 
                           text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500
                           focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all outline-none"
                  />
                </div>
              </div>

              <!-- Descripción -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Descripción <span class="text-gray-400 font-normal">(Opcional)</span></label>
                <textarea 
                  v-model="form.commit"
                  rows="3"
                  class="w-full px-4 py-3 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 
                         text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500
                         focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all outline-none resize-none leading-relaxed"
                  placeholder="Detalles visibles para el cliente..."
                ></textarea>
              </div>
            </div>
          </section>

          <!-- Card: Configuración de Velocidad -->
          <section class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 sm:p-6">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-5 flex items-center gap-2">
              <icon-lucide-activity class="w-4 h-4 text-gray-400" />
              Límites de Velocidad
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
              <!-- Velocidad Bajada -->
              <div>
                <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  <span class="w-2 h-2 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.5)]"></span>
                  Descarga (Download)
                </label>
                <div class="flex h-11 rounded-xl shadow-sm">
                  <input 
                    v-model="form.speed_down"
                    type="number" 
                    class="flex-1 min-w-0 px-4 h-full rounded-l-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 
                           text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500
                           focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none z-10"
                    placeholder="Ej: 50"
                  />
                  <div class="w-24 bg-gray-100 dark:bg-gray-700 border-y border-r border-gray-200 dark:border-gray-600 rounded-r-xl relative">
                    <select v-model="form.download_unit" class="w-full h-full bg-transparent text-gray-700 dark:text-gray-200 px-3 text-sm font-medium focus:outline-none cursor-pointer appearance-none">
                      <option value="M">Mbps</option>
                      <option value="K">Kbps</option>
                    </select>
                    <div class="absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-gray-500 dark:text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Velocidad Subida -->
              <div>
                <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  <span class="w-2 h-2 rounded-full bg-purple-500 shadow-[0_0_8px_rgba(168,85,247,0.5)]"></span>
                  Subida (Upload)
                </label>
                <div class="flex h-11 rounded-xl shadow-sm">
                  <input 
                    v-model="form.speed_up"
                    type="number" 
                    class="flex-1 min-w-0 px-4 h-full rounded-l-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 
                           text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500
                           focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none z-10"
                    placeholder="Ej: 10"
                  />
                  <div class="w-24 bg-gray-100 dark:bg-gray-700 border-y border-r border-gray-200 dark:border-gray-600 rounded-r-xl relative">
                    <select v-model="form.upload_unit" class="w-full h-full bg-transparent text-gray-700 dark:text-gray-200 px-3 text-sm font-medium focus:outline-none cursor-pointer appearance-none">
                      <option value="M">Mbps</option>
                      <option value="K">Kbps</option>
                    </select>
                    <div class="absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-gray-500 dark:text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Burst (Opcional Avanzado) -->
            <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700">
              <button 
                type="button" 
                @click="showAdvancedSpeed = !showAdvancedSpeed"
                class="text-sm text-blue-600 dark:text-blue-400 font-medium flex items-center gap-1 hover:underline group"
              >
                {{ showAdvancedSpeed ? 'Ocultar' : 'Configurar' }} Ráfaga (Burst Limit)
                <icon-lucide-chevron-down class="w-4 h-4 transition-transform duration-200 group-hover:text-blue-500" :class="{'rotate-180': showAdvancedSpeed}" />
              </button>
              
              <div v-if="showAdvancedSpeed" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 animate-fade-in-down">
                 <div class="bg-blue-50 dark:bg-gray-900/40 p-3 rounded-lg border border-blue-100 dark:border-gray-600">
                    <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1 block">Burst Download</label>
                    <input 
                      v-model="form.burst_download" 
                      type="text" 
                      placeholder="Ej: 60M" 
                      class="w-full bg-transparent border-none text-sm p-0 focus:ring-0 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-medium" 
                    />
                 </div>
                 <div class="bg-purple-50 dark:bg-gray-900/40 p-3 rounded-lg border border-purple-100 dark:border-gray-600">
                    <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1 block">Burst Upload</label>
                    <input 
                      v-model="form.burst_upload" 
                      type="text" 
                      placeholder="Ej: 15M" 
                      class="w-full bg-transparent border-none text-sm p-0 focus:ring-0 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-medium" 
                    />
                 </div>
              </div>
            </div>
          </section>
        </div>

        <!-- COLUMNA DERECHA: CONFIGURACIÓN ESPECÍFICA -->
        <div class="space-y-6">
          
          <!-- Card: Configuración Técnica (Dinámica según Plan) -->
          <section v-if="currentConfig" class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 sm:p-6 h-fit sticky top-24">
            <div class="flex items-center gap-3 mb-5 pb-4 border-b border-gray-100 dark:border-gray-700">
               <div class="p-2 rounded-lg bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">
                 <!-- Icono genérico o dinámico -->
                 <icon-lucide-settings class="w-5 h-5" />
               </div>
               <div>
                 <h2 class="text-base font-semibold text-gray-900 dark:text-white leading-tight">Configuración {{ currentConfig.shortLabel }}</h2>
                 <p class="text-xs text-gray-500 dark:text-gray-400">Parámetros técnicos</p>
               </div>
            </div>

            <div class="space-y-4">
              
              <!-- CAMPOS PPPOE -->
              <template v-if="planType === 'pppoe'">
                <div>
                  <label class="flex items-start gap-3 cursor-pointer select-none">
                    <input
                      type="checkbox"
                      v-model="usePool"
                      class="mt-0.5 h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500/30 cursor-pointer"
                    />
                    <span>
                      <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">Asignar Pool de IPs (Remote)</span>
                      <span class="block text-xs text-gray-500 dark:text-gray-400">Por defecto el pool lo asigna el router. Actívalo solo si quieres forzar uno.</span>
                    </span>
                  </label>
                  <input
                    v-if="usePool"
                    type="text"
                    v-model="form.pppoe_pool"
                    placeholder="Ej: pool-pppoe-clientes"
                    class="mt-3 w-full h-11 px-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-sm"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Local Address</label>
                  <input 
                    type="text" 
                    v-model="form.local_address" 
                    placeholder="Ej: 10.0.0.1" 
                    class="w-full h-11 px-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-sm" 
                  />
                </div>
              </template>

              <!-- CAMPOS QUEUE -->
              <template v-if="planType === 'queue'">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Prioridad (Priority)</label>
                  <div class="flex items-center gap-4 bg-gray-50 dark:bg-gray-900 p-3 rounded-xl border border-gray-200 dark:border-gray-600">
                    <input 
                      type="range" 
                      min="1" max="8" 
                      v-model="form.priority"
                      class="w-full h-2 rounded-lg appearance-none cursor-pointer bg-gray-200 dark:bg-gray-700 focus:outline-none"
                      :style="{ 
                          background: `linear-gradient(to right, #3b82f6 0%, #3b82f6 ${((form.priority - 1) * 100) / 7}%, #e5e7eb ${((form.priority - 1) * 100) / 7}%, #e5e7eb 100%)` 
                      }"
                    />
                    <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 shadow-sm transition-all ring-2 ring-blue-500 border-blue-500">
                      <span class="font-bold text-gray-700 dark:text-white text-sm">{{ form.priority }}</span>
                    </div>
                  </div>
                  <div class="flex justify-between px-1 mt-1.5">
                    <span class="text-xs font-medium text-blue-600 dark:text-blue-400">Alta (1)</span>
                    <span class="text-xs text-gray-400">Media</span>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Baja (8)</span>
                  </div>
                </div>
              </template>
                
              <!-- CAMPOS HOTSPOT -->
              <template v-if="planType === 'hotspot'">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Session Timeout</label>
                  <input type="text" v-model="form.session_timeout" placeholder="Ej: 30d 00:00:00" class="w-full h-11 px-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-sm" />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Idle Timeout</label>
                  <input type="text" v-model="form.idle_timeout" placeholder="none" class="w-full h-11 px-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-sm" />
                </div>
              </template>

              <!-- CAMPOS PCQ -->
              <template v-if="planType === 'pcq'">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">PCQ Rate</label>
                  <input type="text" v-model="form.pcq_rate" placeholder="0 (Ilimitado)" class="w-full h-11 px-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-sm" />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Address Mask IPv4</label>
                  <div class="relative">
                    <select v-model="form.address_mask" class="w-full h-11 px-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-sm appearance-none">
                      <option value="32">32 (Individual)</option>
                      <option value="24">24 (Subred)</option>
                    </select>
                    <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-500 dark:text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                  </div>
                </div>
              </template>

            </div>
          </section>

           <!-- Resumen Rápido -->
           <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4 border border-blue-100 dark:border-blue-800">
             <h3 class="text-sm font-bold text-blue-800 dark:text-blue-300 mb-1">Resumen de Cambios</h3>
             <p class="text-xs text-blue-600 dark:text-blue-400 leading-relaxed">
               Estás editando un perfil <strong>{{ currentConfig.shortLabel }}</strong>. Quedará con {{ form.speed_down || '0' }}{{ form.download_unit }} de bajada y {{ form.speed_up || '0' }}{{ form.upload_unit }} de subida.
             </p>
           </div>
        </div>

      </div>

      <!-- BOTONES MÓVILES (Fixed Bottom) -->
      <div class="sm:hidden fixed bottom-0 left-0 right-0 p-4 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 flex gap-3 z-20 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.1)]">
        <button 
          @click="router.back()"
          class="flex-1 px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-700 rounded-xl active:scale-95 transition"
        >
          Cancelar
        </button>
        <button 
          @click="updatePlan" 
          :disabled="loading"
          class="flex-1 px-4 py-3 text-sm font-medium text-white bg-blue-600 rounded-xl shadow-lg flex justify-center items-center gap-2 active:scale-95 transition"
        >
          <span v-if="!loading">Actualizar</span>
          <v-icon v-else name="bi-arrow-repeat" animation="spin" class="w-5 h-5" />
        </button>
      </div>

      <div class="h-24 sm:hidden"></div>

    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/services/api'
import NotificationToast from '@/components/NotificationToast.vue'

const router = useRouter()
const route = useRoute()

// El ID viene como parametro de la ruta (ej: /planes/:id/edit)
const planId = route.params.id

// Estado
const loading = ref(false)
const fetching = ref(true)
const showAdvancedSpeed = ref(false)
// Pool de IPs opcional: por defecto lo asigna el router
const usePool = ref(false)

// Configuración de tipos
const configMap = {
  queue: { label: 'Simple Queue', shortLabel: 'Queue' },
  pppoe: { label: 'PPPoE Profile', shortLabel: 'PPPoE' },
  hotspot: { label: 'HotSpot Profile', shortLabel: 'HotSpot' },
  pcq: { label: 'PCQ Type', shortLabel: 'PCQ' }
}

const typePlanMapInverse = {
  1: 'queue',
  2: 'pcq',      // Corregido: ID 2 es PCQ
  3: 'hotspot',
  4: 'pppoe'     // Corregido: ID 4 es PPPoE
}

// Tipo de plan (se detectará al cargar los datos)
const planType = ref('queue')

const currentConfig = computed(
  () => configMap[planType.value] || configMap.queue
)

// Formulario reactivo
const form = ref({
  name: '',
  cost_product: '',
  commit: '',
  speed_down: '',
  download_unit: 'M',
  speed_up: '',
  upload_unit: 'M',
  priority: 8,
  burst_download: '',
  burst_upload: '',
  // Campos específicos
  pppoe_pool: '',
  local_address: '',
  session_timeout: '',
  idle_timeout: '',
  pcq_rate: '',
  address_mask: '32',
  shared_users: 1
})

const toast = ref(null)

// Si se desactiva el pool, limpiar el valor para no enviarlo
watch(usePool, (enabled) => {
  if (!enabled) form.value.pppoe_pool = ''
})

// Función auxiliar para separar número y unidad (Ej: "50M" -> {val: 50, unit: 'M'})
const parseSpeed = (speedStr) => {
  if (!speedStr) return { val: '', unit: 'M' }
  const match = speedStr.match(/^(\d+)([KMGT])?$/i)
  if (match) {
    return { val: match[1], unit: match[2]?.toUpperCase() || 'M' }
  }
  return { val: speedStr, unit: 'M' }
}

// 1. CARGAR DATOS
const fetchPlanData = async () => {
  fetching.value = true
  try {
    // Asumimos que existe un endpoint api.plan.show(id)
    const { data } = await api.plan.show(planId)
    
    // Mapear respuesta al formulario
    form.value.name = data.name
    form.value.cost_product = data.cost_product
    form.value.commit = data.commit

    // Parsear velocidades
    const down = parseSpeed(data.speed_down)
    form.value.speed_down = down.val
    form.value.download_unit = down.unit

    const up = parseSpeed(data.speed_up)
    form.value.speed_up = up.val
    form.value.upload_unit = up.unit

    // Determinar tipo de plan basado en ID o campo type
    if(data.type_plan_id) {
        planType.value = typePlanMapInverse[data.type_plan_id] || 'queue'
    } else if (data.type) {
        planType.value = data.type
    }

    // Campos específicos (asumiendo que vienen en data o data.details)
    // Ajusta esto según tu respuesta de API real
    form.value.priority = data.priority || 8
    form.value.burst_download = data.burst_download || ''
    form.value.burst_upload = data.burst_upload || ''
    
    // PPPoE
    form.value.pppoe_pool = data.pppoe_pool || ''
    usePool.value = !!form.value.pppoe_pool
    form.value.local_address = data.local_address || ''
    
    // Hotspot
    form.value.shared_users = data.shared_users || 1
    form.value.session_timeout = data.session_timeout || ''
    form.value.idle_timeout = data.idle_timeout || ''
    
    // PCQ
    form.value.pcq_rate = data.pcq_rate || ''
    form.value.address_mask = data.address_mask || '32'

    // Si hay burst, mostrar sección avanzada
    if (form.value.burst_download || form.value.burst_upload) {
        showAdvancedSpeed.value = true
    }

  } catch (error) {
    toast.value?.error(
      'Error al cargar',
      'No se pudieron cargar los datos del plan. Intenta de nuevo.'
    )
    setTimeout(() => {
      router.back()
    }, 2000)
  } finally {
    fetching.value = false
  }
}

onMounted(() => {
    fetchPlanData()
})


// 2. ACTUALIZAR DATOS
const updatePlan = async () => {
  if (!form.value.name || !form.value.cost_product) {
    toast.value?.warning(
      'Datos incompletos',
      'Por favor ingresa el nombre y precio del plan'
    )
    return
  }

  loading.value = true

  try {
    // Mapeo de tipo a ID
    const typePlanMap = {
      queue: 1,
      pppoe: 4,  // Corregido: PPPoE es ID 4
      hotspot: 3,
      pcq: 2     // Corregido: PCQ es ID 2
    }

    const payload = {
      name: form.value.name,
      cost_product: Number(form.value.cost_product),
      commit: form.value.commit || null,
      speed_down: `${form.value.speed_down}${form.value.download_unit}`,
      speed_up: `${form.value.speed_up}${form.value.upload_unit}`,
      
      // 🔥 IMPORTANTE: Incluir type y type_plan_id para mantener el tipo de plan
      type: planType.value,
      type_plan_id: typePlanMap[planType.value],
      
      // Campos opcionales / específicos
      priority: form.value.priority ? parseInt(form.value.priority) : null,
      burst_download: form.value.burst_download || null,
      burst_upload: form.value.burst_upload || null,
      pppoe_pool: usePool.value ? (form.value.pppoe_pool || null) : null,
      local_address: form.value.local_address || null,
      shared_users: form.value.shared_users ? parseInt(form.value.shared_users) : null,
      session_timeout: form.value.session_timeout || null,
      idle_timeout: form.value.idle_timeout || null,
      pcq_rate: form.value.pcq_rate || null,
      address_mask: form.value.address_mask || null,
    }

    // Asumimos endpoint update(id, payload)
    await api.plan.update(planId, payload)

    toast.value?.success(
      'Plan actualizado',
      'El plan ha sido actualizado correctamente'
    )
    
    setTimeout(() => {
      router.push('/planes')
    }, 1500)

  } catch (error) {
    if (error.response?.data?.errors) {
      const errors = Object.values(error.response.data.errors).flat()
      toast.value?.error(
        'Errores de validación',
        errors.join(', ')
      )
    } else {
      toast.value?.error(
        'Error al actualizar',
        error.response?.data?.message || 'No se pudo actualizar el plan. Intenta de nuevo.'
      )
    }
  } finally {
    loading.value = false
  }
}
</script>
