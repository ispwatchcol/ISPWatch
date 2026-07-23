<template>
  <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
    <NotificationToast ref="toast" />

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Instalaciones</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
          Pre-ventas: se agenda con los datos del prospecto, el técnico ejecuta la instalación, y al firmar se puede convertir en cliente.
        </p>
      </div>
      <button @click="openCreate"
        class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Agendar instalación
      </button>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 mb-6 max-w-7xl mx-auto">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Estado</label>
          <select v-model="filters.status" @change="load"
            class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
            <option value="">Todos</option>
            <option value="pendiente">Pendiente</option>
            <option value="completada">Completada</option>
            <option value="cancelada">Cancelada</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Desde</label>
          <input v-model="filters.from" type="date" @change="load"
            class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Hasta</label>
          <input v-model="filters.to" type="date" @change="load"
            class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" />
        </div>
      </div>
    </div>

    <!-- Tabla -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 max-w-7xl mx-auto overflow-hidden">

      <div v-if="loading" class="text-center py-16">
        <div class="inline-block animate-spin rounded-full h-10 w-10 border-4 border-blue-500 border-t-transparent"></div>
        <p class="text-gray-500 dark:text-gray-400 mt-3">Cargando instalaciones...</p>
      </div>

      <div v-else-if="installations.length === 0" class="text-center py-16 text-gray-500 dark:text-gray-400">
        <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
        </svg>
        <p class="mb-3">No hay órdenes de instalación con los filtros seleccionados.</p>
        <button @click="openCreate" class="text-sm text-blue-600 dark:text-blue-400 hover:underline font-medium">
          Agendar la primera
        </button>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-700">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Estado</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Fecha</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Persona</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Técnico</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Dirección</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Equipo</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            <tr v-for="inst in installations" :key="inst.id"
              class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
              <td class="px-4 py-3">
                <span :class="statusBadge(inst.status)" class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase">
                  {{ inst.status }}
                </span>
              </td>
              <td class="px-4 py-3 text-gray-700 dark:text-gray-300 whitespace-nowrap">
                {{ formatDate(inst.scheduled_date) }}
              </td>
              <td class="px-4 py-3">
                <RouterLink v-if="inst.customer_id"
                  :to="`/customers/${inst.customer_id}/edit`"
                  class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                  {{ inst.customer_name || inst.customer_email || '—' }}
                </RouterLink>
                <span v-else class="text-gray-800 dark:text-gray-200 font-medium">
                  {{ inst.customer_name || inst.prospect_name || '—' }}
                  <span class="ml-1 text-[10px] uppercase bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 px-1.5 py-0.5 rounded">Prospecto</span>
                </span>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ inst.customer_tel || inst.customer_email || '' }}</div>
              </td>
              <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ inst.technician_name || inst.technician || '—' }}</td>
              <td class="px-4 py-3 text-gray-600 dark:text-gray-300 max-w-[180px] truncate" :title="inst.address">
                {{ inst.address || '—' }}
              </td>
              <td class="px-4 py-3 text-gray-600 dark:text-gray-300 max-w-[180px] truncate" :title="inst.equipment">
                {{ inst.equipment || '—' }}
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-1">
                  <RouterLink :to="`/installations/${inst.id}`"
                    class="text-xs text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 px-2.5 py-1 rounded-lg transition font-medium">
                    Detalle
                  </RouterLink>
                  <button v-if="inst.is_prospect && inst.status === 'completada'" @click="convertToClient(inst)"
                    class="text-xs text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20 px-2.5 py-1 rounded-lg transition font-medium">
                    Convertir a cliente
                  </button>
                  <button @click="openEdit(inst)"
                    class="text-xs text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 px-2.5 py-1 rounded-lg transition font-medium">
                    Editar
                  </button>
                  <button @click="remove(inst)"
                    class="text-xs text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 px-2.5 py-1 rounded-lg transition font-medium">
                    Eliminar
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="!loading && installations.length > 0"
        class="px-4 py-3 border-t border-gray-100 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">
        {{ installations.length }} orden{{ installations.length !== 1 ? 'es' : '' }}
        <span v-if="filters.status" class="ml-1 font-medium">· {{ filters.status }}</span>
      </div>
    </div>

    <!-- Modal: agendar nueva instalación (crea prospecto + orden) -->
    <div v-if="creating" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-3xl p-6 max-h-[92vh] overflow-y-auto">
        <h3 class="text-base font-bold text-gray-800 dark:text-white mb-1">Agendar instalación</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-5">
          Capturá los datos básicos de la persona y la fecha. El cliente se crea más tarde, al firmar y completar la instalación.
        </p>

        <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2 border-b border-gray-200 dark:border-gray-700 pb-1">Datos del prospecto</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Nombre <span class="text-red-500">*</span></label>
            <input v-model="createForm.name" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Apellido</label>
            <input v-model="createForm.last_name" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Cédula</label>
            <input v-model="createForm.cedula" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Teléfono</label>
            <input v-model="createForm.tel" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Email</label>
            <input v-model="createForm.email" type="email"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Dirección</label>
            <input v-model="createForm.address" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Ciudad</label>
            <input v-model="createForm.city" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Departamento</label>
            <input v-model="createForm.state" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Estrato</label>
            <select v-model="createForm.estrato"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
              <option :value="null">— Sin definir —</option>
              <option v-for="n in 6" :key="n" :value="n">{{ n }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Notas del prospecto</label>
            <textarea v-model="createForm.prospect_notes" rows="2"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm resize-none"></textarea>
          </div>
        </div>

        <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2 border-b border-gray-200 dark:border-gray-700 pb-1">Datos de la instalación</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha programada <span class="text-red-500">*</span></label>
            <input v-model="createForm.scheduled_date" type="date"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Técnico</label>
            <select v-model="createForm.technician_id"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
              <option :value="null">— Sin asignar —</option>
              <option v-for="t in technicians" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
            <p v-if="!technicians.length" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
              No hay usuarios con rol Técnico en el tenant.
            </p>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Equipo / Materiales previstos</label>
            <select v-if="availableDevices.length" v-model="equipPick" @change="appendDeviceTo(createForm)"
              class="w-full mb-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
              <option :value="null">— Agregar equipo desde inventario —</option>
              <option v-for="d in availableDevices" :key="d.id" :value="d.id">{{ deviceLabel(d) }}</option>
            </select>
            <input v-model="createForm.equipment" type="text" placeholder="Se llena al elegir del inventario, o escribe manualmente"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Observaciones de la orden</label>
            <textarea v-model="createForm.notes" rows="2"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm resize-none"></textarea>
          </div>
        </div>

        <div v-if="createError" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ createError }}</div>

        <div class="flex gap-3 mt-5">
          <button @click="submitCreate" :disabled="creatingBusy"
            class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium py-2.5 rounded-lg transition">
            {{ creatingBusy ? 'Creando...' : 'Agendar' }}
          </button>
          <button @click="creating = false"
            class="flex-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white text-sm py-2.5 rounded-lg transition">
            Cancelar
          </button>
        </div>
      </div>
    </div>

    <!-- Modal editar instalación (+ datos del prospecto si aplica) -->
    <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl p-6 max-h-[92vh] overflow-y-auto">
        <h3 class="text-base font-bold text-gray-800 dark:text-white mb-4">Editar orden de instalación</h3>

        <div v-if="editForm.is_prospect">
          <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2 border-b border-gray-200 dark:border-gray-700 pb-1">Datos del prospecto</h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Nombre <span class="text-red-500">*</span></label>
              <input v-model="editForm.prospect.name" type="text"
                class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Apellido</label>
              <input v-model="editForm.prospect.last_name" type="text"
                class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Cédula</label>
              <input v-model="editForm.prospect.cedula" type="text"
                class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Teléfono</label>
              <input v-model="editForm.prospect.tel" type="text"
                class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Email</label>
              <input v-model="editForm.prospect.email" type="email"
                class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Estrato</label>
              <select v-model="editForm.prospect.estrato"
                class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
                <option :value="null">— Sin definir —</option>
                <option v-for="n in 6" :key="n" :value="n">{{ n }}</option>
              </select>
            </div>
          </div>
        </div>

        <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2 border-b border-gray-200 dark:border-gray-700 pb-1">Datos de la instalación</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha <span class="text-red-500">*</span></label>
            <input v-model="editForm.scheduled_date" type="date"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Estado</label>
            <select v-model="editForm.status"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
              <option value="pendiente">Pendiente</option>
              <option value="completada">Completada</option>
              <option value="cancelada">Cancelada</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Técnico</label>
            <select v-model="editForm.technician_id"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
              <option :value="null">— Sin asignar —</option>
              <option v-for="t in technicians" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Dirección</label>
            <input v-model="editForm.address" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Equipo / Materiales</label>
            <select v-if="availableDevices.length" v-model="equipPick" @change="appendDeviceTo(editForm)"
              class="w-full mb-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm">
              <option :value="null">— Agregar equipo desde inventario —</option>
              <option v-for="d in availableDevices" :key="d.id" :value="d.id">{{ deviceLabel(d) }}</option>
            </select>
            <input v-model="editForm.equipment" type="text"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Observaciones</label>
            <textarea v-model="editForm.notes" rows="2"
              class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 text-gray-800 dark:text-white text-sm resize-none"></textarea>
          </div>
        </div>

        <div v-if="editError" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ editError }}</div>

        <div class="flex gap-3 mt-5">
          <button @click="saveEdit" :disabled="saving"
            class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium py-2.5 rounded-lg transition">
            {{ saving ? 'Guardando...' : 'Actualizar' }}
          </button>
          <button @click="editing = null"
            class="flex-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white text-sm py-2.5 rounded-lg transition">
            Cancelar
          </button>
        </div>
      </div>
    </div>

    <!-- Confirmación: eliminar orden de instalación -->
    <ConfirmModal
      :visible="!!deleteTarget"
      title="Eliminar instalación"
      :message="deleteTarget ? `Vas a eliminar la orden de ${deleteTarget.customer_name || deleteTarget.prospect_name || deleteTarget.customer_email || 'el prospecto'} programada para ${formatDate(deleteTarget.scheduled_date)}. Se borrarán sus firmas y no se puede deshacer.` : ''"
      require-text="BORRAR_INSTALACION"
      confirm-text="Eliminar"
      loading-text="Eliminando..."
      :loading="deleting"
      variant="danger"
      @confirm="confirmDelete"
      @cancel="deleteTarget = null"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import api from '@/services/api'
import NotificationToast from '@/components/NotificationToast.vue'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'

const router = useRouter()
const toast = ref(null)
const installations = ref([])
const loading = ref(true)

const filters = ref({ status: '', from: '', to: '' })

const technicians = ref([])

// Equipos del inventario para autollenar "Equipo / Materiales" sin digitar seriales.
const inventoryDevices = ref([])
const equipPick = ref(null)

const availableDevices = computed(() =>
  inventoryDevices.value.filter(d => !d.user_id)
)

const deviceLabel = (d) => {
  const name = `${d.stock?.brand ?? ''} ${d.stock?.model ?? ''}`.trim() || 'Equipo'
  const parts = [name]
  if (d.serial) parts.push(`S/N ${d.serial}`)
  if (d.mac)    parts.push(`MAC ${d.mac}`)
  return parts.join(' · ')
}

const appendDeviceTo = (form) => {
  const d = inventoryDevices.value.find(x => x.id === equipPick.value)
  equipPick.value = null
  if (!d) return
  const label = deviceLabel(d)
  form.equipment = form.equipment?.trim() ? `${form.equipment.trim()}; ${label}` : label
}

const loadInventory = async () => {
  try {
    const { data } = await api.inventory.getAll()
    inventoryDevices.value = Array.isArray(data) ? data : []
  } catch { /* non-blocking: sin permiso de inventario se escribe manual */ }
}

const blankCreate = () => ({
  // Prospect data
  name: '', last_name: '', cedula: '', email: '', tel: '',
  address: '', city: '', state: '', estrato: null, prospect_notes: '',
  // Installation data
  scheduled_date: new Date().toISOString().slice(0, 10),
  technician_id: null,
  equipment: '',
  notes: '',
})

const creating = ref(false)
const creatingBusy = ref(false)
const createError = ref('')
const createForm = ref(blankCreate())

const editing  = ref(null)
const editForm = ref({})
const editError = ref('')
const saving   = ref(false)

const load = async () => {
  loading.value = true
  try {
    const params = {}
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.from)   params.from   = filters.value.from
    if (filters.value.to)     params.to     = filters.value.to
    const { data } = await api.customers.getAllInstallations(params)
    installations.value = data
  } catch {
    toast.value?.error('Error', 'No se pudieron cargar las instalaciones.')
  } finally {
    loading.value = false
  }
}

const loadTechnicians = async () => {
  try {
    const { data } = await api.customers.listTechnicians()
    technicians.value = data
  } catch { /* non-blocking */ }
}

const openCreate = () => {
  createError.value = ''
  createForm.value = blankCreate()
  equipPick.value = null
  creating.value = true
}

const submitCreate = async () => {
  createError.value = ''
  if (!createForm.value.name?.trim())  { createError.value = 'El nombre es obligatorio.'; return }
  if (!createForm.value.scheduled_date) { createError.value = 'La fecha es obligatoria.'; return }
  creatingBusy.value = true
  try {
    const { data } = await api.customers.createInstallationWithProspect(createForm.value)
    toast.value?.success('Agendada', 'Prospecto e instalación creados.')
    creating.value = false
    // Va directo al detalle para que el técnico pueda comenzar a llenar la hoja.
    if (data?.installation?.id) {
      router.push(`/installations/${data.installation.id}`)
    } else {
      await load()
    }
  } catch (err) {
    createError.value = err.response?.data?.message || 'No se pudo crear.'
  } finally {
    creatingBusy.value = false
  }
}

const openEdit = (inst) => {
  editError.value = ''
  editing.value = inst.id
  editForm.value = {
    scheduled_date: inst.scheduled_date?.split('T')[0] ?? inst.scheduled_date ?? '',
    technician_id: inst.technician_id ?? null,
    address:    inst.address ?? '',
    equipment:  inst.equipment ?? '',
    notes:      inst.notes ?? '',
    status:     inst.status,
    is_prospect: !!inst.is_prospect,
    prospect: inst.prospect
      ? {
          name:      inst.prospect.name ?? '',
          last_name: inst.prospect.last_name ?? '',
          cedula:    inst.prospect.cedula ?? '',
          email:     inst.prospect.email ?? '',
          tel:       inst.prospect.tel ?? '',
          estrato:   inst.prospect.estrato ?? null,
        }
      : null,
  }
}

const saveEdit = async () => {
  editError.value = ''
  if (!editForm.value.scheduled_date) { editError.value = 'La fecha es obligatoria.'; return }
  if (editForm.value.is_prospect && !editForm.value.prospect?.name?.trim()) {
    editError.value = 'El nombre del prospecto es obligatorio.'
    return
  }
  saving.value = true
  try {
    const installationPayload = {
      scheduled_date: editForm.value.scheduled_date,
      technician_id: editForm.value.technician_id,
      address:    editForm.value.address,
      equipment:  editForm.value.equipment,
      notes:      editForm.value.notes,
      status:     editForm.value.status,
    }
    if (editForm.value.is_prospect && editForm.value.prospect) {
      await api.customers.updateInstallationProspect(editing.value, editForm.value.prospect)
    }
    await api.customers.updateInstallation(editing.value, installationPayload)
    toast.value?.success('Actualizada', 'Orden actualizada correctamente.')
    editing.value = null
    await load()
  } catch (err) {
    editError.value = err.response?.data?.message || 'Error al actualizar.'
  } finally {
    saving.value = false
  }
}

const deleteTarget = ref(null)
const deleting = ref(false)

const remove = (inst) => {
  deleteTarget.value = inst
}

const confirmDelete = async () => {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await api.customers.deleteInstallation(deleteTarget.value.id)
    toast.value?.success('Eliminada', 'Orden eliminada correctamente.')
    deleteTarget.value = null
    await load()
  } catch {
    toast.value?.error('Error', 'No se pudo eliminar la orden.')
  } finally {
    deleting.value = false
  }
}

const convertToClient = (inst) => {
  if (!inst.prospect_id) return
  router.push({ path: '/customers/create', query: { prospect_id: inst.prospect_id } })
}

const statusBadge = (s) => ({
  pendiente:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  completada: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  cancelada:  'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
}[s] || 'bg-gray-100 text-gray-600')

const formatDate = (d) => {
  if (!d) return ''
  const date = new Date(d + (d.includes('T') ? '' : 'T00:00:00'))
  return date.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

onMounted(() => {
  load()
  loadTechnicians()
  loadInventory()
})
</script>
