<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-3">
            <div class="flex items-center gap-3">
                <button
                    @click="router.push('/sectorials')"
                    class="p-2.5 rounded-xl bg-white dark:bg-gray-800 shadow-md hover:shadow-lg text-gray-600 dark:text-gray-400 hover:text-indigo-600 transition-all"
                >
                    <v-icon name="md-arrowback" class="w-5 h-5" />
                </button>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">Topología FTTH</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Árbol de planta de fibra: OLT → splitter → caja NAP → cliente</p>
                </div>
            </div>
        </div>

        <!-- Resumen -->
        <div v-if="!loading && fiberElements.length" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
            <div v-for="card in summaryCards" :key="card.label" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-4 shadow-sm">
                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <v-icon :name="card.icon" class="w-4 h-4" />
                    {{ card.label }}
                </div>
                <div class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">{{ card.value }}</div>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-16">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-indigo-500 border-t-transparent"></div>
            <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando topología...</p>
        </div>

        <!-- Vacío -->
        <div v-else-if="!flatTree.length" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-10 text-center">
            <v-icon name="bi-diagram-3" class="w-10 h-10 text-gray-300 dark:text-gray-600 mx-auto mb-3" />
            <p class="text-gray-600 dark:text-gray-300 font-medium">Aún no hay elementos de fibra.</p>
            <p class="text-sm text-gray-400 mt-1">Crea un OLT, splitters y cajas NAP desde "Elementos de Red" y conéctalos con el campo "Conectado a (padre)".</p>
            <button
                @click="router.push('/sectorials/create')"
                class="mt-4 inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm"
            >
                <icon-lucide-plus class="w-4 h-4" /> Agregar Elemento
            </button>
        </div>

        <!-- Árbol -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-md overflow-hidden">
            <div
                v-for="node in flatTree"
                :key="node.el.id"
                class="flex items-center gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700/60 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition cursor-pointer"
                @click="router.push(`/sectorials/${node.el.id}`)"
            >
                <!-- Indentación + conector -->
                <div :style="{ width: (node.depth * 24) + 'px' }" class="flex-shrink-0"></div>
                <v-icon v-if="node.depth > 0" name="bi-arrow-return-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 flex-shrink-0" />

                <!-- Badge tipo -->
                <span :class="elementBadge(node.el.element_type)" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border flex-shrink-0">
                    <v-icon :name="elementIcon(node.el.element_type)" class="w-3.5 h-3.5" />
                    {{ elementLabel(node.el.element_type) }}
                </span>

                <!-- Nombre + meta -->
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-gray-800 dark:text-gray-100 truncate">{{ node.el.name }}</div>
                    <div class="text-[11px] text-gray-400 flex flex-wrap gap-x-3">
                        <span v-if="node.el.element_type === 'splitter' && node.el.split_ratio">Ratio {{ node.el.split_ratio }}</span>
                        <span v-if="node.el.pon_port">PON {{ node.el.pon_port }}</span>
                        <span v-if="node.el.vlan">VLAN {{ node.el.vlan }}</span>
                        <span v-if="node.el.ip">{{ node.el.ip }}</span>
                    </div>
                </div>

                <!-- Capacidad -->
                <span v-if="capacityText(node.el)" :class="capacityBadgeClass(node.el)" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border flex-shrink-0">
                    <v-icon name="bi-ethernet" class="w-3 h-3" />
                    {{ capacityText(node.el) }}
                </span>
                <span v-else-if="(node.el.clients_count || 0) > 0" class="text-xs text-gray-400 flex-shrink-0">
                    {{ node.el.clients_count }} cliente(s)
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import {
    elementLabel,
    elementIcon,
    elementBadge,
    isFiber,
} from '@/constants/networkElements'

const router = useRouter()

const elements = ref([])
const loading = ref(true)

const fiberElements = computed(() => elements.value.filter(e => isFiber(e.element_type)))

// Mapa parent_id -> hijos (entre elementos de fibra).
const childrenByParent = computed(() => {
    const map = {}
    for (const el of fiberElements.value) {
        const pid = el.parent_id ?? 'root'
        ;(map[pid] ||= []).push(el)
    }
    return map
})

// Raíces: elementos de fibra sin padre, o cuyo padre no es de fibra.
const roots = computed(() => {
    const fiberIds = new Set(fiberElements.value.map(e => e.id))
    return fiberElements.value
        .filter(e => !e.parent_id || !fiberIds.has(e.parent_id))
        .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
})

// DFS -> lista plana con profundidad, para renderizar con indentación.
const flatTree = computed(() => {
    const out = []
    const seen = new Set()
    const visit = (el, depth) => {
        if (seen.has(el.id)) return // protección contra ciclos
        seen.add(el.id)
        out.push({ el, depth })
        const kids = (childrenByParent.value[el.id] || [])
            .slice()
            .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
        for (const kid of kids) visit(kid, depth + 1)
    }
    for (const r of roots.value) visit(r, 0)
    return out
})

const summaryCards = computed(() => {
    const count = (t) => fiberElements.value.filter(e => e.element_type === t).length
    const freePorts = fiberElements.value.reduce((acc, e) => acc + (e.ports_free ?? 0), 0)
    return [
        { label: 'OLTs',        value: count('olt'),      icon: 'bi-server' },
        { label: 'Splitters',   value: count('splitter'), icon: 'bi-diagram-2' },
        { label: 'Cajas NAP',   value: count('nap'),      icon: 'bi-box-seam' },
        { label: 'Puertos libres', value: freePorts,      icon: 'bi-ethernet' },
    ]
})

const capacityText = (s) => {
    if (s.ports_capacity == null) return null
    return `${s.ports_used ?? 0}/${s.ports_capacity}`
}
const capacityBadgeClass = (s) => {
    const free = s.ports_free
    if (free == null) return 'bg-gray-50 text-gray-600 border-gray-200 dark:bg-gray-700/40 dark:text-gray-300 dark:border-gray-600'
    if (free <= 0) return 'bg-red-50 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800'
    if (free <= 2) return 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800'
    return 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800'
}

const loadElements = async () => {
    try {
        loading.value = true
        const response = await api.sectorials.getAll()
        elements.value = response.data || []
    } catch (err) {
        console.error('Error al cargar topología:', err)
    } finally {
        loading.value = false
    }
}

onMounted(loadElements)
</script>
