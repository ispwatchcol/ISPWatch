<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100">
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
        <button
          @click="$router.push('/staff')"
          class="flex items-center gap-2 px-4 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 rounded-lg text-gray-800 dark:text-gray-100 transition-all"
        >
          <v-icon name="fa-arrow-left" class="w-4 h-4" />
          Volver a Staff
        </button>
      </div>

      <div class="bg-white dark:bg-gray-800 shadow rounded-2xl p-8">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
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
                @change="onRoleChange"
                class="w-full pl-3 pr-10 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 cursor-pointer appearance-none hover:border-blue-400 dark:hover:border-blue-500"
              >
                <option disabled value="">Selecciona un rol</option>
                <option v-for="role in filteredRoles" :key="role.id" :value="role.id">
                  {{ role.name }}
                </option>
              </select>
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none z-50">
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
                class="w-full pl-3 pr-10 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 cursor-pointer appearance-none hover:border-green-400 dark:hover:border-green-500"
              >
                <option>Sí</option>
                <option>No</option>
              </select>
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none z-50">
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
                class="w-full pl-3 pr-10 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 cursor-pointer appearance-none hover:border-purple-400 dark:hover:border-purple-500"
              >
                <option>No</option>
                <option>Sí</option>
              </select>
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none z-50">
                <v-icon name="md-keyboardarrowdown" class="w-5 h-5 text-gray-400 dark:text-gray-500" />
              </div>
            </div>
          </div>
        </div>

        <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-gray-100">Permisos</h2>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
          <div v-for="(items, groupTitle) in permissionsByGroup" :key="groupTitle" class="border rounded-xl p-4 bg-gray-50 dark:bg-gray-700/50">
            <h3 class="font-bold text-gray-700 dark:text-gray-200 mb-2">{{ groupTitle }}</h3>
            <div class="space-y-1 text-sm">
              <label v-for="(label, permKey) in items" :key="permKey" class="flex items-center gap-2">
                <input
                  type="checkbox"
                  :checked="newMember.permissions.includes(permKey)"
                  @change="togglePermission(permKey)"
                  class="accent-blue-600"
                />
                <span>{{ label }}</span>
              </label>
            </div>
          </div>
        </div>

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
  permissions: [],
})

const tenant = ref('')
const tenantId = ref('')
const roles = ref([])
const saving = ref(false)
const toast = ref(null)
const availablePermissions = ref({})
const roleDefaults = ref({})

onMounted(async () => {
  const userData = JSON.parse(localStorage.getItem("userData")) || JSON.parse(sessionStorage.getItem("userData"))
  tenantId.value = userData?.tenant_id

  if (tenantId.value) {
    await loadTenantDomain()
  } else {
    tenant.value = '@sin-tenant'
  }

  await loadRoles()
  await loadPermissions()
})

const loadTenantDomain = async () => {
  const userData = JSON.parse(localStorage.getItem('userData') || sessionStorage.getItem('userData') || '{}')
  if (userData?.email_tenant) {
    const parts = userData.email_tenant.split('@')
    if (parts.length > 1 && parts[1]) {
      tenant.value = `@${parts[1]}`
      return
    }
  }
  tenant.value = '@sin-tenant'
}

const loadRoles = async () => {
  try {
    const response = await api.roles.getAll()
    const data = response.data
    roles.value = Array.isArray(data?.data) ? data.data : Array.isArray(data) ? data : []
    if (roles.value.length === 0) {
      roles.value = [
        { id: 1, name: 'Administrador' },
        { id: 2, name: 'Staff' },
      ]
    }
  } catch (error) {
    console.error('Error al cargar roles:', error)
    roles.value = [
      { id: 1, name: 'Administrador' },
      { id: 2, name: 'Staff' },
    ]
  }
}

const loadPermissions = async () => {
  try {
    console.log('📥 Cargando permisos...')
    const response = await api.roles.getPermissions()
    console.log('📦 Respuesta de permisos:', response.data)
    if (response.data?.success) {
      availablePermissions.value = response.data.data.available || {}
      roleDefaults.value = response.data.data.roleDefaults || {}
      console.log('✅ Permisos cargados:', {
        available: Object.keys(availablePermissions.value),
        roleDefaults: Object.keys(roleDefaults.value)
      })
    }
  } catch (error) {
    console.error('❌ Error al cargar permisos:', error?.response?.data || error?.message)
    toast.value?.error('Error', 'No se pudieron cargar los permisos')
  }
}

const filteredRoles = computed(() => {
  return roles.value.filter(role => role.name !== 'Cliente')
})

const permissionsByGroup = computed(() => {
  return availablePermissions.value
})

const onRoleChange = () => {
  if (newMember.value.role_id) {
    const selectedRole = roles.value.find(r => r.id == newMember.value.role_id)
    if (selectedRole && roleDefaults.value[selectedRole.name]) {
      newMember.value.permissions = [...roleDefaults.value[selectedRole.name]]
    }
  }
}

const togglePermission = (permKey) => {
  const index = newMember.value.permissions.indexOf(permKey)
  if (index > -1) {
    newMember.value.permissions.splice(index, 1)
  } else {
    newMember.value.permissions.push(permKey)
  }
}

const saveUser = async () => {
  saving.value = true
  try {
    if (!tenantId.value) {
      toast.value?.error('Sesión inválida', 'No se encontró información del tenant.')
      return
    }

    if (!newMember.value.name || !newMember.value.email || !newMember.value.password || !newMember.value.role_id) {
      toast.value?.warning('Datos incompletos', 'Por favor complete todos los campos requeridos.')
      return
    }

    const userInsert = {
      name: `${newMember.value.name} ${newMember.value.lastname}`.trim(),
      user_name: newMember.value.name,
      user_lastname: newMember.value.lastname,
      password: newMember.value.password,
      tenant_id: tenantId.value,
      role_id: newMember.value.role_id,
      tel: newMember.value.phone,
      email_tenant: `${newMember.value.username}${tenant.value}`,
      email: newMember.value.email,
      permissions: newMember.value.permissions,
    }

    const response = await api.staff.create(userInsert)

    if (response.data.success) {
      toast.value?.success('Usuario registrado', 'El usuario ha sido registrado correctamente')
      setTimeout(() => {
        router.push('/staff')
      }, 1500)
    }
  } catch (error) {
    console.error('Error al registrar usuario:', error)
    if (error.response?.data?.errors) {
      const errors = Object.values(error.response.data.errors).flat()
      toast.value?.error('Errores de validación', errors.join(', '))
    } else {
      toast.value?.error('Error al registrar', error.response?.data?.message || error.message)
    }
  } finally {
    saving.value = false
  }
}
</script>
