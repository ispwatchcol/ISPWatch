<template>
    <div class="flex min-h-screen bg-slate-50 dark:bg-gray-900 transition-colors duration-300">
        <main class="flex-1 p-6">

            <!-- Header -->
            <PageHeader
                title="Categorías de gasto"
                subtitle="Los conceptos con los que clasificas cada gasto."
                icon="bi-tags"
            >
                <template #actions>
                    <button
                        v-if="can('add_expense')"
                        @click="openAddModal"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl font-semibold text-white transition-all
                               bg-emerald-600 hover:bg-emerald-700 shadow-lg shadow-emerald-500/25 dark:shadow-none
                               hover:scale-[1.02] active:scale-[0.98] motion-reduce:hover:scale-100"
                    >
                        <v-icon name="md-add" class="w-5 h-5 fill-current" />
                        <span>Nueva categoría</span>
                    </button>
                </template>
            </PageHeader>

            <!-- Cargando -->
            <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="n in 6" :key="`sk-${n}`"
                    class="h-28 rounded-3xl bg-white dark:bg-gray-800 border border-slate-100 dark:border-gray-700 animate-pulse"></div>
            </div>

            <!-- Vacío -->
            <div v-else-if="items.length === 0"
                class="text-center py-16 bg-white dark:bg-gray-800 rounded-3xl border border-dashed border-slate-300 dark:border-gray-600">
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center">
                    <v-icon name="bi-tags" class="w-8 h-8 text-emerald-500" />
                </div>
                <p class="text-slate-600 dark:text-slate-300 font-medium">Todavía no hay categorías</p>
                <p class="text-sm text-slate-400 dark:text-slate-500 mt-1">
                    Crea la primera para empezar a clasificar los gastos.
                </p>
                <button v-if="can('add_expense')" @click="openAddModal"
                    class="mt-4 text-sm font-medium text-emerald-600 dark:text-emerald-400 hover:underline">
                    Crear categoría
                </button>
            </div>

            <!-- Grid de tarjetas -->
            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                    v-for="item in items"
                    :key="item.id"
                    class="group relative overflow-hidden bg-white dark:bg-gray-800 border border-slate-200 dark:border-gray-700 rounded-3xl p-5 transition-all hover:shadow-md hover:border-emerald-200 dark:hover:border-emerald-800/60"
                >
                    <div class="absolute -right-6 -top-6 w-20 h-20 rounded-full bg-emerald-50 dark:bg-emerald-900/20 transition-transform duration-500 group-hover:scale-110 motion-reduce:transition-none motion-reduce:group-hover:scale-100"></div>

                    <div class="relative flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 shrink-0 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                                <v-icon name="bi-tags" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <p class="font-semibold text-slate-800 dark:text-white truncate">{{ item.name }}</p>
                        </div>
                    </div>

                    <div v-if="can('edit_expense')" class="relative flex gap-2 mt-4 pt-3 border-t border-slate-100 dark:border-gray-700">
                        <button
                            @click="openEditModal(item)"
                            class="flex-1 flex items-center justify-center gap-1.5 text-xs font-medium py-2 rounded-lg transition
                                   text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20"
                        >
                            <v-icon name="md-edit" class="w-4 h-4" />
                            Editar
                        </button>
                        <button
                            @click="confirmDelete(item)"
                            class="flex items-center justify-center gap-1.5 text-xs font-medium px-3 py-2 rounded-lg transition
                                   text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-900/20"
                            title="Eliminar categoría"
                        >
                            <v-icon name="md-delete" class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Add/Edit Modal -->
            <div v-if="showFormModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeFormModal">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
                    <div class="bg-gradient-to-br from-emerald-500 to-teal-600 p-5 text-white">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold">{{ isEditing ? 'Editar Categoría' : 'Nueva Categoría' }}</h3>
                            <button @click="closeFormModal" class="p-1 hover:bg-white/20 rounded-lg transition-colors">
                                <v-icon name="md-close" class="w-5 h-5" />
                            </button>
                        </div>
                    </div>

                    <form @submit.prevent="handleSave" class="p-4 md:p-6 space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nombre *</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                maxlength="100"
                                placeholder="Ej. Arriendo, Servicios públicos, Combustible..."
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all outline-none"
                            />
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
                                class="flex-1 py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl
                       transition-all font-medium shadow-lg shadow-emerald-500/25 disabled:opacity-50"
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
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                            <v-icon name="md-delete" class="w-6 h-6 text-red-600" />
                            Eliminar Categoría
                        </h2>
                        <button @click="closeDeleteModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                            <v-icon name="md-close" class="w-6 h-6" />
                        </button>
                    </div>

                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                        <div class="flex items-start gap-3">
                            <v-icon name="md-warning-round" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                            <div>
                                <h4 class="font-medium text-red-800 dark:text-red-300">¿Estás seguro?</h4>
                                <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                                    La categoría <strong>"{{ itemToDelete?.name }}"</strong> será eliminada. Los gastos que ya la usan quedarán sin categoría.
                                </p>
                            </div>
                        </div>
                    </div>

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
import { ref, onMounted } from 'vue'
import expenseCategoryApi from '@/services/api/expense-category'
import NotificationToast from '@/components/NotificationToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()

const toast = ref(null)
const loading = ref(false)
const saving = ref(false)
const items = ref([])

const showFormModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const itemToDelete = ref(null)

const form = ref({ name: '' })

const loadItems = async () => {
    loading.value = true
    try {
        const { data } = await expenseCategoryApi.getAll()
        items.value = data || []
    } catch (error) {
        console.error('Error loading expense categories:', error)
        toast.value?.error('Error', 'No se pudieron cargar las categorías')
    } finally {
        loading.value = false
    }
}

const openAddModal = () => {
    isEditing.value = false
    editingId.value = null
    form.value = { name: '' }
    showFormModal.value = true
}

const openEditModal = (item) => {
    isEditing.value = true
    editingId.value = item.id
    form.value = { name: item.name || '' }
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
        const payload = { name: form.value.name }

        if (isEditing.value) {
            await expenseCategoryApi.update(editingId.value, payload)
            toast.value?.success('Actualizada', 'Categoría actualizada correctamente')
        } else {
            await expenseCategoryApi.create(payload)
            toast.value?.success('Creada', 'Nueva categoría agregada correctamente')
        }

        closeFormModal()
        await loadItems()
    } catch (error) {
        console.error('Error saving expense category:', error)
        toast.value?.error('Error', 'No se pudo guardar: ' + error.message)
    } finally {
        saving.value = false
    }
}

const deleteItem = async () => {
    if (!itemToDelete.value) return
    saving.value = true
    try {
        await expenseCategoryApi.delete(itemToDelete.value.id)
        toast.value?.success('Eliminada', 'Categoría eliminada correctamente')
        closeDeleteModal()
        await loadItems()
    } catch (error) {
        console.error('Error deleting expense category:', error)
        toast.value?.error('Error', 'No se pudo eliminar: ' + error.message)
    } finally {
        saving.value = false
    }
}

onMounted(() => {
    loadItems()
})
</script>
