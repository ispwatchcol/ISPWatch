<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 p-4 sm:p-6">
    <NotificationToast ref="toast" />
      <div class="mb-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
          <h1 class="flex items-center gap-2 text-2xl sm:text-3xl font-semibold text-gray-800 dark:text-gray-100">
            <icon-lucide-shield class="h-6 w-6 sm:h-7 sm:w-7 text-blue-600" />
            Administración de Roles
          </h1>
          <p class="mt-1 text-sm sm:text-base text-gray-500 dark:text-gray-400">
            Crea y gestiona roles personalizados con sus permisos asignados
          </p>
        </div>

        <button
          @click="openCreateModal"
          class="flex items-center justify-center w-full sm:w-auto gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-white shadow-md transition-all hover:bg-blue-700"
        >
          <icon-lucide-plus class="h-4 w-4" />
          Crear Rol
        </button>
      </div>

      <div class="rounded-2xl bg-white p-4 sm:p-6 shadow dark:bg-gray-800">
        <div v-if="loading" class="py-12 text-center">
          <div class="inline-block h-12 w-12 animate-spin rounded-full border-4 border-blue-500 border-t-transparent" />
          <p class="mt-4 text-gray-500 dark:text-gray-400">Cargando roles...</p>
        </div>

        <!-- Tabla / Cards -->
        <div v-else class="border border-gray-100 dark:border-gray-700 rounded-xl overflow-hidden">
          
          <!-- Desktop Table -->
          <div class="hidden md:block overflow-x-auto">
          <table class="min-w-full border-collapse">
            <thead>
              <tr class="bg-gray-100 text-sm uppercase tracking-wide text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                <th class="py-3 px-4 text-left">
                  <div class="flex items-center gap-2">
                    <icon-lucide-users class="h-4 w-4 text-blue-600" />
                    Nombre
                  </div>
                </th>
                <th class="py-3 px-4 text-left">
                  <div class="flex items-center gap-2">
                    <icon-lucide-key class="h-4 w-4 text-blue-600" />
                    Permisos
                  </div>
                </th>
                <th class="py-3 px-4 text-left">
                  <div class="flex items-center gap-2">
                    <icon-lucide-calendar class="h-4 w-4 text-blue-600" />
                    Fecha de Creación
                  </div>
                </th>
                <th class="py-3 px-4 text-left">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="role in roles"
                :key="role.id"
                class="border-b border-gray-200 transition-all hover:bg-blue-50 dark:border-gray-700 dark:hover:bg-gray-700/40 bg-white dark:bg-gray-800"
              >
                <td class="py-3 px-4">
                  <div class="font-medium text-gray-800 dark:text-gray-100">
                    {{ role.name }}
                    <div v-if="isPredefinedRole(role.name)" class="mt-1">
                      <span class="inline-flex items-center gap-1 text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 px-2.5 py-1 rounded-full font-medium">
                        <icon-lucide-check-circle class="w-3 h-3" />
                        Predefinido
                      </span>
                    </div>
                  </div>
                </td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">
                  <div class="inline-flex items-center gap-2 bg-gray-100 dark:bg-gray-700 px-3 py-1 rounded-lg">
                    <icon-lucide-key class="text-gray-500 dark:text-gray-400 h-4 w-4" />
                    <span class="font-semibold">{{ role.permissions?.length || 0 }}</span>
                    <span class="text-sm">permisos</span>
                  </div>
                </td>
                <td class="py-3 px-4 text-gray-500 dark:text-gray-400">
                  {{ formatDate(role.created_at) }}
                </td>
                <td class="flex gap-2 py-3 px-4">
                  <button
                    @click="editRole(role)"
                    class="flex items-center gap-1 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition-all hover:scale-[1.03] hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-800/50"
                  >
                    <icon-lucide-pencil class="h-4 w-4" />
                    Editar
                  </button>

                  <button
                    v-if="!isPredefinedRole(role.name)"
                    @click="deleteRole(role)"
                    class="flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 transition-all hover:scale-[1.03] hover:bg-red-100 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-800/50"
                  >
                    <icon-lucide-trash-2 class="h-4 w-4" />
                    Eliminar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          </div>

          <!-- Mobile Cards -->
          <div class="md:hidden divide-y divide-gray-200 dark:divide-gray-700">
            <div v-for="role in roles" :key="role.id" class="p-4 bg-white dark:bg-gray-800">
              <div class="flex justify-between items-start mb-3">
                <div class="font-semibold text-gray-800 dark:text-gray-100 text-sm">
                  {{ role.name }}
                </div>
                <span v-if="isPredefinedRole(role.name)" class="inline-flex items-center gap-1 text-[10px] bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full font-medium">
                  <icon-lucide-check-circle class="w-3 h-3" /> Predefinido
                </span>
              </div>
              
              <div class="grid grid-cols-2 gap-2 text-xs mb-4">
                <div class="col-span-2">
                  <div class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2.5 py-1 rounded-lg">
                    <icon-lucide-key class="text-gray-500 dark:text-gray-400 h-3.5 w-3.5" />
                    <span class="font-semibold text-gray-700 dark:text-gray-200">{{ role.permissions?.length || 0 }}</span>
                    <span class="text-gray-500 dark:text-gray-400">permisos</span>
                  </div>
                </div>
                <div class="col-span-2 mt-1"><span class="text-gray-500 dark:text-gray-400">Creación:</span> <span class="text-gray-800 dark:text-gray-200 ml-1">{{ formatDate(role.created_at) }}</span></div>
              </div>

              <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
                <button
                  @click="editRole(role)"
                  class="flex-1 min-w-[80px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                         border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 transition-all
                         dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-800/50"
                >
                  <icon-lucide-pencil class="h-3.5 w-3.5" /> Editar
                </button>
                <button
                  v-if="!isPredefinedRole(role.name)"
                  @click="deleteRole(role)"
                  class="flex-1 min-w-[80px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                         border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 transition-all
                         dark:border-red-800 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-800/50"
                >
                  <icon-lucide-trash-2 class="h-3.5 w-3.5" /> Eliminar
                </button>
              </div>
            </div>
          </div>

          <div v-if="roles.length === 0" class="py-8 text-center text-gray-500 dark:text-gray-400">
            No hay roles disponibles
          </div>
        </div>
      </div>

      <!-- Modal para crear/editar rol -->
      <Teleport to="body">
        <div
          v-if="showModal"
          class="fixed inset-0 z-50 flex items-center justify-center p-4"
        >
          <div
            class="absolute inset-0 bg-black/50 backdrop-blur-sm"
            @click="closeModal"
          />

          <div class="relative w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800">
            <div class="mb-6 flex items-start justify-between">
              <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600">
                  <icon-lucide-plus v-if="!editingRole" class="h-5 w-5 text-white" />
                  <icon-lucide-pencil v-else class="h-5 w-5 text-white" />
                </div>
                <div>
                  <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    {{ editingRole ? 'Editar Rol' : 'Crear Nuevo Rol' }}
                  </h2>
                  <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ editingRole ? 'Modifica los permisos del rol' : 'Define el nombre y permisos del nuevo rol' }}
                  </p>
                </div>
              </div>

              <button
                @click="closeModal"
                class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
              >
                <icon-lucide-x class="h-5 w-5" />
              </button>
            </div>

            <!-- Nombre del rol -->
            <div class="mb-6">
              <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
                Nombre del Rol
              </label>
              <input
                v-model="formData.name"
                type="text"
                class="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-900/40"
                placeholder="Ej: Soporte Técnico"
              />
            </div>

            <!-- Permisos -->
            <div class="mb-6">
              <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">
                Permisos
              </label>
              <div class="max-h-72 overflow-y-auto">
                <div class="grid md:grid-cols-2 gap-4">
                  <div
                    v-for="(items, groupTitle) in availablePermissions"
                    :key="groupTitle"
                    class="border border-gray-300 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-700/50"
                  >
                    <h4 class="font-semibold text-sm mb-3 text-gray-700 dark:text-gray-200">
                      {{ groupTitle }}
                    </h4>
                    <div class="space-y-2">
                      <label
                        v-for="(label, permKey) in items"
                        :key="permKey"
                        class="flex items-center gap-2 text-sm cursor-pointer"
                      >
                        <input
                          type="checkbox"
                          :checked="formData.permissions.includes(permKey)"
                          @change="togglePermission(permKey)"
                          class="w-4 h-4 accent-blue-600 cursor-pointer rounded"
                        />
                        <span class="text-gray-700 dark:text-gray-300">{{ label }}</span>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Botones -->
            <div class="mt-6 flex justify-end gap-3">
              <button
                @click="closeModal"
                class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
              >
                Cancelar
              </button>
              <button
                @click="saveRole"
                :disabled="saving"
                class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {{ saving ? 'Guardando...' : 'Guardar Rol' }}
              </button>
            </div>
          </div>
        </div>
      </Teleport>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api.js'
import NotificationToast from '@/components/NotificationToast.vue'
import { useAuthStore } from '@/stores/auth.js'

const roles = ref([])
const loading = ref(false)
const saving = ref(false)
const showModal = ref(false)
const toast = ref(null)
const availablePermissions = ref({})
const editingRole = ref(null)

const authStore = useAuthStore()

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
  const editedRoleId = editingRole.value?.id
  
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
      if (editedRoleId && Number(editedRoleId) === Number(authStore.roleId)) {
        await authStore.refreshUserPermissions()
      }

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
