<template>
  <Teleport to="body">
    <div v-if="visible" data-testid="search-picker"
      class="fixed inset-0 z-app-modal flex items-start sm:items-center justify-center bg-black/50 backdrop-blur-sm p-0 sm:p-4"
      @click.self="close" @keydown.esc.stop.prevent="close">
      <div role="dialog" aria-modal="true" :aria-label="title"
        class="bg-white dark:bg-gray-800 w-full sm:max-w-xl h-full sm:h-auto sm:max-h-[85vh] sm:rounded-2xl shadow-2xl flex flex-col">
        <!-- Cabecera + buscador. El buscador es lo primero que recibe el foco:
             se abre y se escribe, sin tocar nada más. -->
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
          <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ title }}</h2>
            <button type="button" @click="close" aria-label="Cerrar"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 px-2 py-1 rounded-lg">✕</button>
          </div>
          <input ref="input" v-model="query" type="search" :placeholder="placeholder" data-testid="search-picker-input"
            autocomplete="off" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="pickActive"
            class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          <div v-if="filters.length" class="mt-2 flex flex-wrap gap-1.5">
            <button v-for="f in filters" :key="f.key" type="button" @click="activeFilter = f.key"
              class="text-xs px-2.5 py-1 rounded-full border transition"
              :class="activeFilter === f.key
                ? 'bg-blue-600 border-blue-600 text-white'
                : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'">
              {{ f.label }} ({{ countFor(f.key) }})
            </button>
          </div>
          <slot name="header" />
        </div>

        <!-- Resultados -->
        <ul ref="list" class="flex-1 overflow-y-auto p-2" role="listbox">
          <li v-for="(item, idx) in results" :key="item.key" role="option" :aria-selected="idx === active"
            :data-testid="`search-picker-option`">
            <button type="button" @click="pick(item)" @mouseenter="active = idx" :disabled="item.disabled"
              class="w-full text-left px-3 py-2.5 rounded-lg transition flex items-start justify-between gap-3 disabled:opacity-50 disabled:cursor-not-allowed"
              :class="idx === active ? 'bg-blue-50 dark:bg-blue-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50'">
              <span class="min-w-0">
                <span class="block text-sm text-gray-800 dark:text-white">
                  {{ item.title }}
                  <span v-if="item.badge" class="ml-1 text-[10px] uppercase px-1.5 py-0.5 rounded align-middle"
                    :class="item.badgeClass || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ item.badge }}</span>
                </span>
                <span v-if="item.subtitle" class="block text-xs text-gray-500 dark:text-gray-400 break-words">{{ item.subtitle }}</span>
              </span>
              <span v-if="item.meta" class="shrink-0 text-xs text-right text-gray-600 dark:text-gray-300 max-w-[45%]">{{ item.meta }}</span>
            </button>
          </li>
        </ul>
        <p v-if="!results.length" class="px-4 pb-4 text-sm text-gray-500 dark:text-gray-400" data-testid="search-picker-empty">
          {{ query.trim() ? `Nada coincide con «${query.trim()}».` : emptyText }}
        </p>
        <p v-else-if="hiddenCount > 0" class="px-4 pb-3 text-[11px] text-gray-500 dark:text-gray-400">
          Se muestran los primeros {{ results.length }} de {{ matched.length }}. Escribe más para acotar.
        </p>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'

/**
 * Buscador en ventana para elegir un elemento de una lista larga (productos,
 * materiales, equipos con serial). Sustituye a los <select> de cientos de
 * opciones, donde había que bajar a ojo hasta encontrar «AMARRES».
 *
 * Cada item: { key, title, subtitle?, meta?, badge?, badgeClass?, group?,
 * search?: texto extra para buscar, disabled? }. Elegir sólo emite `select`:
 * quien lo usa decide qué hacer (ningún item descuenta nada por elegirse).
 *
 * La búsqueda no distingue mayúsculas ni tildes, ignora separadores de serial
 * y MAC (AA:BB = aabb) y exige todas las palabras escritas, en cualquier orden.
 */
const props = defineProps({
  visible:     { type: Boolean, default: false },
  title:       { type: String, default: 'Buscar' },
  placeholder: { type: String, default: 'Escribe para buscar…' },
  items:       { type: Array, default: () => [] },
  emptyText:   { type: String, default: 'No hay elementos para elegir.' },
  /** Pestañas opcionales: [{ key, label, test: (item) => bool }]. La primera es la activa al abrir. */
  filters:     { type: Array, default: () => [] },
  /** Tope de resultados pintados: con miles de seriales, pintar todos traba el teléfono del técnico. */
  limit:       { type: Number, default: 200 },
  initialQuery: { type: String, default: '' },
})

const emit = defineEmits(['select', 'close'])

const input = ref(null)
const list = ref(null)
const query = ref('')
const active = ref(0)
const activeFilter = ref(null)

const fold = (value) => String(value ?? '')
  .normalize('NFD').replace(/[̀-ͯ]/g, '')
  .toLowerCase()

const compact = (value) => fold(value).replace(/[\s:.\-_/]/g, '')

const haystack = (item) => {
  const text = [item.title, item.subtitle, item.meta, item.badge, item.search].filter(Boolean).join(' ')
  return { words: fold(text), compact: compact(text) }
}

const indexed = computed(() => props.items.map(item => ({ item, ...haystack(item) })))

const filterTest = (key) => props.filters.find(f => f.key === key)?.test ?? (() => true)

const matchesQuery = (entry, terms) => terms.every(t => entry.words.includes(t) || entry.compact.includes(compact(t)))

const terms = computed(() => fold(query.value).split(/\s+/).filter(Boolean))

const matched = computed(() => {
  const test = filterTest(activeFilter.value)
  return indexed.value
    .filter(e => test(e.item) && matchesQuery(e, terms.value))
    .map(e => e.item)
})

const results = computed(() => matched.value.slice(0, props.limit))
const hiddenCount = computed(() => matched.value.length - results.value.length)

const countFor = (key) => {
  const test = filterTest(key)
  return indexed.value.filter(e => test(e.item) && matchesQuery(e, terms.value)).length
}

watch(results, () => { active.value = 0 })

watch(() => props.visible, async (open) => {
  if (!open) return
  query.value = props.initialQuery
  activeFilter.value = props.filters[0]?.key ?? null
  active.value = 0
  await nextTick()
  input.value?.focus()
}, { immediate: true })

const move = async (step) => {
  if (!results.value.length) return
  active.value = (active.value + step + results.value.length) % results.value.length
  await nextTick()
  list.value?.querySelectorAll('[role=option]')[active.value]?.scrollIntoView({ block: 'nearest' })
}

const pick = (item) => {
  if (!item || item.disabled) return
  emit('select', item)
}

const pickActive = () => pick(results.value[active.value])

const close = () => emit('close')
</script>
