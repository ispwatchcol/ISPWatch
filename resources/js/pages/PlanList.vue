<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- CONTENIDO -->
    <main class="flex-1 p-4 sm:p-6 lg:p-10 overflow-y-auto flex flex-col gap-6">

      <!-- ENCABEZADO -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl sm:text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="bi-speedometer2" class="text-blue-600 w-7 h-7" />
            Planes de Internet
          </h1>
          <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
            Gestiona las velocidades y precios de tus servicios.
          </p>
        </div>

        <!-- Crear -->
        <button
          @click="createPlan"
          class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl shadow-md flex items-center gap-2 transition-all duration-200 w-full sm:w-auto justify-center"
        >
          <icon-lucide-plus-circle class="w-4 h-4" />
          Nuevo Plan {{ currentTabName ? ` ${currentTabName}` : '' }}
        </button>
      </div>

      <!-- TARJETA -->
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow p-4 sm:p-6 flex flex-col gap-8">

      <!-- TABS NUEVOS -->
      <div class="pb-2 border-b border-gray-200 dark:border-gray-700 mb-2">
        <nav class="flex gap-2 overflow-x-auto px-1">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="currentTab = tab.id"
            class="px-4 py-2 rounded-xl text-sm font-medium flex items-center justify-center gap-2
                  transition-all duration-200 whitespace-nowrap shadow-sm"
            :class="[
              currentTab === tab.id
                ? 'bg-blue-600 text-white shadow-lg scale-[1.02]'
                : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
            ]"
          >
            <v-icon :name="tab.icon" class="w-4 h-4" />
            <span class="text-center">{{ tab.name }}</span>
          </button>
        </nav>
      </div>




        <!-- FILTROS Y BUSCADOR -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-2">
        <div class="relative w-full sm:w-96">
          <input
            v-model="search"
            type="text"
            placeholder="Buscar plan..."
            class="px-4 py-2.5 w-full rounded-xl bg-gray-100 dark:bg-gray-800
                  border border-transparent focus:border-blue-500
                  text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500
                  shadow-inner focus:ring-0 transition-all duration-200"
          />
        </div>
        <!-- Selección múltiple -->
        <div
          v-if="selectedPlans.length > 0"
          class="flex items-center gap-2 animate-fade-in bg-blue-50 dark:bg-blue-900/20 p-2 rounded-lg"
        >
          <span class="text-sm font-medium text-blue-700 dark:text-blue-300">
            {{ selectedPlans.length }} seleccionados
          </span>
          <button
            class="text-xs bg-red-100 text-red-700 px-3 py-1.5 rounded-md hover:bg-red-200 dark:bg-red-500/20 dark:text-red-300 dark:hover:bg-red-500/30 transition"
          >
            Eliminar
          </button>
        </div>

      </div>

        <!-- LOADING -->
        <div v-if="loading" class="text-center py-12">
          <v-icon name="bi-arrow-repeat" animation="spin" class="text-blue-500 w-8 h-8 mb-2" />
          <p class="text-gray-500 dark:text-gray-400">Cargando planes...</p>
        </div>

        <!-- TABLA DESKTOP -->
        <div
          v-else
          class="hidden md:block overflow-x-auto rounded-xl border border-gray-100 dark:border-gray-700"
        >
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
              <tr>
                <th class="px-4 py-3 text-left">
                  <label class="checkbox-modern">
                    <input type="checkbox" v-model="selectAll" />
                    <span></span>
                  </label>
                </th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Plan</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Precio</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Velocidad</th>
                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Activos</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Acciones</th>
              </tr>
            </thead>

            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
              <tr
                v-for="plan in filteredPlans"
                :key="plan.id"
                class="hover:bg-blue-50/60 dark:hover:bg-gray-700/40 transition-colors duration-150"
              >
              <td class="px-4 py-4">
                <label class="checkbox-modern">
                  <input type="checkbox" :value="plan.id" v-model="selectedPlans" />
                  <span></span>
                </label>
              </td>


                <td class="px-4 py-4">
                  <div class="font-semibold text-gray-900 dark:text-white leading-tight">
                    {{ plan.name }}
                  </div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-tight">
                    {{ plan.description }}
                  </div>
                </td>

                <td class="px-4 py-4">
                  <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
                    {{ formatCurrency(plan.price) }}
                  </span>
                </td>

                <td class="px-4 py-4">
                  <div class="flex items-center gap-3">
                    <div class="text-blue-600 dark:text-blue-400 flex items-center gap-1">
                      <icon-lucide-arrow-down class="w-3 h-3" /> {{ plan.download_speed }}
                    </div>
                    <div class="text-purple-600 dark:text-purple-400 flex items-center gap-1">
                      <icon-lucide-arrow-up class="w-3 h-3" /> {{ plan.upload_speed }}
                    </div>
                  </div>
                </td>

                <td class="px-4 py-4 text-center text-sm font-medium text-gray-600 dark:text-gray-300">
                  {{ plan.active_clients_count }}
                </td>

                <td class="px-4 py-4">
                  <div class="flex items-center justify-end gap-2">
                    <button
                      @click="editPlan(plan)"
                      class="p-1.5 rounded-md text-blue-600 hover:bg-blue-100 dark:text-blue-400 dark:hover:bg-blue-900/40 transition"
                    >
                      <icon-lucide-pencil class="w-4 h-4" />
                    </button>
                    <button
                      @click="deletePlan(plan.id)"
                      class="p-1.5 rounded-md text-red-600 hover:bg-red-100 dark:text-red-400 dark:hover:bg-red-900/40 transition"
                    >
                      <icon-lucide-trash-2 class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MOBILE CARDS -->
        <div class="md:hidden space-y-5">
          <div
            v-for="plan in filteredPlans"
            :key="plan.id"
            class="bg-white dark:bg-gray-800 rounded-2xl shadow p-5 flex flex-col gap-3 transition-all"
          >
            <div class="flex justify-between items-start">
              <div>
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                  {{ plan.name }}
                </h2>
                <p class="text-green-600 dark:text-green-400 font-bold mt-0.5">
                  {{ formatCurrency(plan.price) }}
                </p>
              </div>

              <input
                type="checkbox"
                :value="plan.id"
                v-model="selectedPlans"
                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400">
              {{ plan.description }}
            </p>

            <div class="flex items-center justify-between text-xs pt-2">
              <div class="flex gap-4">
                <div class="text-blue-600 dark:text-blue-400 flex items-center gap-1">
                  <icon-lucide-arrow-down class="w-3 h-3" /> {{ plan.download_speed }}
                </div>
                <div class="text-purple-600 dark:text-purple-400 flex items-center gap-1">
                  <icon-lucide-arrow-up class="w-3 h-3" /> {{ plan.upload_speed }}
                </div>
              </div>

              <div class="flex items-center gap-1 text-gray-500 dark:text-gray-400">
                <v-icon name="pr-users" class="w-3 h-3" />
                {{ plan.active_clients_count }}
              </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
              <button
                @click="editPlan(plan)"
                class="p-1.5 rounded-md text-blue-600 hover:bg-blue-100 dark:text-blue-400 dark:hover:bg-blue-900/40"
              >
                <icon-lucide-pencil class="w-4 h-4" />
              </button>
              <button
                @click="deletePlan(plan.id)"
                class="p-1.5 rounded-md text-red-600 hover:bg-red-100 dark:text-red-400 dark:hover:bg-red-900/40"
              >
                <icon-lucide-trash-2 class="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>

        <!-- EMPTY STATE -->
        <div v-if="!loading && filteredPlans.length === 0" class="text-center py-10">
          <div class="w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-3">
            <icon-lucide-search class="w-6 h-6 text-gray-400" />
          </div>
          <p class="text-gray-500 dark:text-gray-400">
            No se encontraron planes para "{{ currentTabName.trim() }}".
          </p>
          <button @click="createPlan" class="mt-4 text-sm font-semibold text-blue-600 hover:underline">
            Crear el primero
          </button>
        </div>

      </div>
    </main>
  </div>
</template>


<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const loading = ref(false)
const search = ref('')
const currentTab = ref('queue')
const selectedPlans = ref([])

const tabs = [
  { id: 'queue', name: 'Planes Queue', icon: 'bi-list-task' },
  { id: 'pcq', name: 'Planes PCQ', icon: 'bi-diagram-3' },
  { id: 'hotspot', name: 'Planes HotSpot', icon: 'bi-wifi' },
  { id: 'pppoe', name: 'Planes PPPoE', icon: 'bi-hdd-network' },
]

const allPlans = ref([
  { id: 1, type: 'queue', name: 'PLAN 5 MEGAS', price: 60000, download_speed: '5M', upload_speed: '5M', active_clients_count: 12, description: 'Plan básico hogar' },
  { id: 2, type: 'queue', name: 'PLAN 10 MEGAS', price: 90000, download_speed: '10M', upload_speed: '10M', active_clients_count: 5, description: 'Plan estándar + IVA' },
  { id: 3, type: 'pppoe', name: 'PPPOE 20 MEGAS', price: 120000, download_speed: '20M', upload_speed: '10M', active_clients_count: 40, description: 'Fibra óptica' },
  { id: 4, type: 'pcq', name: 'PCQ Corporativo', price: 250000, download_speed: '50M', upload_speed: '50M', active_clients_count: 2, description: 'Empresarial simétrico' },
])

const currentTabName = computed(() => {
   const tab = tabs.find(t => t.id === currentTab.value)
   return tab ? tab.name.replace('Planes', '').trim() : ''
})

const filteredPlans = computed(() => {
  return allPlans.value.filter(plan =>
    plan.type === currentTab.value &&
    (!search.value || plan.name.toLowerCase().includes(search.value.toLowerCase()))
  )
})

const selectAll = computed({
  get() {
    return filteredPlans.value.length > 0 &&
      filteredPlans.value.every(p => selectedPlans.value.includes(p.id));
  },
  set(value) {
    const ids = filteredPlans.value.map(p => p.id);
    if (value) {
      selectedPlans.value = [...new Set([...selectedPlans.value, ...ids])];
    } else {
      selectedPlans.value = selectedPlans.value.filter(id => !ids.includes(id));
    }
  }
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0
  }).format(value)
}

const createPlan = () => router.push({ path: '/planes/create', query: { type: currentTab.value } })
const editPlan = (plan) => router.push(`/planes/${plan.id}/edit`)
const deletePlan = (id) => {
  if (confirm('¿Seguro?')) {
    allPlans.value = allPlans.value.filter(p => p.id !== id)
  }
}
</script>
