<template>
    <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
        <main class="flex-1 p-4 md:p-8">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
                <div>
                    <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
                        <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-xl">
                            <v-icon name="bi-cash-coin" class="text-blue-600 dark:text-blue-400 w-6 h-6 md:w-7 md:h-7" />
                        </div>
                        Gastos
                    </h1>
                    <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
                        Registro de gastos de la empresa
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
                    <span>Nuevo Gasto</span>
                </button>
            </div>

            <!-- Filters -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Desde</label>
                        <input
                            v-model="filters.date_from"
                            type="date"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                     bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                     focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Hasta</label>
                        <input
                            v-model="filters.date_to"
                            type="date"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                     bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                     focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Categoría</label>
                        <select
                            v-model="filters.expense_category_id"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                     bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                     focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none"
                        >
                            <option value="">Todas</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Estado</label>
                        <select
                            v-model="filters.status"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                     bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                     focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none"
                        >
                            <option value="">Todos</option>
                            <option value="activo">Activo</option>
                            <option value="anulado">Anulado</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Summary -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
                        Total del período filtrado
                    </p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ formatMoney(totalFiltered) }}</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ activeItems.length }} gasto(s) activo(s)</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                        Por categoría
                    </p>
                    <div v-if="categoryBreakdown.length === 0" class="text-sm text-gray-400 dark:text-gray-500">
                        Sin datos para el período seleccionado
                    </div>
                    <ul v-else class="space-y-1 max-h-24 overflow-y-auto">
                        <li v-for="row in categoryBreakdown" :key="row.name" class="flex justify-between text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ row.name }}</span>
                            <span class="font-medium text-gray-900 dark:text-white">{{ formatMoney(row.total) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Desktop Table View -->
            <div class="hidden lg:block bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
                <div v-if="loading" class="flex items-center justify-center py-16">
                    <v-icon name="ri-loader-4-line" animation="spin" class="w-8 h-8 text-blue-500" />
                    <span class="ml-3 text-gray-500 dark:text-gray-400">Cargando gastos...</span>
                </div>

                <div v-else-if="items.length === 0" class="text-center py-16">
                    <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
                        <v-icon name="bi-cash-coin" class="w-10 h-10 text-gray-400" />
                    </div>
                    <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">Sin gastos registrados</p>
                    <p class="text-gray-400 dark:text-gray-500 text-sm mt-2">Ajusta los filtros o agrega el primer gasto</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-100 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Fecha</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Categoría</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Descripción</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">A nombre de</th>
                                <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Monto</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Estado</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">{{ item.expense_date }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{{ item.category?.name || '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{{ item.description || '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{{ item.beneficiary?.name || '—' }}</td>
                                <td class="px-6 py-4 text-sm text-right font-medium text-gray-900 dark:text-white">{{ formatMoney(item.amount) }}</td>
                                <td class="px-6 py-4">
                                    <span
                                        class="px-2.5 py-1 rounded-full text-xs font-medium"
                                        :class="item.status === 'anulado'
                                            ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                            : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'"
                                    >
                                        {{ item.status === 'anulado' ? 'Anulado' : 'Activo' }}
                                    </span>
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
                                            v-if="can('edit_expense') && item.status !== 'anulado'"
                                            @click="confirmVoid(item)"
                                            class="p-2 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20 rounded-lg transition-all hover:scale-110"
                                            title="Anular"
                                        >
                                            <v-icon name="md-cancel-outline" class="w-4 h-4" />
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
                <div v-if="loading" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center">
                    <v-icon name="ri-loader-4-line" animation="spin" class="w-8 h-8 text-blue-500 mx-auto" />
                    <span class="block mt-3 text-gray-500 dark:text-gray-400">Cargando gastos...</span>
                </div>

                <template v-else-if="items.length > 0">
                    <div v-for="item in items" :key="item.id" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white">{{ item.category?.name || 'Sin categoría' }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ item.expense_date }}</p>
                            </div>
                            <span
                                class="px-2.5 py-1 rounded-full text-xs font-medium"
                                :class="item.status === 'anulado'
                                    ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                    : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'"
                            >
                                {{ item.status === 'anulado' ? 'Anulado' : 'Activo' }}
                            </span>
                        </div>
                        <p class="text-lg font-bold text-gray-900 dark:text-white mb-2">{{ formatMoney(item.amount) }}</p>
                        <p v-if="item.description" class="text-sm text-gray-600 dark:text-gray-300 mb-1">{{ item.description }}</p>
                        <p v-if="item.beneficiary" class="text-xs text-gray-500 dark:text-gray-400 mb-3">A nombre de: {{ item.beneficiary.name }}</p>

                        <div class="grid grid-cols-2 gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
                            <button
                                v-if="can('edit_expense')"
                                @click="openEditModal(item)"
                                class="py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                       transition-colors text-sm font-medium flex items-center justify-center gap-1"
                            >
                                <v-icon name="fa-edit" class="w-4 h-4" />
                                Editar
                            </button>
                            <button
                                v-if="can('edit_expense') && item.status !== 'anulado'"
                                @click="confirmVoid(item)"
                                class="py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg
                       transition-colors text-sm font-medium flex items-center justify-center gap-1"
                            >
                                <v-icon name="md-cancel-outline" class="w-4 h-4" />
                                Anular
                            </button>
                        </div>
                    </div>
                </template>

                <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center">
                    <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">Sin gastos registrados</p>
                </div>
            </div>

            <!-- Add/Edit Modal -->
            <div v-if="showFormModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeFormModal">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden max-h-[90vh] overflow-y-auto">
                    <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-5 text-white sticky top-0 z-10">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold">{{ isEditing ? 'Editar Gasto' : 'Nuevo Gasto' }}</h3>
                            <button @click="closeFormModal" class="p-1 hover:bg-white/20 rounded-lg transition-colors">
                                <v-icon name="md-close" class="w-5 h-5" />
                            </button>
                        </div>
                    </div>

                    <form @submit.prevent="handleSave" class="p-4 md:p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Fecha *</label>
                                <input
                                    v-model="form.expense_date"
                                    type="date"
                                    required
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                         focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Monto *</label>
                                <input
                                    v-model="form.amount"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    required
                                    placeholder="0.00"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                         focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Categoría / Concepto</label>
                                <select
                                    v-model="form.expense_category_id"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                         focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                                >
                                    <option value="">Sin categoría</option>
                                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">A nombre de quién</label>
                                <select
                                    v-model="form.user_id"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                         focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                                >
                                    <option value="">Ninguno (gasto general)</option>
                                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Descripción</label>
                                <input
                                    v-model="form.description"
                                    type="text"
                                    maxlength="255"
                                    placeholder="Descripción breve del gasto..."
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                         focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Observaciones</label>
                                <textarea
                                    v-model="form.notes"
                                    rows="3"
                                    placeholder="Observaciones adicionales (opcional)..."
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                         focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                                ></textarea>
                            </div>
                            <div v-if="isEditing">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Estado</label>
                                <select
                                    v-model="form.status"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                         focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none"
                                >
                                    <option value="activo">Activo</option>
                                    <option value="anulado">Anulado</option>
                                </select>
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

            <!-- Void Confirmation Modal -->
            <div
                v-if="showVoidModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
                @click.self="closeVoidModal"
            >
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                            <v-icon name="md-cancel-outline" class="w-6 h-6 text-red-600" />
                            Anular Gasto
                        </h2>
                        <button @click="closeVoidModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                            <v-icon name="md-close" class="w-6 h-6" />
                        </button>
                    </div>

                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                        <div class="flex items-start gap-3">
                            <v-icon name="md-warning-round" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                            <div>
                                <h4 class="font-medium text-red-800 dark:text-red-300">¿Confirmas anular este gasto?</h4>
                                <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                                    El gasto de <strong>{{ formatMoney(itemToVoid?.amount) }}</strong> quedará marcado como anulado y no se contará en el total del informe.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <button
                            @click="closeVoidModal"
                            class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                            :disabled="saving"
                        >
                            Cancelar
                        </button>
                        <button
                            @click="voidItem"
                            :disabled="saving"
                            class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
                        >
                            <v-icon v-if="saving" name="ri-loader-4-line" animation="spin" class="w-4 h-4" />
                            <v-icon v-else name="md-cancel-outline" class="w-4 h-4" />
                            {{ saving ? 'Anulando...' : 'Anular' }}
                        </button>
                    </div>
                </div>
            </div>

            <NotificationToast ref="toast" />

        </main>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import expenseApi from '@/services/api/expense'
import expenseCategoryApi from '@/services/api/expense-category'
import catalogsApi from '@/services/api/catalogs'
import NotificationToast from '@/components/NotificationToast.vue'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()

const toast = ref(null)
const loading = ref(false)
const saving = ref(false)
const items = ref([])
const categories = ref([])
const users = ref([])

const filters = ref({
    date_from: '',
    date_to: '',
    expense_category_id: '',
    status: '',
})

const showFormModal = ref(false)
const showVoidModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const itemToVoid = ref(null)

const emptyForm = () => ({
    expense_date: new Date().toISOString().slice(0, 10),
    amount: '',
    expense_category_id: '',
    user_id: '',
    description: '',
    notes: '',
    status: 'activo',
})

const form = ref(emptyForm())

const activeItems = computed(() => items.value.filter(i => i.status !== 'anulado'))

const totalFiltered = computed(() =>
    activeItems.value.reduce((sum, i) => sum + Number(i.amount || 0), 0)
)

const categoryBreakdown = computed(() => {
    const map = {}
    activeItems.value.forEach(i => {
        const name = i.category?.name || 'Sin categoría'
        map[name] = (map[name] || 0) + Number(i.amount || 0)
    })
    return Object.entries(map)
        .map(([name, total]) => ({ name, total }))
        .sort((a, b) => b.total - a.total)
})

const formatMoney = (value) => {
    const num = Number(value || 0)
    return num.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

const loadItems = async () => {
    loading.value = true
    try {
        const params = {}
        if (filters.value.date_from) params.date_from = filters.value.date_from
        if (filters.value.date_to) params.date_to = filters.value.date_to
        if (filters.value.expense_category_id) params.expense_category_id = filters.value.expense_category_id
        if (filters.value.status) params.status = filters.value.status

        const { data } = await expenseApi.getAll(params)
        items.value = data || []
    } catch (error) {
        console.error('Error loading expenses:', error)
        toast.value?.error('Error', 'No se pudieron cargar los gastos')
    } finally {
        loading.value = false
    }
}

const loadCategories = async () => {
    try {
        const { data } = await expenseCategoryApi.getAll()
        categories.value = data || []
    } catch (error) {
        console.error('Error loading expense categories:', error)
    }
}

const loadUsers = async () => {
    try {
        const { data } = await catalogsApi.getUsers()
        users.value = data || []
    } catch (error) {
        console.error('Error loading users catalog:', error)
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
        expense_date: item.expense_date,
        amount: item.amount,
        expense_category_id: item.expense_category_id || '',
        user_id: item.user_id || '',
        description: item.description || '',
        notes: item.notes || '',
        status: item.status || 'activo',
    }
    showFormModal.value = true
}

const closeFormModal = () => {
    showFormModal.value = false
    isEditing.value = false
    editingId.value = null
}

const confirmVoid = (item) => {
    itemToVoid.value = item
    showVoidModal.value = true
}

const closeVoidModal = () => {
    showVoidModal.value = false
    itemToVoid.value = null
}

const handleSave = async () => {
    saving.value = true
    try {
        const payload = {
            expense_date: form.value.expense_date,
            amount: form.value.amount,
            expense_category_id: form.value.expense_category_id || null,
            user_id: form.value.user_id || null,
            description: form.value.description || null,
            notes: form.value.notes || null,
        }

        if (isEditing.value) {
            payload.status = form.value.status
            await expenseApi.update(editingId.value, payload)
            toast.value?.success('Actualizado', 'Gasto actualizado correctamente')
        } else {
            await expenseApi.create(payload)
            toast.value?.success('Creado', 'Nuevo gasto registrado correctamente')
        }

        closeFormModal()
        await loadItems()
    } catch (error) {
        console.error('Error saving expense:', error)
        toast.value?.error('Error', 'No se pudo guardar: ' + error.message)
    } finally {
        saving.value = false
    }
}

const voidItem = async () => {
    if (!itemToVoid.value) return
    saving.value = true
    try {
        await expenseApi.update(itemToVoid.value.id, {
            expense_date: itemToVoid.value.expense_date,
            amount: itemToVoid.value.amount,
            expense_category_id: itemToVoid.value.expense_category_id || null,
            user_id: itemToVoid.value.user_id || null,
            description: itemToVoid.value.description || null,
            notes: itemToVoid.value.notes || null,
            status: 'anulado',
        })
        toast.value?.success('Anulado', 'Gasto anulado correctamente')
        closeVoidModal()
        await loadItems()
    } catch (error) {
        console.error('Error voiding expense:', error)
        toast.value?.error('Error', 'No se pudo anular: ' + error.message)
    } finally {
        saving.value = false
    }
}

watch(filters, () => {
    loadItems()
}, { deep: true })

onMounted(() => {
    loadItems()
    loadCategories()
    loadUsers()
})
</script>
