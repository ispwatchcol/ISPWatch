<template>
  <Teleport to="body">
    <div v-if="visible" data-testid="units-modal"
      class="fixed inset-0 z-app-modal flex items-start sm:items-center justify-center bg-black/50 backdrop-blur-sm p-0 sm:p-4"
      @click.self="close">
      <div role="dialog" aria-modal="true" aria-label="Unidades de medida"
        class="bg-white dark:bg-gray-800 w-full sm:max-w-lg h-full sm:h-auto sm:max-h-[85vh] sm:rounded-2xl shadow-2xl flex flex-col">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">Unidades de medida</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
              Con qué se mide cada material: metro, unidad, rollo… Los productos «por cantidad» eligen una de esta lista.
            </p>
          </div>
          <button type="button" @click="close" aria-label="Cerrar"
            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 px-2 py-1 rounded-lg">✕</button>
        </div>

        <!-- Alta -->
        <form v-if="canEdit" @submit.prevent="create" class="p-4 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-end gap-2">
          <div class="flex-1 min-w-[9rem]">
            <label class="block text-xs text-gray-600 dark:text-gray-300 mb-1" for="unit-name">Nueva unidad</label>
            <input id="unit-name" v-model="draft.name" type="text" maxlength="20" required data-testid="unit-name"
              placeholder="Ej.: bolsa x100, kilo, par"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-800 dark:text-white" />
          </div>
          <div>
            <label class="block text-xs text-gray-600 dark:text-gray-300 mb-1" for="unit-decimals">Se cuenta en</label>
            <select id="unit-decimals" v-model.number="draft.decimals"
              class="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-800 dark:text-white">
              <option v-for="o in decimalOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </div>
          <button type="submit" :disabled="busy || !draft.name.trim()" data-testid="unit-add"
            class="bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
            Agregar
          </button>
          <p v-if="error" class="w-full text-xs text-rose-600 dark:text-rose-400">{{ error }}</p>
        </form>

        <ul class="flex-1 overflow-y-auto p-2">
          <li v-for="u in units" :key="u.id ?? `b-${u.name}`"
            class="px-3 py-2 rounded-lg flex flex-wrap items-center justify-between gap-2 hover:bg-gray-50 dark:hover:bg-gray-700/40">
            <template v-if="editing?.id === u.id && !u.built_in">
              <input v-model="editing.name" type="text" maxlength="20"
                class="flex-1 min-w-[8rem] bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm text-gray-800 dark:text-white" />
              <select v-model.number="editing.decimals"
                class="bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm text-gray-800 dark:text-white">
                <option v-for="o in decimalOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <span class="flex gap-1">
                <button type="button" @click="saveEdit" :disabled="busy || !editing.name.trim()"
                  class="text-xs bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white px-3 py-1 rounded-lg">Guardar</button>
                <button type="button" @click="editing = null" class="text-xs text-gray-500 hover:underline px-1">Cancelar</button>
              </span>
            </template>
            <template v-else>
              <span class="min-w-0">
                <span class="text-sm text-gray-800 dark:text-white">{{ u.name }}</span>
                <span v-if="u.built_in" class="ml-1 text-[10px] uppercase px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Básica</span>
                <span class="block text-[11px] text-gray-500 dark:text-gray-400">
                  {{ decimalsText(u.decimals) }} · {{ u.products_count }} producto(s)
                </span>
              </span>
              <span v-if="!u.built_in && canEdit" class="flex gap-1">
                <button type="button" @click="startEdit(u)" :disabled="busy"
                  class="text-xs text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/20 px-2 py-1 rounded-lg">Editar</button>
                <button v-if="canDelete" type="button" @click="remove(u)" :disabled="busy || u.products_count > 0"
                  :title="u.products_count > 0 ? 'La usan productos: cámbiales la unidad o renómbrala' : 'Eliminar'"
                  class="text-xs text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 px-2 py-1 rounded-lg disabled:opacity-40">Eliminar</button>
              </span>
            </template>
          </li>
        </ul>
        <p class="px-4 pb-4 text-[11px] text-gray-500 dark:text-gray-400">
          Renombrar una unidad cambia también la de los productos que la usan: sirve para unificar «mts» y «metro».
          «Se cuenta en» es la precisión que se propone al crear un producto con esa unidad.
        </p>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import inventoryUnitApi from '@/services/api/inventory-unit'
import { firstError } from '@/utils/apiError'
import { usePermissions } from '@/composables/usePermissions'

const props = defineProps({
  visible: { type: Boolean, default: false },
  units:   { type: Array, default: () => [] },
})

// Mismos permisos que las rutas: crear/editar con view_inventory, borrar con
// delete_inventory. Quien sólo ve soporte consulta la lista.
const { can } = usePermissions()
const canEdit   = computed(() => can('view_inventory'))
const canDelete = computed(() => can('delete_inventory'))

// `changed` pide al padre recargar el catálogo (y los productos, si se renombró).
const emit = defineEmits(['close', 'changed'])

const decimalOptions = [
  { value: 0, label: 'Enteros (piezas)' },
  { value: 1, label: 'Hasta 1 decimal' },
  { value: 2, label: 'Hasta 2 decimales (metros)' },
]
const decimalsText = (d) => decimalOptions.find(o => o.value === Number(d))?.label ?? 'Enteros (piezas)'

const draft = ref({ name: '', decimals: 0 })
const editing = ref(null)
const busy = ref(false)
const error = ref('')

watch(() => props.visible, (open) => {
  if (open) { draft.value = { name: '', decimals: 0 }; editing.value = null; error.value = '' }
})

const run = async (fn) => {
  busy.value = true
  error.value = ''
  try {
    await fn()
  } catch (e) {
    error.value = firstError(e) || 'No se pudo guardar.'
  } finally {
    busy.value = false
  }
}

const create = () => run(async () => {
  await inventoryUnitApi.create({ name: draft.value.name.trim(), decimals: draft.value.decimals })
  draft.value = { name: '', decimals: 0 }
  emit('changed', { renamed: 0 })
})

const startEdit = (u) => { editing.value = { id: u.id, name: u.name, decimals: u.decimals } }

const saveEdit = () => run(async () => {
  const { data } = await inventoryUnitApi.update(editing.value.id, {
    name: editing.value.name.trim(), decimals: editing.value.decimals,
  })
  editing.value = null
  emit('changed', { renamed: data?.products_renamed ?? 0 })
})

const remove = (u) => {
  if (!window.confirm(`¿Eliminar la unidad «${u.name}»?`)) return
  run(async () => {
    await inventoryUnitApi.delete(u.id)
    emit('changed', { renamed: 0 })
  })
}

const close = () => emit('close')
</script>
