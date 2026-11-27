<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">
      <div class="max-w-4xl mx-auto">

        <!-- Header -->
        <div class="mb-6">
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
              <v-icon name="bi-hdd-network" class="text-purple-600 dark:text-purple-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            Mis equipos
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
            Lo que tienes asignado para usar en instalaciones y tickets. Si te falta algo, pídele a quien
            administra el inventario que te lo entregue.
          </p>
        </div>

        <div v-if="loading" class="flex items-center justify-center py-16 text-gray-500 dark:text-gray-400">
          <v-icon name="ri-loader-4-line" animation="spin" class="w-6 h-6 mr-2" /> Cargando…
        </div>

        <div v-else-if="error" class="rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4">
          <p class="text-sm text-amber-800 dark:text-amber-200">{{ error }}</p>
          <button type="button" @click="load" class="mt-2 text-sm font-medium underline text-amber-800 dark:text-amber-200">Reintentar</button>
        </div>

        <template v-else>
          <!-- Resumen: cuántos de cada modelo, de un vistazo -->
          <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6" data-testid="mine-summary">
            <p class="text-sm text-gray-700 dark:text-gray-200">
              <strong class="text-2xl font-bold text-gray-900 dark:text-white">{{ devices.length }}</strong>
              {{ devices.length === 1 ? 'equipo' : 'equipos' }}
              <span class="mx-2 text-gray-300 dark:text-gray-600">·</span>
              <strong class="text-2xl font-bold text-gray-900 dark:text-white">{{ materials.length }}</strong>
              {{ materials.length === 1 ? 'material' : 'materiales' }}
            </p>
            <div v-if="porModelo.length" class="mt-3 flex flex-wrap gap-2">
              <span v-for="m in porModelo" :key="m.item"
                class="px-2.5 py-1 rounded-full text-xs font-medium bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                {{ m.item }} · {{ m.count }}
              </span>
            </div>
          </div>

          <div v-if="!devices.length && !materials.length"
            class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center" data-testid="mine-empty">
            <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
              <v-icon name="bi-box-seam" class="w-10 h-10 text-gray-400" />
            </div>
            <p class="text-gray-600 dark:text-gray-300 font-medium">No tienes equipos ni materiales asignados.</p>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Cuando te entreguen algo, aparece aquí.</p>
          </div>

          <template v-else>
            <div v-if="devices.length + materials.length > 5" class="relative mb-4">
              <v-icon name="io-search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
              <input v-model="filter" type="search" placeholder="Buscar por modelo, serial, MAC o material…"
                data-testid="mine-search"
                class="w-full pl-9 pr-3 py-2.5 text-sm border border-gray-300 dark:border-gray-600 rounded-xl
                       bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-purple-500 outline-none" />
            </div>

            <!-- Equipos con serial -->
            <section v-if="devices.length" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
              <h2 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-3">
                Equipos con serial ({{ visibleDevices.length }}{{ filter ? ` de ${devices.length}` : '' }})
              </h2>
              <ul class="divide-y divide-gray-100 dark:divide-gray-700" data-testid="mine-devices">
                <li v-for="d in visibleDevices" :key="d.id" class="py-2.5 flex items-start justify-between gap-3">
                  <span class="min-w-0">
                    <span class="block text-sm font-medium text-gray-800 dark:text-white truncate">{{ d.item }}</span>
                    <span class="block text-[11px] font-mono text-gray-500 dark:text-gray-400 break-all">
                      {{ d.serial || 'sin serial' }}<template v-if="d.mac"> · {{ d.mac }}</template>
                    </span>
                  </span>
                </li>
                <li v-if="!visibleDevices.length" class="py-2.5 text-sm italic text-gray-500 dark:text-gray-400">
                  Ningún equipo coincide.
                </li>
              </ul>
            </section>

            <!-- Materiales por cantidad -->
            <section v-if="materials.length" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6">
              <h2 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-3">
                Materiales ({{ visibleMaterials.length }}{{ filter ? ` de ${materials.length}` : '' }})
              </h2>
              <ul class="divide-y divide-gray-100 dark:divide-gray-700" data-testid="mine-materials">
                <li v-for="m in visibleMaterials" :key="m.stock_id" class="py-2.5 flex items-center justify-between gap-3">
                  <span class="text-sm text-gray-800 dark:text-white min-w-0 truncate">{{ m.item }}</span>
                  <span class="shrink-0 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ fmtQty(m.quantity) }}{{ m.unit ? ` ${m.unit}` : '' }}
                  </span>
                </li>
                <li v-if="!visibleMaterials.length" class="py-2.5 text-sm italic text-gray-500 dark:text-gray-400">
                  Ningún material coincide.
                </li>
              </ul>
            </section>
          </template>
        </template>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import inventoryApi from '@/services/api/inventory'
import { firstError } from '@/utils/apiError'
import { fmtQty } from '@/utils/materialQuantity'
import { searchIndex, searchTerms, matchesIndex } from '@/utils/textSearch'

/**
 * «Mis equipos» (bitácora § 138): lo que tiene asignado el usuario de la sesión,
 * y nada más. Es la pantalla de inventario del técnico: el ISP pidió que viera
 * únicamente sus equipos, y antes la única forma de que viera lo que carga era
 * darle «Ver inventario», que le abría también las bodegas y lo de los demás.
 * Sólo lectura y sin precios.
 */

const loading = ref(true)
const error = ref('')
const devices = ref([])
const materials = ref([])
const filter = ref('')

const terms = computed(() => searchTerms(filter.value))
const visibleDevices = computed(() => terms.value.length
  ? devices.value.filter(d => matchesIndex(terms.value, d._search))
  : devices.value)
const visibleMaterials = computed(() => terms.value.length
  ? materials.value.filter(m => matchesIndex(terms.value, m._search))
  : materials.value)

// «ZTE F670L · 5»: cuántos tiene de cada modelo, sin contar a mano.
const porModelo = computed(() => {
  const counts = new Map()
  for (const d of devices.value) counts.set(d.item, (counts.get(d.item) || 0) + 1)
  return [...counts].map(([item, count]) => ({ item, count })).sort((a, b) => b.count - a.count)
})

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await inventoryApi.mine()
    devices.value = (data.devices || []).map(d => ({ ...d, _search: searchIndex([d.item, d.serial, d.mac]) }))
    materials.value = (data.materials || []).map(m => ({ ...m, _search: searchIndex([m.item]) }))
  } catch (e) {
    error.value = firstError(e, 'No se pudieron cargar tus equipos.')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
