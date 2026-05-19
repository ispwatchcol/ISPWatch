<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">
      
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-xl">
              <v-icon name="bi-building" class="text-blue-600 dark:text-blue-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            Proveedores
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
            Gestiona los proveedores de equipos
          </p>
        </div>
        
        <button
          v-if="can('inventory.create')"
          @click="openAddModal"
          class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800
                 text-white px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg hover:shadow-xl
                 transition-all transform hover:-translate-y-0.5
                 font-medium w-full sm:w-auto justify-center"
        >
          <v-icon name="md-add" class="w-5 h-5 fill-current" />
          <span>Nuevo Proveedor</span>
        </button>
      </div>

      <!-- Search -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
        <div class="relative">
          <v-icon name="io-search" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Buscar por nombre, email, ciudad..."
            class="w-full pl-10 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                   bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                   focus:ring-2 focus:ring-blue-500 focus:border-transparent
                   transition-all outline-none"
          />
        </div>
      </div>

      <!-- Desktop Table View -->
      <div class="hidden lg:block bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center py-16">
          <v-icon name="ri-loader-4-line" animation="spin" class="w-8 h-8 text-blue-500" />
          <span class="ml-3 text-gray-500 dark:text-gray-400">Cargando proveedores...</span>
        </div>

        <!-- Empty state -->
        <div v-else-if="filteredItems.length === 0" class="text-center py-16">
          <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
            <v-icon name="bi-building" class="w-10 h-10 text-gray-400" />
          </div>
          <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">
            {{ searchQuery ? 'Sin resultados' : 'Sin proveedores registrados' }}
          </p>
          <p class="text-gray-400 dark:text-gray-500 text-sm mt-2">
            {{ searchQuery ? 'Intenta con otros términos de búsqueda' : 'Agrega tu primer proveedor' }}
          </p>
          <button
            v-if="!searchQuery"
            @click="openAddModal"
            class="mt-6 px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                   transition-colors text-sm font-medium inline-flex items-center gap-2"
          >
            <v-icon name="md-add" class="w-4 h-4 fill-current" />
            Agregar Primer Proveedor
          </button>
        </div>

        <!-- Data table -->
        <div v-else class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-100 dark:bg-gray-700">
              <tr>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">ID</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Nombre</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Email</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Teléfono</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Ciudad</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Asesor</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr
                v-for="item in filteredItems"
                :key="item.id"
                class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
              >
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="text-sm font-bold text-blue-600 dark:text-blue-400">#{{ item.id }}</span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                      <v-icon name="bi-building" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                      <span class="text-sm font-medium text-gray-900 dark:text-white">{{ item.name }}</span>
                      <p v-if="item.identification" class="text-xs text-gray-500 dark:text-gray-400">{{ item.identification }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-gray-700 dark:text-gray-300">{{ item.email || '—' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-gray-700 dark:text-gray-300">{{ item.phone || '—' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-gray-700 dark:text-gray-300">{{ item.city || '—' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-gray-700 dark:text-gray-300">{{ item.advisor_name || '—' }}</span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <button
                      v-if="can('inventory.edit')"
                      @click="openEditModal(item)"
                      class="p-2 text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20 rounded-lg transition-all hover:scale-110"
                      title="Editar"
                    >
                      <v-icon name="fa-edit" class="w-4 h-4" />
                    </button>
                    <button
                      v-if="can('inventory.delete')"
                      @click="confirmDelete(item)"
                      class="p-2 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20 rounded-lg transition-all hover:scale-110"
                      title="Eliminar"
                    >
                      <v-icon name="md-delete" class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile Card View -->
      <div class="lg:hidden space-y-4">
        <!-- Mobile Loading -->
        <div v-if="loading" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center">
          <v-icon name="ri-loader-4-line" animation="spin" class="w-8 h-8 text-blue-500 mx-auto" />
          <span class="block mt-3 text-gray-500 dark:text-gray-400">Cargando proveedores...</span>
        </div>

        <!-- Mobile Cards -->
        <template v-else-if="filteredItems.length > 0">
          <div
            v-for="item in filteredItems"
            :key="item.id"
            class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 hover:shadow-lg transition-all"
          >
            <!-- Header -->
            <div class="flex items-start justify-between mb-3">
              <div class="flex items-center gap-2">
                <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
                  <v-icon name="bi-building" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                  <span class="text-xs font-bold text-blue-600 dark:text-blue-400">#{{ item.id }}</span>
                  <p class="text-sm font-medium text-gray-800 dark:text-white mt-0.5">{{ item.name }}</p>
                  <p v-if="item.identification" class="text-xs text-gray-500 dark:text-gray-400">{{ item.identification }}</p>
                </div>
              </div>
            </div>

            <!-- Info Grid -->
            <div class="grid grid-cols-2 gap-3 mb-3">
              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Email</p>
                <p class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ item.email || 'N/A' }}</p>
              </div>
              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Teléfono</p>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ item.phone || 'N/A' }}</p>
              </div>
              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Ciudad</p>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ item.city || 'N/A' }}</p>
              </div>
              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Asesor</p>
                <p class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ item.advisor_name || 'N/A' }}</p>
              </div>
            </div>

            <!-- Actions -->
            <div class="grid grid-cols-2 gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
              <button
                v-if="can('inventory.edit')"
                @click="openEditModal(item)"
                class="py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                       transition-colors text-sm font-medium flex items-center justify-center gap-1"
              >
                <v-icon name="fa-edit" class="w-4 h-4" />
                Editar
              </button>
              <button
                v-if="can('inventory.delete')"
                @click="confirmDelete(item)"
                class="py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg
                       transition-colors text-sm font-medium flex items-center justify-center gap-1"
              >
                <v-icon name="md-delete" class="w-4 h-4" />
                Eliminar
              </button>
            </div>
          </div>
        </template>

        <!-- Mobile Empty State -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center">
          <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
            <v-icon name="bi-building" class="w-10 h-10 text-gray-400" />
          </div>
          <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">
            {{ searchQuery ? 'Sin resultados' : 'Sin proveedores' }}
          </p>
          <p class="text-gray-400 dark:text-gray-500 text-sm mt-2 mb-6">
            {{ searchQuery ? 'Intenta con otros términos' : 'Agrega tu primer proveedor' }}
          </p>
          <button
            v-if="!searchQuery"
            @click="openAddModal"
            class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                   transition-colors text-sm font-medium flex items-center justify-center gap-2"
          >
            <v-icon name="md-add" class="w-4 h-4 fill-current" />
            Agregar Proveedor
          </button>
        </div>
      </div>

      <!-- Add/Edit Modal -->
      <div v-if="showFormModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeFormModal">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden max-h-[90vh] overflow-y-auto">
          <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-5 text-white sticky top-0 z-10">
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-semibold">{{ isEditing ? 'Editar Proveedor' : 'Nuevo Proveedor' }}</h3>
              <button @click="closeFormModal" class="p-1 hover:bg-white/20 rounded-lg transition-colors">
                <v-icon name="md-close" class="w-5 h-5" />
              </button>
            </div>
          </div>

          <form @submit.prevent="handleSave" class="p-4 md:p-6 space-y-5">
            <!-- Datos principales -->
            <div>
              <h4 class="text-sm font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-3">Datos del Proveedor</h4>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nombre *</label>
                  <input
                    v-model="form.name"
                    type="text"
                    required
                    placeholder="Nombre del proveedor..."
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Identificación</label>
                  <input
                    v-model="form.identification"
                    type="text"
                    placeholder="NIT / CC..."
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email</label>
                  <input
                    v-model="form.email"
                    type="email"
                    placeholder="email@proveedor.com"
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Teléfono</label>
                  <input
                    v-model="form.phone"
                    type="text"
                    placeholder="Teléfono..."
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dirección</label>
                  <input
                    v-model="form.addr"
                    type="text"
                    placeholder="Dirección..."
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Ciudad</label>
                  <input
                    v-model="form.city"
                    type="text"
                    placeholder="Ciudad..."
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
              </div>
            </div>

            <!-- Datos del asesor -->
            <div>
              <h4 class="text-sm font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-3">Datos del Asesor</h4>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nombre del Asesor</label>
                  <input
                    v-model="form.advisor_name"
                    type="text"
                    placeholder="Nombre..."
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Cargo</label>
                  <input
                    v-model="form.advisor_position"
                    type="text"
                    placeholder="Cargo..."
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Teléfono del Asesor</label>
                  <input
                    v-model="form.advisor_phone"
                    type="text"
                    placeholder="Teléfono..."
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email del Asesor</label>
                  <input
                    v-model="form.advisor_email"
                    type="email"
                    placeholder="email@asesor.com"
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                           focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                  />
                </div>
              </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
              <button
                type="button"
                @click="closeFormModal"
                class="flex-1 py-2.5 px-4 border-2 border-gray-300 dark:border-gray-600 rounded-xl
                       text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700
                       transition-all font-medium"
                :disabled="saving"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="flex-1 py-2.5 px-4 bg-gradient-to-r from-blue-600 to-blue-700
                       hover:from-blue-700 hover:to-blue-800 text-white rounded-xl
                       transition-all font-medium shadow-lg disabled:opacity-50"
                :disabled="saving"
              >
                {{ saving ? 'Guardando...' : (isEditing ? 'Actualizar' : 'Crear') }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Delete Confirmation Modal -->
      <div
        v-if="showDeleteModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
        @click.self="closeDeleteModal"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <v-icon name="md-delete" class="w-6 h-6 text-red-600" />
                Eliminar Proveedor
              </h2>
            </div>
            <button
              @click="closeDeleteModal"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
              <v-icon name="md-close" class="w-6 h-6" />
            </button>
          </div>

          <!-- Content -->
          <div class="space-y-4">
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <v-icon name="md-warning-round" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                <div>
                  <h4 class="font-medium text-red-800 dark:text-red-300">¿Estás seguro?</h4>
                  <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                    Esta acción no se puede deshacer. El proveedor <strong>"{{ itemToDelete?.name }}"</strong> será eliminado permanentemente.
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="closeDeleteModal"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
              :disabled="saving"
            >
              Cancelar
            </button>
            <button
              @click="deleteItem"
              :disabled="saving"
              class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
            >
              <v-icon v-if="saving" name="ri-loader-4-line" animation="spin" class="w-4 h-4" />
              <v-icon v-else name="md-delete" class="w-4 h-4" />
              {{ saving ? 'Eliminando...' : 'Eliminar' }}
            </button>
          </div>
        </div>
      </div>

      <NotificationToast ref="toast" />

    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { supabase } from '@/supabase.js'
import NotificationToast from '@/components/NotificationToast.vue'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()

const toast = ref(null)
const loading = ref(false)
const saving = ref(false)
const searchQuery = ref('')
const items = ref([])
const tenantId = ref(null)

// Get tenant_id from logged-in user
const getUserTenantId = () => {
  const userData = JSON.parse(localStorage.getItem('userData')) ?? JSON.parse(sessionStorage.getItem('userData'))
  if (!userData?.tenant_id) {
    console.error('⚠️ No se encontró tenant_id del usuario autenticado.')
    return null
  }
  return userData.tenant_id
}

const showFormModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const itemToDelete = ref(null)

const emptyForm = () => ({
  name: '',
  email: '',
  phone: '',
  addr: '',
  city: '',
  identification: '',
  advisor_name: '',
  advisor_phone: '',
  advisor_email: '',
  advisor_position: ''
})

const form = ref(emptyForm())

const filteredItems = computed(() => {
  if (!searchQuery.value) return items.value
  const q = searchQuery.value.toLowerCase()
  return items.value.filter(item =>
    (item.name || '').toLowerCase().includes(q) ||
    (item.email || '').toLowerCase().includes(q) ||
    (item.city || '').toLowerCase().includes(q) ||
    (item.identification || '').toLowerCase().includes(q)
  )
})

const loadItems = async () => {
  loading.value = true
  try {
    if (!tenantId.value) return

    const { data, error } = await supabase
      .from('inventory_provider')
      .select('*')
      .eq('tenant_id', tenantId.value)
      .order('name')

    if (error) throw error
    items.value = data || []
  } catch (error) {
    console.error('Error loading providers:', error)
    toast.value?.error('Error', 'No se pudieron cargar los proveedores')
  } finally {
    loading.value = false
  }
}

const openAddModal = () => {
  isEditing.value = false
  editingId.value = null
  form.value = emptyForm()
  showFormModal.value = true
}

const openEditModal = (item) => {
  isEditing.value = true
  editingId.value = item.id
  form.value = {
    name: item.name || '',
    email: item.email || '',
    phone: item.phone || '',
    addr: item.addr || '',
    city: item.city || '',
    identification: item.identification || '',
    advisor_name: item.advisor_name || '',
    advisor_phone: item.advisor_phone || '',
    advisor_email: item.advisor_email || '',
    advisor_position: item.advisor_position || ''
  }
  showFormModal.value = true
}

const closeFormModal = () => {
  showFormModal.value = false
  isEditing.value = false
  editingId.value = null
}

const confirmDelete = (item) => {
  itemToDelete.value = item
  showDeleteModal.value = true
}

const closeDeleteModal = () => {
  showDeleteModal.value = false
  itemToDelete.value = null
}

const handleSave = async () => {
  saving.value = true
  try {
    const payload = {
      name: form.value.name,
      email: form.value.email || null,
      phone: form.value.phone || null,
      addr: form.value.addr || null,
      city: form.value.city || null,
      identification: form.value.identification || null,
      advisor_name: form.value.advisor_name || null,
      advisor_phone: form.value.advisor_phone || null,
      advisor_email: form.value.advisor_email || null,
      advisor_position: form.value.advisor_position || null,
      tenant_id: tenantId.value
    }

    if (isEditing.value) {
      const { error } = await supabase
        .from('inventory_provider')
        .update(payload)
        .eq('id', editingId.value)
      if (error) throw error
      toast.value?.success('Actualizado', 'Proveedor actualizado correctamente')
    } else {
      const { error } = await supabase
        .from('inventory_provider')
        .insert(payload)
      if (error) throw error
      toast.value?.success('Creado', 'Nuevo proveedor agregado correctamente')
    }

    closeFormModal()
    await loadItems()
  } catch (error) {
    console.error('Error saving:', error)
    toast.value?.error('Error', 'No se pudo guardar: ' + error.message)
  } finally {
    saving.value = false
  }
}

const deleteItem = async () => {
  if (!itemToDelete.value) return
  saving.value = true
  try {
    const { error } = await supabase
      .from('inventory_provider')
      .delete()
      .eq('id', itemToDelete.value.id)
    if (error) throw error
    toast.value?.success('Eliminado', 'Proveedor eliminado correctamente')
    closeDeleteModal()
    await loadItems()
  } catch (error) {
    console.error('Error deleting:', error)
    toast.value?.error('Error', 'No se pudo eliminar: ' + error.message)
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  tenantId.value = getUserTenantId()
  loadItems()
})
</script>
