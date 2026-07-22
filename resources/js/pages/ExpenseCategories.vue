<template>
    <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
        <main class="flex-1 p-4 md:p-8">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
                <div>
                    <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
                        <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-xl">
                            <v-icon name="bi-tags" class="text-blue-600 dark:text-blue-400 w-6 h-6 md:w-7 md:h-7" />
                        </div>
                        Categorías de Gasto
                    </h1>
                    <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
                        Conceptos usados para clasificar los gastos de la empresa
                    </p>
                </div>

                <button
                    v-if="can('add_expense')"
                    @click="openAddModal"
                    class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800
                 text-white px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg hover:shadow-xl
                 transition-all transform hover:-translate-y-0.5
                 font-medium w-full sm:w-auto justify-center"
                >
                    <v-icon name="md-add" class="w-5 h-5 fill-current" />
                    <span>Nueva Categoría</span>
                </button>
            </div>

            <!-- List -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
                <div v-if="loading" class="flex items-center justify-center py-16">
                    <v-icon name="ri-loader-4-line" animation="spin" class="w-8 h-8 text-blue-500" />
                    <span class="ml-3 text-gray-500 dark:text-gray-400">Cargando categorías...</span>
                </div>

                <div v-else-if="items.length === 0" class="text-center py-16">
                    <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
                        <v-icon name="bi-tags" class="w-10 h-10 text-gray-400" />
                    </div>
                    <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">Sin categorías registradas</p>
                    <p class="text-gray-400 dark:text-gray-500 text-sm mt-2">Agrega tu primera categoría de gasto</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-100 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Nombre</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-sm font-bold text-blue-600 dark:text-blue-400">#{{ item.id }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ item.name }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <button
                                            v-if="can('edit_expense')"
                                            @click="openEditModal(item)"
                                            class="p-2 text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20 rounded-lg transition-all hover:scale-110"
                                            title="Editar"
                                        >
                                            <v-icon name="fa-edit" class="w-4 h-4" />
                                        </button>
                                        <button
                                            v-if="can('edit_expense')"
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

            <!-- Add/Edit Modal -->
            <div v-if="showFormModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeFormModal">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-5 text-white">
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
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
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
