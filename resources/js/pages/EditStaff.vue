<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100">
    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
      <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="fa-user-edit" class="text-blue-800 text-3xl" style="transform: scale(1.5);" />
            Editar usuario del Staff
            </h1>
          <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
            Modifica la información del usuario y sus permisos.
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
      <!-- Loading state -->
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500 dark:text-gray-400">Cargando información del usuario</p>
      </div>

      <!-- Tarjeta principal -->
      <div class="bg-white dark:bg-gray-800 shadow rounded-2xl p-8">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
          <!-- Nombre de usuario -->
          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Nombre de usuario</label>
            <div class="flex border rounded-lg overflow-hidden dark:border-gray-600">
              <input
                v-model="editMember.username"
                type="text"
                class="flex-1 p-2 bg-white dark:bg-gray-700 focus:outline-none"
                placeholder="usuario"
              />
              <span class="px-3 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 flex items-center text-sm border-l dark:border-gray-600">
                {{ tenant }}
              </span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Contraseña</label>
            <input
              v-model="editMember.password"
              type="password"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="Dejar vacío para no cambiar"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Correo electrónico</label>
            <input
              v-model="editMember.email"
              type="email"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="usuario@ejemplo.com"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Teléfono celular</label>
            <input
              v-model="editMember.phone"
              type="text"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="Número de celular"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Nombre</label>
            <input
              v-model="editMember.name"
              type="text"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="Nombre"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Apellido</label>
            <input
              v-model="editMember.lastname"
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
                v-model="editMember.role_id" 
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
              <v-icon name="md-publicrounded" class="w-4 h-4 text-green-600 dark:text-green-400" />
              Operar todas las zonas
            </label>
            <div class="relative">
              <select 
                v-model="editMember.allZones" 
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
              <v-icon name="md-securityrounded" class="w-4 h-4 text-purple-600 dark:text-purple-400" />
              Autenticación de dos pasos
            </label>
            <div class="relative">
              <select 
                v-model="editMember.twoFA" 
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
            @click="updateUser"
            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg shadow transition-all"
          >
            {{ saving ? 'Guardando...' : 'Guardar Cambios' }}
          </button>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api.js'

const route = useRoute()
const router = useRouter()
const userId = route.params.id

const editMember = ref({
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

const tenant = ref('')
const roles = ref([])
const loading = ref(false)
const saving = ref(false)

onMounted(async () => {
  await loadRoles()
  await loadUserData()
})

const loadUserData = async () => {
  loading.value = true
  try {
    // 1. Cargar datos del usuario a editar desde la API
    const response = await api.staff.getOne(userId)

    if (response.data.success) {
      const data = response.data.data

      // Rellenar el formulario
      editMember.value = {
        username: data.user_name,
        password: '',
        email: data.email || '',
        phone: data.tel || '',
        name: data.user_name, // Ajusta si tienes campo separado para nombre real
        lastname: data.user_lastname,
        role_id: data.role_id,
        allZones: 'Sí',
        twoFA: 'No',
      }

      // 2. LOGICA PARA OBTENER EL DOMINIO DEL TENANT (Igual que en StaffNew)
      const userData = JSON.parse(localStorage.getItem('userData') || sessionStorage.getItem('userData'))

      // Intento 1: Extraer del email_tenant guardado en sesión
      if (userData?.email_tenant) {
          const parts = userData.email_tenant.split('@');
          if (parts.length > 1) {
              tenant.value = `@${parts[1]}`;
          } else {
              tenant.value = '@sin-tenant';
          }
      } 
      // Intento 2: Si tienes el ID, podrías llamar a la API (Opcional, el método 1 es más rápido)
      else {
         tenant.value = '@sin-tenant';
      }
      
      console.log("✅ Dominio cargado en Edit:", tenant.value);
    }
  } catch (error) {
    console.error('❌ Error al cargar el usuario:', error.response?.data || error)
    alert('No se pudo cargar la información del usuario.')
    router.push('/staff')
  } finally {
    loading.value = false
  }
}

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

const loadRoles = async () => {
  try {
  const response = await api.roles.getAll()
  if (response.data.success) {
    roles.value = response.data.data
  } else if (response.data && Array.isArray(response.data)) {
    roles.value = response.data
  }
  } catch (error) {
    console.error('❌ Error al cargar roles:', error)
  }
}

// Computed property to filter out "Cliente" role from staff selection
const filteredRoles = computed(() => {
  return roles.value.filter(role => role.name !== 'Cliente')
})

const updateUser = async () => {
  saving.value = true
  try {
    // basic validation
    if (!editMember.value.name || !editMember.value.lastname) {
      alert('⚠️ Por favor completa el nombre y apellido')
      return
    }

    if (!editMember.value.email) {
      alert('⚠️ Por favor completa el email')
      return
    }

    if (!editMember.value.role_id) {
      alert('⚠️ Por favor selecciona un rol')
      return
    }

    const updateData = {
        user_name: editMember.value.username,
        user_lastname: editMember.value.lastname,
        email: editMember.value.email,
        tel: editMember.value.phone,
        role_id: editMember.value.role_id,
        updated_at: new Date().toISOString(),
      }

    if (editMember.value.password && editMember.value.password.trim() !== '') {
      updateData.password = editMember.value.password
    }

    const response = await api.staff.update(userId, updateData)

    if (response.data.success) {
      alert('✅ Usuario actualizado correctamente.')
      router.push('/staff')
    }
  } catch (error) {
    console.error('⚠️ Error al actualizar usuario:', error.response?.data || error)

    // show validation errors from API
    if (error.response?.data?.errors) {
      const errors = Object.values(error.response.data.errors).flat()
      alert(`❌ Errores de validación:\n${errors.join('\n')}`)
    } else {
      alert(`❌ Error al actualizar usuario: ${error.response?.data?.message || error.message}`)
    }
  } finally {
    saving.value = false
  }
}
</script>

