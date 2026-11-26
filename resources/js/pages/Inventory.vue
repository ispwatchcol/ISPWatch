<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">
      
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
              <v-icon name="bi-box-seam" class="text-purple-600 dark:text-purple-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            Inventario
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">Gestiona todos los dispositivos del inventario</p>
        </div>
        
        <button
          v-if="can('view_inventory')"
          @click="$router.push({ name: 'InventoryIntake', query: { tipo: 'equipo' } })"
          class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800
                 text-white px-5 py-3 rounded-xl flex items-center justify-center gap-2
                 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5
                 font-medium w-full sm:w-auto"
        >
          <v-icon name="md-add" class="w-5 h-5 fill-current" />
          <span>Ingresar equipos</span>
        </button>
      </div>

      <!-- Cuántos hay y dónde. Las cifras respetan la búsqueda y los filtros de
           proveedor y sucursal, y cada tarjeta filtra la tabla por su estado.
           Antes contaban el catálogo («En stock» eran MODELOS) y al buscar
           «RG-EW300» ninguna cifra decía cuántos había ni cuántos estaban
           instalados (Chaguaní, 2026-11-26). -->
      <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 md:gap-4 mb-6" data-testid="status-cards">
        <button
          v-for="card in statusCards"
          :key="card.key || 'all'"
          type="button"
          @click="setStatus(card.key)"
          :aria-pressed="filters.status === card.key"
          :data-testid="`status-card-${card.key || 'all'}`"
          class="text-left rounded-xl bg-white dark:bg-gray-800 shadow-md p-4 border-l-4 transition-all
                 hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500"
          :class="[
            card.border,
            card.key === '' ? 'col-span-2 lg:col-span-1' : '',
            filters.status === card.key ? 'ring-2 ring-purple-500 ring-offset-2 ring-offset-gray-50 dark:ring-offset-gray-900' : ''
          ]"
        >
          <span class="block text-xs font-medium text-gray-600 dark:text-gray-400 uppercase tracking-wide">{{ card.label }}</span>
          <span class="block text-2xl md:text-3xl font-bold text-gray-900 dark:text-gray-100 mt-1" :data-testid="`status-count-${card.key || 'all'}`">
            {{ loading ? '…' : card.count }}
          </span>
          <span class="block text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ card.hint }}</span>
        </button>
      </div>

      <!-- Filters and Search -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <!-- Search -->
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
              <v-icon name="md-search" class="w-4 h-4 mr-1" />
              Buscar
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                <v-icon name="md-search" class="w-5 h-5 text-gray-400" />
              </div>
              <input
                v-model="filters.search"
                type="search"
                data-testid="inventory-search"
                placeholder="Modelo, serial, MAC, cliente o técnico…"
                class="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-gray-600 
                       rounded-xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 focus:border-transparent
                       transition-all outline-none"
              />
            </div>
          </div>

          <!-- Provider Filter -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
              <v-icon name="bi-building" class="w-4 h-4 mr-1" />
              Proveedor
            </label>
            <div class="relative">
              <select
                v-model="filters.provider"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 
                       rounded-xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 transition-all outline-none appearance-none"
              >
                <option value="">Todos</option>
                <option
                  v-for="provider in providers"
                  :key="provider.id"
                  :value="provider.id"
                >
                  {{ provider.name }}
                </option>
              </select>
              <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                <v-icon name="md-keyboardarrowdown" />
              </div>
            </div>
          </div>

          <!-- Branch Filter -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
              <v-icon name="md-storemalldirectory" class="w-4 h-4 mr-1" />
              Sucursal
            </label>
            <div class="relative">
              <select
                v-model="filters.branch"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 
                       rounded-xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 transition-all outline-none appearance-none"
              >
                <option value="">Todas</option>
                <option
                  v-for="branch in branches"
                  :key="branch.id"
                  :value="branch.id"
                >
                  {{ branch.name }}
                </option>
              </select>
              <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                <v-icon name="md-keyboardarrowdown" />
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Desktop Table View -->
      <div class="hidden lg:block bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-100 dark:bg-gray-700">
              <tr>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">ID</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Dispositivo</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Serial / MAC</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Proveedor</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Dónde está</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Precio</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr
                v-for="device in pageDevices"
                :key="device.id"
                class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
              >
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="text-sm font-bold text-purple-600 dark:text-purple-400">#{{ device.id }}</span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
                      <v-icon name="bi-box-seam" class="w-4 h-4 text-purple-600 dark:text-purple-400" />
                    </div>
                    <div>
                      <div class="text-sm font-medium text-gray-800 dark:text-white">
                        {{ device.stock_brand || 'Sin marca' }} {{ device.stock_model || '' }}
                      </div>
                      <div class="text-xs text-gray-500 dark:text-gray-400">
                        Stock ID: {{ device.stock_id || 'N/A' }}
                      </div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-xs">
                    <div class="text-gray-700 dark:text-gray-300 flex items-center gap-1 font-mono">
                      <v-icon name="bi-upc" class="w-3 h-3" />
                      {{ device.serial || 'N/A' }}
                    </div>
                    <div class="text-gray-500 dark:text-gray-500 mt-1 flex items-center gap-1 font-mono">
                      <v-icon name="md-wifi" class="w-3 h-3" />
                      {{ device.mac || 'N/A' }}
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm text-gray-800 dark:text-gray-200">
                    <v-icon name="bi-building" class="w-3 h-3 mr-1" />
                    {{ device.provider_name || 'Sin proveedor' }}
                  </div>
                </td>
                <td class="px-6 py-4">
                  <!-- Estado y custodio: sin esto no se sabía que el equipo
                       estaba instalado hasta que «Eliminar» lo rechazaba. -->
                  <span class="text-[11px] font-medium px-2 py-0.5 rounded-full" :class="statusClass(device.status)">
                    {{ statusLabel(device.status) }}
                  </span>
                  <div class="mt-1 text-xs text-gray-600 dark:text-gray-300 max-w-[14rem] truncate" :title="device.holder_label">
                    {{ device.holder_label || device.branch_name || 'Bodega' }}
                  </div>
                  <!-- Orden de prospecto: todavía no hay cliente. Sin esta
                       línea se leía «Instalado · cliente» y nadie sabía de quién. -->
                  <div v-if="device.installed_for?.type === 'prospect'" data-testid="installed-for-prospect"
                    class="mt-0.5 text-[11px] text-amber-700 dark:text-amber-300">
                    Aún no es cliente · pasa a su ficha al darlo de alta
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="text-sm font-bold text-green-600 dark:text-green-400">
                    {{ formatCurrency(device.stock_price) }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center gap-2">
                    <button
                      @click="viewDevice(device)"
                      class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 
                             rounded-lg transition-all hover:scale-110"
                      title="Ver detalles"
                    >
                      <v-icon name="md-visibility" class="w-4 h-4 fill-current" />
                    </button>
                    <button
                      v-if="can('view_inventory')"
                      @click="editDevice(device)"
                      class="p-2 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 
                             rounded-lg transition-all hover:scale-110"
                      title="Editar"
                    >
                      <v-icon name="md-edit" class="w-4 h-4 fill-current" />
                    </button>
                    <button
                      v-if="can('view_inventory') && canRetire(device)"
                      @click="openRetire(device)"
                      data-testid="retire-device"
                      class="p-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/30
                             rounded-lg transition-all hover:scale-110"
                      title="Dar de baja (dañado, perdido o devuelto al proveedor)"
                    >
                      <v-icon name="md-cancel-outlined" class="w-4 h-4 fill-current" />
                    </button>
                    <button
                      v-if="can('delete_inventory')"
                      @click="deleteDevice(device)"
                      class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30
                             rounded-lg transition-all hover:scale-110"
                      title="Eliminar"
                    >
                      <v-icon name="md-delete" class="w-4 h-4 fill-current" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <div v-if="loading" class="flex items-center justify-center py-16 text-gray-500 dark:text-gray-400">
            <v-icon name="ri-loader-4-line" animation="spin" class="w-6 h-6 mr-2" /> Cargando equipos…
          </div>

          <!-- Empty State -->
          <div
            v-if="!loading && filteredDevices.length === 0"
            class="text-center py-16"
            data-testid="inventory-empty"
          >
            <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
              <v-icon name="bi-box-seam" class="w-10 h-10 text-gray-400" />
            </div>
            <template v-if="hasActiveFilters">
              <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">Ningún equipo coincide con los filtros</p>
              <button @click="clearFilters" type="button"
                class="mt-6 px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors text-sm font-medium">
                Limpiar filtros
              </button>
            </template>
            <template v-else>
              <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No se encontraron dispositivos</p>
              <p class="text-gray-400 dark:text-gray-500 text-sm mt-2">
                Intenta ajustar los filtros o agrega un nuevo dispositivo
              </p>
              <button
                @click="$router.push({ name: 'InventoryIntake', query: { tipo: 'equipo' } })"
                class="mt-6 px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg
                       transition-colors text-sm font-medium inline-flex items-center gap-2"
              >
                <v-icon name="md-add" class="w-4 h-4 fill-current" />
                Agregar Primer Dispositivo
              </button>
            </template>
          </div>
        </div>

        <!-- Pagination: cuenta lo FILTRADO. Antes contaba todo el inventario,
             así que con una búsqueda ofrecía páginas vacías y, al entrar a una,
             la tabla quedaba en blanco y sin paginador para volver. -->
        <div v-if="filteredDevices.length > 0" class="px-6 py-4 border-t border-gray-200 dark:border-gray-700
                    flex flex-col sm:flex-row items-center justify-between gap-4 bg-gray-50 dark:bg-gray-900/50">
          <div class="text-sm text-gray-700 dark:text-gray-300" data-testid="pagination-summary">
            Mostrando <span class="font-semibold">{{ paginationStart }}</span> -
            <span class="font-semibold">{{ paginationEnd }}</span> de
            <span class="font-semibold">{{ totalDevices }}</span> {{ totalDevices === 1 ? 'equipo' : 'equipos' }}
            <span v-if="totalDevices !== devices.length" class="text-gray-500 dark:text-gray-400">
              (filtrados de {{ devices.length }})
            </span>
          </div>
          <Pagination :current-page="currentPage" :total-pages="totalPages" @change="goToPage" />
        </div>
      </div>

      <!-- Mobile Card View -->
      <div class="lg:hidden space-y-4">
        <div
          v-for="device in pageDevices"
          :key="device.id"
          class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 hover:shadow-lg transition-all"
        >
          <!-- Header -->
          <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-2">
              <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
                <v-icon name="bi-box-seam" class="w-5 h-5 text-purple-600 dark:text-purple-400" />
              </div>
              <div>
                <span class="text-xs font-bold text-purple-600 dark:text-purple-400">#{{ device.id }}</span>
                <p class="text-sm font-medium text-gray-800 dark:text-white mt-0.5">
                  {{ device.stock_brand || 'Sin marca' }} {{ device.stock_model || '' }}
                </p>
              </div>
            </div>
            <span class="text-xs font-bold text-green-600 dark:text-green-400">
              {{ formatCurrency(device.stock_price) }}
            </span>
          </div>

          <!-- Info Grid -->
          <div class="grid grid-cols-2 gap-3 mb-3">
            <div class="col-span-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg p-2">
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Serial</p>
              <p class="text-sm text-gray-800 dark:text-gray-200 font-mono">
                {{ device.serial || 'N/A' }}
              </p>
            </div>
            <div class="col-span-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg p-2">
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">MAC Address</p>
              <p class="text-sm text-gray-800 dark:text-gray-200 font-mono">
                {{ device.mac || 'N/A' }}
              </p>
            </div>
            <div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Proveedor</p>
              <p class="text-sm text-gray-800 dark:text-gray-200 truncate">
                {{ device.provider_name || 'N/A' }}
              </p>
            </div>
            <div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Dónde está</p>
              <span class="text-[11px] font-medium px-2 py-0.5 rounded-full" :class="statusClass(device.status)">{{ statusLabel(device.status) }}</span>
              <p class="mt-1 text-xs text-gray-800 dark:text-gray-200 break-words">
                {{ device.holder_label || device.branch_name || 'Bodega' }}
              </p>
              <p v-if="device.installed_for?.type === 'prospect'" class="mt-0.5 text-[11px] text-amber-700 dark:text-amber-300">
                Aún no es cliente
              </p>
            </div>
          </div>

          <!-- Actions -->
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="viewDevice(device)"
              class="py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                     transition-colors text-sm font-medium flex items-center justify-center gap-1"
            >
              <v-icon name="md-visibility" class="w-4 h-4 fill-current" />
              Ver
            </button>
            <button
              v-if="can('view_inventory')"
              @click="editDevice(device)"
              class="py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg
                     transition-colors text-sm font-medium flex items-center justify-center gap-1"
            >
              <v-icon name="md-edit" class="w-4 h-4 fill-current" />
              Editar
            </button>
            <button
              v-if="can('view_inventory') && canRetire(device)"
              @click="openRetire(device)"
              class="py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg
                     transition-colors text-sm font-medium flex items-center justify-center gap-1"
            >
              <v-icon name="md-cancel-outlined" class="w-4 h-4 fill-current" />
              Dar de baja
            </button>
            <button
              v-if="can('delete_inventory')"
              @click="deleteDevice(device)"
              class="py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg
                     transition-colors text-sm font-medium flex items-center justify-center gap-1"
            >
              <v-icon name="md-delete" class="w-4 h-4 fill-current" />
              Eliminar
            </button>
          </div>
        </div>

        <div v-if="loading" class="flex items-center justify-center py-10 text-gray-500 dark:text-gray-400">
          <v-icon name="ri-loader-4-line" animation="spin" class="w-6 h-6 mr-2" /> Cargando equipos…
        </div>

        <!-- Mobile Empty State -->
        <div
          v-if="!loading && filteredDevices.length === 0"
          class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center"
        >
          <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
            <v-icon name="bi-box-seam" class="w-10 h-10 text-gray-400" />
          </div>
          <template v-if="hasActiveFilters">
            <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">Ningún equipo coincide</p>
            <button @click="clearFilters" type="button"
              class="mt-6 w-full py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors text-sm font-medium">
              Limpiar filtros
            </button>
          </template>
          <template v-else>
            <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">Sin dispositivos</p>
            <p class="text-gray-400 dark:text-gray-500 text-sm mt-2 mb-6">
              No hay dispositivos en el inventario
            </p>
            <button
              @click="$router.push({ name: 'InventoryIntake', query: { tipo: 'equipo' } })"
              class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg
                     transition-colors text-sm font-medium flex items-center justify-center gap-2"
            >
              <v-icon name="md-add" class="w-4 h-4 fill-current" />
              Agregar Dispositivo
            </button>
          </template>
        </div>

        <!-- Mobile Pagination -->
        <div v-if="filteredDevices.length > 0"
             class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 flex flex-col gap-3">
          <div class="text-sm text-center text-gray-700 dark:text-gray-300">
            <span class="font-semibold">{{ paginationStart }}</span> -
            <span class="font-semibold">{{ paginationEnd }}</span> de
            <span class="font-semibold">{{ totalDevices }}</span>
            <span v-if="totalDevices !== devices.length" class="text-gray-500 dark:text-gray-400">
              (filtrados de {{ devices.length }})
            </span>
          </div>
          <Pagination :current-page="currentPage" :total-pages="totalPages" @change="goToPage" class="justify-center" />
        </div>
      </div>

      <!-- Delete Confirmation Modal -->
      <div
        v-if="showDeleteModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
        @click.self="closeDeleteModal"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <v-icon name="md-delete" class="w-6 h-6 text-red-600" />
                Eliminar Dispositivo
              </h2>
            </div>
            <button
              @click="closeDeleteModal"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
              <v-icon name="md-close" class="w-6 h-6" />
            </button>
          </div>

          <!-- Content -->
          <div class="space-y-4">
            <!-- Instalado: no se ofrece un «Eliminar» que el servidor va a
                 rechazar. Se dice dónde está y cómo se saca. -->
            <div v-if="deviceToDelete?.status === 'installed'" data-testid="delete-installed"
              class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4 text-sm text-amber-900 dark:text-amber-200">
              <p class="font-medium">Este equipo está instalado: {{ deviceToDelete?.holder_label }}.</p>
              <p v-if="deviceToDelete?.installed_for?.type === 'prospect'" class="mt-1" data-testid="delete-installed-prospect">
                Se registró en la orden #{{ deviceToDelete?.installed_for?.installation_id }} de un prospecto que todavía no es
                cliente. Al darlo de alta, el equipo pasa a su ficha. Si se cargó por error y la orden aún no está firmada,
                quítalo desde la hoja (<em>Equipos y materiales usados</em> → <em>Quitar</em>).
              </p>
              <p v-else class="mt-1">
                No se puede eliminar ni dar de baja mientras siga en casa del cliente. Retíralo desde un
                <strong>ticket de ese cliente</strong> → <em>Equipos de la visita</em> → <em>Retirar equipo del
                cliente</em>: elige si vuelve a la bodega o si se da de baja (dañado).
              </p>
            </div>
            <div v-else class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <v-icon name="md-warning-round" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                <div>
                  <h4 class="font-medium text-red-800 dark:text-red-300">¿Estás seguro?</h4>
                  <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                    Esta acción no se puede deshacer. El dispositivo <strong>"#{{ deviceToDelete?.id }} - {{ deviceToDelete?.stock_brand }} {{ deviceToDelete?.stock_model }}"</strong> será eliminado permanentemente.
                  </p>
                  <p class="text-xs text-red-500 dark:text-red-400 mt-2">
                    Eliminar es para lo que se cargó por error. Si el equipo se dañó, se perdió o volvió al
                    proveedor, usa <strong>Dar de baja</strong>: sale del inventario y queda en el historial.
                  </p>
                </div>
              </div>
            </div>
            <!-- El motivo del rechazo, aquí y no sólo en un aviso que se va. -->
            <div v-if="deleteError" data-testid="delete-error"
              class="rounded-lg border border-slate-300 dark:border-gray-600 bg-slate-50 dark:bg-gray-900/40 p-3 text-sm text-slate-800 dark:text-slate-200">
              <p>{{ deleteError }}</p>
              <button v-if="canRetire(deviceToDelete)" type="button" @click="retireInsteadOfDelete"
                class="mt-2 px-3 py-1.5 text-xs font-semibold text-white bg-amber-600 hover:bg-amber-700 rounded-lg">
                Dar de baja en su lugar
              </button>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="closeDeleteModal"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
              :disabled="deleting"
            >
              Cancelar
            </button>
            <button
              v-if="deviceToDelete?.status !== 'installed'"
              @click="confirmDeleteDevice"
              :disabled="deleting"
              class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
            >
              <v-icon v-if="deleting" name="ri-loader-4-line" animation="spin" class="w-4 h-4" />
              <v-icon v-else name="md-delete" class="w-4 h-4" />
              {{ deleting ? 'Eliminando...' : 'Eliminar' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Dar de baja: dañado, perdido o devuelto al proveedor. -->
      <div v-if="retireTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
        @click.self="retireTarget = null">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6" data-testid="retire-modal">
          <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2 mb-2">
            <v-icon name="md-cancel-outlined" class="w-6 h-6 text-amber-600" />
            Dar de baja
          </h2>
          <p class="text-sm text-gray-600 dark:text-gray-300">
            <strong>#{{ retireTarget.id }} · {{ retireTarget.stock_brand }} {{ retireTarget.stock_model }}</strong>
            <span class="font-mono text-xs"> · {{ retireTarget.serial || 'sin serial' }}</span>
          </p>
          <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Sale del inventario y no vuelve a ofrecerse en órdenes ni tickets. No se borra: queda en
            Movimientos como «Baja», con tu nombre y el motivo.
          </p>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mt-4 mb-1">Motivo (opcional)</label>
          <input v-model="retireNotes" type="text" maxlength="255" placeholder="Dañado por rayo, perdido, devuelto al proveedor…"
            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-amber-500 outline-none" />
          <div class="flex justify-end gap-3 mt-6">
            <button @click="retireTarget = null" :disabled="retiring"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg">Cancelar</button>
            <button @click="confirmRetire" :disabled="retiring" data-testid="retire-confirm"
              class="px-4 py-2 text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 rounded-lg disabled:opacity-50">
              {{ retiring ? 'Dando de baja…' : 'Dar de baja' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Notification Toast -->
      <NotificationToast ref="toast" />

    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import inventoryApi from '@/services/api/inventory'
import inventoryProviderApi from '@/services/api/inventory-provider'
import inventoryBranchApi from '@/services/api/inventory-branch'
import { firstError } from '@/utils/apiError'
import { searchIndex, searchTerms, matchesIndex } from '@/utils/textSearch'
import NotificationToast from '@/components/NotificationToast.vue'
import Pagination from '@/components/ui/Pagination.vue'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()
const router = useRouter()

// State
const devices = ref([])
const providers = ref([])
const branches = ref([])
const loading = ref(true)
const toast = ref(null)

// Delete modal state
const showDeleteModal = ref(false)
const deviceToDelete = ref(null)
const deleting = ref(false)
const tenantId = ref(null)

// Get tenant_id from logged-in user
const getUserTenantId = () => {
  const userData = JSON.parse(localStorage.getItem('userData')) ?? JSON.parse(sessionStorage.getItem('userData'))
  if (!userData?.tenant_id) {
    console.error('⚠️ No se encontró tenant_id del usuario autenticado.')
    return null
  }
  return userData.tenant_id
}

// Filters — `status` lo ponen las tarjetas de arriba ('' = todos).
const blankFilters = () => ({ search: '', provider: '', branch: '', status: '' })
const filters = ref(blankFilters())

// Pagination
const currentPage = ref(1)
const itemsPerPage = ref(10)

// Un equipo viejo sin estado se trata como en bodega, igual que su etiqueta.
const STATUS_KEYS = ['stock', 'assigned', 'installed', 'retired']
const statusOf = (device) => (STATUS_KEYS.includes(device.status) ? device.status : 'stock')

// 1) Búsqueda + proveedor + sucursal, SIN el estado: es la base de los conteos
//    (si el estado también filtrara aquí, al pulsar «En bodega» las demás
//    tarjetas caerían a cero).
const matchingDevices = computed(() => {
  const terms = searchTerms(filters.value.search)
  const { provider, branch } = filters.value

  return devices.value.filter(d =>
    (!terms.length || matchesIndex(terms, d._search)) &&
    (!provider || d.provider_id == provider) &&
    (!branch || d.branch_id == branch)
  )
})

const statusCounts = computed(() => {
  const counts = { stock: 0, assigned: 0, installed: 0, retired: 0 }
  for (const d of matchingDevices.value) counts[statusOf(d)]++
  return counts
})

const hasActiveFilters = computed(() => {
  const f = filters.value
  return !!(f.search.trim() || f.provider || f.branch || f.status)
})

const statusCards = computed(() => {
  const c = statusCounts.value
  const disponibles = c.stock + c.assigned
  const conFiltro = !!(filters.value.search.trim() || filters.value.provider || filters.value.branch)

  return [
    {
      key: '', label: conFiltro ? 'Coinciden' : 'Todos', count: matchingDevices.value.length, border: 'border-purple-500',
      // Lo que el ISP pregunta: «¿cuántos tengo?» = los que puede entregar.
      hint: `${disponibles} disponible${disponibles === 1 ? '' : 's'} (bodega + técnicos)`,
    },
    { key: 'stock',     label: 'En bodega',    count: c.stock,     border: 'border-emerald-500', hint: 'Listos para entregar' },
    { key: 'assigned',  label: 'Con técnicos', count: c.assigned,  border: 'border-blue-500',    hint: 'En la mochila de alguien' },
    { key: 'installed', label: 'Instalados',   count: c.installed, border: 'border-violet-500',  hint: 'En casa de clientes' },
    { key: 'retired',   label: 'De baja',      count: c.retired,   border: 'border-gray-400',    hint: 'Ya no cuentan' },
  ]
})

// 2) + estado: lo que lista la tabla.
const filteredDevices = computed(() => {
  const status = filters.value.status
  return status ? matchingDevices.value.filter(d => statusOf(d) === status) : matchingDevices.value
})

// 3) La página visible.
const pageDevices = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredDevices.value.slice(start, start + itemsPerPage.value)
})

// Todo cuenta sobre lo FILTRADO: antes contaba el inventario entero, así que
// con una búsqueda el paginador ofrecía páginas que no existían.
const totalDevices = computed(() => filteredDevices.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalDevices.value / itemsPerPage.value)))
const paginationStart = computed(() => totalDevices.value === 0 ? 0 : (currentPage.value - 1) * itemsPerPage.value + 1)
const paginationEnd = computed(() => Math.min(currentPage.value * itemsPerPage.value, totalDevices.value))

// Filtrar vuelve a la primera página, y si la lista se achica (una baja, otra
// búsqueda) la página actual nunca queda más allá de la última.
watch(
  () => [filters.value.search, filters.value.provider, filters.value.branch, filters.value.status],
  () => { currentPage.value = 1 }
)
watch(totalPages, (last) => { if (currentPage.value > last) currentPage.value = last })

const setStatus = (status) => {
  // Pulsar la tarjeta activa la apaga: vuelve a «todos».
  filters.value.status = filters.value.status === status ? '' : status
}

const clearFilters = () => { filters.value = blankFilters() }

// Methods
const loadDevices = async () => {
  loading.value = true
  try {
    const { data } = await inventoryApi.getAll()

    devices.value = (data || []).map(device => {
      const row = {
        ...device,
        stock_brand: device.stock?.brand || null,
        stock_model: device.stock?.model || null,
        stock_price: device.stock?.price || 0,
        provider_name: device.provider?.name || null,
        branch_name: device.branch?.name || null
      }
      // Se indexa una vez al cargar, no en cada tecla. Incluye el custodio:
      // «¿qué tiene instalado Javier?» o «¿qué hay en Chaguaní?».
      row._search = searchIndex([
        row.id, row.stock_brand, row.stock_model, row.serial, row.mac,
        row.holder_label, row.branch_name, row.provider_name,
      ])
      return row
    })
  } catch (error) {
    console.error('Error loading devices:', error)
    toast.value?.error('Error', 'Error al cargar los dispositivos')
  } finally {
    loading.value = false
  }
}

const loadProviders = async () => {
  try {
    const { data } = await inventoryProviderApi.getAll()
    providers.value = data || []
  } catch (error) {
    console.error('Error loading providers:', error)
  }
}

const loadBranches = async () => {
  try {
    const { data } = await inventoryBranchApi.getAll()
    branches.value = data || []
  } catch (error) {
    console.error('Error loading branches:', error)
  }
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0
  }).format(value || 0)
}

const viewDevice = (device) => {
  // TODO: Implement view modal
  console.log('View device:', device)
  toast.value?.info('Información del Dispositivo', `Serial: ${device.serial}\nMAC: ${device.mac}`)
}

const editDevice = (device) => {
  router.push(`/inventory/${device.id}/edit`)
}

const deleteDevice = (device) => {
  deviceToDelete.value = device
  deleteError.value = ''
  showDeleteModal.value = true
}

const closeDeleteModal = () => {
  showDeleteModal.value = false
  deviceToDelete.value = null
  deleteError.value = ''
}

// ── Estado y baja ────────────────────────────────────────────────────────
// Eliminar rechaza un equipo instalado o que se movió en un ticket y manda a
// «darlo de baja», pero la lista no tenía ningún botón para eso: el operador
// quedaba atascado (reporte de Chaguaní, 2026-11-25).
const deleteError = ref('')
const STATUS = {
  stock:     { label: 'En bodega',   cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' },
  assigned:  { label: 'Asignado',    cls: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' },
  installed: { label: 'Instalado',   cls: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300' },
  retired:   { label: 'De baja',     cls: 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300' },
}
const statusLabel = (s) => STATUS[s]?.label ?? 'En bodega'
const statusClass = (s) => STATUS[s]?.cls ?? STATUS.stock.cls
// Sólo lo que está en bodega o en manos de alguien: lo instalado se retira del
// cliente desde un ticket, y lo dado de baja ya lo está.
const canRetire = (device) => !!device && ['stock', 'assigned', undefined, null].includes(device.status)

const retireTarget = ref(null)
const retireNotes = ref('')
const retiring = ref(false)

const openRetire = (device) => {
  retireTarget.value = device
  retireNotes.value = ''
}

const retireInsteadOfDelete = () => {
  const device = deviceToDelete.value
  closeDeleteModal()
  openRetire(device)
}

const confirmRetire = async () => {
  if (!retireTarget.value || retiring.value) return
  retiring.value = true
  try {
    await inventoryApi.retire(retireTarget.value.id, { notes: retireNotes.value || null })
    toast.value?.success('Dado de baja', 'El equipo salió del inventario y quedó en Movimientos.')
    retireTarget.value = null
    await loadDevices()
  } catch (error) {
    toast.value?.error('Error', firstError(error, 'No se pudo dar de baja el equipo.'))
  } finally {
    retiring.value = false
  }
}

const confirmDeleteDevice = async () => {
  if (!deviceToDelete.value) return
  deleting.value = true

  try {
    await inventoryApi.delete(deviceToDelete.value.id)

    toast.value?.success('Eliminado', 'Dispositivo eliminado correctamente')
    closeDeleteModal()
    await loadDevices()
  } catch (error) {
    console.error('Error deleting device:', error)
    // El motivo se queda en la ventana, junto al botón para resolverlo.
    deleteError.value = firstError(error, 'No se pudo eliminar el dispositivo')
    toast.value?.error('No se eliminó', deleteError.value)
  } finally {
    deleting.value = false
  }
}

const goToPage = (page) => {
  if (page >= 1 && page <= totalPages.value) currentPage.value = page
}

// Lifecycle
onMounted(async () => {
  tenantId.value = getUserTenantId()
  await Promise.all([
    loadDevices(),
    loadProviders(),
    loadBranches()
  ])
})
</script>

<style scoped>
/* Additional custom styles if needed */
</style>
