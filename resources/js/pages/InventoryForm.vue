<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">
      
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
              <v-icon name="bi-box-seam" class="text-purple-600 dark:text-purple-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            {{ isEdit ? 'Editar Dispositivo' : 'Nuevo Dispositivo' }}
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
            {{ isEdit ? 'Actualiza la información del dispositivo' : 'Agrega un nuevo dispositivo al inventario' }}
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

      <!-- Form Card -->
      <div class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden max-w-5xl mx-auto">
        
        <!-- Progress Indicator -->
        <div class="bg-gradient-to-r from-purple-600 to-purple-700 p-6 text-white">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium opacity-90">Formulario de Dispositivo</span>
            <span class="text-xs opacity-75">* Campos obligatorios</span>
          </div>
          <div class="h-1 bg-purple-500/30 rounded-full overflow-hidden">
            <div class="h-full bg-white rounded-full transition-all" :style="`width: ${progress}%`"></div>
          </div>
        </div>

        <form @submit.prevent="handleSubmit" class="p-6 md:p-8">
          
          <!-- Section: Información del Producto -->
          <div class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
              <div class="w-8 h-8 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                <span class="text-purple-600 dark:text-purple-400 font-bold text-sm">1</span>
              </div>
              Información del Producto
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-1 gap-6">
              <!-- Stock Selection -->
              <div>
                <label class="label">
                  <v-icon name="md-inventory-round" class="w-4 h-4 mr-1 inline" />
                  Stock / Modelo *
                </label>
                <div class="relative">
                  <select
                    v-model="form.stock_id"
                    required
                    class="input appearance-none"
                    :disabled="loading"
                  >
                    <option value="">Selecciona un stock...</option>
                    <option
                      v-for="stock in stocks"
                      :key="stock.id"
                      :value="stock.id"
                    >
                      {{ stock.brand || 'Sin marca' }} - {{ stock.model || 'Sin modelo' }} (Precio: {{ formatCurrency(stock.price) }})
                    </option>
                  </select>
                  <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                    <v-icon name="md-keyboardarrowdown" />
                  </div>
                </div>
                <p class="hint">Selecciona el producto del stock</p>
              </div>
            </div>
          </div>

          <!-- Section: Identificación -->
          <div class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
              <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                <span class="text-blue-600 dark:text-blue-400 font-bold text-sm">2</span>
              </div>
              Identificación del Dispositivo
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Serial -->
              <div>
                <label class="label">
                  <v-icon name="bi-upc" class="w-4 h-4 mr-1 inline" />
                  Número de Serie
                </label>
                <input
                  v-model="form.serial"
                  type="text"
                  placeholder="ABC123456789"
                  class="input font-mono"
                  :disabled="loading"
                />
                <p class="hint">Número de serie único del dispositivo</p>
              </div>

              <!-- MAC Address -->
              <div>
                <label class="label">
                  <v-icon name="md-wifi" class="w-4 h-4 mr-1 inline" />
                  Dirección MAC
                </label>
                <input
                  v-model="form.mac"
                  type="text"
                  placeholder="00:11:22:33:44:55"
                  class="input font-mono"
                  :disabled="loading"
                  @input="formatMacAddress"
                />
                <p class="hint">Dirección MAC del dispositivo de red</p>
              </div>
            </div>
          </div>

          <!-- Section: Ubicación -->
          <div class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
              <div class="w-8 h-8 bg-orange-100 dark:bg-orange-900/30 rounded-lg flex items-center justify-center">
                <span class="text-orange-600 dark:text-orange-400 font-bold text-sm">3</span>
              </div>
              Ubicación y Proveedor
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
              <!-- Provider -->
              <div>
                <label class="label">
                  <v-icon name="bi-building" class="w-4 h-4 mr-1 inline" />
                  Proveedor
                </label>
                <div class="relative">
                  <select
                    v-model="form.provider_id"
                    class="input appearance-none"
                    :disabled="loading"
                  >
                    <option value="">Sin proveedor</option>
                    <option
                      v-for="provider in providers"
                      :key="provider.id"
                      :value="provider.id"
                    >
                      {{ provider.name }}
                    </option>
                  </select>
                  <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                    <v-icon name="md-keyboardarrowdown" />
                  </div>
                </div>
                <p class="hint">Proveedor del dispositivo</p>
              </div>

              <!-- Branch -->
              <div>
                <label class="label">
                  <v-icon name="md-storemalldir" class="w-4 h-4 mr-1 inline" />
                  Sucursal
                </label>
                <div class="relative">
                  <select
                    v-model="form.branch_id"
                    class="input appearance-none"
                    :disabled="loading"
                  >
                    <option value="">Sin sucursal</option>
                    <option
                      v-for="branch in branches"
                      :key="branch.id"
                      :value="branch.id"
                    >
                      {{ branch.name }}
                    </option>
                  </select>
                  <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                    <v-icon name="md-keyboardarrowdown" />
                  </div>
                </div>
                <p class="hint">Sucursal donde está ubicado</p>
              </div>

              <!-- User Assignment -->
              <div>
                <label class="label">
                  <v-icon name="pr-user" class="w-4 h-4 mr-1 inline" />
                  Asignado a
                </label>
                <div class="relative">
                  <select
                    v-model="form.user_id"
                    class="input appearance-none"
                    :disabled="loading"
                  >
                    <option value="">Sin asignar</option>
                    <option
                      v-for="user in users"
                      :key="user.id"
                      :value="user.id"
                    >
                      {{ user.name }}
                    </option>
                  </select>
                  <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                    <v-icon name="md-keyboardarrowdown" />
                  </div>
                </div>
                <p class="hint">Usuario responsable del dispositivo</p>
              </div>
            </div>
          </div>

          <!-- Form Summary Card -->
          <div v-if="form.stock_id && selectedStock" 
               class="bg-gradient-to-r from-purple-50 to-indigo-50 dark:from-purple-900/20 dark:to-indigo-900/20 
                      rounded-xl p-6 mb-8 border border-purple-200 dark:border-purple-800">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
              <v-icon name="bi-info-circle" class="w-4 h-4" />
              Resumen del Dispositivo
            </h4>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <p class="text-xs text-gray-600 dark:text-gray-400">Producto</p>
                <p class="text-sm font-semibold text-gray-900 dark:text-white mt-1">
                  {{ selectedStock.brand }} {{ selectedStock.model }}
                </p>
              </div>
              <div>
                <p class="text-xs text-gray-600 dark:text-gray-400">Precio</p>
                <p class="text-base font-bold text-purple-600 dark:text-purple-400 mt-1">
                  {{ formatCurrency(selectedStock.price) }}
                </p>
              </div>
              <div>
                <p class="text-xs text-gray-600 dark:text-gray-400">Serial</p>
                <p class="text-sm font-mono text-gray-900 dark:text-white mt-1">
                  {{ form.serial || 'Sin definir' }}
                </p>
              </div>
            </div>
          </div>

          <!-- Submit Buttons -->
          <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t border-gray-200 dark:border-gray-700">
            <button
              type="button"
              @click="goBack"
              class="flex-1 py-3 px-6 border-2 border-gray-300 dark:border-gray-600 rounded-xl
                     text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700
                     transition-all font-medium flex items-center justify-center gap-2"
              :disabled="loading"
            >
              <v-icon name="md-close" class="w-5 h-5" />
              Cancelar
            </button>
            <button
              type="submit"
              class="flex-1 py-3 px-6 bg-gradient-to-r from-purple-600 to-purple-700 
                     hover:from-purple-700 hover:to-purple-800 text-white rounded-xl
                     transition-all font-medium shadow-lg hover:shadow-xl
                     transform hover:-translate-y-0.5
                     disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none
                     flex items-center justify-center gap-2"
              :disabled="loading"
            >
              <v-icon v-if="loading" name="bi-arrow-clockwise" animation="spin" class="w-5 h-5" />
              <v-icon v-else name="md-check" class="w-5 h-5" />
              {{ loading ? 'Guardando...' : (isEdit ? 'Actualizar Dispositivo' : 'Crear Dispositivo') }}
            </button>
          </div>

        </form>
      </div>

    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { supabase } from '@/supabase.js'

const router = useRouter()
const route = useRoute()
const deviceId = route.params.id
const isEdit = !!deviceId

// State
const loading = ref(false)
const stocks = ref([])
const providers = ref([])
const branches = ref([])
const users = ref([])

// Form
const form = ref({
  stock_id: null,
  provider_id: null,
  user_id: null,
  branch_id: null,
  serial: '',
  mac: ''
})

// Computed
const selectedStock = computed(() => {
  return stocks.value.find(s => s.id === form.value.stock_id)
})

const progress = computed(() => {
  let filled = 0
  if (form.value.stock_id) filled += 25
  if (form.value.serial) filled += 25
  if (form.value.mac) filled += 25
  if (form.value.provider_id || form.value.branch_id || form.value.user_id) filled += 25
  return filled
})

// Methods
const loadStocks = async () => {
  try {
    const { data, error } = await supabase
      .from('inventory_stock')
      .select('id, brand, model, price')
      .order('brand')

    if (error) throw error
    stocks.value = data || []
  } catch (error) {
    console.error('Error loading stocks:', error)
    alert('Error al cargar el stock')
  }
}

const loadProviders = async () => {
  try {
    const { data, error } = await supabase
      .from('inventory_provider')
      .select('id, name')
      .order('name')

    if (error) throw error
    providers.value = data || []
  } catch (error) {
    console.error('Error loading providers:', error)
  }
}

const loadBranches = async () => {
  try {
    const { data, error } = await supabase
      .from('inventory_branch')
      .select('id, name')
      .order('name')

    if (error) throw error
    branches.value = data || []
  } catch (error) {
    console.error('Error loading branches:', error)
  }
}

const loadUsers = async () => {
  try {
    const { data, error } = await supabase
      .from('users')
      .select('id, name')
      .order('name')

    if (error) throw error
    users.value = data || []
  } catch (error) {
    console.error('Error loading users:', error)
  }
}

const loadDevice = async () => {
  if (!isEdit) return

  loading.value = true
  try {
    const { data, error } = await supabase
      .from('inventory_device')
      .select('*')
      .eq('id', deviceId)
      .single()

    if (error) throw error

    form.value = {
      stock_id: data.stock_id || null,
      provider_id: data.provider_id || null,
      user_id: data.user_id || null,
      branch_id: data.branch_id || null,
      serial: data.serial || '',
      mac: data.mac || ''
    }
  } catch (error) {
    console.error('Error loading device:', error)
    alert('Error al cargar el dispositivo')
    router.push('/inventory')
  } finally {
    loading.value = false
  }
}

const formatMacAddress = (event) => {
  let value = event.target.value.replace(/[^0-9A-Fa-f]/g, '')
  
  if (value.length > 12) {
    value = value.substring(0, 12)
  }
  
  const formatted = value.match(/.{1,2}/g)?.join(':') || value
  form.value.mac = formatted.toUpperCase()
}

const handleSubmit = async () => {
  loading.value = true
  try {
    const payload = {
      stock_id: form.value.stock_id || null,
      provider_id: form.value.provider_id || null,
      user_id: form.value.user_id || null,
      branch_id: form.value.branch_id || null,
      serial: form.value.serial || null,
      mac: form.value.mac || null
    }

    if (isEdit) {
      // Update
      const { error } = await supabase
        .from('inventory_device')
        .update(payload)
        .eq('id', deviceId)

      if (error) throw error
      alert('✅ Dispositivo actualizado correctamente')
    } else {
      // Create
      const { error } = await supabase
        .from('inventory_device')
        .insert(payload)

      if (error) throw error
      alert('✅ Dispositivo creado correctamente')
    }

    router.push('/inventory')
  } catch (error) {
    console.error('Error saving device:', error)
    alert('❌ Error al guardar el dispositivo: ' + error.message)
  } finally {
    loading.value = false
  }
}

const goBack = () => {
  router.push('/inventory')
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0
  }).format(value || 0)
}

// Lifecycle
onMounted(async () => {
  await Promise.all([
    loadStocks(),
    loadProviders(),
    loadBranches(),
    loadUsers()
  ])
  await loadDevice()
})
</script>

<style scoped>
.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2;
}
.input {
  @apply w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
         focus:ring-2 focus:ring-purple-500 focus:border-transparent
         disabled:opacity-50 disabled:cursor-not-allowed transition-all
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
