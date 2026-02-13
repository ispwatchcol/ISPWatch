<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">
      
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
              <v-icon name="md-inventory-round" class="text-purple-600 dark:text-purple-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            Stock / Modelos
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
            Gestiona las marcas y modelos de equipos disponibles
          </p>
        </div>
        
        <button
          @click="openAddModal"
          class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800
                 text-white px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg hover:shadow-xl
                 transition-all transform hover:-translate-y-0.5
                 font-medium w-full sm:w-auto justify-center"
        >
          <v-icon name="md-add" class="w-5 h-5 fill-current" />
          <span>Nuevo Stock</span>
        </button>
      </div>

      <!-- Search -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
        <div class="relative">
          <v-icon name="io-search" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Buscar por marca, modelo..."
            class="w-full pl-10 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                   bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                   focus:ring-2 focus:ring-purple-500 focus:border-transparent
                   transition-all outline-none"
          />
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center py-16">
          <v-icon name="ri-loader-4-line" animation="spin" class="w-8 h-8 text-purple-500" />
          <span class="ml-3 text-gray-500 dark:text-gray-400">Cargando stock...</span>
        </div>

        <!-- Empty state -->
        <div v-else-if="filteredItems.length === 0" class="text-center py-16">
          <v-icon name="md-inventory-round" class="w-16 h-16 text-gray-300 dark:text-gray-600 mx-auto mb-4" />
          <h3 class="text-lg font-semibold text-gray-600 dark:text-gray-300">
            {{ searchQuery ? 'Sin resultados' : 'Sin stock registrado' }}
          </h3>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            {{ searchQuery ? 'Intenta con otros términos de búsqueda' : 'Agrega tu primer stock/modelo' }}
          </p>
        </div>

        <!-- Data table -->
        <div v-else class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-100 dark:bg-gray-700">
              <tr>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">ID</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Marca</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Modelo</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Precio</th>
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
                  <span class="text-sm font-bold text-purple-600 dark:text-purple-400">#{{ item.id }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm font-medium text-gray-900 dark:text-white">{{ item.brand || 'N/A' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-gray-700 dark:text-gray-300">{{ item.model || 'N/A' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(item.price) }}</span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <button
                      @click="openEditModal(item)"
                      class="p-2 text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                      title="Editar"
                    >
                      <v-icon name="fa-edit" class="w-4 h-4" />
                    </button>
                    <button
                      @click="confirmDelete(item)"
                      class="p-2 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20 rounded-lg transition-colors"
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

      <!-- Add/Edit Modal -->
      <div v-if="showFormModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeFormModal">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
          <!-- Modal Header -->
          <div class="bg-gradient-to-r from-purple-600 to-purple-700 p-5 text-white">
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-semibold">{{ isEditing ? 'Editar Stock' : 'Nuevo Stock' }}</h3>
              <button @click="closeFormModal" class="p-1 hover:bg-white/20 rounded-lg transition-colors">
                <v-icon name="md-close" class="w-5 h-5" />
              </button>
            </div>
          </div>

          <!-- Modal Body -->
          <form @submit.prevent="handleSave" class="p-6 space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Marca *</label>
              <input
                v-model="form.brand"
                type="text"
                required
                placeholder="Ej: TP-Link, MikroTik, Ubiquiti..."
                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all outline-none"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Modelo *</label>
              <input
                v-model="form.model"
                type="text"
                required
                placeholder="Ej: hAP ac², LHG XL HP5..."
                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all outline-none"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Precio (COP)</label>
              <input
                v-model.number="form.price"
                type="number"
                min="0"
                step="100"
                placeholder="0"
                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all outline-none"
              />
            </div>

            <!-- Actions -->
            <div class="flex gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
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
                class="flex-1 py-2.5 px-4 bg-gradient-to-r from-purple-600 to-purple-700
                       hover:from-purple-700 hover:to-purple-800 text-white rounded-xl
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
      <div v-if="showDeleteModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeDeleteModal">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
          <div class="p-6 text-center">
            <div class="w-16 h-16 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
              <v-icon name="md-warning-round" class="w-8 h-8 text-red-600 dark:text-red-400" />
            </div>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">¿Eliminar stock?</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">
              ¿Estás seguro de eliminar <strong>{{ itemToDelete?.brand }} {{ itemToDelete?.model }}</strong>? Esta acción no se puede deshacer.
            </p>
          </div>
          <div class="flex border-t border-gray-200 dark:border-gray-700">
            <button
              @click="closeDeleteModal"
              class="flex-1 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700
                     font-medium transition-colors"
              :disabled="saving"
            >
              Cancelar
            </button>
            <button
              @click="deleteItem"
              class="flex-1 py-3 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20
                     font-medium transition-colors border-l border-gray-200 dark:border-gray-700"
              :disabled="saving"
            >
              {{ saving ? 'Eliminando...' : 'Eliminar' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Notification Toast -->
      <NotificationToast ref="toast" />

    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { supabase } from '@/supabase.js'
import NotificationToast from '@/components/NotificationToast.vue'

const toast = ref(null)
const loading = ref(false)
const saving = ref(false)
const searchQuery = ref('')
const items = ref([])

// Modal state
const showFormModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const itemToDelete = ref(null)

const form = ref({
  brand: '',
  model: '',
  price: 0
})

// Computed
const filteredItems = computed(() => {
  if (!searchQuery.value) return items.value
  const q = searchQuery.value.toLowerCase()
  return items.value.filter(item =>
    (item.brand || '').toLowerCase().includes(q) ||
    (item.model || '').toLowerCase().includes(q)
  )
})

// Load data
const loadItems = async () => {
  loading.value = true
  try {
    const { data, error } = await supabase
      .from('inventory_stock')
      .select('*')
      .order('brand')

    if (error) throw error
    items.value = data || []
  } catch (error) {
    console.error('Error loading stocks:', error)
    toast.value?.error('Error', 'No se pudo cargar el stock')
  } finally {
    loading.value = false
  }
}

// Modal actions
const openAddModal = () => {
  isEditing.value = false
  editingId.value = null
  form.value = { brand: '', model: '', price: 0 }
  showFormModal.value = true
}

const openEditModal = (item) => {
  isEditing.value = true
  editingId.value = item.id
  form.value = {
    brand: item.brand || '',
    model: item.model || '',
    price: item.price || 0
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

// CRUD
const handleSave = async () => {
  saving.value = true
  try {
    const payload = {
      brand: form.value.brand,
      model: form.value.model,
      price: form.value.price || 0
    }

    if (isEditing.value) {
      const { error } = await supabase
        .from('inventory_stock')
        .update(payload)
        .eq('id', editingId.value)
      if (error) throw error
      toast.value?.success('Actualizado', 'Stock actualizado correctamente')
    } else {
      const { error } = await supabase
        .from('inventory_stock')
        .insert(payload)
      if (error) throw error
      toast.value?.success('Creado', 'Nuevo stock agregado correctamente')
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
      .from('inventory_stock')
      .delete()
      .eq('id', itemToDelete.value.id)
    if (error) throw error
    toast.value?.success('Eliminado', 'Stock eliminado correctamente')
    closeDeleteModal()
    await loadItems()
  } catch (error) {
    console.error('Error deleting:', error)
    toast.value?.error('Error', 'No se pudo eliminar: ' + error.message)
  } finally {
    saving.value = false
  }
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0
  }).format(value || 0)
}

onMounted(() => {
  loadItems()
})
</script>
