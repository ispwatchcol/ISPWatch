<template>
    <div>
        <!-- Encabezado -->
        <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
            <div>
                <h3 class="text-lg font-bold text-gray-800 dark:text-white flex items-center gap-2">
                    <v-icon name="bi-arrow-repeat" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                    Servicios adicionales
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    Se suman a la factura mensual del cliente. No generan factura aparte.
                </p>
            </div>
            <button
                @click="openAddModal"
                class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl font-semibold text-white transition-all
                       bg-emerald-600 hover:bg-emerald-700 shadow-lg shadow-emerald-500/25 dark:shadow-none
                       hover:scale-[1.02] active:scale-[0.98] motion-reduce:hover:scale-100"
            >
                <v-icon name="md-add" class="w-5 h-5 fill-current" />
                Asignar servicio
            </button>
        </div>

        <!-- Cargando -->
        <div v-if="loading" class="space-y-3">
            <div v-for="n in 3" :key="`sk-${n}`"
                class="h-20 rounded-2xl bg-slate-100 dark:bg-gray-700/40 animate-pulse"></div>
        </div>

        <!-- Vacío -->
        <div v-else-if="items.length === 0"
            class="text-center py-10 rounded-2xl border border-dashed border-slate-300 dark:border-gray-600">
            <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center">
                <v-icon name="bi-arrow-repeat" class="w-7 h-7 text-emerald-500" />
            </div>
            <p class="text-slate-600 dark:text-slate-300 font-medium">Este cliente no tiene servicios adicionales</p>
            <p class="text-sm text-slate-400 dark:text-slate-500 mt-1">
                Sólo paga su plan mensual.
            </p>
        </div>

        <template v-else>
            <!-- Total recurrente -->
            <div class="flex items-center justify-between gap-3 mb-4 px-4 py-3 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20">
                <span class="text-sm font-medium text-emerald-800 dark:text-emerald-300">
                    Se suma cada mes a su factura
                </span>
                <span class="text-lg font-bold text-emerald-700 dark:text-emerald-400 tabular-nums">
                    {{ formatCurrency(totalMensual) }}
                </span>
            </div>

            <!-- Asignaciones -->
            <div class="space-y-3">
                <div
                    v-for="item in items"
                    :key="item.id"
                    class="rounded-2xl border border-slate-200 dark:border-gray-700 p-4 transition-colors"
                    :class="item.is_active ? 'bg-white dark:bg-gray-900' : 'bg-slate-50 dark:bg-gray-900/40 opacity-70'"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-semibold text-slate-800 dark:text-white">
                                    {{ item.service?.name || 'Servicio eliminado' }}
                                </p>
                                <span v-if="!item.is_active"
                                    class="text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-lg bg-slate-200 text-slate-600 dark:bg-gray-700 dark:text-slate-400">
                                    Dado de baja
                                </span>
                                <span v-if="item.pending_billing"
                                    class="text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-lg bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                                    title="Este cliente ya recibió su factura del mes, pero este servicio no aparece en ella">
                                    Sin cobrar este mes
                                </span>
                                <span v-if="item.price !== null && item.price !== undefined"
                                    class="text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"
                                    title="Este cliente tiene un precio propio: no cambia si cambia el del catálogo">
                                    Precio propio
                                </span>
                            </div>

                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                Desde el {{ formatDate(item.starts_at) }}
                                <span v-if="item.ends_at"> · hasta el {{ formatDate(item.ends_at) }}</span>
                                <span v-if="item.assigner?.name"> · lo activó {{ item.assigner.name }}</span>
                            </p>
                            <p v-if="item.notes" class="text-xs text-slate-400 dark:text-slate-500 mt-1 italic">
                                {{ item.notes }}
                            </p>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="font-bold text-slate-800 dark:text-white tabular-nums">
                                {{ formatCurrency(lineTotal(item)) }}
                            </p>
                            <p v-if="item.quantity > 1" class="text-xs text-slate-400 dark:text-slate-500 tabular-nums">
                                {{ item.quantity }} × {{ formatCurrency(item.effective_price) }}
                            </p>
                            <p v-else class="text-xs text-slate-400 dark:text-slate-500">al mes</p>
                        </div>
                    </div>

                    <div class="flex gap-2 mt-3 pt-3 border-t border-slate-100 dark:border-gray-700">
                        <button
                            @click="openEditModal(item)"
                            class="flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg transition
                                   text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20"
                        >
                            <v-icon name="md-edit" class="w-4 h-4" />
                            Editar
                        </button>
                        <button
                            v-if="item.is_active"
                            @click="toggleActive(item, false)"
                            class="flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg transition
                                   text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-gray-700"
                            title="Deja de cobrarse desde la próxima factura; conserva el historial"
                        >
                            <v-icon name="md-close" class="w-4 h-4" />
                            Dar de baja
                        </button>
                        <button
                            v-else
                            @click="toggleActive(item, true)"
                            class="flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg transition
                                   text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20"
                        >
                            <v-icon name="bi-arrow-repeat" class="w-4 h-4" />
                            Reactivar
                        </button>
                        <button
                            @click="confirmDelete(item)"
                            class="ml-auto flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg transition
                                   text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-900/20"
                            title="Sólo si nunca se ha cobrado"
                        >
                            <v-icon name="md-delete" class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- Modal alta / edición -->
        <div v-if="showFormModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeFormModal">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden max-h-[92vh] flex flex-col">
                <div class="bg-gradient-to-br from-emerald-500 to-teal-600 p-5 text-white shrink-0">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">
                            {{ isEditing ? 'Editar asignación' : 'Asignar servicio adicional' }}
                        </h3>
                        <button @click="closeFormModal" class="p-1 hover:bg-white/20 rounded-lg transition-colors">
                            <v-icon name="md-close" class="w-5 h-5" />
                        </button>
                    </div>
                </div>

                <form @submit.prevent="handleSave" class="p-4 md:p-6 space-y-5 overflow-y-auto">
                    <!-- Servicio: sólo al asignar. Cambiarlo después sería otra
                         asignación distinta con el historial de la anterior. -->
                    <div v-if="!isEditing">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Servicio *</label>
                        <select
                            v-model="form.additional_service_id"
                            required
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                                   bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                                   focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all outline-none"
                        >
                            <option value="" disabled>-- Selecciona un servicio --</option>
                            <option v-for="s in catalogo" :key="s.id" :value="s.id">
                                {{ s.name }} — {{ formatCurrency(s.price) }}
                            </option>
                        </select>
                        <p v-if="catalogo.length === 0" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                            No hay servicios activos en el catálogo. Créalos en Finanzas → Servicios adicionales.
                        </p>
                    </div>
                    <div v-else class="px-4 py-3 rounded-xl bg-slate-50 dark:bg-gray-700/40">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Servicio</p>
                        <p class="font-semibold text-slate-800 dark:text-white">{{ editingItem?.service?.name }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Precio para este cliente
                            </label>
                            <input
                                v-model="form.price"
                                type="number"
                                min="0"
                                step="1"
                                onwheel="this.blur()"
                                :placeholder="`${precioCatalogo}`"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 tabular-nums
                                       focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all outline-none"
                            />
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                Déjalo vacío para usar el del catálogo ({{ formatCurrency(precioCatalogo) }}) y que
                                siga sus cambios.
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Cantidad</label>
                            <input
                                v-model.number="form.quantity"
                                type="number"
                                min="1"
                                step="1"
                                onwheel="this.blur()"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 tabular-nums
                                       focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all outline-none"
                            />
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                Ej. dos routers extra.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Desde *</label>
                            <DatePicker v-model="form.starts_at" accent="emerald" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Hasta</label>
                            <DatePicker v-model="form.ends_at" accent="emerald" />
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                Opcional: baja programada.
                            </p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notas</label>
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            placeholder="Opcional — por qué se le asignó, número de serie del equipo..."
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
                                   bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                                   focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all outline-none"
                        ></textarea>
                    </div>

                    <!-- Resumen del cobro -->
                    <div class="flex items-center justify-between px-4 py-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20">
                        <span class="text-sm font-medium text-emerald-800 dark:text-emerald-300">Se cobrará cada mes</span>
                        <span class="font-bold text-emerald-700 dark:text-emerald-400 tabular-nums">
                            {{ formatCurrency(previewMensual) }}
                        </span>
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
                            :disabled="saving || (!isEditing && !form.additional_service_id)"
                        >
                            {{ saving ? 'Guardando...' : (isEditing ? 'Actualizar' : 'Asignar') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Confirmación de borrado -->
        <div
            v-if="showDeleteModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
            @click.self="closeDeleteModal"
        >
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2 mb-5">
                    <v-icon name="md-delete" class="w-6 h-6 text-red-600" />
                    Eliminar asignación
                </h2>

                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                    <p class="text-sm text-red-600 dark:text-red-400">
                        <strong>"{{ itemToDelete?.service?.name }}"</strong> se quitará de este cliente.
                        Si ya se cobró en alguna factura, el sistema no dejará borrarlo — en ese caso
                        <strong>dale de baja</strong> para conservar el historial.
                    </p>
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
                        class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 transition-colors flex items-center gap-2"
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
import { ref, computed, onMounted } from 'vue'
import DatePicker from '@/components/DatePicker.vue'
import customerAdditionalServiceApi from '@/services/api/customer-additional-service'
import additionalServiceApi from '@/services/api/additional-service'

const props = defineProps({
    customerId: { type: [String, Number], required: true },
})

const emit = defineEmits(['notify'])

const loading = ref(false)
const saving = ref(false)
const items = ref([])
const catalogo = ref([])

const showFormModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const editingItem = ref(null)
const itemToDelete = ref(null)

const emptyForm = () => ({
    additional_service_id: '',
    price: '',
    quantity: 1,
    starts_at: new Date().toISOString().slice(0, 10),
    ends_at: '',
    notes: '',
})

const form = ref(emptyForm())

const formatCurrency = (val) => {
    const n = parseFloat(val) || 0
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

/**
 * Fecha legible sin pasar por `new Date()`.
 *
 * El backend serializa un cast `date` como medianoche UTC
 * ("2026-08-01T00:00:00.000000Z"); construir un Date con eso en UTC-5 devuelve
 * el día ANTERIOR. Cortando la cadena se lee el día que se guardó.
 */
const formatDate = (v) => {
    const s = v ? String(v).slice(0, 10) : ''
    if (!s) return '—'
    const [y, m, d] = s.split('-')
    return `${d}/${m}/${y}`
}

const lineTotal = (item) => (parseFloat(item.effective_price) || 0) * (item.quantity || 1)

// Sólo lo vigente: una asignación dada de baja ya no se cobra.
const totalMensual = computed(() =>
    items.value.filter(i => i.is_active).reduce((sum, i) => sum + lineTotal(i), 0)
)

const precioCatalogo = computed(() => {
    if (isEditing.value) return parseFloat(editingItem.value?.service?.price) || 0
    const s = catalogo.value.find(s => s.id === form.value.additional_service_id)
    return parseFloat(s?.price) || 0
})

const previewMensual = computed(() => {
    const unit = form.value.price === '' || form.value.price === null
        ? precioCatalogo.value
        : parseFloat(form.value.price) || 0
    return unit * (form.value.quantity || 1)
})

const loadItems = async () => {
    loading.value = true
    try {
        const { data } = await customerAdditionalServiceApi.getAll(props.customerId)
        items.value = data || []
    } catch (error) {
        console.error('Error loading customer additional services:', error)
        emit('notify', { type: 'error', title: 'Error', message: 'No se pudieron cargar los servicios adicionales.' })
    } finally {
        loading.value = false
    }
}

const loadCatalogo = async () => {
    try {
        const { data } = await additionalServiceApi.getAll()
        catalogo.value = (data || []).filter(s => s.is_active)
    } catch (error) {
        console.error('Error loading additional service catalog:', error)
    }
}

const openAddModal = () => {
    isEditing.value = false
    editingItem.value = null
    form.value = emptyForm()
    showFormModal.value = true
}

const openEditModal = (item) => {
    isEditing.value = true
    editingItem.value = item
    form.value = {
        additional_service_id: item.additional_service_id,
        // null = sigue el catálogo; el campo se muestra vacío con el precio de
        // lista como placeholder para que la diferencia se vea.
        price: item.price === null || item.price === undefined ? '' : parseFloat(item.price),
        quantity: item.quantity || 1,
        starts_at: item.starts_at ? String(item.starts_at).slice(0, 10) : '',
        ends_at: item.ends_at ? String(item.ends_at).slice(0, 10) : '',
        notes: item.notes || '',
    }
    showFormModal.value = true
}

const closeFormModal = () => {
    showFormModal.value = false
    isEditing.value = false
    editingItem.value = null
}

const confirmDelete = (item) => {
    itemToDelete.value = item
    showDeleteModal.value = true
}

const closeDeleteModal = () => {
    showDeleteModal.value = false
    itemToDelete.value = null
}

const notifyError = (error, fallback) => {
    emit('notify', {
        type: 'error',
        title: 'Error',
        message: error.response?.data?.message || fallback,
    })
}

const handleSave = async () => {
    saving.value = true
    try {
        const payload = {
            price: form.value.price === '' ? null : Number(form.value.price),
            quantity: form.value.quantity || 1,
            starts_at: form.value.starts_at,
            ends_at: form.value.ends_at || null,
            notes: form.value.notes || null,
        }

        if (isEditing.value) {
            await customerAdditionalServiceApi.update(props.customerId, editingItem.value.id, payload)
            emit('notify', { type: 'success', title: 'Actualizado', message: 'Asignación actualizada.' })
        } else {
            await customerAdditionalServiceApi.create(props.customerId, {
                ...payload,
                additional_service_id: form.value.additional_service_id,
            })
            emit('notify', { type: 'success', title: 'Asignado', message: 'El servicio se cobrará en la próxima factura.' })
        }

        closeFormModal()
        await loadItems()
    } catch (error) {
        notifyError(error, 'No se pudo guardar la asignación.')
    } finally {
        saving.value = false
    }
}

const toggleActive = async (item, active) => {
    saving.value = true
    try {
        await customerAdditionalServiceApi.update(props.customerId, item.id, { is_active: active })
        emit('notify', {
            type: 'success',
            title: active ? 'Reactivado' : 'Dado de baja',
            message: active
                ? 'Vuelve a cobrarse desde la próxima factura.'
                : 'Deja de cobrarse. Las facturas anteriores no cambian.',
        })
        await loadItems()
    } catch (error) {
        notifyError(error, 'No se pudo cambiar el estado.')
    } finally {
        saving.value = false
    }
}

const deleteItem = async () => {
    if (!itemToDelete.value) return
    saving.value = true
    try {
        await customerAdditionalServiceApi.delete(props.customerId, itemToDelete.value.id)
        emit('notify', { type: 'success', title: 'Eliminado', message: 'Asignación eliminada.' })
        closeDeleteModal()
        await loadItems()
    } catch (error) {
        // Caso esperado: ya se cobró. El backend responde 422 con la salida.
        notifyError(error, 'No se pudo eliminar la asignación.')
        closeDeleteModal()
    } finally {
        saving.value = false
    }
}

onMounted(() => {
    loadItems()
    loadCatalogo()
})
</script>
