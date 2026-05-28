<template>
  <div v-if="parsedRanges.length > 0 || loading" class="mt-2">
    <div class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden bg-white dark:bg-gray-800 shadow-sm">
      <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-2 flex items-center justify-between">
        <div class="flex items-center gap-2 text-white">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
          <span class="font-semibold text-xs">Analizador de IPs</span>
        </div>
        <button v-if="!loading && parsedRanges.length > 0" type="button" @click="toggleAll"
          class="text-white/90 hover:text-white text-xs font-medium">
          {{ allExpanded ? 'Colapsar todo' : 'Expandir todo' }}
        </button>
        <div v-if="loading" class="flex items-center gap-1.5 text-white/80 text-xs">
          <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Consultando...
        </div>
      </div>
      <div class="grid grid-cols-3 divide-x divide-gray-200 dark:divide-gray-700 border-b border-gray-200 dark:border-gray-700">
        <div class="px-3 py-2 text-center">
          <p class="text-lg font-bold text-gray-800 dark:text-white">{{ ipStats.total }}</p>
          <p class="text-[10px] text-gray-500 dark:text-gray-400">Total hosts</p>
        </div>
        <div class="px-3 py-2 text-center">
          <p class="text-lg font-bold text-green-600 dark:text-green-400">{{ ipStats.free }}</p>
          <p class="text-[10px] text-gray-500 dark:text-gray-400">Libres</p>
        </div>
        <div class="px-3 py-2 text-center">
          <p class="text-lg font-bold text-red-500 dark:text-red-400">{{ ipStats.used }}</p>
          <p class="text-[10px] text-gray-500 dark:text-gray-400">Ocupadas</p>
        </div>
      </div>
      <div v-for="(range, idx) in parsedRanges" :key="idx" class="border-t border-gray-200 dark:border-gray-700">
        <button type="button" @click="toggleRange(idx)"
          class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-900/30 flex items-center justify-between text-left hover:bg-gray-100 dark:hover:bg-gray-900/50">
          <span class="flex items-center gap-2">
            <svg class="w-3.5 h-3.5 text-gray-400 transition-transform shrink-0"
              :class="expandedRanges.has(idx) ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
            <span class="text-xs font-mono font-semibold text-gray-700 dark:text-gray-300">🌐 {{ range.cidr }}</span>
          </span>
          <span class="text-xs text-gray-500">
            {{ range.hosts.length }} hosts ·
            <span class="text-green-600 dark:text-green-400 font-medium">{{ range.freeHosts.length }} libres</span>
          </span>
        </button>
        <div v-if="expandedRanges.has(idx)" class="px-3 py-2 max-h-44 overflow-y-auto">
          <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-1">
            <button v-for="ip in range.hosts" :key="ip" type="button"
              @click="range.freeSet.has(ip) && $emit('update:modelValue', ip)" :class="[
                'px-1 py-1 text-[10px] font-mono rounded transition-all truncate',
                !range.freeSet.has(ip)
                  ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 cursor-not-allowed line-through'
                  : modelValue === ip
                    ? 'bg-blue-500 text-white cursor-pointer ring-2 ring-blue-400'
                    : 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 hover:bg-green-200 dark:hover:bg-green-900/40 cursor-pointer'
              ]" :title="!range.freeSet.has(ip) ? 'IP en uso' : 'Click para asignar'"
              :disabled="!range.freeSet.has(ip)">{{ ip.split('.').pop() }}</button>
          </div>
        </div>
      </div>
      <div class="px-4 py-1.5 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 flex items-center gap-4 flex-wrap">
        <div class="flex items-center gap-1.5">
          <div class="w-2.5 h-2.5 rounded bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-700"></div>
          <span class="text-[10px] text-gray-500 dark:text-gray-400">Libre</span>
        </div>
        <div class="flex items-center gap-1.5">
          <div class="w-2.5 h-2.5 rounded bg-blue-500 border border-blue-400"></div>
          <span class="text-[10px] text-gray-500 dark:text-gray-400">Seleccionada</span>
        </div>
        <div class="flex items-center gap-1.5">
          <div class="w-2.5 h-2.5 rounded bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-700"></div>
          <span class="text-[10px] text-gray-500 dark:text-gray-400">En uso</span>
        </div>
        <div v-if="modelValue" class="ml-auto text-[10px] text-blue-600 dark:text-blue-400 font-medium">
          ✓ {{ modelValue }}
        </div>
      </div>
    </div>
  </div>
  <div v-else-if="loaded && routerId"
    class="mt-2 flex items-center gap-2 text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg px-3 py-2">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
    El router seleccionado no tiene rangos IP configurados.
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import api from '@/services/api'

const props = defineProps({
  modelValue: { type: String, default: '' },
  routerId:   { type: [Number, String, null], default: null },
})
defineEmits(['update:modelValue'])

const rangosIpStr   = ref('')
const usedIpsSet    = ref(new Set())
const loading       = ref(false)
const loaded        = ref(false)
const expandedRanges = ref(new Set())

const parseCIDR = (cidr, usedSet) => {
  const m = cidr.match(/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/)
  if (!m) return null
  const prefix = parseInt(m[2])
  if (prefix < 20 || prefix > 30) return null
  const parts = m[1].split('.').map(Number)
  const ipLong = ((parts[0] << 24) | (parts[1] << 16) | (parts[2] << 8) | parts[3]) >>> 0
  const mask = (0xFFFFFFFF << (32 - prefix)) >>> 0
  const network = (ipLong & mask) >>> 0
  const broadcast = (network | (~mask >>> 0)) >>> 0
  const hosts = [], freeHosts = []
  for (let i = network + 1; i < broadcast; i++) {
    const ip = [(i >>> 24) & 255, (i >>> 16) & 255, (i >>> 8) & 255, i & 255].join('.')
    hosts.push(ip)
    if (!usedSet.has(ip)) freeHosts.push(ip)
  }
  return { cidr, hosts, freeHosts, freeSet: new Set(freeHosts) }
}

const parsedRanges = computed(() => {
  if (!rangosIpStr.value) return []
  return rangosIpStr.value.split('\n').map(l => l.trim()).filter(Boolean)
    .map(cidr => parseCIDR(cidr, usedIpsSet.value)).filter(Boolean)
})

const ipStats = computed(() => {
  const total = parsedRanges.value.reduce((s, r) => s + r.hosts.length, 0)
  const free = parsedRanges.value.reduce((s, r) => s + r.freeHosts.length, 0)
  const used = total - free
  return { total, free, used }
})

const allExpanded = computed(() =>
  parsedRanges.value.length > 0 && expandedRanges.value.size === parsedRanges.value.length
)

const toggleRange = (idx) => {
  const s = new Set(expandedRanges.value)
  s.has(idx) ? s.delete(idx) : s.add(idx)
  expandedRanges.value = s
}

const toggleAll = () => {
  expandedRanges.value = allExpanded.value
    ? new Set()
    : new Set(parsedRanges.value.map((_, i) => i))
}

const load = async (id) => {
  rangosIpStr.value = ''
  usedIpsSet.value = new Set()
  expandedRanges.value = new Set()
  loaded.value = false
  if (!id) return
  loading.value = true
  try {
    const res = await api.routers.getFreeIps(id)
    rangosIpStr.value = res.data.rangos_ip ?? ''
    usedIpsSet.value = new Set(res.data.used_ips ?? [])
  } catch (e) {
    console.warn('No se pudieron cargar IPs libres:', e)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

watch(() => props.routerId, (id) => load(id), { immediate: true })
</script>
