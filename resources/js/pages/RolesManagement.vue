<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100">
    <NotificationToast ref="toast" />
    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="md-adminpanelsettings-round" class="text-blue-600 w-8 h-8" />
            Administración de Roles
          </h1>
          <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
            Crea y gestiona roles personalizados con sus permisos.
          </p>
        </div>
        <button
          @click="openCreateModal"
          class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-all"
        >
          <v-icon name="fa-plus" class="w-4 h-4" />
          Crear Rol
        </button>
      </div>

      <!-- Tabla de roles -->
      <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
        <div v-if="loading" class="text-center py-8">
          <p class="text-gray-500 dark:text-gray-400">Cargando roles...</p>
        </div>

        <table v-else class="w-full">
          <thead class="bg-gray-100 dark:bg-gray-700">
            <tr>
              <th class="px-6 py-3 text-left text-sm font-semibold">Nombre</th>
              <th class="px-6 py-3 text-left text-sm font-semibold">Permisos</th>
              <th class="px-6 py-3 text-left text-sm font-semibold">Fecha de Creación</th>
              <th class="px-6 py-3 text-right text-sm font-semibold">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y dark:divide-gray-700">
            <tr v-for="role in roles" :key="role.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
              <td class="px-6 py-4">
                <span class="font-medium">{{ role.name }}</span>
                <span v-if="isPredefinedRole(role.name)" class="ml-2 text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-2 py-1 rounded">
                  Predefinido
                </span>
              </td>
              <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                {{ role.permissions?.length || 0 }} permisos
              </td>
              <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                {{ formatDate(role.created_at) }}
              </td>
              <td class="px-6 py-4 text-right space-x-2">
                <button
                  @click="editRole(role)"
                  class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-sm font-medium"
                >
                  Editar
                </button>
                <button
                  v-if="!isPredefinedRole(role.name)"
                  @click="deleteRole(role)"
                  class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 text-sm font-medium"
                >
                  Eliminar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Modal para crear/editar rol -->
      <div
        v-if="showModal"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
        @click.self="closeModal"
      >
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-2xl max-h-96 overflow-y-auto">
          <div class="sticky top-0 bg-gray-50 dark:bg-gray-700 px-6 py-4 border-b dark:border-gray-600 flex justify-between items-center">
            <h2 class="text-xl font-semibold">
              {{ editingRole ? 'Editar Rol' : 'Crear Nuevo Rol' }}
            </h2>
            <button @click="closeModal" class="text-gray-500 hover:text-gray-700 dark:text-gray-400">
              <v-icon name="fa-times" class="w-5 h-5" />
            </button>
          </div>

          <div class="p-6">
            <!-- Nombre del rol -->
            <div class="mb-6">
              <label class="block text-sm font-medium mb-2">Nombre del Rol</label>
              <input
                v-model="formData.name"
                type="text"
                class="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Ej: Soporte Técnico"
              />
            </div>

            <!-- Permisos -->
            <div class="mb-6">
              <label class="block text-sm font-medium mb-3">Permisos</label>
              <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                  v-for="(items, groupTitle) in availablePermissions"
                  :key="groupTitle"
                  class="border rounded-lg p-4 dark:border-gray-600"
                >
                  <h4 class="font-semibold text-sm mb-3 text-gray-700 dark:text-gray-200">{{ groupTitle }}</h4>
                  <div class="space-y-2">
                    <label
                      v-for="(label, permKey) in items"
                      :key="permKey"
                      class="flex items-center gap-2 text-sm"
                    >
                      <input
                        type="checkbox"
                        :checked="formData.permissions.includes(permKey)"
                        @change="togglePermission(permKey)"
                        class="accent-blue-600"
                      />
                      <span>{{ label }}</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>

            <!-- Botones -->
            <div class="flex justify-end gap-3">
              <button
                @click="closeModal"
                class="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 transition-all"
              >
                Cancelar
              </button>
              <button
                @click="saveRole"
                :disabled="saving"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-all disabled:opacity-50"
              >
                {{ saving ? 'Guardando...' : 'Guardar Rol' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api.js'
import NotificationToast from '@/components/NotificationToast.vue'

const roles = ref([])
const loading = ref(false)
const saving = ref(false)
const showModal = ref(false)
const toast = ref(null)
const availablePermissions = ref({})
const editingRole = ref(null)

const formData = ref({
  name: '',
  permissions: [],
})

const predefinedRoles = ['Administrador', 'Cliente', 'Técnico', 'Contabilidad']

onMounted(async () => {
  await loadRoles()
  await loadPermissions()
})

const loadRoles = async () => {
  loading.value = true
  try {
    const response = await api.roles.getAll()
    if (response.data?.success) {
      roles.value = response.data.data
    }
  } catch (error) {
    console.error('Error al cargar roles:', error)
    toast.value?.error('Error', 'No se pudieron cargar los roles')
  } finally {
    loading.value = false
  }
}

const loadPermissions = async () => {
  try {
    const response = await api.roles.getPermissions()
    if (response.data?.success) {
      availablePermissions.value = response.data.data.available || {}
    }
  } catch (error) {
    console.error('Error al cargar permisos:', error)
  }
}

const openCreateModal = () => {
  editingRole.value = null
  formData.value = {
    name: '',
    permissions: [],
  }
  showModal.value = true
}

const editRole = (role) => {
  editingRole.value = role
  formData.value = {
    name: role.name,
    permissions: [...(role.permissions || [])],
  }
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
  editingRole.value = null
  formData.value = {
    name: '',
    permissions: [],
  }
}

const togglePermission = (permKey) => {
  const index = formData.value.permissions.indexOf(permKey)
  if (index > -1) {
    formData.value.permissions.splice(index, 1)
  } else {
    formData.value.permissions.push(permKey)
  }
}

const saveRole = async () => {
  if (!formData.value.name.trim()) {
    toast.value?.warning('Validación', 'Por favor ingresa un nombre para el rol')
    return
  }

  saving.value = true
  try {
    let response
    if (editingRole.value) {
      response = await api.roles.update(editingRole.value.id, formData.value)
      toast.value?.success('Éxito', 'Rol actualizado correctamente')
    } else {
      response = await api.roles.create(formData.value)
      toast.value?.success('Éxito', 'Rol creado correctamente')
    }

    if (response.data?.success) {
      closeModal()
      await loadRoles()
    }
  } catch (error) {
    console.error('Error al guardar rol:', error)
    if (error.response?.data?.errors) {
      const errors = Object.values(error.response.data.errors).flat()
      toast.value?.error('Error', errors.join(', '))
    } else {
      toast.value?.error('Error', error.response?.data?.message || 'No se pudo guardar el rol')
    }
  } finally {
    saving.value = false
  }
}

const deleteRole = async (role) => {
  if (!confirm(`¿Estás seguro de que deseas eliminar el rol "${role.name}"?`)) {
    return
  }

  try {
    const response = await api.roles.delete(role.id)
    if (response.data?.success) {
      toast.value?.success('Éxito', 'Rol eliminado correctamente')
      await loadRoles()
    }
  } catch (error) {
    console.error('Error al eliminar rol:', error)
    toast.value?.error('Error', error.response?.data?.message || 'No se pudo eliminar el rol')
  }
}

const isPredefinedRole = (roleName) => {
  return predefinedRoles.includes(roleName)
}

const formatDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('es-ES')
}
</script>
