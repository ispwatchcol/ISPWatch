<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 p-6 md:p-10">

    <!-- Header -->
    <div class="mb-8 flex items-center justify-between">
      <div class="flex items-center gap-4">
        <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl flex items-center justify-center shadow-lg shadow-emerald-500/30">
          <v-icon name="md-payments-outlined" class="w-6 h-6 text-white" />
        </div>
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Formas de Pago</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400">Administra los métodos de pago disponibles para tus clientes</p>
        </div>
      </div>
      <button
        @click="openCreate"
        class="flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-xl font-medium text-sm transition shadow-md shadow-emerald-500/20"
      >
        <v-icon name="md-add" class="w-5 h-5" />
        Nueva forma de pago
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="w-8 h-8 border-4 border-emerald-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Grid -->
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="method in methods"
        :key="method.id"
        class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 flex flex-col gap-4 transition hover:shadow-md"
        :class="{ 'opacity-60': !method.is_active }"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center"
              :class="method.is_active
                ? 'bg-emerald-100 dark:bg-emerald-900/30'
                : 'bg-gray-100 dark:bg-gray-700'">
              <v-icon name="ri-bank-card-line"
                :class="method.is_active ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400'"
                class="w-5 h-5" />
            </div>
            <div>
              <p class="font-semibold text-gray-800 dark:text-white">{{ method.name }}</p>
              <p v-if="method.description" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ method.description }}</p>
            </div>
          </div>
          <span
            class="shrink-0 px-2.5 py-1 text-[11px] font-semibold rounded-full"
            :class="method.is_active
              ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
              : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
          >
            {{ method.is_active ? 'Activo' : 'Inactivo' }}
          </span>
        </div>

        <div class="flex gap-2 pt-1 border-t border-gray-100 dark:border-gray-700">
          <button
            @click="openEdit(method)"
            class="flex-1 flex items-center justify-center gap-1.5 text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 py-2 rounded-lg transition"
          >
            <v-icon name="md-edit" class="w-4 h-4" />
            Editar
          </button>
          <button
            @click="toggleActive(method)"
            class="flex-1 flex items-center justify-center gap-1.5 text-xs font-medium py-2 rounded-lg transition"
            :class="method.is_active
              ? 'text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20'
              : 'text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20'"
          >
            <v-icon :name="method.is_active ? 'md-pausecircle' : 'md-playcircle'" class="w-4 h-4" />
            {{ method.is_active ? 'Desactivar' : 'Activar' }}
          </button>
          <button
            @click="confirmDelete(method)"
            class="flex items-center justify-center gap-1.5 text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 px-3 py-2 rounded-lg transition"
          >
            <v-icon name="md-delete" class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- Empty state -->
      <div
        v-if="methods.length === 0"
        class="sm:col-span-2 lg:col-span-3 text-center py-16 bg-white dark:bg-gray-800 rounded-2xl border border-dashed border-gray-300 dark:border-gray-600"
      >
        <v-icon name="md-payments-outlined" class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-3" />
        <p class="text-gray-500 dark:text-gray-400 font-medium">No hay formas de pago registradas</p>
        <button @click="openCreate" class="mt-4 text-sm text-emerald-600 dark:text-emerald-400 font-medium hover:underline">
          + Crear la primera
        </button>
      </div>
    </div>

    <!-- Modal Crear / Editar -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
      @click.self="showModal = false"
    >
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-5">
          {{ editingId ? 'Editar Forma de Pago' : 'Nueva Forma de Pago' }}
        </h3>
        <form @submit.prevent="submitForm" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">
              Nombre <span class="text-red-500">*</span>
            </label>
            <input
              v-model="form.name"
              type="text"
              maxlength="100"
              required
              placeholder="Ej: Transferencia Bancaria"
              class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">
              Descripción
            </label>
            <input
              v-model="form.description"
              type="text"
              maxlength="255"
              placeholder="Opcional"
              class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
          </div>
          <div class="flex items-center gap-3">
            <button
              type="button"
              @click="form.is_active = !form.is_active"
              :class="form.is_active ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"
              class="relative inline-flex h-6 w-11 rounded-full transition-colors duration-200"
            >
              <span
                :class="form.is_active ? 'translate-x-5' : 'translate-x-1'"
                class="inline-block w-4 h-4 mt-1 bg-white rounded-full shadow transition-transform duration-200"
              />
            </button>
            <span class="text-sm text-gray-700 dark:text-gray-300">Activo</span>
          </div>

          <p v-if="formError" class="text-sm text-red-600 dark:text-red-400">{{ formError }}</p>

          <div class="flex gap-3 pt-2">
            <button
              type="submit"
              :disabled="saving"
              class="flex-1 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-medium transition"
            >
              {{ saving ? 'Guardando...' : (editingId ? 'Actualizar' : 'Crear') }}
            </button>
            <button
              type="button"
              @click="showModal = false"
              class="px-5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 py-2.5 rounded-xl transition"
            >
              Cancelar
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal Confirmar Eliminar -->
    <div
      v-if="deleteTarget"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
      @click.self="deleteTarget = null"
    >
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
        <div class="w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
          <v-icon name="md-delete" class="w-6 h-6 text-red-600 dark:text-red-400" />
        </div>
        <h3 class="font-bold text-gray-800 dark:text-white mb-2">¿Eliminar "{{ deleteTarget.name }}"?</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
          Esta acción no se puede deshacer. Los pagos existentes con esta forma de pago no se verán afectados.
        </p>
        <div class="flex gap-3">
          <button
            @click="executeDelete"
            :disabled="deleting"
            class="flex-1 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-medium transition"
          >
            {{ deleting ? 'Eliminando...' : 'Sí, eliminar' }}
          </button>
          <button
            @click="deleteTarget = null"
            class="flex-1 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 py-2.5 rounded-xl transition"
          >
            Cancelar
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { apiClient } from '@/services/api'

const methods  = ref([])
const loading  = ref(true)
const saving   = ref(false)
const deleting = ref(false)

const showModal   = ref(false)
const editingId   = ref(null)
const deleteTarget = ref(null)
const formError   = ref('')

const form = ref({ name: '', description: '', is_active: true })

const load = async () => {
  loading.value = true
  try {
    const { data } = await apiClient.get('/billing/payment-methods')
    methods.value = data
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const openCreate = () => {
  editingId.value = null
  form.value = { name: '', description: '', is_active: true }
  formError.value = ''
  showModal.value = true
}

const openEdit = (method) => {
  editingId.value = method.id
  form.value = { name: method.name, description: method.description || '', is_active: method.is_active }
  formError.value = ''
  showModal.value = true
}

const submitForm = async () => {
  saving.value = true
  formError.value = ''
  try {
    if (editingId.value) {
      const { data } = await apiClient.put(`/billing/payment-methods/${editingId.value}`, form.value)
      const i = methods.value.findIndex(m => m.id === editingId.value)
      if (i !== -1) methods.value[i] = data
    } else {
      const { data } = await apiClient.post('/billing/payment-methods', form.value)
      methods.value.push(data)
      methods.value.sort((a, b) => a.name.localeCompare(b.name))
    }
    showModal.value = false
  } catch (e) {
    formError.value = e.response?.data?.message || 'Error al guardar.'
  } finally {
    saving.value = false
  }
}

const toggleActive = async (method) => {
  try {
    const { data } = await apiClient.put(`/billing/payment-methods/${method.id}`, {
      is_active: !method.is_active,
    })
    const i = methods.value.findIndex(m => m.id === method.id)
    if (i !== -1) methods.value[i] = data
  } catch (e) {
    console.error(e)
  }
}

const confirmDelete = (method) => {
  deleteTarget.value = method
}

const executeDelete = async () => {
  deleting.value = true
  try {
    await apiClient.delete(`/billing/payment-methods/${deleteTarget.value.id}`)
    methods.value = methods.value.filter(m => m.id !== deleteTarget.value.id)
    deleteTarget.value = null
  } catch (e) {
    alert(e.response?.data?.message || 'Error al eliminar.')
  } finally {
    deleting.value = false
  }
}

onMounted(load)
</script>
