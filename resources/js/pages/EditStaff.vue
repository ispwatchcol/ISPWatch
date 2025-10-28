<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100">
    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
      <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="fa-user-edit" class="text-blue-800 text-3xl" style="transform: scale(1.5);" />
            Editar usuario del Staff
            </h1>
          <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
            Modifica la información del usuario y sus permisos.
          </p>
        </div>

        <div class="mb-6">
          <button
            @click="$router.push('/staff')"
            class="flex items-center gap-2 px-4 py-2 bg-gray-200 hover:bg-gray-300 
                  dark:bg-gray-800 dark:hover:bg-gray-700 rounded-lg text-gray-800 
                  dark:text-gray-100 transition-all"
          >
            <v-icon name="fa-arrow-left" class="w-4 h-4" />
            Volver a Staff
          </button>
        </div>
      </div>

      <!-- Tarjeta principal -->
      <div class="bg-white dark:bg-gray-800 shadow rounded-2xl p-8">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
          <!-- Nombre de usuario -->
          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Nombre de usuario</label>
            <div class="flex border rounded-lg overflow-hidden dark:border-gray-600">
              <input
                v-model="editMember.username"
                type="text"
                class="flex-1 p-2 bg-white dark:bg-gray-700 focus:outline-none"
                placeholder="usuario"
              />
              <span class="px-3 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 flex items-center text-sm border-l dark:border-gray-600">
                {{ tenant }}
              </span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Contraseña</label>
            <input
              v-model="editMember.password"
              type="password"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="••••••••"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Correo electrónico</label>
            <input
              v-model="editMember.email"
              type="email"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="usuario@colombia-net.com"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Teléfono celular</label>
            <input
              v-model="editMember.phone"
              type="text"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="+57 300 123 4567"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Nombre</label>
            <input
              v-model="editMember.name"
              type="text"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="Nombre"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Apellido</label>
            <input
              v-model="editMember.lastname"
              type="text"
              class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600"
              placeholder="Apellido"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Tipo de usuario</label>
            <select v-model="editMember.role_id" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600">
              <option disabled value="">Selecciona un rol</option>
              <option v-for="role in roles" :key="role.id" :value="role.id">
                {{ role.name }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Operar todas las zonas</label>
            <select v-model="editMember.allZones" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600">
              <option>Sí</option>
              <option>No</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1 text-gray-600 dark:text-gray-300">Autenticación de dos pasos</label>
            <select v-model="editMember.twoFA" class="w-full p-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600">
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
            @click="updateUser"
            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg shadow transition-all"
          >
            Guardar Cambios
          </button>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { supabase } from '@/supabase.js'

const route = useRoute()
const router = useRouter()
const userId = route.params.id

const editMember = ref({
  username: '',
  password: '',
  email: '',
  phone: '',
  name: '',
  lastname: '',
  role_id: '',
  allZones: 'Sí',
  twoFA: 'No',
})

const tenant = ref('')
const roles = ref([])

onMounted(async () => {
  await loadRoles()
  await loadUserData()
})

const loadUserData = async () => {
  const { data, error } = await supabase
    .from('user')
    .select('id, user_name, user_lastname, email, email_tenant, tel, role_id, password')
    .eq('id', userId)
    .single()

  if (error) {
    console.error('❌ Error al cargar el usuario:', error)
    alert('No se pudo cargar la información del usuario.')
    return
  }

  editMember.value = {
    username: data.user_name,
    password: data.password || '',
    email: data.email || '',
    phone: data.tel || '',
    name: data.user_name,
    lastname: data.user_lastname,
    role_id: data.role_id,
    allZones: 'Sí',
    twoFA: 'No',
  }

  // Obtener dominio del tenant actual
  const userData =
    JSON.parse(localStorage.getItem('userData')) ||
    JSON.parse(sessionStorage.getItem('userData'))

  if (userData?.tenant_id) {
    const { data: tenantData } = await supabase
      .from('tenant')
      .select('domain')
      .eq('id', userData.tenant_id)
      .single()

    tenant.value = tenantData ? `@${tenantData.domain}` : '@sin-tenant'
  } else {
    tenant.value = '@sin-tenant'
  }
}

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

const loadRoles = async () => {
  const { data, error } = await supabase.from('role').select('id, name')
  if (!error && data) roles.value = data
}

const updateUser = async () => {
  try {
    const { error } = await supabase
      .from('user')
      .update({
        user_name: editMember.value.username,
        user_lastname: editMember.value.lastname,
        email: editMember.value.email,
        tel: editMember.value.phone,
        password: editMember.value.password || undefined,
        role_id: editMember.value.role_id,
        updated_at: new Date().toISOString(),
      })
      .eq('id', userId)

    if (error) {
      console.error('❌ Error de Supabase:', error)
      alert(`❌ Error al actualizar usuario: ${error.message}`)
      return
    }

    alert('✅ Usuario actualizado correctamente.')
    router.push('/staff')
  } catch (err) {
    console.error('⚠️ Error general:', err)
    alert('❌ Error inesperado al actualizar usuario.')
  }
}
</script>

