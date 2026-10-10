<template>
  <!-- Sólo se muestra a quien maneja finanzas. No es por estética: encender esto
       hace que dar de alta un equipo mueva el balance, así que la decisión es de
       quien responde por el balance. El backend lo exige igual (KAN-91). -->
  <div v-if="puedeVerGastos"
       class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
    <div class="flex items-center gap-3 mb-4">
      <div class="p-2 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg">
        <v-icon name="bi-cash-coin" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
      </div>
      <div>
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white">
          Gasto automático al ingresar inventario
        </h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">
          Que la compra de equipos se descuente sola del balance de finanzas.
        </p>
      </div>
    </div>

    <!-- El aviso va ANTES del interruptor y no en letra chica debajo: el doble
         conteo es el error que esta función hace fácil cometer, y el momento de
         advertirlo es antes de encenderla, no después. -->
    <div class="mb-5 p-4 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
      <p class="text-sm text-amber-900 dark:text-amber-200">
        <strong>No lo actives si ya registras las facturas de compra como gasto.</strong>
        Se contaría dos veces la misma compra y el balance mostraría menos utilidad de la real.
      </p>
    </div>

    <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg mb-4">
      <div>
        <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
          Crear el gasto al ingresar equipos
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
          Aplica al alta de un equipo, a la entrada de material y a la carga masiva.
          El importe es el precio del catálogo por la cantidad.
        </p>
      </div>
      <label class="relative inline-flex items-center cursor-pointer shrink-0 ml-4">
        <input v-model="activo" type="checkbox" class="sr-only peer" />
        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-emerald-300 dark:peer-focus:ring-emerald-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-emerald-600"></div>
      </label>
    </div>

    <div v-if="activo" class="mb-4">
      <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
        Categoría de gasto
      </label>
      <select v-model="categoriaId"
        class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
               bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
               focus:ring-2 focus:ring-emerald-500 outline-none">
        <option :value="null">Sin categoría</option>
        <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <p v-if="!categorias.length" class="text-xs text-gray-500 dark:text-gray-400 mt-2">
        No tienes categorías de gasto creadas. Puedes dejarlo sin categoría y clasificarlos después.
      </p>
    </div>

    <!-- Un modelo sin precio de catálogo no genera gasto, y hay que decirlo acá:
         si aparece sólo cuando ya pasó, el usuario no sabe qué mirar. -->
    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
      Si un modelo no tiene precio en el catálogo, no se crea gasto y se te avisa en el momento.
      El importe queda congelado: cambiar el precio del catálogo después no modifica los gastos ya creados.
    </p>

    <div class="flex justify-end">
      <button type="button" @click="guardar" :disabled="guardando || !hayCambios"
        class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 transition">
        {{ guardando ? 'Guardando…' : 'Guardar' }}
      </button>
    </div>

    <NotificationToast ref="toast" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiClient } from '@/services/api'
import tenantApi from '@/services/api/tenant'
import expenseCategoryApi from '@/services/api/expense-category'
import { useAuthStore } from '@/stores/auth'
import NotificationToast from '@/components/NotificationToast.vue'

const auth = useAuthStore()
const toast = ref(null)

const puedeVerGastos = computed(() => auth.hasPermission('view_expenses'))

const activo = ref(false)
const categoriaId = ref(null)
const categorias = ref([])
const guardando = ref(false)

// Lo guardado, para saber si hay algo que enviar.
const original = ref({ activo: false, categoriaId: null })
const hayCambios = computed(
  () => activo.value !== original.value.activo || categoriaId.value !== original.value.categoriaId
)

const cargar = async () => {
  const tenantId = auth.user?.tenant_id
  if (!tenantId) return

  const [tenantRes, categoriasRes] = await Promise.allSettled([
    apiClient.get(`/tenants/${tenantId}`),
    expenseCategoryApi.getAll(),
  ])

  if (tenantRes.status === 'fulfilled') {
    const t = tenantRes.value.data?.data ?? {}
    activo.value = Boolean(t.inventory_entry_creates_expense)
    categoriaId.value = t.inventory_expense_category_id ?? null
    original.value = { activo: activo.value, categoriaId: categoriaId.value }
  }

  if (categoriasRes.status === 'fulfilled') {
    categorias.value = categoriasRes.value.data?.data ?? categoriasRes.value.data ?? []
  }
}

const guardar = async () => {
  guardando.value = true
  try {
    await tenantApi.updateConfig({
      inventory_entry_creates_expense: activo.value,
      inventory_expense_category_id: activo.value ? categoriaId.value : null,
    })
    original.value = { activo: activo.value, categoriaId: categoriaId.value }
    toast.value?.success('Guardado', activo.value
      ? 'Las entradas de inventario van a generar gasto.'
      : 'Las entradas de inventario ya no generan gasto.')
  } catch (e) {
    toast.value?.error('Error', e.response?.data?.message || 'No se pudo guardar la configuración.')
  } finally {
    guardando.value = false
  }
}

onMounted(() => {
  if (puedeVerGastos.value) cargar()
})
</script>
