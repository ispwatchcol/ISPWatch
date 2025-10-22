<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100">
    <!-- Sidebar -->
    <Sidebar />
    

    <!-- Contenido principal -->
    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
      <!-- Encabezado -->
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="pr-user-plus" class="text-blue-600 w-7 h-7" />
            Registrar nuevo usuario del Staff
          </h1>
          <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
            Crea un nuevo usuario y asigna permisos de acceso al sistema.
          </p>
        </div>
          <div class="mb-6">
            <button
            @click="$router.push('/staff')"
            class="flex items-center gap-2 px-4 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 rounded-lg text-gray-800 dark:text-gray-100 transition"
            >
            <v-icon name="fa-arrow-left" class="w-4 h-4" />
            Volver a Staff
            </button>
        </div>
      </div>

      <!-- Tarjeta principal -->
      <div class="bg-white dark:bg-gray-800 shadow rounded-2xl p-8">
        <!-- Formulario -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Nombre de usuario</label>
            <input v-model="newMember.username" type="text" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600" placeholder="@colombia-net-tolima" />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Contraseña</label>
            <input v-model="newMember.password" type="password" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600" placeholder="••••••••" />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Correo electrónico</label>
            <input v-model="newMember.email" type="email" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600" placeholder="usuario@colombia-net.com" />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Teléfono celular</label>
            <input v-model="newMember.phone" type="text" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600" placeholder="+57 300 123 4567" />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Nombre</label>
            <input v-model="newMember.name" type="text" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600" />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Apellido</label>
            <input v-model="newMember.lastname" type="text" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600" />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Tipo de usuario</label>
            <select v-model="newMember.role" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600">
              <option value="Administrador">Administrador</option>
              <option value="Finanzas">Finanzas</option>
              <option value="Técnico">Técnico</option>
              <option value="Soporte">Soporte</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Operar todas las zonas</label>
            <select v-model="newMember.allZones" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600">
              <option>Sí</option>
              <option>No</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Autenticación de dos pasos</label>
            <select v-model="newMember.twoFA" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600">
              <option>No</option>
              <option>Sí</option>
            </select>
          </div>
        </div>

        <!-- Permisos -->
        <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-gray-100">Permisos</h2>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
          <div v-for="group in permissions" :key="group.title" class="border rounded-xl p-4 bg-gray-50 dark:bg-gray-700/50">
            <h3 class="font-bold text-gray-700 dark:text-gray-200 mb-2">{{ group.title }}</h3>
            <div class="space-y-1 text-sm">
              <label v-for="(perm, i) in group.items" :key="i" class="flex items-center gap-2">
                <input type="checkbox" v-model="perm.checked" class="accent-blue-600" />
                <span>{{ perm.label }}</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Botón -->
        <div class="mt-8 text-right">
          <button
            @click="saveUser"
            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg shadow transition-all"
          >
            Guardar Usuario
          </button>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import Sidebar from '@/components/Sidebar.vue'
import { ref } from 'vue'

const newMember = ref({
  username: '',
  password: '',
  email: '',
  phone: '',
  name: '',
  lastname: '',
  role: 'Administrador',
  allZones: 'Sí',
  twoFA: 'No',
})

const permissions = ref([
  {
    title: 'Clientes',
    items: [
      { label: 'Editar Descuento', checked: true },
      { label: 'Activar y Desactivar Clientes', checked: false },
      { label: 'Eliminar Instalaciones', checked: false },
      { label: 'Editar Saldo Pendiente', checked: true },
      { label: 'Lista de Clientes', checked: true },
      { label: 'Editar Servicio Internet', checked: false },
      { label: 'Tráfico Clientes', checked: true },
      { label: 'Agregar Clientes', checked: true },
    ],
  },
  {
    title: 'Facturas',
    items: [
      { label: 'Dashboard / Estadísticas', checked: true },
      { label: 'Agregar Gasto', checked: true },
      { label: 'Buscar Facturas', checked: false },
      { label: 'Editar Total a Pagar', checked: false },
      { label: 'Registrar Pagos', checked: true },
      { label: 'Eliminar Factura', checked: true },
      { label: 'Promesas de Pago', checked: false },
    ],
  },
  {
    title: 'Contabilidad',
    items: [
      { label: 'Editar Gasto', checked: true },
      { label: 'Registrar Pago Mayor 3 Días', checked: false },
      { label: 'Eliminar Transferencia', checked: false },
      { label: 'Registrar Pagos', checked: true },
      { label: 'Editar Fecha de Pago', checked: false },
      { label: 'Lista de Gastos', checked: true },
      { label: 'Lista de Facturas', checked: true },
      { label: 'Agregar Transferencia', checked: true },
    ],
  },
])

const saveUser = () => {
  console.log('Nuevo usuario guardado:', newMember.value, permissions.value)
  alert('Usuario registrado correctamente ✅')
}
</script>

<style scoped>
input[type='checkbox'] {
  accent-color: #2563eb; /* azul tailwind */
}
</style>
