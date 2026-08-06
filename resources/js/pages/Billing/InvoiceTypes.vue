<template>
  <div class="min-h-screen bg-slate-50 dark:bg-gray-900 p-6 transition-colors duration-300">

    <!-- Header -->
    <PageHeader
      title="Tipos de factura"
      subtitle="Los tipos que usas al facturar: equipos, TV, reconexión, traslado…"
      icon="bi-tags"
    >
      <template #actions>
        <button
          @click="openCreate"
          class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl font-semibold text-white transition-all
                 bg-emerald-600 hover:bg-emerald-700 shadow-lg shadow-emerald-500/25 dark:shadow-none
                 hover:scale-[1.02] active:scale-[0.98] motion-reduce:hover:scale-100"
        >
          <v-icon name="md-add" class="w-5 h-5" />
          Nuevo tipo
        </button>
      </template>
    </PageHeader>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="w-8 h-8 border-4 border-emerald-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <div v-else class="space-y-8">
      <!-- Tipos del sistema -->
      <section>
        <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Del sistema</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
          Los usa la facturación automática, la instalación y los cargos de ticket, así que no se editan ni se eliminan.
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div
            v-for="type in systemTypes"
            :key="type.id ?? type.slug"
            class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl p-5"
          >
            <span class="px-3 py-1 rounded-full text-xs font-semibold" :class="colorClasses(type.color)">
              {{ type.name }}
            </span>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">{{ type.description || '—' }}</p>
            <p class="text-[11px] font-mono text-gray-400 mt-2">{{ type.slug }}</p>
          </div>
        </div>
      </section>

      <!-- Tipos propios -->
      <section>
        <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Tuyos</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div
            v-for="type in ownTypes"
            :key="type.id"
            class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-3xl p-5 flex flex-col gap-4 transition hover:shadow-md"
            :class="{ 'opacity-60': !type.is_active }"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <span class="px-3 py-1 rounded-full text-xs font-semibold" :class="colorClasses(type.color)">
                  {{ type.name }}
                </span>
                <p v-if="type.description" class="text-xs text-gray-500 dark:text-gray-400 mt-2">{{ type.description }}</p>
                <p class="text-[11px] font-mono text-gray-400 mt-2">{{ type.slug }}</p>
              </div>
              <span
                class="shrink-0 px-2.5 py-1 text-[11px] font-semibold rounded-full"
                :class="type.is_active
                  ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                  : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
              >
                {{ type.is_active ? 'Activo' : 'Inactivo' }}
              </span>
            </div>

            <div class="flex gap-2 pt-1 border-t border-gray-100 dark:border-gray-700">
              <button
                @click="openEdit(type)"
                class="flex-1 flex items-center justify-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 py-2 rounded-lg transition"
              >
                <v-icon name="md-edit" class="w-4 h-4" />
                Editar
              </button>
              <button
                @click="toggleActive(type)"
                class="flex-1 flex items-center justify-center gap-1.5 text-xs font-medium py-2 rounded-lg transition"
                :class="type.is_active
                  ? 'text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20'
                  : 'text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20'"
              >
                <v-icon :name="type.is_active ? 'md-pausecircle' : 'md-playcircle'" class="w-4 h-4" />
                {{ type.is_active ? 'Desactivar' : 'Activar' }}
              </button>
              <button
                @click="deleteTarget = type"
                class="flex items-center justify-center text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 px-3 py-2 rounded-lg transition"
              >
                <v-icon name="md-delete" class="w-4 h-4" />
              </button>
            </div>
          </div>

          <!-- Empty state -->
          <div
            v-if="ownTypes.length === 0"
            class="sm:col-span-2 lg:col-span-3 text-center py-16 bg-white dark:bg-gray-800 rounded-2xl border border-dashed border-gray-300 dark:border-gray-600"
          >
            <v-icon name="bi-tags" class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-3" />
            <p class="text-gray-500 dark:text-gray-400 font-medium">Todavía no has creado tipos propios</p>
            <button @click="openCreate" class="mt-4 text-sm text-emerald-600 dark:text-emerald-400 font-medium hover:underline">
              + Crear el primero
            </button>
          </div>
        </div>
      </section>
    </div>

    <!-- Modal Crear / Editar -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
      @click.self="showModal = false"
    >
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-5">
          {{ editingId ? 'Editar tipo de factura' : 'Nuevo tipo de factura' }}
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
              placeholder="Ej: Factura de Equipos"
              class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
            <p v-if="!editingId" class="text-[11px] text-gray-400 mt-1">
              Identificador que se guardará: <span class="font-mono">{{ previewSlug || '—' }}</span>
            </p>
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

          <div>
            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">
              Color de la etiqueta
            </label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="color in colors"
                :key="color"
                type="button"
                @click="form.color = color"
                class="px-3 py-1 rounded-full text-xs font-semibold border-2 transition"
                :class="[colorClasses(color), form.color === color ? 'border-gray-800 dark:border-white' : 'border-transparent']"
              >
                {{ form.name || 'Ejemplo' }}
              </button>
            </div>
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
            <span class="text-sm text-gray-700 dark:text-gray-300">Disponible al facturar</span>
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

    <!-- Confirmar eliminación -->
    <ConfirmModal
      :visible="!!deleteTarget"
      variant="danger"
      title="Eliminar tipo de factura"
      :message="deleteTarget
        ? `Se eliminará el tipo &quot;${deleteTarget.name}&quot;. Si ya lo usaste en alguna factura, desactívalo en lugar de eliminarlo.`
        : ''"
      confirm-text="Eliminar"
      loading-text="Eliminando..."
      :loading="deleting"
      @confirm="executeDelete"
      @cancel="deleteTarget = null"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import billingService from '@/services/billing'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { INVOICE_TYPE_COLORS, paletteClasses, loadInvoiceTypes } from '@/utils/invoiceType'

const types    = ref([])
const loading  = ref(true)
const saving   = ref(false)
const deleting = ref(false)

const showModal    = ref(false)
const editingId    = ref(null)
const deleteTarget = ref(null)
const formError    = ref('')

const colors = INVOICE_TYPE_COLORS
const form = ref({ name: '', description: '', color: 'blue', is_active: true })

const systemTypes = computed(() => types.value.filter(t => t.is_system))
const ownTypes    = computed(() => types.value.filter(t => !t.is_system))

// Espejo de InvoiceType::slugFromName() para que el operador vea qué se va a
// guardar antes de crear (el identificador ya no cambia después).
const previewSlug = computed(() =>
  form.value.name
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().trim()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 50)
)

// Mismo mapa de clases que pinta los listados: un tipo se ve igual aquí que en
// la tabla de facturas.
const colorClasses = paletteClasses

const load = async () => {
  loading.value = true
  try {
    const { data } = await billingService.getInvoiceTypes()
    types.value = data
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const openCreate = () => {
  editingId.value = null
  form.value = { name: '', description: '', color: 'blue', is_active: true }
  formError.value = ''
  showModal.value = true
}

const openEdit = (type) => {
  editingId.value = type.id
  form.value = {
    name: type.name,
    description: type.description || '',
    color: type.color || 'slate',
    is_active: type.is_active,
  }
  formError.value = ''
  showModal.value = true
}

const submitForm = async () => {
  saving.value = true
  formError.value = ''
  try {
    if (editingId.value) {
      const { data } = await billingService.updateInvoiceType(editingId.value, form.value)
      const i = types.value.findIndex(t => t.id === editingId.value)
      if (i !== -1) types.value[i] = data
    } else {
      const { data } = await billingService.createInvoiceType(form.value)
      types.value.push(data)
    }
    showModal.value = false
    // Refresca el catálogo compartido para que los selectores lo vean sin recargar.
    loadInvoiceTypes(true)
  } catch (e) {
    formError.value = e.response?.data?.message
      || Object.values(e.response?.data?.errors ?? {})[0]?.[0]
      || 'Error al guardar.'
  } finally {
    saving.value = false
  }
}

const toggleActive = async (type) => {
  try {
    const { data } = await billingService.updateInvoiceType(type.id, { is_active: !type.is_active })
    const i = types.value.findIndex(t => t.id === type.id)
    if (i !== -1) types.value[i] = data
    loadInvoiceTypes(true)
  } catch (e) {
    console.error(e)
  }
}

const executeDelete = async () => {
  deleting.value = true
  try {
    await billingService.deleteInvoiceType(deleteTarget.value.id)
    types.value = types.value.filter(t => t.id !== deleteTarget.value.id)
    deleteTarget.value = null
    loadInvoiceTypes(true)
  } catch (e) {
    alert(e.response?.data?.message || 'Error al eliminar.')
  } finally {
    deleting.value = false
  }
}

onMounted(load)
</script>
