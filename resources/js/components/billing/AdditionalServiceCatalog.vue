<template>
    <div>
        <!-- Barra de acción -->
        <div class="flex items-center justify-between gap-3 mb-5">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Los servicios que puedes asignar a varios clientes. Se cobran dentro de la factura mensual
                de cada uno, no en una factura aparte.
            </p>
            <button
                @click="openAddModal"
                class="shrink-0 inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl font-semibold text-white transition-all
                       bg-emerald-600 hover:bg-emerald-700 shadow-lg shadow-emerald-500/25 dark:shadow-none
                       hover:scale-[1.02] active:scale-[0.98] motion-reduce:hover:scale-100"
            >
                <v-icon name="md-add" class="w-5 h-5 fill-current" />
                <span>Nuevo servicio</span>
            </button>
        </div>

        <!-- Cargando -->
        <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="n in 6" :key="`sk-${n}`"
                class="h-44 rounded-3xl bg-white dark:bg-gray-800 border border-slate-100 dark:border-gray-700 animate-pulse"></div>
        </div>

        <!-- Vacío -->
        <div v-else-if="items.length === 0"
            class="text-center py-16 bg-white dark:bg-gray-800 rounded-3xl border border-dashed border-slate-300 dark:border-gray-600">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center">
                <v-icon name="bi-arrow-repeat" class="w-8 h-8 text-emerald-500" />
            </div>
            <p class="text-slate-600 dark:text-slate-300 font-medium">Todavía no hay servicios adicionales</p>
            <p class="text-sm text-slate-400 dark:text-slate-500 mt-1 max-w-md mx-auto">
                Crea el primero — por ejemplo "Alquiler de router extra" — y podrás asignárselo
                a los clientes que lo tengan.
            </p>
            <button @click="openAddModal"
                class="mt-4 text-sm font-medium text-emerald-600 dark:text-emerald-400 hover:underline">
                Crear servicio
            </button>
        </div>

        <!-- Grid de tarjetas -->
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div
                v-for="item in items"
                :key="item.id"
                class="group relative overflow-hidden bg-white dark:bg-gray-800 border border-slate-200 dark:border-gray-700 rounded-3xl p-5 transition-all hover:shadow-md hover:border-emerald-200 dark:hover:border-emerald-800/60"
                :class="{ 'opacity-60': !item.is_active }"
            >
                <div class="absolute -right-6 -top-6 w-20 h-20 rounded-full bg-emerald-50 dark:bg-emerald-900/20 transition-transform duration-500 group-hover:scale-110 motion-reduce:transition-none motion-reduce:group-hover:scale-100"></div>

                <div class="relative">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 shrink-0 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                                <v-icon name="bi-arrow-repeat" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-800 dark:text-white truncate">{{ item.name }}</p>
                                <p v-if="item.description" class="text-xs text-slate-400 dark:text-slate-500 truncate">
                                    {{ item.description }}
                                </p>
                            </div>
                        </div>
                        <span v-if="!item.is_active"
                            class="shrink-0 text-[10px] font-semibold uppercase tracking-wide px-2 py-1 rounded-lg bg-slate-100 text-slate-500 dark:bg-gray-700 dark:text-slate-400">
                            Inactivo
                        </span>
                    </div>

                    <!-- Precio y alcance -->
                    <div class="flex items-end justify-between gap-3 mt-4">
                        <div>
                            <p class="text-xl font-bold text-slate-800 dark:text-white tabular-nums">
                                {{ formatCurrency(item.price) }}
                            </p>
                            <p class="text-[11px] text-slate-400 dark:text-slate-500">al mes, por cliente</p>
                        </div>
                        <div class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                            <v-icon name="bi-people" class="w-4 h-4" />
                            <span class="tabular-nums">{{ item.active_assignments_count ?? 0 }}</span>
                        </div>
                    </div>

                    <!-- Reglas de cobro -->
                    <div class="flex flex-wrap gap-1.5 mt-3">
                        <span class="text-[10px] font-medium px-2 py-1 rounded-lg bg-slate-100 text-slate-600 dark:bg-gray-700 dark:text-slate-300"
                            :title="PRORATION_HELP[item.proration_mode]">
                            {{ PRORATION_LABELS[item.proration_mode] || item.proration_mode }}
                        </span>
                        <span
                            class="text-[10px] font-medium px-2 py-1 rounded-lg"
                            :class="item.charge_on_courtesy_month
                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400'
                                : 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400'"
                            :title="item.charge_on_courtesy_month
                                ? 'Se cobra igual en los meses de cortesía por instalación'
                                : 'No se cobra durante los meses de cortesía por instalación'"
                        >
                            {{ item.charge_on_courtesy_month ? 'Cobra en cortesía' : 'Gratis en cortesía' }}
                        </span>
                    </div>
                </div>

                <div class="relative flex gap-2 mt-4 pt-3 border-t border-slate-100 dark:border-gray-700">
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
                        title="Eliminar servicio"
                    >
                        <v-icon name="md-delete" class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal alta / edición -->
        <div v-if="showFormModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeFormModal">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden max-h-[92vh] flex flex-col">
                <div class="bg-gradient-to-br from-emerald-500 to-teal-600 p-5 text-white shrink-0">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">{{ isEditing ? 'Editar servicio' : 'Nuevo servicio adicional' }}</h3>
                        <button @click="closeFormModal" class="p-1 hover:bg-white/20 rounded-lg transition-colors">
                            <v-icon name="md-close" class="w-5 h-5" />
                        </button>
                    </div>
                </div>

                <form @submit.prevent="handleSave" class="p-4 md:p-6 space-y-5 overflow-y-auto">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nombre *</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            maxlength="120"
                            placeholder="Ej. Alquiler de router extra, Soporte técnico mensual..."
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                                   bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                                   focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all outline-none"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Descripción</label>
                        <input
                            v-model="form.description"
                            type="text"
                            maxlength="255"
                            placeholder="Opcional — para que el equipo sepa de qué se trata"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                                   bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                                   focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all outline-none"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Precio mensual *</label>
                        <input
                            v-model.number="form.price"
                            type="number"
                            required
                            min="0"
                            step="1"
                            onwheel="this.blur()"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                                   bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 tabular-nums
                                   focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all outline-none"
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            = {{ formatCurrency(form.price) }} · Es el precio de lista; al asignarlo puedes dejarle
                            otro precio a un cliente concreto.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Cobro del primer mes
                        </label>
                        <select
                            v-model="form.proration_mode"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                                   bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                                   focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all outline-none"
                        >
                            <option v-for="(label, mode) in PRORATION_LABELS" :key="mode" :value="mode">{{ label }}</option>
                        </select>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ PRORATION_HELP[form.proration_mode] }}
                        </p>
                    </div>

                    <!-- Reglas de cobro -->
                    <div class="space-y-3 pt-1">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input
                                v-model="form.charge_on_courtesy_month"
                                type="checkbox"
                                class="mt-0.5 w-4 h-4 shrink-0 rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500"
                            />
                            <span class="text-sm">
                                <span class="font-medium text-gray-700 dark:text-gray-300">Cobrar en meses de cortesía</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    Si el cliente está en un mes de cortesía por instalación, este servicio se cobra
                                    igual. La promoción suele cubrir el internet, no los equipos.
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="mt-0.5 w-4 h-4 shrink-0 rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500"
                            />
                            <span class="text-sm">
                                <span class="font-medium text-gray-700 dark:text-gray-300">Activo</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    Un servicio inactivo deja de ofrecerse al asignar, pero conserva su historial.
                                </span>
                            </span>
                        </label>
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

        <!-- Modal de borrado -->
        <div
            v-if="showDeleteModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
            @click.self="closeDeleteModal"
        >
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                        <v-icon name="md-delete" class="w-6 h-6 text-red-600" />
                        Eliminar servicio
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
                                <strong>"{{ itemToDelete?.name }}"</strong> se eliminará del catálogo.
                                Si ya está asignado a algún cliente, el sistema no dejará borrarlo — tendrás que
                                desactivarlo para conservar el historial de facturación.
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
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import additionalServiceApi from '@/services/api/additional-service'

// Sin guardas de permiso en el front, igual que Tipos de factura y Formas de
// pago: todo el módulo va bajo `view_billing`, así que llegar a esta pantalla
// ya implica poder escribir en ella. (No existe un `edit_billing`.)
const emit = defineEmits(['notify'])

/**
 * Mismo vocabulario que la política de primera factura de los planes
 * (Billing::FIRST_INVOICE_MODES). Las claves son las del backend; los textos,
 * lo que el operador necesita para decidir sin abrir la documentación.
 */
const PRORATION_LABELS = {
    full:     'Mes completo',
    prorated: 'Proporcional a los días',
    none:     'No cobrar el primer mes',
}

const PRORATION_HELP = {
    full:     'Si se activa el 20, se cobra el mes entero. Lo habitual cuando ya entregaste el equipo.',
    prorated: 'Si se activa el 20 de un mes de 30, se cobran los 10 días restantes.',
    none:     'El primer mes no se cobra; el servicio empieza a facturarse en el ciclo siguiente.',
}

const loading = ref(false)
const saving = ref(false)
const items = ref([])

const showFormModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const itemToDelete = ref(null)

const emptyForm = () => ({
    name: '',
    description: '',
    price: 0,
    proration_mode: 'full',
    charge_on_courtesy_month: true,
    is_active: true,
})

const form = ref(emptyForm())

const formatCurrency = (val) => {
    const n = parseFloat(val) || 0
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

const loadItems = async () => {
    loading.value = true
    try {
        const { data } = await additionalServiceApi.getAll()
        items.value = data || []
    } catch (error) {
        console.error('Error loading additional services:', error)
        emit('notify', { type: 'error', title: 'Error', message: 'No se pudieron cargar los servicios adicionales.' })
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
        description: item.description || '',
        price: parseFloat(item.price) || 0,
        proration_mode: item.proration_mode || 'full',
        charge_on_courtesy_month: !!item.charge_on_courtesy_month,
        is_active: !!item.is_active,
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
        const payload = { ...form.value, description: form.value.description || null }

        if (isEditing.value) {
            await additionalServiceApi.update(editingId.value, payload)
            emit('notify', { type: 'success', title: 'Actualizado', message: 'Servicio adicional actualizado.' })
        } else {
            await additionalServiceApi.create(payload)
            emit('notify', { type: 'success', title: 'Creado', message: 'Servicio adicional creado.' })
        }

        closeFormModal()
        await loadItems()
    } catch (error) {
        // El backend explica el porqué (nombre repetido, modo inválido...):
        // mostrar su mensaje es más útil que un "no se pudo guardar" genérico.
        const message = error.response?.data?.message || 'No se pudo guardar el servicio.'
        emit('notify', { type: 'error', title: 'Error', message })
    } finally {
        saving.value = false
    }
}

const deleteItem = async () => {
    if (!itemToDelete.value) return
    saving.value = true
    try {
        await additionalServiceApi.delete(itemToDelete.value.id)
        emit('notify', { type: 'success', title: 'Eliminado', message: 'Servicio adicional eliminado.' })
        closeDeleteModal()
        await loadItems()
    } catch (error) {
        // Caso esperado: el servicio ya está asignado. El backend responde 422
        // con la salida (desactivarlo), así que se muestra tal cual.
        const message = error.response?.data?.message || 'No se pudo eliminar el servicio.'
        emit('notify', { type: 'error', title: 'No se puede eliminar', message })
        closeDeleteModal()
    } finally {
        saving.value = false
    }
}

onMounted(loadItems)
</script>
