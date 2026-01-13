<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Notification Toast -->
    <NotificationToast ref="toast" />
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

      <!-- TARJETA PRINCIPAL -->
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow p-4 sm:p-6 flex flex-col gap-6">

        <!-- TABS DE NAVEGACIÓN -->
        <div class="pb-2 border-b border-gray-200 dark:border-gray-700">
          <nav class="flex gap-2 overflow-x-auto px-1 scrollbar-hide">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              @click="currentTab = tab.id"
              class="px-4 py-2 rounded-xl text-sm font-medium flex items-center justify-center gap-2
                     transition-all duration-200 whitespace-nowrap shadow-sm border border-transparent"
              :class="[
                currentTab === tab.id
                  ? 'bg-blue-600 text-white shadow-md scale-[1.02] border-blue-500'
                  : 'bg-gray-50 dark:bg-gray-700/50 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border-gray-200 dark:border-gray-600'
              ]"
            >
              <v-icon :name="tab.icon" class="w-4 h-4" />
              <span>{{ tab.name }}</span>
            </button>
          </nav>
        </div>

        <!-- BARRA DE HERRAMIENTAS (BUSCADOR Y FILTROS) -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
          
          <!-- Buscador con Lupa -->
          <div class="relative w-full sm:w-96 group">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <!-- Icono Lupa (SVG manual para asegurar visualización) -->
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400 group-focus-within:text-blue-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </div>
            <input
              v-model="search"
              type="text"
              placeholder="Buscar plan por nombre..."
              class="pl-10 pr-4 py-2 w-full rounded-xl bg-gray-50 dark:bg-gray-900/50
                     border border-gray-200 dark:border-gray-700 
                     focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:bg-white dark:focus:bg-gray-900
                     text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500
                     transition-all duration-200 text-sm outline-none"
            />
          </div>

          <!-- Acciones de Selección Múltiple -->
          <transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
          >
            <div
              v-if="selectedPlans.length > 0"
              class="flex items-center gap-3 bg-blue-50 dark:bg-blue-900/20 px-4 py-2 rounded-lg border border-blue-100 dark:border-blue-800"
            >
              <span class="text-sm font-medium text-blue-700 dark:text-blue-300">
                {{ selectedPlans.length }} seleccionados
              </span>
              <div class="h-4 w-px bg-blue-200 dark:bg-blue-700"></div>
              <button
                class="text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition-colors flex items-center gap-1"
              >
                <icon-lucide-trash-2 class="w-3.5 h-3.5" />
                Eliminar
              </button>
            </div>
          </transition>
        </div>

        <!-- LOADING STATE -->
        <div v-if="loading" class="text-center py-12">
          <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
          <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando planes...</p>
        </div>

        <!-- TABLA DESKTOP -->
        <div
          v-else
          class="hidden md:block overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm"
        >
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
              <tr>
                <th class="px-4 py-3.5 w-12">
                  <!-- Checkbox Header Custom -->
                  <label class="flex items-center cursor-pointer relative">
                    <input type="checkbox" v-model="selectAll" class="peer sr-only" />
                    <div class="w-5 h-5 bg-white dark:bg-gray-700 border-2 border-gray-300 dark:border-gray-500 rounded-md 
                                peer-checked:bg-blue-600 peer-checked:border-blue-600 peer-hover:border-blue-400
                                transition-all duration-200 flex items-center justify-center">
                      <svg class="w-3.5 h-3.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                      </svg>
                    </div>
                  </label>
                </th>
                <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Plan</th>
                <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Precio</th>
                <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Velocidad</th>
                <th class="px-4 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Activos</th>
                <th class="px-4 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
              </tr>
            </thead>

            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
              <tr
                v-for="plan in filteredPlans"
                :key="plan.id"
                class="group hover:bg-blue-50/30 dark:hover:bg-gray-700/30 transition-colors duration-150"
                :class="{'bg-blue-50/40 dark:bg-blue-900/10': selectedPlans.includes(plan.id)}"
              >
                <td class="px-4 py-4">
                  <!-- Checkbox Row Custom -->
                  <label class="flex items-center cursor-pointer relative">
                    <input type="checkbox" :value="plan.id" v-model="selectedPlans" class="peer sr-only" />
                    <div class="w-5 h-5 bg-white dark:bg-gray-700 border-2 border-gray-300 dark:border-gray-600 rounded-md 
                                peer-checked:bg-blue-600 peer-checked:border-blue-600 peer-hover:border-blue-400
                                group-hover:border-gray-400 dark:group-hover:border-gray-500
                                transition-all duration-200 flex items-center justify-center shadow-sm">
                      <svg class="w-3.5 h-3.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                      </svg>
                    </div>
                  </label>
                </td>

                <td class="px-4 py-4">
                  <div class="font-medium text-gray-900 dark:text-white leading-tight">
                    {{ plan.name }}
                  </div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-tight line-clamp-1">
                    {{ plan.commit }}
                  </div>
                </td>

                <td class="px-4 py-4">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-300 border border-green-200 dark:border-green-500/30">
                    {{ formatCurrency(plan.cost_product) }}
                  </span>
                </td>

                <td class="px-4 py-4">
                  <div class="flex items-center gap-3">
                    <div class="flex items-center gap-1.5 text-xs font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2 py-1 rounded">
                      <icon-lucide-arrow-down class="w-3 h-3" /> {{ plan.speed_down }}
                    </div>
                    <div class="flex items-center gap-1.5 text-xs font-medium bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-1 rounded">
                      <icon-lucide-arrow-up class="w-3 h-3" /> {{ plan.speed_up }}
                    </div>
                  </div>
                </td>

                <td class="px-4 py-4 text-center">
                   <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-200">
                    {{ plan.active_clients_count }}
                   </span>
                </td>

                <td class="px-4 py-4">
                  <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                    <button
                      @click="editPlan(plan)"
                      class="p-2 rounded-lg text-gray-500 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 dark:text-gray-400 dark:hover:text-blue-400 transition"
                      title="Editar"
                    >
                      <icon-lucide-pencil class="w-4 h-4" />
                    </button>
                    <button
                      @click="deletePlan(plan.id)"
                      class="p-2 rounded-lg text-gray-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 dark:text-gray-400 dark:hover:text-red-400 transition"
                      title="Eliminar"
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
        <div class="md:hidden space-y-4">
          <div
            v-for="plan in filteredPlans"
            :key="plan.id"
            class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 flex flex-col gap-3 relative overflow-hidden"
            :class="{'ring-2 ring-blue-500 ring-offset-2 dark:ring-offset-gray-900': selectedPlans.includes(plan.id)}"
          >
            <!-- Checkbox Mobile Posicionado Absoluto -->
            <div class="absolute top-4 right-4 z-10">
               <label class="flex items-center cursor-pointer relative">
                  <input type="checkbox" :value="plan.id" v-model="selectedPlans" class="peer sr-only" />
                  <div class="w-6 h-6 bg-white dark:bg-gray-700 border-2 border-gray-300 dark:border-gray-600 rounded-full 
                              peer-checked:bg-blue-600 peer-checked:border-blue-600 
                              transition-all duration-200 flex items-center justify-center shadow-sm">
                    <svg class="w-3.5 h-3.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                    </svg>
                  </div>
                </label>
            </div>

            <div class="pr-8">
              <h2 class="text-base font-bold text-gray-900 dark:text-white">
                {{ plan.name }}
              </h2>
              <div class="flex items-baseline gap-2 mt-1">
                 <span class="text-lg font-bold text-green-600 dark:text-green-400">
                  {{ formatCurrency(plan.cost_product) }}
                 </span>
                 <span class="text-xs text-gray-400 font-normal">/mes</span>
              </div>
            </div>

            <p class="text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/50 p-2 rounded-lg">
              {{ plan.commit }}
            </p>

            <div class="flex items-center justify-between pt-2">
              <div class="flex gap-3">
                <div class="flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400">
                  <icon-lucide-arrow-down class="w-3.5 h-3.5" /> {{ plan.speed_down }}
                </div>
                <div class="flex items-center gap-1 text-xs font-medium text-purple-600 dark:text-purple-400">
                  <icon-lucide-arrow-up class="w-3.5 h-3.5" /> {{ plan.speed_up }}
                </div>
              </div>
              
              <div class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                <v-icon name="pr-users" class="w-3.5 h-3.5" />
                {{ plan.active_clients_count }} clientes
              </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-gray-100 dark:border-gray-700 mt-1">
              <button
                @click="editPlan(plan)"
                class="flex-1 py-2 rounded-lg text-sm font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:text-blue-400 dark:hover:bg-blue-900/30 transition flex items-center justify-center gap-2"
              >
                <icon-lucide-pencil class="w-4 h-4" /> Editar
              </button>
              <button
                @click="deletePlan(plan.id)"
                class="flex-1 py-2 rounded-lg text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30 transition flex items-center justify-center gap-2"
              >
                <icon-lucide-trash-2 class="w-4 h-4" /> Eliminar
              </button>
            </div>
          </div>
        </div>

        <!-- EMPTY STATE -->
        <div v-if="!loading && filteredPlans.length === 0" class="text-center py-16 px-4">
          <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4 animate-bounce-slow">
            <icon-lucide-search class="w-8 h-8 text-gray-400" />
          </div>
          <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-1">
             No se encontraron planes
          </h3>
          <p class="text-gray-500 dark:text-gray-400 max-w-xs mx-auto mb-6">
            No hay resultados para "{{ search }}" en la categoría {{ currentTabName }}.
          </p>
          <button @click="createPlan" class="bg-blue-600 text-white px-6 py-2 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-500/30">
            Crear el primero
          </button>
        </div>

      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api.js'
import NotificationToast from '@/components/NotificationToast.vue'

/* ---------------------------
   STATE
----------------------------*/
const router = useRouter()
const loading = ref(false)
const search = ref('')
const currentTab = ref('queue')
const selectedPlans = ref([])
const allPlans = ref([])
const toast = ref(null)

/* ---------------------------
   TABS
   (DEBEN coincidir con type_plans.code)
----------------------------*/
const tabs = [
  { id: 'queue', name: 'Planes Queue', icon: 'bi-list-task' },
  { id: 'pcq', name: 'Planes PCQ', icon: 'bi-diagram-3' },
  { id: 'hotspot', name: 'Planes HotSpot', icon: 'bi-wifi' },
  { id: 'pppoe', name: 'Planes PPPoE', icon: 'bi-hdd-network' },
]

/* ---------------------------
   COMPUTEDS
----------------------------*/
const currentTabName = computed(() => {
  const tab = tabs.find(t => t.id === currentTab.value)
  return tab ? tab.name.replace('Planes', '').trim() : ''
})

const filteredPlans = computed(() => {
  return allPlans.value.filter(plan => {
    const matchesType =
      plan.type_plan?.code === currentTab.value

    const matchesSearch =
      !search.value ||
      plan.name.toLowerCase().includes(search.value.toLowerCase())

    return matchesType && matchesSearch
  })
})

const selectAll = computed({
  get() {
    return (
      filteredPlans.value.length > 0 &&
      filteredPlans.value.every(p =>
        selectedPlans.value.includes(p.id)
      )
    )
  },
  set(value) {
    const ids = filteredPlans.value.map(p => p.id)
    selectedPlans.value = value
      ? [...new Set([...selectedPlans.value, ...ids])]
      : selectedPlans.value.filter(id => !ids.includes(id))
  }
})

/* ---------------------------
   METHODS
----------------------------*/

const formatCurrency = (value) => {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0
  }).format(value)
}

const createPlan = () =>
  router.push({
    path: '/planes/create',
    query: { type: currentTab.value } // queue, pcq, hotspot, pppoe
  })

const editPlan = (plan) => {
  const typeCode = plan.type_plan?.code || plan.type || currentTab.value

  router.push({
    name: 'plan-edit', 
    params: { id: plan.id },
    query: { type: typeCode }
  })
}



const loadPlans = async () => {
  loading.value = true
  try {
    const userData =
      JSON.parse(localStorage.getItem('userData')) ||
      JSON.parse(sessionStorage.getItem('userData'))

    if (!userData?.tenant_id) {
      console.warn('⚠️ No tenant, no se cargan planes')
      allPlans.value = []
      return
    }

    const response = await api.plan.getAll({
      tenant: userData.tenant_id
    })

    allPlans.value = response.data.data
  } catch (error) {
    console.error('Error cargando planes:', error)
  } finally {
    loading.value = false
  }
}

const deletePlan = async (id) => {
  if (!confirm('¿Seguro deseas eliminar este plan?')) return

  try {
    await api.plan.delete(id)
    allPlans.value = allPlans.value.filter(p => p.id !== id)
    selectedPlans.value = selectedPlans.value.filter(pId => pId !== id)
    toast.value?.success(
      'Plan eliminado',
      'El plan ha sido eliminado correctamente'
    )
  } catch (error) {
    console.error('Error eliminando plan:', error)
    toast.value?.error(
      'Error al eliminar',
      error.response?.data?.message || 'No se pudo eliminar el plan. Intenta de nuevo.'
    )
  }
}


/* ---------------------------
   LIFECYCLE
----------------------------*/
onMounted(loadPlans)
</script>


<style scoped>
/* Ocultar barra de desplazamiento en los tabs */
.scrollbar-hide::-webkit-scrollbar {
    display: none;
}
.scrollbar-hide {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
