<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100">
    <!-- Notification Toast -->
    <NotificationToast ref="toast" />

    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="bi-person-plus" class="text-blue-600 w-7 h-7" />
            Registrar nuevo usuario del Staff
          </h1>
          <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
            Crea un nuevo usuario y asigna permisos de acceso al sistema.
          </p>
        </div>

        <div class="mb-6">
          <button
            @click="$router.push('/staff')"
            class="flex items-center gap-2 px-4 py-2 bg-gray-200 hover:bg-gray-300 
                  dark:bg-gray-800 dark:hover:bg-gray-700 rounded-lg text-gray-800 
                  dark:text-gray-100 transition-all"
          >
            <v-icon name="fa-arrow-left" class="w-4 h-4" />
            Volver a Staff
          </button>
        </div>
      </div>

      <!-- Tarjeta principal -->
      <div class="bg-white dark:bg-gray-800 shadow rounded-2xl p-8">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
          <!-- Nombre de usuario -->
          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Nombre de usuario</label>
            <div class="flex border rounded-lg overflow-hidden dark:border-gray-600">
              <input
                v-model="newMember.username"
                type="text"
                class="flex-1 p-2 bg-white dark:bg-gray-700 focus:outline-none"
                placeholder="Usuario"
              />
              <span class="px-3 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 flex items-center text-sm border-l dark:border-gray-600">
                {{ tenant }}
              </span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Contraseña</label>
            <input
              v-model="newMember.password"
              type="password"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="••••••••"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Correo electrónico</label>
            <input
              v-model="newMember.email"
              type="email"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="user@example.com"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Teléfono celular</label>
            <input
              v-model="newMember.phone"
              type="tel"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="Número de celular"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Nombre</label>
            <input
              v-model="newMember.name"
              type="text"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="Nombre"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Apellido</label>
            <input
              v-model="newMember.lastname"
              type="text"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="Apellido"
            />
          </div>


          <div>
            <label class="block text-sm font-medium mb-2 text-gray-600 dark:text-gray-300 flex items-center gap-2">
              <v-icon name="md-adminpanelsettings-round" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
              Tipo de usuario
            </label>
            <div class="relative">
              <select 
                v-model="newMember.role_id" 
                class="w-full pl-3 pr-10 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg 
                       bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent
                       transition-all duration-200 cursor-pointer appearance-none relative
                       hover:border-blue-400 dark:hover:border-blue-500"
              >
                <option disabled value="">Selecciona un rol</option>
                <option v-for="role in filteredRoles" :key="role.id" :value="role.id">
                  {{ role.name }}
                </option>
              </select>
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none z-50 transition-transform duration-200 select-arrow">
                <v-icon name="md-keyboardarrowdown" class="w-5 h-5 text-gray-400 dark:text-gray-500" />
              </div>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-2 text-gray-600 dark:text-gray-300 flex items-center gap-2">
              <v-icon name="md-public-round" class="w-4 h-4 text-green-600 dark:text-green-400" />
              Operar todas las zonas
            </label>
            <div class="relative">
              <select 
                v-model="newMember.allZones" 
                class="w-full pl-3 pr-10 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg 
                       bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100
                       focus:ring-2 focus:ring-green-500 focus:border-transparent
                       transition-all duration-200 cursor-pointer appearance-none relative
                       hover:border-green-400 dark:hover:border-green-500"
              >
                <option>Sí</option>
                <option>No</option>
              </select>
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none z-50 transition-transform duration-200 select-arrow">
                <v-icon name="md-keyboardarrowdown" class="w-5 h-5 text-gray-400 dark:text-gray-500" />
              </div>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-2 text-gray-600 dark:text-gray-300 flex items-center gap-2">
              <v-icon name="md-security-round" class="w-4 h-4 text-purple-600 dark:text-purple-400" />
              Autenticación de dos pasos
            </label>
            <div class="relative">
              <select 
                v-model="newMember.twoFA" 
                class="w-full pl-3 pr-10 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg 
                       bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 focus:border-transparent
                       transition-all duration-200 cursor-pointer appearance-none relative
                       hover:border-purple-400 dark:hover:border-purple-500"
              >
                <option>No</option>
                <option>Sí</option>
              </select>
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none z-50 transition-transform duration-200 select-arrow">
                <v-icon name="md-keyboardarrowdown" class="w-5 h-5 text-gray-400 dark:text-gray-500" />
              </div>
            </div>
          </div>
        </div>
        <!-- Permisos -->
        <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-gray-100">Permisos</h2>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
          <div v-for="group in permissions" :key="group.title" class="border rounded-xl p-4 bg-gray-50 dark:bg-gray-700/50">
            <h3 class="font-bold text-gray-700 dark:text-gray-200 mb-2">{{ group.title }}</h3>
            <div class="space-y-1 text-sm">
              <label v-for="(perm, i) in group.items" :key="i" class="flex items-center gap-2">
                <input type="checkbox" v-model="perm.checked" class="accent-blue-600" />
                <span>{{ perm.label }}</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Botón -->
        <div class="mt-8 text-right">
          <button
            @click="saveUser"
            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg shadow transition-all"
          >
            {{ saving ? 'Guardando..' : 'Guardar Usuario'}}
          </button>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api.js'
import NotificationToast from '@/components/NotificationToast.vue'

const router = useRouter()

// Modelo del formulario
const newMember = ref({
  username: '',
  password: '',
  email: '',
  phone: '',
  name: '',
  lastname: '',
  role_id: '',
  allZones: 'Sí',
  twoFA: 'No',
})

// Estados reactivos
const tenant = ref('')
const tenantId = ref('')
const roles = ref([])
const saving = ref(false)
const toast = ref(null)

// 👇 LÓGICA UNIFICADA Y SEGURA (Igual que en Staff List)
onMounted(async () => {
  console.log("🔄 Iniciando StaffNew...")

  // 1. Leer datos del storage (Local o Session)
  const userData =
    JSON.parse(localStorage.getItem("userData")) ||
    JSON.parse(sessionStorage.getItem("userData"))

  // 2. Extraer tenant_id
  tenantId.value = userData?.tenant_id

  console.log("🔍 Tenant ID recuperado:", tenantId.value)

  // 3. Lógica de carga
  if (tenantId.value) {
    await loadTenantDomain()
  } else {
    console.warn("⚠️ No se encontró tenant_id en el Storage. Usuario posiblemente desconectado.")
    tenant.value = '@sin-tenant'
  }

  // 4. Cargar roles siempre
  await loadRoles()
})

// Cargar dominio del tenant
const loadTenantDomain = async () => {
  // PLAN A: Extraer del email_tenant del usuario logueado en localStorage
  // Es el método más confiable ya que el admin siempre tiene el dominio correcto
  const userData = JSON.parse(localStorage.getItem('userData') || sessionStorage.getItem('userData') || '{}')

  if (userData?.email_tenant) {
    const parts = userData.email_tenant.split('@')
    if (parts.length > 1 && parts[1]) {
      tenant.value = `@${parts[1]}`
      console.log('✅ Dominio extraido de email_tenant del usuario:', tenant.value)
      return
    }
  }

  // PLAN B: API del tenant (fallback - el campo domain puede tener timestamp)
  try {
    console.log('🔍 Buscando dominio via API para Tenant ID:', tenantId.value)
    const response = await api.tenant.getOne(tenantId.value)
    console.log('📦 Respuesta API Tenant:', response.data)

    if (response.data.success) {
      const domain = response.data.data?.domain || response.data.domain
      if (domain) {
        // Limpiar el timestamp del final del dominio si existe (ej: nombre-empresa-1778274279)
        const cleanDomain = domain.replace(/-\d{9,}$/, '')
        tenant.value = `@${cleanDomain}`
        console.log('✅ Dominio cargado desde API (limpiado):', tenant.value)
        return
      }
    }
  } catch (error) {
    console.error('⚠️ Error API tenant.getOne:', error)
  }

  // PLAN C: Si todo falla
  console.error('❌ No se pudo obtener el dominio de ninguna forma.')
  tenant.value = '@sin-tenant'
}


// Lista de permisos (Estática para la UI)
const permissions = ref([
  {
    title: 'Clientes',
    items: [
      { label: 'Editar Descuento', checked: true },
      { label: 'Activar y Desactivar Clientes', checked: false },
      { label: 'Eliminar Instalaciones', checked: false },
      { label: 'Editar Saldo Pendiente', checked: true },
      { label: 'Lista de Clientes', checked: true },
      { label: 'Editar Servicio Internet', checked: false },
      { label: 'Tráfico Clientes', checked: true },
      { label: 'Agregar Clientes', checked: true },
    ],
  },
  {
    title: 'Facturas',
    items: [
      { label: 'Dashboard / Estadísticas', checked: true },
      { label: 'Agregar Gasto', checked: true },
      { label: 'Buscar Facturas', checked: false },
      { label: 'Editar Total a Pagar', checked: false },
      { label: 'Registrar Pagos', checked: true },
      { label: 'Eliminar Factura', checked: true },
      { label: 'Promesas de Pago', checked: false },
    ],
  },
  {
    title: 'Contabilidad',
    items: [
      { label: 'Editar Gasto', checked: true },
      { label: 'Registrar Pago Mayor 3 Días', checked: false },
      { label: 'Eliminar Transferencia', checked: false },
      { label: 'Registrar Pagos', checked: true },
      { label: 'Editar Fecha de Pago', checked: false },
      { label: 'Lista de Gastos', checked: true },
      { label: 'Lista de Facturas', checked: true },
      { label: 'Agregar Transferencia', checked: true },
    ],
  },
])

// Cargar lista de roles disponibles
const loadRoles = async () => {
  try {
    console.log('📥 Cargando roles...')
    const response = await api.roles.getAll()
    const data = response.data

    if (data?.success && Array.isArray(data.data)) {
      roles.value = data.data
    } else if (data?.data && Array.isArray(data.data)) {
      roles.value = data.data
    } else if (Array.isArray(data)) {
      roles.value = data
    } else {
      console.warn('⚠️ Estructura de respuesta inesperada:', data)
      roles.value = []
    }

    if (roles.value.length === 0) {
      console.warn('⚠️ API no devolvió roles, usando valores predeterminados')
      roles.value = [
        { id: 1, name: 'Administrador' },
        { id: 2, name: 'Staff' },
      ]
    }

    console.log('✅ Roles cargados:', roles.value.length, roles.value)
  } catch (error) {
    console.error('❌ Error al cargar roles:', error?.response?.status, error?.message)
    // Fallback con roles por defecto para no bloquear la UI
    roles.value = [
      { id: 1, name: 'Administrador' },
      { id: 2, name: 'Staff' },
    ]
    console.log('🔄 Usando roles predeterminados como fallback')
  }
}

// Computed property to filter out "Cliente" role from staff selection
const filteredRoles = computed(() => {
  return roles.value.filter(role => role.name !== 'Cliente')
})

// Guardar nuevo usuario
const saveUser = async () => {
  saving.value = true
  try {
    if (!tenantId.value) {
      toast.value?.error(
        'Sesión inválida',
        'No se encontró información del tenant. Por favor, cierra e inicia sesión nuevamente.'
      )
      return
    }

    // Validaciones básicas
    if (!newMember.value.name) {
      toast.value?.warning(
        'Datos incompletos',
        'Por favor ingrese un nombre'
      )
      return
    }

    if (!newMember.value.email || !newMember.value.password) {
      toast.value?.warning(
        'Datos incompletos',
        'Por favor ingrese un correo electrónico y una contraseña'
      )
      return
    } 

    if (!newMember.value.role_id) {
      toast.value?.warning(
        'Datos incompletos',
        'Por favor seleccione un rol'
      )
      return
    }

    // Preparar objeto para enviar
    const userInsert = {
      name: `${newMember.value.name} ${newMember.value.lastname}`.trim(), // Combined name for DB
      user_name: newMember.value.username,
      user_lastname: newMember.value.lastname,
      password: newMember.value.password,
      tenant_id: tenantId.value,
      role_id: newMember.value.role_id,
      tel: newMember.value.phone,
      email_tenant: `${newMember.value.username}${tenant.value}`, // Concatenar usuario + dominio
      email: newMember.value.email,
    }

    console.log("📤 Enviando usuario:", userInsert)

    const response = await api.staff.create(userInsert)

    if (response.data.success) {
      toast.value?.success(
        'Usuario registrado',
        'El usuario ha sido registrado correctamente'
      )
      setTimeout(() => {
        router.push('/staff') // Redirigir a la lista
      }, 1500)
    }
  } catch (error) {
    console.error('⚠️ Error al registrar usuario:', error.response?.data || error)

    // Mostrar mensaje de error específico si existe
    if (error.response?.data?.errors) {
      const errors = Object.values(error.response.data.errors).flat()
      toast.value?.error(
        'Errores de validación',
        error.response?.data?.message || errors.join(', ')
      )
    } else {
      toast.value?.error(
        'Error al registrar',
        error.response?.data?.message || error.message
      )
    }
  } finally {
    saving.value = false
  }
}
</script>
