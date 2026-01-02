<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 flex flex-col transition-colors duration-300">
    <!-- HEADER -->
    <header class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 sticky top-0 z-10">
      <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        <div class="flex items-center gap-4">
          <button 
            @click="router.back()" 
            class="p-2 -ml-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 dark:text-gray-400 transition"
            title="Volver"
          >
            <icon-lucide-arrow-left class="w-5 h-5" />
          </button>
          <div>
            <h1 class="text-lg sm:text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
              {{ isEdit ? 'Editar Plan' : 'Crear Plan' }}
              <span 
                class="px-2 py-0.5 rounded-md text-sm font-medium border"
                :class="currentConfig.badgeClass"
              >
                {{ currentConfig.label }}
              </span>
            </h1>
          </div>
        </div>

        <!-- Botones de Acción (Desktop) -->
        <div class="hidden sm:flex items-center gap-3">
          <button 
            @click="router.back()"
            class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition"
          >
            Cancelar
          </button>
          <button 
            @click="savePlan" 
            :disabled="loading"
            class="px-5 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-md transition flex items-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed"
          >
            <v-icon v-if="loading" name="bi-arrow-repeat" animation="spin" class="w-4 h-4" />
            <icon-lucide-save v-else class="w-4 h-4" />
            {{ isEdit ? 'Actualizar Plan' : 'Guardar Plan' }}
          </button>
        </div>
      </div>
    </header>

    <!-- CONTENIDO PRINCIPAL (igual que CrearPlan.vue) -->
    <main class="flex-1 max-w-5xl w-full mx-auto p-4 sm:p-6 lg:p-8">
      <!-- Aquí iría TODO el contenido que ya tenías: info general, velocidad, configuración técnica -->
      <!-- ... -->

      <!-- Resumen Rápido -->
      <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4 border border-blue-100 dark:border-blue-800">
        <h3 class="text-sm font-bold text-blue-800 dark:text-blue-300 mb-1">Resumen del Plan</h3>
        <p class="text-xs text-blue-600 dark:text-blue-400 leading-relaxed">
          Se creará un perfil <strong>{{ currentConfig.shortLabel }}</strong> con {{ form.speed_down || '0' }}{{ form.download_unit }} de bajada y {{ form.speed_up || '0' }}{{ form.upload_unit }} de subida.
        </p>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/services/api'

const router = useRouter()
const route = useRoute()

const planId = route.params.id || null
const planType = computed(() => route.query.type || 'queue')
const isEdit = computed(() => !!planId)

const configMap = {
  queue: { label: 'Simple Queue', shortLabel: 'Queue' },
  pppoe: { label: 'PPPoE Profile', shortLabel: 'PPPoE' },
  hotspot: { label: 'HotSpot Profile', shortLabel: 'HotSpot' },
  pcq: { label: 'PCQ Type', shortLabel: 'PCQ' }
}
const currentConfig = computed(() => configMap[planType.value] || configMap.queue)

const loading = ref(false)

const typePlanMap = { queue: 1, pppoe: 2, hotspot: 3, pcq: 4 }

const form = ref({
  name: '',
  cost_product: '',
  commit: '',
  speed_down: '',
  download_unit: 'M',
  speed_up: '',
  upload_unit: 'M',
  // Campos opcionales según tipo
  shared_users: '',
  pppoe_pool: '',
  local_address: '',
  session_timeout: '',
  idle_timeout: '',
  pcq_rate: '',
  address_mask: '32',
  priority: 4,
  burst_download: '',
  burst_upload: ''
})

// 🚀 Si es edición, traer datos del plan
onMounted(async () => {
  if (isEdit.value) {
    loading.value = true
    try {
      const { data } = await api.plan.get(planId)
      // mapear datos al form
      form.value.name = data.name || ''
      form.value.cost_product = data.cost_product || ''
      form.value.commit = data.commit || ''
      form.value.speed_down = parseInt(data.speed_down) || ''
      form.value.download_unit = data.speed_down?.endsWith('K') ? 'K' : 'M'
      form.value.speed_up = parseInt(data.speed_up) || ''
      form.value.upload_unit = data.speed_up?.endsWith('K') ? 'K' : 'M'
      // campos específicos según tipo
      if (planType.value === 'hotspot') {
        form.value.shared_users = data.shared_users || ''
        form.value.session_timeout = data.session_timeout || ''
        form.value.idle_timeout = data.idle_timeout || ''
      } else if (planType.value === 'pppoe') {
        form.value.pppoe_pool = data.pppoe_pool || ''
        form.value.local_address = data.local_address || ''
      } else if (planType.value === 'pcq') {
        form.value.pcq_rate = data.pcq_rate || ''
        form.value.address_mask = data.address_mask || '32'
      } else if (planType.value === 'queue') {
        form.value.priority = data.priority || 4
      }
    } catch (error) {
      console.error(error)
      alert('❌ Error cargando el plan')
    } finally {
      loading.value = false
    }
  }
})

const savePlan = async () => {
  if (!form.value.name || !form.value.cost_product) {
    alert('⚠️ Nombre y precio son obligatorios')
    return
  }

  loading.value = true

  try {
    const userData =
      JSON.parse(localStorage.getItem('userData')) ||
      JSON.parse(sessionStorage.getItem('userData'))

    if (!userData?.tenant_id) {
      alert('❌ No se encontró el tenant')
      return
    }

    const payload = {
      name: form.value.name,
      cost_product: Number(form.value.cost_product),
      commit: form.value.commit || null,
      speed_down: `${form.value.speed_down}${form.value.download_unit}`,
      speed_up: `${form.value.speed_up}${form.value.upload_unit}`,
      type: planType.value,
      type_plan_id: typePlanMap[planType.value],
      tenant_id: userData.tenant_id,
      shared_users: form.value.shared_users || null,
      pppoe_pool: form.value.pppoe_pool || null,
      local_address: form.value.local_address || null,
      session_timeout: form.value.session_timeout || null,
      idle_timeout: form.value.idle_timeout || null,
      pcq_rate: form.value.pcq_rate || null,
      address_mask: form.value.address_mask || null,
      priority: form.value.priority || null,
      burst_download: form.value.burst_download || null,
      burst_upload: form.value.burst_upload || null
    }

    if (isEdit.value) {
      await api.plan.update(planId, payload)
      alert(`✅ Plan actualizado correctamente`)
    } else {
      await api.plan.create(payload)
      alert(`✅ Plan creado correctamente`)
    }

    router.push('/planes')
  } catch (error) {
    console.error(error)
    alert(error.response?.data?.message || '❌ Error al guardar el plan')
  } finally {
    loading.value = false
  }
}
</script>
