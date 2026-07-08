<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <NotificationToast ref="toast" />

        <!-- ── Confirm Dialog ─────────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <div v-if="confirmDialog.show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <!-- Backdrop -->
                    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="cancelConfirm" />

                    <!-- Card -->
                    <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-100 dark:border-gray-700 p-6 max-w-md w-full">

                        <!-- Icon circle -->
                        <div :class="confirmIconBg" class="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-5">
                            <v-icon :name="confirmDialog.icon" :class="confirmIconColor" class="w-7 h-7" />
                        </div>

                        <!-- Title -->
                        <h3 class="text-lg font-bold text-center text-gray-900 dark:text-white mb-2">
                            {{ confirmDialog.title }}
                        </h3>

                        <!-- Message -->
                        <p class="text-sm text-center text-gray-500 dark:text-gray-400 mb-6 leading-relaxed">
                            {{ confirmDialog.message }}
                        </p>

                        <!-- Text confirmation input -->
                        <div v-if="confirmDialog.requireText" class="mb-5">
                            <p class="text-xs text-center text-gray-400 dark:text-gray-500 mb-2">
                                Escribe <span class="font-mono font-bold text-red-600 dark:text-red-400">{{ confirmDialog.requireText }}</span> para confirmar
                            </p>
                            <input
                                v-model="confirmInputText"
                                type="text"
                                autocomplete="off"
                                placeholder=""
                                class="w-full px-3 py-2 text-sm text-center rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-red-400"
                            />
                        </div>

                        <!-- Buttons -->
                        <div class="flex gap-3">
                            <button
                                @click="cancelConfirm"
                                class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 font-medium transition-all text-sm"
                            >
                                Cancelar
                            </button>
                            <button
                                @click="acceptConfirm"
                                :disabled="!!confirmDialog.requireText && confirmInputText !== confirmDialog.requireText"
                                :class="confirmBtnClass"
                                class="flex-1 px-4 py-2.5 rounded-xl text-white font-medium transition-all text-sm disabled:opacity-40 disabled:cursor-not-allowed"
                            >
                                {{ confirmDialog.confirmLabel }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">Clientes</h1>
            <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">Gestión de perfiles de clientes</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto justify-end">
            <!-- Export CSV -->
            <button
                @click="exportToCSV"
                class="text-sm bg-blue-50 text-blue-700 border border-blue-200 px-3 py-2 rounded-lg hover:bg-blue-100 transition-all flex items-center gap-2 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                title="Exportar archivo CSV puro"
            >
                <icon-lucide-file-text class="w-4 h-4" />
                CSV
            </button>

            <!-- Export Excel -->
            <button
                @click="exportToExcel"
                class="text-sm bg-green-50 text-green-700 border border-green-200 px-3 py-2 rounded-lg hover:bg-green-100 transition-all flex items-center gap-2 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50"
                title="Exportar archivo compatible con Excel"
            >
                <icon-lucide-file-spreadsheet class="w-4 h-4" />
                Excel
            </button>

            <button
                v-if="can('add_clients')"
                @click="router.push('/customers/create')"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 sm:px-6 py-2.5 sm:py-3 rounded-lg flex items-center justify-center gap-2 transition"
            >
                <v-icon name="bi-person-plus" class="w-5 h-5" />
                <span class="text-sm sm:text-base">Nuevo Cliente</span>
            </button>
        </div>
        </div>

        <!-- Search + Router filter + Provision -->
        <div class="mb-6 flex flex-col sm:flex-row gap-3 sm:gap-4 items-stretch sm:items-start">
            <!-- Search -->
            <div class="relative flex-1">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por nombre, email, IP..."
                    class="w-full bg-white dark:bg-gray-800 text-gray-800 dark:text-white px-4 py-2.5 sm:py-3 pl-11 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base"
                />
                <v-icon name="io-search" class="absolute left-3 top-2.5 sm:top-3.5 w-5 h-5 text-gray-400" />
                <button v-if="searchQuery" @click="searchQuery = ''"
                    class="absolute right-3 top-2 sm:top-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <v-icon name="io-close-circle" class="w-6 h-6" />
                </button>
            </div>

            <!-- Router filter -->
            <div class="relative">
                <select
                    v-model="filterRouterId"
                    class="h-full appearance-none bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 pl-9 pr-8 py-2.5 sm:py-3 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base cursor-pointer"
                >
                    <option :value="null">Todos los routers</option>
                    <option v-for="r in availableRouters" :key="r.id" :value="r.id">
                        {{ r.name }}{{ r.pppoe ? ' · PPPoE' : '' }}
                    </option>
                </select>
                <icon-lucide-router class="absolute left-2.5 top-2.5 sm:top-3 w-4 h-4 text-gray-400 pointer-events-none" />
                <button v-if="filterRouterId" @click="filterRouterId = null"
                    class="absolute right-2 top-2.5 sm:top-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <v-icon name="io-close-circle" class="w-5 h-5" />
                </button>
            </div>

            <!-- Registros por página -->
            <div class="relative">
                <select
                    v-model="perPage"
                    class="h-full appearance-none bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 pl-9 pr-8 py-2.5 sm:py-3 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base cursor-pointer font-medium"
                    title="Registros por página"
                >
                    <option value="10">10 registros</option>
                    <option value="100">100 registros</option>
                    <option value="500">500 registros</option>
                    <option value="1000">1000 registros</option>
                    <option value="todos">Todos</option>
                </select>
                <icon-lucide-list class="absolute left-2.5 top-2.5 sm:top-3 w-4 h-4 text-gray-400 pointer-events-none" />
                <svg class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                </svg>
            </div>

            <!-- Provision button -->
            <button
                @click="provisionCustomer"
                :disabled="provisionFullyBlocked"
                class="bg-green-600 hover:bg-green-700 text-white px-4 sm:px-6 py-2.5 sm:py-3 rounded-lg flex items-center justify-center gap-2 transition whitespace-nowrap text-sm sm:text-base disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-green-600"
                :title="provisionFullyBlocked
                    ? 'El router no tiene activada la opción «Agregar Cliente en Mikrotik»'
                    : `Provisionar ${provisionableCustomers.length} cliente(s) al Router Board`"
            >
                <icon-lucide-server class="w-5 h-5" />
                <span>{{ provisionBtnLabel }}</span>
                <span v-if="selectedRouterInfo?.pppoe"
                    class="bg-white/20 text-white text-xs px-1.5 py-0.5 rounded-full font-medium leading-none">
                    PPPoE
                </span>
            </button>
        </div>

        <!-- Aviso: carga a RB bloqueada porque el router no tiene "Agregar Cliente en Mikrotik" -->
        <div v-if="provisionFullyBlocked"
            class="mb-6 flex items-start gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 px-4 py-3 rounded-lg text-sm">
            <v-icon name="md-warningamber-round" class="w-5 h-5 shrink-0 mt-0.5" />
            <p>
                <template v-if="selectedRouterInfo">
                    El router <strong>{{ selectedRouterInfo.name }}</strong> no tiene activada la opción
                    <strong>«Agregar Cliente en Mikrotik»</strong>, por lo que no se pueden cargar clientes a la RB.
                    Actívala en la configuración del router para habilitar la carga.
                </template>
                <template v-else>
                    No se pueden cargar clientes: sus routers no tienen activada la opción
                    <strong>«Agregar Cliente en Mikrotik»</strong>. Actívala en cada router para habilitar la carga.
                </template>
            </p>
        </div>

        <!-- Aviso parcial: algunos clientes se omitirán por ese motivo -->
        <div v-else-if="blockedByMktCount > 0"
            class="mb-6 flex items-start gap-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300 px-4 py-3 rounded-lg text-sm">
            <v-icon name="md-info" class="w-5 h-5 shrink-0 mt-0.5" />
            <p>
                {{ blockedByMktCount }} cliente(s) no se cargarán porque su router no tiene activada la opción
                <strong>«Agregar Cliente en Mikrotik»</strong>.
            </p>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
            <template v-if="provisionProgress">
                <p class="text-gray-700 dark:text-gray-200 mt-4 font-medium">
                    Provisionando clientes… {{ provisionProgress.current }} de {{ provisionProgress.total }}
                </p>
                <p class="text-gray-500 dark:text-gray-400 mt-1 text-sm">
                    Cada cliente se carga al router de a uno (puede tardar varios segundos). No cierres esta pestaña.
                </p>
            </template>
            <p v-else class="text-gray-500 dark:text-gray-400 mt-4">Cargando clientes...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            {{ error }}
        </div>

        <!-- Table / Cards -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">

            <!-- Desktop table -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                    <th v-for="col in sortableColumns" :key="col.key"
                        @click="toggleSort(col.key)"
                        class="px-6 pt-4 pb-2 text-xs font-medium text-gray-600 dark:text-gray-300 uppercase cursor-pointer select-none hover:text-gray-800 dark:hover:text-white transition-colors group"
                        :class="col.align === 'center' ? 'text-center' : 'text-left'">
                        <div class="flex items-center gap-1.5" :class="col.align === 'center' ? 'justify-center' : ''">
                            <span class="leading-none">{{ col.label }}</span>
                            <icon-lucide-arrow-up-down v-if="sortBy !== col.key" class="block shrink-0 w-3.5 h-3.5 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300" />
                            <icon-lucide-arrow-up v-else-if="sortOrder === 'asc'" class="block shrink-0 w-3.5 h-3.5 text-blue-500" />
                            <icon-lucide-arrow-down v-else class="block shrink-0 w-3.5 h-3.5 text-blue-500" />
                        </div>
                    </th>
                    <th class="px-6 pt-4 pb-2 text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Acciones</th>
                    </tr>
                    <!-- Minibuscador por columna -->
                    <tr class="bg-gray-50 dark:bg-gray-700">
                    <th v-for="col in sortableColumns" :key="col.key + '-filter'" class="px-3 pb-3 pt-0 align-top">
                        <select v-if="col.key === 'status'" v-model="columnFilters.status"
                            class="w-full text-xs font-normal normal-case bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 px-2 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-1 focus:ring-blue-500 cursor-pointer">
                            <option value="">Todos</option>
                            <option value="activo">Activo</option>
                            <option value="suspendido">Suspendido</option>
                            <option value="cancelado">Cancelado</option>
                            <option value="gratis">Gratis</option>
                            <option value="retirado">Retirado</option>
                        </select>
                        <input v-else v-model="columnFilters[col.key]" type="text"
                            :placeholder="col.label"
                            class="w-full text-xs font-normal normal-case bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 px-2 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-1 focus:ring-blue-500 placeholder-gray-400" />
                    </th>
                    <th class="px-3 pb-3 pt-0 align-top text-center">
                        <button v-if="hasColumnFilters" @click="clearColumnFilters"
                            class="text-xs font-normal normal-case text-blue-600 dark:text-blue-400 hover:underline">
                            Limpiar
                        </button>
                    </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <tr v-for="customer in pagedCustomers" :key="customer.user_id"
                        class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    <td class="px-6 py-4 text-sm font-medium text-gray-800 dark:text-white">{{ customer.name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">{{ customer.last_name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.email }}</td>
                    <td class="px-6 py-4 text-sm font-mono text-gray-600 dark:text-gray-300">{{ customer.ip_user || '-' }}</td>
                    <td class="px-6 py-4 text-sm font-mono text-gray-600 dark:text-gray-300">{{ customer.precinto || '-' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.service_name || '-' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.sectorial_name || '-' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                        <span>{{ customer.router_name || '-' }}</span>
                        <span v-if="customer.router_falla_general"
                            class="ml-1.5 inline-flex items-center gap-0.5 px-2 py-0.5 text-[10px] font-semibold rounded-full bg-red-100 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800"
                            title="Router en falla general">
                            <v-icon name="md-warningamber" class="w-3 h-3" /> Falla
                        </span>
                        <span v-if="customer.router_pppoe && !customer.pppoe_username"
                            class="ml-1.5 inline-flex items-center gap-0.5 text-xs font-medium text-amber-600 dark:text-amber-400"
                            title="Router PPPoE sin credenciales guardadas — edita el cliente para configurarlas">
                            <v-icon name="md-warningamber" class="w-3.5 h-3.5" />PPPoE?
                        </span>
                        <span v-else-if="customer.router_pppoe && customer.pppoe_username"
                            class="ml-1.5 inline-flex items-center text-xs font-medium text-blue-500 dark:text-blue-400">
                            PPPoE
                        </span>
                        <span v-else-if="routerControlBadge(customer)"
                            class="ml-1.5 inline-flex items-center text-xs font-medium"
                            :class="routerControlBadge(customer).cls">
                            {{ routerControlBadge(customer).label }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span :class="['px-2 py-1 text-xs font-medium rounded-full', statusBadge(customer).cls]">
                            {{ statusBadge(customer).label }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex justify-center gap-2 flex-wrap">
                        <button v-if="can('view_clients')" @click="router.push(`/customers/${customer.user_id}/edit`)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-blue-50 text-blue-700 border border-blue-200
                                hover:bg-blue-100 hover:scale-[1.03] transition-all
                                dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50">
                            <icon-lucide-pencil class="w-3.5 h-3.5" /> Editar
                        </button>

                        <button v-if="can('activate_deactivate_clients') && customer.status" @click="suspendCustomer(customer)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-orange-50 text-orange-700 border border-orange-200
                                hover:bg-orange-100 hover:scale-[1.03] transition-all
                                dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-800 dark:hover:bg-orange-800/50">
                            <icon-lucide-pause-circle class="w-3.5 h-3.5" /> Suspender
                        </button>

                        <button v-else-if="can('activate_deactivate_clients') && !customer.status" @click="activateCustomer(customer)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-green-50 text-green-700 border border-green-200
                                hover:bg-green-100 hover:scale-[1.03] transition-all
                                dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50">
                            <icon-lucide-play-circle class="w-3.5 h-3.5" /> Activar
                        </button>

                        <button v-if="can('activate_deactivate_clients')" @click="deleteCustomer(customer)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-red-50 text-red-700 border border-red-200
                                hover:bg-red-100 hover:scale-[1.03] transition-all
                                dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-800/50">
                            <icon-lucide-trash-2 class="w-3.5 h-3.5" /> Eliminar
                        </button>
                        </div>
                    </td>
                    </tr>

                    <tr v-if="filteredCustomers.length === 0">
                    <td colspan="10" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                        {{ hasActiveFilters ? 'No se encontraron resultados' : 'No hay clientes registrados' }}
                    </td>
                    </tr>
                </tbody>
                </table>

                <!-- Paginación desktop -->
                <div v-if="perPage !== 'todos' && totalPages > 1"
                    class="bg-gray-50 dark:bg-gray-700/50 px-4 py-4 flex items-center justify-between border-t border-gray-200 dark:border-gray-700">
                    <div class="text-sm text-gray-600 dark:text-gray-400 font-medium">
                        {{ paginationInfo }}
                    </div>
                    <Pagination :current-page="currentPage" :total-pages="totalPages" @change="goToPage" />
                </div>
            </div>

            <!-- Mobile cards -->
            <div class="md:hidden divide-y divide-gray-200 dark:divide-gray-700">
                <div v-for="customer in pagedCustomers" :key="customer.user_id" class="p-4">
                <div class="space-y-3">
                    <div class="flex justify-between items-start">
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-white">{{ customer.name }} {{ customer.last_name }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ customer.email }}</p>
                    </div>
                    <span :class="['px-2 py-1 text-xs font-medium rounded-full shrink-0', statusBadge(customer).cls]">
                        {{ statusBadge(customer).label }}
                    </span>
                    </div>

                    <div class="grid grid-cols-2 gap-1.5 text-sm">
                    <div><span class="text-gray-400">IP:</span> <span class="font-mono ml-1">{{ customer.ip_user || '-' }}</span></div>
                    <div><span class="text-gray-400">Precinto:</span> <span class="font-mono ml-1">{{ customer.precinto || '-' }}</span></div>
                    <div><span class="text-gray-400">Plan:</span> <span class="ml-1">{{ customer.service_name || '-' }}</span></div>
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-gray-400">Router:</span>
                        <span class="ml-1">{{ customer.router_name || '-' }}</span>
                        <span v-if="customer.router_falla_general"
                            class="text-xs font-semibold text-red-600 dark:text-red-400"
                            title="Router en falla general">⚠ Falla</span>
                        <span v-if="customer.router_pppoe && !customer.pppoe_username"
                            class="text-xs font-medium text-amber-600 dark:text-amber-400"
                            title="Credenciales PPPoE no configuradas">⚠ PPPoE?</span>
                        <span v-else-if="customer.router_pppoe && customer.pppoe_username"
                            class="text-xs font-medium text-blue-500 dark:text-blue-400">PPPoE</span>
                        <span v-else-if="routerControlBadge(customer)"
                            class="text-xs font-medium" :class="routerControlBadge(customer).cls">
                            {{ routerControlBadge(customer).label }}</span>
                    </div>
                    <div><span class="text-gray-400">Sectorial:</span> <span class="ml-1">{{ customer.sectorial_name || '-' }}</span></div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-1">
                    <button v-if="can('view_clients')" @click="router.push(`/customers/${customer.user_id}/edit`)"
                        class="flex-1 min-w-[90px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                            bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 transition-all
                            dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800">
                        <icon-lucide-pencil class="w-3.5 h-3.5" /> Editar
                    </button>

                    <button v-if="can('activate_deactivate_clients') && customer.status" @click="suspendCustomer(customer)"
                        class="flex-1 min-w-[90px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                            bg-orange-50 text-orange-700 border border-orange-200 hover:bg-orange-100 transition-all
                            dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-800">
                        <icon-lucide-pause-circle class="w-3.5 h-3.5" /> Suspender
                    </button>

                    <button v-else-if="can('activate_deactivate_clients') && !customer.status" @click="activateCustomer(customer)"
                        class="flex-1 min-w-[90px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                            bg-green-50 text-green-700 border border-green-200 hover:bg-green-100 transition-all
                            dark:bg-green-900/30 dark:text-green-300 dark:border-green-800">
                        <icon-lucide-play-circle class="w-3.5 h-3.5" /> Activar
                    </button>

                    <button v-if="can('activate_deactivate_clients')" @click="deleteCustomer(customer)"
                        class="px-3 py-2 text-xs font-medium rounded-lg flex items-center gap-1
                            bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-all
                            dark:bg-red-900/30 dark:text-red-300 dark:border-red-800">
                        <icon-lucide-trash-2 class="w-3.5 h-3.5" />
                    </button>
                    </div>
                </div>
                </div>

                <div v-if="filteredCustomers.length === 0" class="p-8 text-center text-gray-500 dark:text-gray-400">
                {{ hasActiveFilters ? 'No se encontraron resultados' : 'No hay clientes registrados' }}
                </div>

                <!-- Paginación mobile -->
                <div v-if="perPage !== 'todos' && totalPages > 1"
                    class="bg-gray-50 dark:bg-gray-700/50 px-4 py-4 flex flex-col gap-3 border-t border-gray-200 dark:border-gray-700">
                    <div class="text-sm text-gray-600 dark:text-gray-400 font-medium text-center">
                        {{ paginationInfo }}
                    </div>
                    <Pagination :current-page="currentPage" :total-pages="totalPages" @change="goToPage" class="justify-center" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import * as XLSX from 'xlsx'
import api from '../services/api'
import NotificationToast from '@/components/NotificationToast.vue'
import Pagination from '@/components/ui/Pagination.vue'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()
import { useTableControls } from '@/composables/useTableControls'

const router         = useRouter()
const toast          = ref(null)
const customers      = ref([])
const loading        = ref(true)
// Progreso del aprovisionamiento masivo (se procesa de a 1 cliente por request).
const provisionProgress = ref(null) // { current, total } o null cuando no aplica
const error          = ref('')
const searchQuery    = ref('')
const filterRouterId = ref(null)

// Minibuscador por columna (un filtro de texto independiente bajo cada título).
const columnFilters = ref({
    name: '',
    last_name: '',
    email: '',
    ip: '',
    precinto: '',
    plan: '',
    sectorial: '',
    router: '',
    status: '',
})

// Accesor del texto buscable por columna (reutilizado por los filtros de columna).
const columnText = {
    name:      c => c.name || '',
    last_name: c => c.last_name || '',
    email:     c => c.email || '',
    ip:        c => c.ip_user || '',
    precinto:  c => c.precinto || '',
    plan:      c => c.service_name || '',
    sectorial: c => c.sectorial_name || '',
    router:    c => c.router_name || '',
}

const hasColumnFilters = computed(() =>
    Object.values(columnFilters.value).some(v => v && String(v).trim())
)
const clearColumnFilters = () => {
    for (const k in columnFilters.value) columnFilters.value[k] = ''
}
const hasActiveFilters = computed(() =>
    !!searchQuery.value || !!filterRouterId.value || hasColumnFilters.value
)

// ── Confirm dialog state ────────────────────────────────────────────────────
const confirmDialog = ref({
    show: false,
    type: 'warning',       // 'info' | 'warning' | 'danger'
    icon: 'md-warning',
    title: '',
    message: '',
    confirmLabel: 'Confirmar',
    requireText: null,
    resolve: null,
})
const confirmInputText = ref('')

const confirmIconBg = computed(() => ({
    'bg-green-100  dark:bg-green-900/30':  confirmDialog.value.type === 'info',
    'bg-amber-100  dark:bg-amber-900/30':  confirmDialog.value.type === 'warning',
    'bg-red-100    dark:bg-red-900/30':    confirmDialog.value.type === 'danger',
}))

const confirmIconColor = computed(() => ({
    'text-green-600  dark:text-green-400': confirmDialog.value.type === 'info',
    'text-amber-600  dark:text-amber-400': confirmDialog.value.type === 'warning',
    'text-red-600    dark:text-red-400':   confirmDialog.value.type === 'danger',
}))

const confirmBtnClass = computed(() => ({
    'bg-green-600 hover:bg-green-700':  confirmDialog.value.type === 'info',
    'bg-amber-500 hover:bg-amber-600':  confirmDialog.value.type === 'warning',
    'bg-red-600   hover:bg-red-700':    confirmDialog.value.type === 'danger',
}))

const openConfirm = (options) =>
    new Promise((resolve) => {
        confirmDialog.value = { show: true, resolve, ...options }
    })

const acceptConfirm = () => {
    confirmDialog.value.resolve(true)
    confirmDialog.value.show = false
    confirmInputText.value = ''
}

const cancelConfirm = () => {
    confirmDialog.value.resolve(false)
    confirmDialog.value.show = false
    confirmInputText.value = ''
}

// ── Router filter helpers ────────────────────────────────────────────────────
const availableRouters = computed(() => {
    const seen = new Set()
    const list = []
    for (const c of customers.value) {
        if (c.router_id && !seen.has(c.router_id)) {
            seen.add(c.router_id)
            list.push({
                id: c.router_id,
                name: c.router_name,
                simple_queue: !!c.router_simple_queue,
                control_pcq: !!c.router_control_pcq,
                hotspot: !!c.router_hotspot,
                pppoe: !!c.router_pppoe,
                dhcp: !!c.router_dhcp,
                agregar_cliente_mkt: !!c.router_agregar_cliente_mkt,
            })
        }
    }
    return list.sort((a, b) => a.name.localeCompare(b.name))
})

const selectedRouterInfo = computed(() =>
    filterRouterId.value
        ? availableRouters.value.find(r => r.id === filterRouterId.value) ?? null
        : null
)

const provisionBtnLabel = computed(() =>
    selectedRouterInfo.value ? `Cargar a ${selectedRouterInfo.value.name}` : 'Cargar a RB'
)

// Badge junto al nombre del router en la tabla, según el modo de control (excluyente).
// PPPoE se maneja aparte porque tiene su variante de "credenciales pendientes".
const routerControlBadge = (c) => {
    if (c.router_hotspot)      return { label: 'HotSpot', cls: 'text-purple-500 dark:text-purple-400' }
    if (c.router_control_pcq)  return { label: 'PCQ',     cls: 'text-teal-500 dark:text-teal-400' }
    if (c.router_simple_queue) return { label: 'Queue',   cls: 'text-indigo-500 dark:text-indigo-400' }
    if (c.router_dhcp)         return { label: 'DHCP',    cls: 'text-cyan-500 dark:text-cyan-400' }
    return null
}

// Texto del modal "Cargar al Router" según el modo de control (excluyente) del router.
const routerControlDetail = (r) => {
    if (r.pppoe)        return 'secret PPPoE'
    if (r.hotspot)      return 'usuario HotSpot'
    if (r.control_pcq)  return 'cola PCQ de ancho de banda'
    if (r.simple_queue) return 'queue simple de ancho de banda'
    if (r.dhcp)         return 'lease DHCP estático'
    return 'queue de ancho de banda'
}

// ── Data loading ────────────────────────────────────────────────────────────
const filteredCustomers = computed(() => {
    let list = customers.value
    if (filterRouterId.value) {
        list = list.filter(c => c.router_id === filterRouterId.value)
    }

    // Búsqueda global (incluye precinto, sectorial, etc.)
    const q = searchQuery.value.toLowerCase().trim()
    if (q) {
        list = list.filter(c =>
            `${c.name} ${c.last_name}`.toLowerCase().includes(q) ||
            (c.email?.toLowerCase() || '').includes(q) ||
            (c.ip_user?.toLowerCase() || '').includes(q) ||
            (c.precinto?.toLowerCase() || '').includes(q) ||
            (c.service_name?.toLowerCase() || '').includes(q) ||
            (c.sectorial_name?.toLowerCase() || '').includes(q) ||
            (c.router_name?.toLowerCase() || '').includes(q)
        )
    }

    // Filtros por columna (minibuscador bajo cada título).
    const cf = columnFilters.value
    for (const key in columnText) {
        const term = cf[key]?.toLowerCase().trim()
        if (!term) continue
        const accessor = columnText[key]
        list = list.filter(c => accessor(c).toLowerCase().includes(term))
    }
    // Estado se filtra por clave exacta (refleja gratis/cancelado, no solo el booleano).
    if (cf.status) {
        list = list.filter(c => (c.service_status || (c.status ? 'activo' : 'suspendido')) === cf.status)
    }

    return list
})

// ── Compuerta de carga a RB ───────────────────────────────────────────────────
// Solo se puede cargar a routers con "Agregar Cliente en Mikrotik" activado.
const provisionableCustomers = computed(() =>
    filteredCustomers.value.filter(c => c.router_agregar_cliente_mkt)
)
const blockedByMktCount = computed(() =>
    filteredCustomers.value.length - provisionableCustomers.value.length
)
// Hay clientes a la vista pero ninguno es cargable → carga totalmente bloqueada.
const provisionFullyBlocked = computed(() =>
    filteredCustomers.value.length > 0 && provisionableCustomers.value.length === 0
)

// ── Orden + paginación (composable reutilizable, mismo patrón que Planes) ─────
const sortableColumns = [
    { key: 'name', label: 'Nombre', align: 'left' },
    { key: 'last_name', label: 'Apellido', align: 'left' },
    { key: 'email', label: 'Email', align: 'left' },
    { key: 'ip', label: 'IP', align: 'left' },
    { key: 'precinto', label: 'Precinto', align: 'left' },
    { key: 'plan', label: 'Plan', align: 'left' },
    { key: 'sectorial', label: 'Sectorial', align: 'left' },
    { key: 'router', label: 'Router', align: 'left' },
    { key: 'status', label: 'Estado', align: 'center' },
]

const {
    perPage,
    currentPage,
    sortBy,
    sortOrder,
    paginatedItems: pagedCustomers,
    totalPages,
    paginationInfo,
    toggleSort,
    goToPage,
    resetPagination,
} = useTableControls(filteredCustomers, {
    // Por defecto el cliente más reciente (mayor user_id) aparece primero.
    defaultSort: 'num',
    defaultOrder: 'desc',
    sortAccessors: {
        num: c => c.user_id ?? 0,
        name: c => c.name ?? '',
        last_name: c => c.last_name ?? '',
        email: c => c.email ?? '',
        ip: c => c.ip_user ?? '',
        precinto: c => c.precinto ?? '',
        plan: c => c.service_name ?? '',
        sectorial: c => c.sectorial_name ?? '',
        router: c => c.router_name ?? '',
        status: c => c.service_status || (c.status ? 'activo' : 'suspendido'),
    },
})

// Cualquier filtro (global, router o por columna) vuelve a la primera página.
watch([searchQuery, filterRouterId, columnFilters], () => resetPagination(), { deep: true })

// Estado de servicio → etiqueta + colores (refleja gratis/cancelado, no solo el booleano)
const STATUS_BADGES = {
    activo:     { label: 'Activo',     cls: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' },
    suspendido: { label: 'Suspendido', cls: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' },
    cancelado:  { label: 'Cancelado',  cls: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' },
    gratis:     { label: 'Gratis',     cls: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' },
    retirado:   { label: 'Retirado',   cls: 'bg-slate-100 text-slate-600 dark:bg-slate-700/50 dark:text-slate-400' },
}
const statusBadge = (c) => {
    const key = c.service_status || (c.status ? 'activo' : 'suspendido')
    return STATUS_BADGES[key] || STATUS_BADGES.activo
}

const loadCustomers = async () => {
    try {
        loading.value = true
        const response = await api.customers.getAll()
        customers.value = response.data
    } catch (err) {
        console.error('Error al cargar clientes:', err)
        error.value = 'Error al cargar los clientes.'
    } finally {
        loading.value = false
    }
}

// ── Actions ─────────────────────────────────────────────────────────────────
const provisionCustomer = async () => {
    if (filteredCustomers.value.length === 0) {
        toast.value?.warning('Sin clientes', 'No hay clientes para provisionar. Ajusta tu búsqueda.')
        return
    }

    // Compuerta: solo se cargan clientes de routers con "Agregar Cliente en Mikrotik".
    const targets = provisionableCustomers.value
    if (targets.length === 0) {
        toast.value?.warning(
            'Carga deshabilitada',
            selectedRouterInfo.value
                ? `El router «${selectedRouterInfo.value.name}» no tiene activada la opción «Agregar Cliente en Mikrotik». Actívala en la configuración del router para poder cargar clientes.`
                : 'Ningún cliente mostrado puede cargarse: sus routers no tienen activada la opción «Agregar Cliente en Mikrotik».',
            { duration: 9000 }
        )
        return
    }

    const count      = targets.length
    const isSingle   = count === 1
    const c0         = targets[0]
    const ri         = selectedRouterInfo.value
    const routerName = ri?.name ?? 'sus routers asignados'
    const detail     = ri ? routerControlDetail(ri) : 'según el control de cada router'
    const skipNote   = blockedByMktCount.value > 0
        ? ` Se omitirán ${blockedByMktCount.value} cliente(s) cuyo router no tiene «Agregar Cliente en Mikrotik».`
        : ''

    const confirmed = await openConfirm({
        type: 'info',
        icon: 'bi-server',
        title: isSingle ? 'Cargar al Router' : `Cargar ${count} clientes al Router`,
        message: (isSingle
            ? `Se cargará a ${c0.name} ${c0.last_name} en ${routerName} (${detail}).`
            : `Se provisionarán ${count} clientes en ${routerName} (${detail}). Esta operación puede tardar unos segundos.`) + skipNote,
        confirmLabel: 'Cargar',
    })

    if (!confirmed) return

    try {
        loading.value = true
        const customerIds = targets.map(c => c.user_id)
        provisionProgress.value = { current: 0, total: customerIds.length }

        // Aprovisionamiento ASÍNCRONO (solución definitiva al 504): cada cliente
        // tarda ~17-34s (SSH al CORE → SSH anidado al router), así que correrlo
        // dentro del request HTTP revienta el cap de ~60s del gateway. Disparamos
        // un job en cola por cliente y hacemos polling del progreso; ningún
        // request individual se acerca al límite del proxy.
        const startResp = await api.customers.bulkProvisionStart(customerIds)
        const jobId = startResp.data?.job_id
        if (!jobId) throw new Error('No se pudo iniciar el aprovisionamiento.')

        // Polling hasta status=done. Toleramos errores transitorios de red: el
        // job sigue corriendo en el worker aunque una consulta falle.
        const POLL_MS = 2500
        // Si processed no avanza en ~75s (más que el peor caso de un cliente,
        // ~34s) asumimos que NO hay worker procesando la cola y cortamos con un
        // mensaje claro en vez de cargar para siempre.
        const STALL_LIMIT = Math.ceil(75000 / POLL_MS)
        let status = null
        let pollErrors = 0
        let lastProcessed = -1
        let stallPolls = 0
        // eslint-disable-next-line no-constant-condition
        while (true) {
            await new Promise(r => setTimeout(r, POLL_MS))
            let processed = lastProcessed
            try {
                const st = await api.customers.bulkProvisionStatus(jobId)
                status = st.data
                pollErrors = 0
                processed = status.processed || 0
                provisionProgress.value = {
                    current: processed,
                    total: status.total || customerIds.length,
                }
                if (status.status === 'done') break
            } catch (e) {
                if (++pollErrors >= 5) throw e
                continue // blip de red: reintentar sin contar como estancamiento
            }

            if (processed === lastProcessed) {
                if (++stallPolls >= STALL_LIMIT) {
                    throw new Error('El aprovisionamiento no avanzó. Probablemente el worker de la cola no está corriendo (php artisan queue:work / componente "worker" en el servidor).')
                }
            } else {
                stallPolls = 0
                lastProcessed = processed
            }
        }

        const results             = status.results || []
        const success_count       = status.success_count || 0
        const fail_count          = status.fail_count || 0
        const pppoe_skipped_count = status.pppoe_skipped_count || 0

        if (results.length === 1) {
            // Caso un solo cliente: reportamos queue y secret PPPoE por separado.
            const r             = results[0]
            const name          = r.customer_name || 'El cliente'
            const alreadyOnRb   = r.queue_result?.action === 'updated' || r.pppoe_result?.action === 'updated'

            if (r.success && r.pppoe_created) {
                if (alreadyOnRb) {
                    toast.value?.warning('Cliente ya estaba en el router',
                        `${name}: ya existía en el router — no se creó, solo se actualizó la queue y el secret PPPoE.`)
                } else {
                    toast.value?.success('Cargado al router',
                        `${name}: queue + secret PPPoE creados correctamente.`)
                }
            } else if (r.success && r.pppoe_skipped) {
                toast.value?.warning('Queue cargado — PPPoE pendiente',
                    `${name}: ${r.queue_message}. ${r.pppoe_message}. Edita el cliente para configurar las credenciales PPPoE.`,
                    { duration: 9000 })
            } else if (r.success && !r.pppoe_applies) {
                if (alreadyOnRb) {
                    toast.value?.warning('Cliente ya estaba en el router',
                        `${name}: ya existía en el router — no se creó, solo se actualizó la queue.`)
                } else {
                    toast.value?.success('Cargado al router', `${name}: ${r.queue_message}.`)
                }
            } else {
                const reasons = []
                if (!r.queue_ok) reasons.push(`Queue: ${r.queue_message}`)
                if (r.pppoe_applies && !r.pppoe_skipped && !r.pppoe_created) reasons.push(`Secret PPPoE: ${r.pppoe_message}`)
                toast.value?.error('No se pudo cargar al router',
                    `${name} → ${reasons.join(' · ') || r.message || 'Error desconocido.'}`,
                    { duration: 12000 })
            }
        } else if (fail_count > 0 && success_count > 0) {
            const firstErr = results.find(x => !x.success)
            toast.value?.warning('Provisionamiento parcial',
                `${success_count} exitoso(s), ${fail_count} con error${firstErr ? ` (ej. ${firstErr.customer_name}: ${firstErr.message})` : ''}.`,
                { duration: 9000 })
        } else if (fail_count > 0) {
            const firstErr = results.find(x => !x.success)
            toast.value?.error('Error al provisionar',
                `${fail_count} cliente(s) con error${firstErr ? `: ${firstErr.message}` : ''}.`,
                { duration: 9000 })
        } else if (pppoe_skipped_count > 0) {
            toast.value?.warning('Queue cargado — PPPoE pendiente',
                `Queue cargado en ${success_count} cliente(s). ${pppoe_skipped_count} con router PPPoE sin credenciales guardadas — edítalos para configurarlas.`,
                { duration: 9000 })
        } else {
            const alreadyCount = results.filter(r => r.success && (r.queue_result?.action === 'updated' || r.pppoe_result?.action === 'updated')).length
            if (alreadyCount === success_count) {
                toast.value?.warning('Clientes ya estaban en el router',
                    `${alreadyCount} cliente(s) ya existían en el router — no se crearon, solo se actualizó la queue.`,
                    { duration: 9000 })
            } else if (alreadyCount > 0) {
                toast.value?.warning('Carga con advertencias',
                    `${success_count - alreadyCount} cliente(s) creado(s). ${alreadyCount} ya existían en el router (solo se actualizó la queue).`,
                    { duration: 9000 })
            } else {
                toast.value?.success('Provisionamiento exitoso',
                    `${success_count} cliente(s) cargado(s) correctamente.`)
            }
        }
    } catch (err) {
        console.error('Error al provisionar:', err)
        const msg = err.response?.data?.message
            || (err.code === 'ECONNABORTED' ? 'La operación tardó demasiado (timeout): el router/CORE no respondió.' : err.message)
            || 'No se pudo conectar con el servidor.'
        toast.value?.error('Error al cargar al router', msg, { duration: 11000 })
    } finally {
        loading.value = false
        provisionProgress.value = null
    }
}

const suspendCustomer = async (customer) => {
    const confirmed = await openConfirm({
        type: 'warning',
        icon: 'md-pausecircle',
        title: 'Suspender cliente',
        message: `¿Estás seguro de suspender a ${customer.name} ${customer.last_name}? Se bloqueará su acceso al servicio.`,
        confirmLabel: 'Suspender',
    })
    if (!confirmed) return

    try {
        loading.value = true
        const response = await api.customers.suspend(customer.user_id)
        toast.value?.success('Cliente suspendido', response.data.message || 'El acceso fue bloqueado correctamente.')
        loadCustomers()
    } catch (err) {
        const msg = err.response?.data?.message || 'Error al suspender el cliente.'
        if (err.response?.status === 400) {
            toast.value?.info('Sin cambios', msg)
            loadCustomers()
        } else {
            toast.value?.error('Error al suspender', msg)
        }
    } finally {
        loading.value = false
    }
}

const activateCustomer = async (customer) => {
    const confirmed = await openConfirm({
        type: 'info',
        icon: 'md-playcircle',
        title: 'Activar cliente',
        message: `¿Estás seguro de activar a ${customer.name} ${customer.last_name}? Se restaurará su acceso al servicio.`,
        confirmLabel: 'Activar',
    })
    if (!confirmed) return

    try {
        loading.value = true
        const response = await api.customers.activate(customer.user_id)
        toast.value?.success('Cliente activado', response.data.message || 'El acceso fue restaurado correctamente.')
        loadCustomers()
    } catch (err) {
        const msg = err.response?.data?.message || 'Error al activar el cliente.'
        if (err.response?.status === 400) {
            toast.value?.info('Sin cambios', msg)
            loadCustomers()
        } else {
            toast.value?.error('Error al activar', msg)
        }
    } finally {
        loading.value = false
    }
}

const deleteCustomer = async (customer) => {
    const confirmed = await openConfirm({
        type: 'danger',
        icon: 'md-deleteforever',
        title: 'Eliminar cliente',
        message: `Estás a punto de eliminar a ${customer.name} ${customer.last_name} junto con todas sus facturas y pagos. Esta acción no se puede deshacer.`,
        confirmLabel: 'Eliminar',
        requireText: 'ELIMINAR',
    })
    if (!confirmed) return

    try {
        await api.customers.delete(customer.user_id)
        toast.value?.success('Cliente eliminado', `${customer.name} ${customer.last_name} fue eliminado correctamente.`)
        loadCustomers()
    } catch (err) {
        console.error('Error al eliminar cliente:', err)
        toast.value?.error('Error al eliminar', err.response?.data?.message || 'No se pudo eliminar el cliente.')
    }
}

// ── Export ──────────────────────────────────────────────────────────────────
const exportRows = () =>
    filteredCustomers.value.map((c, idx) => ({
        '#': idx + 1,
        'Nombre': c.name || '',
        'Apellido': c.last_name || '',
        'Email': c.email || '',
        'IP': c.ip_user || '',
        'Precinto': c.precinto || '',
        'Plan': c.service_name || '',
        'Sectorial': c.sectorial_name || '',
        'Router': c.router_name || '',
        'Estado': c.status ? 'Activo' : 'Suspendido',
    }))

const downloadFile = (content, filename, mimeType) => {
    const blob = new Blob([content], { type: mimeType })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.setAttribute('href', url)
    link.setAttribute('download', filename)
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
}

const exportToCSV = () => {
    if (filteredCustomers.value.length === 0) {
        toast.value?.warning('Sin datos', 'No hay clientes para exportar.')
        return
    }
    const rows = exportRows()
    const headers = Object.keys(rows[0])
    const csv = [
        headers.join(','),
        ...rows.map(r => headers.map(h => `"${String(r[h]).replace(/"/g, '""')}"`).join(',')),
    ].join('\n')

    const date = new Date().toISOString().split('T')[0]
    downloadFile('﻿' + csv, `clientes_${date}.csv`, 'text/csv;charset=utf-8;')
}

const exportToExcel = () => {
    if (filteredCustomers.value.length === 0) {
        toast.value?.warning('Sin datos', 'No hay clientes para exportar.')
        return
    }
    const rows = exportRows()
    const worksheet = XLSX.utils.json_to_sheet(rows)
    worksheet['!cols'] = [
        { wch: 5 },   // #
        { wch: 20 },  // Nombre
        { wch: 20 },  // Apellido
        { wch: 28 },  // Email
        { wch: 15 },  // IP
        { wch: 14 },  // Precinto
        { wch: 18 },  // Plan
        { wch: 18 },  // Sectorial
        { wch: 18 },  // Router
        { wch: 12 },  // Estado
    ]
    const workbook = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Clientes')

    const date = new Date().toISOString().split('T')[0]
    XLSX.writeFile(workbook, `clientes_${date}.xlsx`)
}

onMounted(loadCustomers)
</script>
