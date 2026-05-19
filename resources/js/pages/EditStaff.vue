<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100">
    <NotificationToast ref="toast" />
    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="fa-user-edit" class="text-blue-800 text-3xl" style="transform: scale(1.5);" />
            Editar usuario del Staff
          </h1>
          <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
            Modifica la información del usuario.
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

      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500 dark:text-gray-400">Cargando información del usuario</p>
      </div>

      <div v-else class="bg-white dark:bg-gray-800 shadow rounded-2xl p-8">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
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
            <div class="relative">
              <input
                v-model="editMember.password"
                :type="showPassword ? 'text' : 'password'"
                class="w-full p-2 pr-10 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
                placeholder="⛔ Contraseña oculta por seguridad (Escribe para cambiar)"
              />
              <button
                type="button"
                @click="showPassword = !showPassword"
                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 focus:outline-none"
              >
                <v-icon :name="showPassword ? 'fa-eye-slash' : 'fa-eye'" class="w-5 h-5" />
              </button>
            </div>
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
                v-model="editMember.allZones"
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
                v-model="editMember.twoFA"
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
import NotificationToast from '@/components/NotificationToast.vue'

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
const showPassword = ref(false)
const toast = ref(null)

onMounted(async () => {
  await loadRoles()
  await loadUserData()
})

const loadUserData = async () => {
  loading.value = true
  try {
    const response = await api.staff.getOne(userId)

    if (response.data.success) {
      const data = response.data.data
      const emailTenantPrefix = (data.email_tenant || '').split('@')[0] || ''

      editMember.value = {
        username: emailTenantPrefix,
        password: '',
        email: data.email || '',
        phone: data.tel || '',
        name: data.user_name || '',
        lastname: data.user_lastname || '',
        role_id: data.role_id,
        allZones: 'Sí',
        twoFA: 'No',
      }

      const userData = JSON.parse(localStorage.getItem('userData') || sessionStorage.getItem('userData'))
      if (userData?.email_tenant) {
        const parts = userData.email_tenant.split('@')
        if (parts.length > 1) {
          tenant.value = `@${parts[1]}`
        } else {
          tenant.value = '@sin-tenant'
        }
      } else {
        tenant.value = '@sin-tenant'
      }
    }
  } catch (error) {
    console.error('Error al cargar el usuario:', error)
    toast.value?.error('Error de carga', 'No se pudo cargar la información del usuario.')
    router.push('/staff')
  } finally {
    loading.value = false
  }
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

const filteredRoles = computed(() => {
  return roles.value.filter(role => role.name !== 'Cliente')
})

const updateUser = async () => {
  saving.value = true
  try {
    if (!editMember.value.name || !editMember.value.lastname) {
      toast.value?.warning('Datos incompletos', 'Por favor completa el nombre y apellido')
      return
    }

    if (!editMember.value.email) {
      toast.value?.warning('Datos incompletos', 'Por favor completa el email')
      return
    }

    if (!editMember.value.role_id) {
      toast.value?.warning('Datos incompletos', 'Por favor selecciona un rol')
      return
    }

    const updateData = {
      name: `${editMember.value.name} ${editMember.value.lastname}`.trim(),
      user_name: editMember.value.name,
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
      toast.value?.success('Usuario actualizado', 'El usuario ha sido actualizado correctamente')
      setTimeout(() => {
        router.push('/staff')
      }, 1500)
    }
  } catch (error) {
    console.error('Error al actualizar usuario:', error)

    if (error.response?.data?.errors) {
      const errors = Object.values(error.response.data.errors).flat()
      toast.value?.error('Errores de validación', errors.join(', '))
    } else {
      toast.value?.error('Error al actualizar', error.response?.data?.message || error.message)
    }
  } finally {
    saving.value = false
  }
}
</script>
