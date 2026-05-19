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
          v-if="can('billing.create')"
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
          <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
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

            <!-- Selector de Límite -->
            <div class="relative">
              <select
                v-model="perPage"
                class="pl-10 pr-4 py-2 rounded-xl bg-gray-50 dark:bg-gray-900/50
                       border border-gray-200 dark:border-gray-700
                       focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:bg-white dark:focus:bg-gray-900
                       text-gray-700 dark:text-gray-200
                       transition-all duration-200 text-sm outline-none cursor-pointer
                       appearance-none pr-8 font-medium"
              >
                <option value="10">10 registros</option>
                <option value="100">100 registros</option>
                <option value="500">500 registros</option>
                <option value="1000">1000 registros</option>
                <option value="todos">Todos</option>
              </select>
              <icon-lucide-list class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500 dark:text-gray-400 pointer-events-none" />
              <svg class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500 dark:text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
              </svg>
            </div>
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
                @click="deleteBulkPlans"
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
                <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:text-gray-700 dark:hover:text-gray-200 transition-colors group"
                    @click="toggleSort('name')">
                  <div class="flex items-center gap-2">
                    Plan
                    <icon-lucide-arrow-up-down v-if="sortBy !== 'name'" class="w-4 h-4 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300 transition-colors" />
                    <icon-lucide-arrow-up v-else-if="sortOrder === 'asc'" class="w-4 h-4 text-blue-500" />
                    <icon-lucide-arrow-down v-else class="w-4 h-4 text-blue-500" />
                  </div>
                </th>
                <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:text-gray-700 dark:hover:text-gray-200 transition-colors group"
                    @click="toggleSort('price')">
                  <div class="flex items-center gap-2">
                    Precio
                    <icon-lucide-arrow-up-down v-if="sortBy !== 'price'" class="w-4 h-4 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300 transition-colors" />
                    <icon-lucide-arrow-up v-else-if="sortOrder === 'asc'" class="w-4 h-4 text-blue-500" />
                    <icon-lucide-arrow-down v-else class="w-4 h-4 text-blue-500" />
                  </div>
                </th>
                <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:text-gray-700 dark:hover:text-gray-200 transition-colors group"
                    @click="toggleSort('speed')">
                  <div class="flex items-center gap-2">
                    Velocidad
                    <icon-lucide-arrow-up-down v-if="sortBy !== 'speed'" class="w-4 h-4 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300 transition-colors" />
                    <icon-lucide-arrow-up v-else-if="sortOrder === 'asc'" class="w-4 h-4 text-blue-500" />
                    <icon-lucide-arrow-down v-else class="w-4 h-4 text-blue-500" />
                  </div>
                </th>
                <th class="px-4 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:text-gray-700 dark:hover:text-gray-200 transition-colors group"
                    @click="toggleSort('active')">
                  <div class="flex items-center justify-center gap-2">
                    Activos
                    <icon-lucide-arrow-up-down v-if="sortBy !== 'active'" class="w-4 h-4 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300 transition-colors" />
                    <icon-lucide-arrow-up v-else-if="sortOrder === 'asc'" class="w-4 h-4 text-blue-500" />
                    <icon-lucide-arrow-down v-else class="w-4 h-4 text-blue-500" />
                  </div>
                </th>
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
                  <div class="flex items-center justify-end gap-2">
                    <button
                      v-if="isPppoePlan(plan)"
                      @click="openSyncModal(plan)"
                      class="p-2 rounded-lg text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 dark:text-gray-500 dark:hover:text-emerald-400 transition-all duration-200"
                      title="Cargar a RB"
                    >
                      <icon-lucide-upload class="w-4 h-4" />
                    </button>
                    <button
                      v-if="can('billing.edit')"
                      @click="editPlan(plan)"
                      class="p-2 rounded-lg text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 dark:text-gray-500 dark:hover:text-blue-400 transition-all duration-200"
                      title="Editar"
                    >
                      <icon-lucide-pencil class="w-4 h-4" />
                    </button>
                    <button
                      v-if="can('billing.delete')"
                      @click="deletePlan(plan.id)"
                      class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 dark:text-gray-500 dark:hover:text-red-400 transition-all duration-200"
                      title="Eliminar"
                    >
                      <icon-lucide-trash-2 class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- PAGINACIÓN -->
          <div v-if="perPage !== 'todos' && totalPages > 1" class="bg-gray-50 dark:bg-gray-700/50 px-4 py-4 flex items-center justify-between border-t border-gray-200 dark:border-gray-700">
            <div class="text-sm text-gray-600 dark:text-gray-400 font-medium">
              {{ paginationInfo }}
            </div>
            <div class="flex items-center gap-2">
              <button
                @click="prevPage"
                :disabled="currentPage === 1"
                class="px-3 py-1.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300
                       bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                       hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed
                       transition-all duration-200 flex items-center gap-1"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                Anterior
              </button>

              <div class="flex items-center gap-1">
                <button
                  v-for="page in Math.min(5, totalPages)"
                  :key="page"
                  @click="goToPage(page)"
                  :class="{
                    'bg-blue-600 text-white border-blue-600': currentPage === page,
                    'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700': currentPage !== page
                  }"
                  class="w-8 h-8 rounded-lg font-medium text-sm border
                         transition-all duration-200"
                >
                  {{ page }}
                </button>
                <span v-if="totalPages > 5" class="text-gray-500 dark:text-gray-400 px-2">...</span>
                <button
                  v-if="totalPages > 5"
                  @click="goToPage(totalPages)"
                  :class="{
                    'bg-blue-600 text-white border-blue-600': currentPage === totalPages,
                    'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700': currentPage !== totalPages
                  }"
                  class="w-8 h-8 rounded-lg font-medium text-sm border
                         transition-all duration-200"
                >
                  {{ totalPages }}
                </button>
              </div>

              <button
                @click="nextPage"
                :disabled="currentPage === totalPages"
                class="px-3 py-1.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300
                       bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                       hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed
                       transition-all duration-200 flex items-center gap-1"
              >
                Siguiente
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
              </button>
            </div>
          </div>
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
                <v-icon name="bi-people" class="w-3.5 h-3.5" />
                {{ plan.active_clients_count }} clientes
              </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-gray-100 dark:border-gray-700 mt-1">
              <button
                v-if="isPppoePlan(plan)"
                @click="openSyncModal(plan)"
                class="flex-1 py-2 rounded-lg text-sm font-medium text-emerald-600 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/20 dark:text-emerald-400 dark:hover:bg-emerald-900/30 transition flex items-center justify-center gap-2"
              >
                <icon-lucide-upload class="w-4 h-4" /> Cargar RB
              </button>
              <button
                v-if="can('billing.edit')"
                @click="editPlan(plan)"
                class="flex-1 py-2 rounded-lg text-sm font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:text-blue-400 dark:hover:bg-blue-900/30 transition flex items-center justify-center gap-2"
              >
                <icon-lucide-pencil class="w-4 h-4" /> Editar
              </button>
              <button
                v-if="can('billing.delete')"
                @click="deletePlan(plan.id)"
                class="flex-1 py-2 rounded-lg text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30 transition flex items-center justify-center gap-2"
              >
                <icon-lucide-trash-2 class="w-4 h-4" /> Eliminar
              </button>
            </div>
          </div>

          <!-- PAGINACIÓN MOBILE -->
          <div v-if="perPage !== 'todos' && totalPages > 1" class="md:hidden bg-gray-50 dark:bg-gray-700/50 px-4 py-4 rounded-lg border border-gray-200 dark:border-gray-700 flex flex-col gap-3">
            <div class="text-sm text-gray-600 dark:text-gray-400 font-medium text-center">
              {{ paginationInfo }}
            </div>
            <div class="flex items-center justify-between gap-2">
              <button
                @click="prevPage"
                :disabled="currentPage === 1"
                class="px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300
                       bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                       hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed
                       transition-all duration-200 flex items-center gap-2 flex-1"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                Anterior
              </button>

              <div class="text-sm font-semibold text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600">
                {{ currentPage }} / {{ totalPages }}
              </div>

              <button
                @click="nextPage"
                :disabled="currentPage === totalPages"
                class="px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300
                       bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                       hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed
                       transition-all duration-200 flex items-center gap-2 flex-1"
              >
                Siguiente
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
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

    <div
      v-if="showSyncModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
      @click.self="closeSyncModal"
    >
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg p-6 m-4">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
              <icon-lucide-upload class="w-6 h-6 text-emerald-600" />
              Cargar a RB
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
              {{ planToSync?.name || 'Plan PPPoE' }}
            </p>
          </div>
          <button
            @click="closeSyncModal"
            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
          >
            <icon-lucide-x class="w-6 h-6" />
          </button>
        </div>

        <div class="space-y-4">
          <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg p-4">
            <div class="flex items-start gap-3">
              <icon-lucide-server class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0 mt-0.5" />
              <div>
                <h4 class="font-medium text-emerald-800 dark:text-emerald-300">Perfil PPPoE que se cargara</h4>
                <p class="text-sm text-emerald-700 dark:text-emerald-400 mt-1">
                  {{ planToSync?.name }} • {{ planToSync?.speed_down }} / {{ planToSync?.speed_up }}
                </p>
              </div>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Router destino
            </label>
            <select
              v-model="selectedSyncRouterId"
              :disabled="loadingSyncRouters || syncingProfile || availableRouters.length === 0"
              class="w-full h-11 px-4 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none text-sm appearance-none disabled:opacity-60"
            >
              <option value="">
                {{ loadingSyncRouters ? 'Cargando routers...' : 'Seleccionar router...' }}
              </option>
              <option
                v-for="rb in availableRouters"
                :key="rb.id"
                :value="String(rb.id)"
              >
                {{ rb.name }} - {{ rb.ip }}{{ rb.pppoe ? ' • PPPoE' : '' }}
              </option>
            </select>
          </div>

          <div v-if="selectedSyncRouter" class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            Se cargara el perfil en <strong>{{ selectedSyncRouter.name }}</strong> ({{ selectedSyncRouter.ip }}).
            <span v-if="!selectedSyncRouter.pppoe">Este router no tiene marcado el flag PPPoE en el sistema, pero igualmente se intentara la carga.</span>
          </div>

          <div v-if="!loadingSyncRouters && availableRouters.length === 0" class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 text-sm text-yellow-700 dark:text-yellow-300">
            No se encontraron routers disponibles para cargar el perfil.
          </div>
        </div>

        <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
          <button
            @click="closeSyncModal"
            class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
          >
            Cancelar
          </button>
          <button
            @click="syncPppoePlanToRouter"
            :disabled="syncingProfile || !selectedSyncRouterId"
            class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
          >
            <icon-lucide-loader-2 v-if="syncingProfile" class="w-4 h-4 animate-spin" />
            <icon-lucide-upload v-else class="w-4 h-4" />
            {{ syncingProfile ? 'Cargando...' : 'Cargar a RB' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Modal Confirmar Eliminación -->
    <div
      v-if="showDeleteModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
      @click.self="closeDeleteModal"
    >
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6 m-4">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
          <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
              <icon-lucide-trash-2 class="w-6 h-6 text-red-600" />
              Eliminar Plan
            </h2>
          </div>
          <button
            @click="closeDeleteModal"
            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
          >
            <icon-lucide-x class="w-6 h-6" />
          </button>
        </div>

        <!-- Content -->
        <div class="space-y-4">
          <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <div class="flex items-start gap-3">
              <icon-lucide-alert-triangle class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
              <div>
                <h4 class="font-medium text-red-800 dark:text-red-300">¿Estás seguro?</h4>
                <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                  Esta acción no se puede deshacer. El plan <strong>"{{ planToDelete?.name }}"</strong> será eliminado permanentemente.
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
          <button
            @click="closeDeleteModal"
            class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
          >
            Cancelar
          </button>
          <button
            @click="confirmDelete"
            :disabled="deletingPlan"
            class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
          >
            <icon-lucide-loader-2 v-if="deletingPlan" class="w-4 h-4 animate-spin" />
            <icon-lucide-trash v-else class="w-4 h-4" />
            {{ deletingPlan ? 'Eliminando...' : 'Eliminar' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api.js'
import NotificationToast from '@/components/NotificationToast.vue'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()

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
const perPage = ref(10)
const currentPage = ref(1)
const sortBy = ref('name')
const sortOrder = ref('asc')

// Estados del modal eliminar
const showDeleteModal = ref(false)
const planToDelete = ref(null)
const deletingPlan = ref(false)

// Estados del modal sync PPPoE
const showSyncModal = ref(false)
const planToSync = ref(null)
const syncingProfile = ref(false)
const loadingSyncRouters = ref(false)
const selectedSyncRouterId = ref('')
const routers = ref([])

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

const allFilteredPlans = computed(() => {
  let filtered = allPlans.value.filter(plan => {
    const matchesType =
      plan.type_plan?.code === currentTab.value

    const matchesSearch =
      !search.value ||
      plan.name.toLowerCase().includes(search.value.toLowerCase())

    return matchesType && matchesSearch
  })

  // Aplicar ordenamiento
  filtered.sort((a, b) => {
    let aVal, bVal

    switch (sortBy.value) {
      case 'name':
        aVal = a.name.toLowerCase()
        bVal = b.name.toLowerCase()
        break
      case 'price':
        aVal = a.cost_product || 0
        bVal = b.cost_product || 0
        break
      case 'speed':
        aVal = parseInt(a.speed_down) || 0
        bVal = parseInt(b.speed_down) || 0
        break
      case 'active':
        aVal = a.active_clients_count || 0
        bVal = b.active_clients_count || 0
        break
      default:
        return 0
    }

    if (aVal < bVal) return sortOrder.value === 'asc' ? -1 : 1
    if (aVal > bVal) return sortOrder.value === 'asc' ? 1 : -1
    return 0
  })

  return filtered
})

const totalPages = computed(() => {
  if (perPage.value === 'todos') return 1
  const total = allFilteredPlans.value.length
  const limit = parseInt(perPage.value)
  return Math.ceil(total / limit)
})

const filteredPlans = computed(() => {
  if (perPage.value === 'todos') {
    return allFilteredPlans.value
  }

  const limit = parseInt(perPage.value)
  const start = (currentPage.value - 1) * limit
  const end = start + limit

  return allFilteredPlans.value.slice(start, end)
})

const paginationInfo = computed(() => {
  if (perPage.value === 'todos') {
    return `Total: ${allFilteredPlans.value.length} registros`
  }
  const limit = parseInt(perPage.value)
  const total = allFilteredPlans.value.length
  const start = (currentPage.value - 1) * limit + 1
  const end = Math.min(currentPage.value * limit, total)
  return `${start}-${end} de ${total}`
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

const availableRouters = computed(() => {
  return [...routers.value].sort((a, b) => {
    if (!!a.pppoe !== !!b.pppoe) {
      return a.pppoe ? -1 : 1
    }

    return `${a.name} ${a.ip}`.localeCompare(`${b.name} ${b.ip}`)
  })
})

const selectedSyncRouter = computed(() => {
  return availableRouters.value.find(rb => String(rb.id) === String(selectedSyncRouterId.value)) || null
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

const nextPage = () => {
  if (currentPage.value < totalPages.value) {
    currentPage.value++
  }
}

const prevPage = () => {
  if (currentPage.value > 1) {
    currentPage.value--
  }
}

const goToPage = (page) => {
  if (page >= 1 && page <= totalPages.value) {
    currentPage.value = page
  }
}

const resetPagination = () => {
  currentPage.value = 1
}

const toggleSort = (column) => {
  if (sortBy.value === column) {
    sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value = column
    sortOrder.value = 'asc'
  }
  resetPagination()
}

const getSortIcon = (column) => {
  if (sortBy.value !== column) return null
  return sortOrder.value === 'asc' ? 'arrow-up' : 'arrow-down'
}

const getTenantId = () => {
  const userData =
    JSON.parse(localStorage.getItem('userData')) ||
    JSON.parse(sessionStorage.getItem('userData'))

  return userData?.tenant_id || null
}

const isPppoePlan = (plan) => {
  return (plan?.type_plan?.code || plan?.type) === 'pppoe'
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

const loadRouters = async () => {
  loadingSyncRouters.value = true

  try {
    const tenantId = getTenantId()
    const response = await api.routers.getAll(tenantId ? { tenant: tenantId } : {})
    const data = Array.isArray(response.data) ? response.data : response.data?.data || []

    routers.value = data.map(routerData => ({
      id: routerData.id,
      name: routerData.name,
      ip: routerData.ip,
      pppoe: !!routerData.pppoe,
      status: routerData.status,
    }))
  } catch (error) {
    console.error('Error cargando routers:', error)
    toast.value?.error(
      'Error al cargar routers',
      error.response?.data?.message || 'No se pudo obtener la lista de routers.'
    )
  } finally {
    loadingSyncRouters.value = false
  }
}

const openSyncModal = async (plan) => {
  planToSync.value = plan
  showSyncModal.value = true
  selectedSyncRouterId.value = ''

  await loadRouters()

  const preferredRouter =
    availableRouters.value.find(rb => rb.pppoe) ||
    availableRouters.value[0]

  if (preferredRouter) {
    selectedSyncRouterId.value = String(preferredRouter.id)
  }
}

const closeSyncModal = () => {
  showSyncModal.value = false
  planToSync.value = null
  selectedSyncRouterId.value = ''
}

const syncPppoePlanToRouter = async () => {
  if (!planToSync.value || !selectedSyncRouterId.value) {
    toast.value?.warning(
      'Seleccion faltante',
      'Selecciona el router destino para cargar el perfil PPPoE.'
    )
    return
  }

  syncingProfile.value = true

  try {
    const tenantId = getTenantId()
    const { data } = await api.plan.syncPppoeProfile(
      planToSync.value.id,
      {
        router_id: Number(selectedSyncRouterId.value),
      },
      tenantId ? { tenant: tenantId } : {}
    )

    toast.value?.success(
      'Perfil cargado',
      data.message || 'El perfil PPPoE fue cargado correctamente en la RB.'
    )

    closeSyncModal()
  } catch (error) {
    toast.value?.error(
      'Error al cargar a RB',
      error.response?.data?.message || 'No se pudo cargar el perfil PPPoE en la RB seleccionada.'
    )
  } finally {
    syncingProfile.value = false
  }
}



const loadPlans = async () => {
  loading.value = true
  try {
    const tenantId = getTenantId()

    if (!tenantId) {
      console.warn('⚠️ No tenant, no se cargan planes')
      allPlans.value = []
      return
    }

    const response = await api.plan.getAll({
      tenant: tenantId
    })

    allPlans.value = response.data.data
  } catch (error) {
    console.error('Error cargando planes:', error)
  } finally {
    loading.value = false
  }
}

// Abrir modal de confirmación para eliminar
const deletePlan = (id) => {
  const planData = allPlans.value.find(p => p.id === id)
  if (planData) {
    planToDelete.value = planData
    showDeleteModal.value = true
  }
}

// Cerrar modal de eliminar
const closeDeleteModal = () => {
  showDeleteModal.value = false
  planToDelete.value = null
}

// Confirmar eliminación
const confirmDelete = async () => {
  if (!planToDelete.value) return
  
  deletingPlan.value = true
  
  try {
    await api.plan.delete(planToDelete.value.id)
    
    allPlans.value = allPlans.value.filter(p => p.id !== planToDelete.value.id)
    selectedPlans.value = selectedPlans.value.filter(pId => pId !== planToDelete.value.id)
    
    toast.value?.success(
      'Plan eliminado',
      `El plan "${planToDelete.value.name}" ha sido eliminado correctamente`
    )
    
    closeDeleteModal()
  } catch (error) {
    toast.value?.error(
      'Error al eliminar',
      error.response?.data?.message || 'No se pudo eliminar el plan. Intenta de nuevo.'
    )
  } finally {
    deletingPlan.value = false
  }
}

// Eliminar planes seleccionados (bulk delete)
const deleteBulkPlans = async () => {
  if (selectedPlans.value.length === 0) return
  
  const confirmMsg = `¿Estás seguro de eliminar ${selectedPlans.value.length} plan(es) seleccionado(s)? Esta acción no se puede deshacer.`
  
  if (!confirm(confirmMsg)) return
  
  const plansToDelete = [...selectedPlans.value]
  let successCount = 0
  let errorCount = 0
  
  for (const planId of plansToDelete) {
    try {
      await api.plan.delete(planId)
      allPlans.value = allPlans.value.filter(p => p.id !== planId)
      selectedPlans.value = selectedPlans.value.filter(id => id !== planId)
      successCount++
    } catch (error) {
      console.error(`Error eliminando plan ${planId}:`, error)
      errorCount++
    }
  }
  
  if (successCount > 0) {
    toast.value?.success(
      'Planes eliminados',
      `${successCount} plan(es) eliminado(s) correctamente`
    )
  }
  
  if (errorCount > 0) {
    toast.value?.error(
      'Error parcial',
      `No se pudieron eliminar ${errorCount} plan(es)`
    )
  }
}


/* ---------------------------
   WATCHERS
----------------------------*/
import { watch } from 'vue'

watch([search, currentTab, perPage], () => {
  resetPagination()
})

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
