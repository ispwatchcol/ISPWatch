<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">
      
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-indigo-100 dark:bg-indigo-900/30 rounded-xl">
              <v-icon name="md-settings" class="text-indigo-600 dark:text-indigo-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            Configuración
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">Gestiona las preferencias del sistema</p>
        </div>
        
        <button
          @click="saveAllSettings"
          :disabled="!hasChanges"
          class="bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 
                 text-white px-5 py-3 rounded-xl flex items-center justify-center gap-2 
                 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5
                 font-medium w-full sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <v-icon name="md-save" class="w-5 h-5 fill-current" />
          <span>Guardar Cambios</span>
        </button>
      </div>

      <!-- Settings Navigation Tabs -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md mb-6 overflow-hidden">
        <div class="border-b border-gray-200 dark:border-gray-700">
          <nav class="flex overflow-x-auto">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              @click="activeTab = tab.id"
              :class="[
                'flex items-center gap-2 px-6 py-4 text-sm font-medium transition-all whitespace-nowrap',
                activeTab === tab.id
                  ? 'border-b-2 border-indigo-600 text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/20'
                  : 'text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700/50'
              ]"
            >
              <v-icon :name="tab.icon" class="w-5 h-5" />
              {{ tab.label }}
            </button>
          </nav>
        </div>
      </div>

      <!-- Tab Content -->
      <div class="space-y-6">
        
        <!-- General Settings -->
        <div v-show="activeTab === 'general'" class="space-y-6">
          <SettingsSection
            title="Información General"
            description="Configuración básica de la aplicación"
            icon="md-info"
          >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="label">Nombre de la Empresa</label>
                <input
                  v-model="settings.company_name"
                  type="text"
                  placeholder="ISPWatch"
                  class="input"
                  :disabled="!isAdmin"
                  @input="hasChanges = true"
                />
                <p v-if="!isAdmin" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                  ℹ️ Solo los administradores pueden editar este campo
                </p>
              </div>
              <div>
                <label class="label">Email de Contacto</label>
                <input
                  v-model="settings.contact_email"
                  type="email"
                  placeholder="contacto@ispwatch.com"
                  class="input"
                  @input="hasChanges = true"
                />
              </div>
              <div>
                <label class="label">Teléfono</label>
                <input
                  v-model="settings.phone"
                  type="tel"
                  placeholder="+57 300 123 4567"
                  class="input"
                  @input="hasChanges = true"
                />
              </div>
              <div>
                <label class="label">Dirección</label>
                <input
                  v-model="settings.address"
                  type="text"
                  placeholder="Calle 123 #45-67"
                  class="input"
                  @input="hasChanges = true"
                />
              </div>
            </div>
          </SettingsSection>

          <SettingsSection
            title="Configuración Regional"
            description="Zona horaria y formato de fecha"
            icon="md-language"
          >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="label">Zona Horaria</label>
                <div class="relative">
                  <select v-model="settings.timezone" class="input appearance-none" @change="hasChanges = true">
                    <option value="America/Bogota">Colombia (UTC-5)</option>
                    <option value="America/Mexico_City">México (UTC-6)</option>
                    <option value="America/Lima">Perú (UTC-5)</option>
                    <option value="America/Santiago">Chile (UTC-3)</option>
                    <option value="America/Buenos_Aires">Argentina (UTC-3)</option>
                  </select>
                  <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                    <v-icon name="md-keyboardarrowdown" />
                  </div>
                </div>
              </div>
              <div>
                <label class="label">Moneda</label>
                <div class="relative">
                  <select v-model="settings.currency" class="input appearance-none" @change="hasChanges = true">
                    <option value="COP">Peso Colombiano (COP)</option>
                    <option value="USD">Dólar (USD)</option>
                    <option value="MXN">Peso Mexicano (MXN)</option>
                    <option value="EUR">Euro (EUR)</option>
                  </select>
                  <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                    <v-icon name="md-keyboardarrowdown" />
                  </div>
                </div>
              </div>
            </div>
          </SettingsSection>
        </div>

        <!-- Appearance Settings -->
        <div v-show="activeTab === 'appearance'" class="space-y-6">
          <SettingsSection
            title="Tema de la Aplicación"
            description="Personaliza la apariencia visual"
            icon="md-palette"
          >
            <div class="space-y-4">
              <div>
                <label class="label mb-4">Modo de Color</label>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <button
                    @click="setTheme('light')"
                    :class="[
                      'p-4 rounded-xl border-2 transition-all',
                      currentTheme === 'light'
                        ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                        : 'border-gray-200 dark:border-gray-700 hover:border-indigo-300'
                    ]"
                  >
                    <v-icon name="md-lightmode" class="w-8 h-8 text-yellow-500 mx-auto mb-2" />
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Modo Claro</p>
                  </button>
                  <button
                    @click="setTheme('dark')"
                    :class="[
                      'p-4 rounded-xl border-2 transition-all',
                      currentTheme === 'dark'
                        ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                        : 'border-gray-200 dark:border-gray-700 hover:border-indigo-300'
                    ]"
                  >
                    <v-icon name="md-nightlight" class="w-8 h-8 text-indigo-500 mx-auto mb-2" />
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Modo Oscuro</p>
                  </button>
                  <button
                    @click="setTheme('system')"
                    :class="[
                      'p-4 rounded-xl border-2 transition-all',
                      currentTheme === 'system'
                        ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                        : 'border-gray-200 dark:border-gray-700 hover:border-indigo-300'
                    ]"
                  >
                    <v-icon name="md-computer" class="w-8 h-8 text-gray-500 mx-auto mb-2" />
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Sistema</p>
                  </button>
                </div>
              </div>
            </div>
          </SettingsSection>

          <SettingsSection
            title="Personalización"
            description="Ajusta la interfaz a tu gusto"
            icon="md-brush"
          >
            <div class="space-y-4">
              <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <div class="flex items-center gap-3">
                  <v-icon name="md-textfields" class="w-5 h-5 text-gray-600 dark:text-gray-400" />
                  <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Densidad Compacta</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Reduce el espaciado de elementos</p>
                  </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input
                    v-model="settings.compact_mode"
                    type="checkbox"
                    class="sr-only peer"
                    @change="hasChanges = true"
                  />
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"></div>
                </label>
              </div>

              <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <div class="flex items-center gap-3">
                  <v-icon name="md-animation" class="w-5 h-5 text-gray-600 dark:text-gray-400" />
                  <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Animaciones</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Habilitar transiciones y efectos</p>
                  </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input
                    v-model="settings.animations_enabled"
                    type="checkbox"
                    class="sr-only peer"
                    @change="hasChanges = true"
                  />
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"></div>
                </label>
              </div>
            </div>
          </SettingsSection>
        </div>

        <!-- Notifications Settings -->
        <div v-show="activeTab === 'notifications'" class="space-y-6">
          <SettingsSection
            title="Notificaciones del Sistema"
            description="Configura cómo y cuándo recibir alertas"
            icon="md-notifications"
          >
            <div class="space-y-4">
              <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <div class="flex items-center gap-3">
                  <v-icon name="md-email" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                  <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Notificaciones por Email</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Recibir alertas importantes por correo</p>
                  </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input
                    v-model="settings.email_notifications"
                    type="checkbox"
                    class="sr-only peer"
                    @change="hasChanges = true"
                  />
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"></div>
                </label>
              </div>

              <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <div class="flex items-center gap-3">
                  <v-icon name="bi-bell" class="w-5 h-5 text-yellow-600 dark:text-yellow-400" />
                  <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Notificaciones Push</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Alertas en tiempo real en el navegador</p>
                  </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input
                    v-model="settings.push_notifications"
                    type="checkbox"
                    class="sr-only peer"
                    @change="hasChanges = true"
                  />
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"></div>
                </label>
              </div>

              <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <div class="flex items-center gap-3">
                  <v-icon name="md-warning" class="w-5 h-5 text-red-600 dark:text-red-400" />
                  <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Alertas de Facturas Vencidas</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Notificar cuando una factura está vencida</p>
                  </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input
                    v-model="settings.overdue_alerts"
                    type="checkbox"
                    class="sr-only peer"
                    @change="hasChanges = true"
                  />
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"></div>
                </label>
              </div>

              <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <div class="flex items-center gap-3">
                  <v-icon name="bi-router" class="w-5 h-5 text-green-600 dark:text-green-400" />
                  <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Alertas de Routers Offline</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Notificar cuando un router se desconecta</p>
                  </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input
                    v-model="settings.router_offline_alerts"
                    type="checkbox"
                    class="sr-only peer"
                    @change="hasChanges = true"
                  />
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"></div>
                </label>
              </div>
            </div>
          </SettingsSection>
        </div>

        <!-- System Settings -->
        <div v-show="activeTab === 'system'" class="space-y-6">
          <SettingsSection
            title="Información del Sistema"
            description="Detalles de la aplicación y base de datos"
            icon="md-info"
          >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Versión de la Aplicación</p>
                <p class="text-lg font-bold text-gray-800 dark:text-gray-200">v1.0.0</p>
              </div>
              <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Base de Datos</p>
                <p class="text-lg font-bold text-gray-800 dark:text-gray-200">Supabase</p>
              </div>
              <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Última Actualización</p>
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ new Date().toLocaleDateString('es-CO') }}</p>
              </div>
              <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Estado del Sistema</p>
                <p class="text-sm font-medium text-green-600 dark:text-green-400 flex items-center gap-2">
                  <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                  Operativo
                </p>
              </div>
            </div>
          </SettingsSection>

          <SettingsSection
            title="Mantenimiento"
            description="Herramientas de mantenimiento del sistema"
            icon="md-build"
          >
            <div class="space-y-3">
              <button
                @click="clearCache"
                class="w-full p-4 bg-gray-50 dark:bg-gray-700/50 hover:bg-gray-100 dark:hover:bg-gray-700 
                       rounded-lg transition-all text-left flex items-center justify-between group"
              >
                <div class="flex items-center gap-3">
                  <v-icon name="md-delete" class="w-5 h-5 text-orange-600 dark:text-orange-400" />
                  <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Limpiar Caché</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Eliminar archivos temporales</p>
                  </div>
                </div>
                <v-icon name="md-chevronright" class="w-5 h-5 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300" />
              </button>

              <button
                @click="exportData"
                class="w-full p-4 bg-gray-50 dark:bg-gray-700/50 hover:bg-gray-100 dark:hover:bg-gray-700 
                       rounded-lg transition-all text-left flex items-center justify-between group"
              >
                <div class="flex items-center gap-3">
                  <v-icon name="md-download" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                  <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Exportar Datos</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Descargar backup de la base de datos</p>
                  </div>
                </div>
                <v-icon name="md-chevronright" class="w-5 h-5 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300" />
              </button>
            </div>
          </SettingsSection>
        </div>

      </div>

    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import SettingsSection from '@/components/SettingsSection.vue'
import axios from 'axios'

// State
const activeTab = ref('general')
const hasChanges = ref(false)
const currentTheme = ref('system')
const userData = ref(null)
const isAdmin = computed(() => userData.value?.role_name?.toLowerCase() === 'administrador')
const loading = ref(false)

const tabs = [
  { id: 'general', label: 'General', icon: 'md-settings' },
  { id: 'appearance', label: 'Apariencia', icon: 'md-palette' },
  { id: 'notifications', label: 'Notificaciones', icon: 'md-notifications' },
  { id: 'system', label: 'Sistema', icon: 'md-computer' }
]

const settings = ref({
  // General
  company_name: '',
  contact_email: 'contacto@ispwatch.com',
  phone: '+57 300 123 4567',
  address: 'Calle 123 #45-67',
  timezone: 'America/Bogota',
  currency: 'COP',
  
  // Appearance
  theme: 'system',
  compact_mode: false,
  animations_enabled: true,
  
  // Notifications
  email_notifications: true,
  push_notifications: true,
  overdue_alerts: true,
  router_offline_alerts: true
})

// Methods
const setTheme = (theme) => {
  currentTheme.value = theme
  hasChanges.value = true
  
  // Apply theme immediately
  if (theme === 'dark') {
    document.documentElement.classList.add('dark')
  } else if (theme === 'light') {
    document.documentElement.classList.remove('dark')
  } else {
    // System preference
    if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
      document.documentElement.classList.add('dark')
    } else {
      document.documentElement.classList.remove('dark')
    }
  }
  
  localStorage.setItem('theme', theme)
}

const saveAllSettings = async () => {
  loading.value = true
  
  try {
    // Save tenant info to database if user is admin
    if (isAdmin.value && userData.value?.tenant_id) {
      const tenantResponse = await axios.put(
        `http://localhost:8000/api/tenants/${userData.value.tenant_id}`,
        {
          name: settings.value.company_name,
          user_id: userData.value.id
        }
      )
      
      if (!tenantResponse.data.success) {
        throw new Error(tenantResponse.data.message || 'Error al guardar tenant')
      }
    }
    
    // Save to localStorage
    localStorage.setItem('settings', JSON.stringify(settings.value))
    localStorage.setItem('theme', currentTheme.value)
    
    hasChanges.value = false
    alert('✅ Configuración guardada correctamente')
  } catch (error) {
    console.error('Error saving settings:', error)
    alert('❌ Error al guardar la configuración: ' + (error.response?.data?.message || error.message))
  } finally {
    loading.value = false
  }
}

const clearCache = () => {
  if (confirm('¿Deseas limpiar el caché de la aplicación?')) {
    localStorage.removeItem('cache')
    alert('✅ Caché limpiado correctamente')
  }
}

const exportData = () => {
  alert('📦 Exportando datos...\nEsta función estará disponible próximamente')
}

// Load tenant data from API
const loadTenantData = async () => {
  try {
    if (!userData.value?.tenant_id) {
      console.warn('No tenant_id found in userData')
      return
    }
    
    const response = await axios.get(`http://localhost:8000/api/tenants/${userData.value.tenant_id}`)
    
    if (response.data.success && response.data.data) {
      settings.value.company_name = response.data.data.name || ''
    }
  } catch (error) {
    console.error('Error loading tenant data:', error)
  }
}

// Lifecycle
onMounted(async () => {
  // Load user data from localStorage
  const localUserData = localStorage.getItem('userData') || sessionStorage.getItem('userData')
  if (localUserData) {
    try {
      userData.value = JSON.parse(localUserData)
    } catch (e) {
      console.error('Error parsing user data:', e)
    }
  }
  
  // Load tenant data from API
  await loadTenantData()
  
  // Load saved settings from localStorage
  const savedSettings = localStorage.getItem('settings')
  if (savedSettings) {
    try {
      const parsed = JSON.parse(savedSettings)
      // Only override non-tenant settings
      settings.value = { 
        ...settings.value, 
        ...parsed,
        // Keep company_name from tenant data if loaded
        company_name: settings.value.company_name || parsed.company_name
      }
    } catch (e) {
      console.error('Error loading settings:', e)
    }
  }
  
  // Load theme preference
  const savedTheme = localStorage.getItem('theme') || 'system'
  currentTheme.value = savedTheme
})
</script>

<style scoped>
.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2;
}
.input {
  @apply w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
         focus:ring-2 focus:ring-indigo-500 focus:border-transparent
         disabled:opacity-50 disabled:cursor-not-allowed transition-all
         placeholder:text-gray-400 dark:placeholder:text-gray-500;
}
</style>
