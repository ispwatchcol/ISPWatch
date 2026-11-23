<template>
    <div>
        <label v-if="label" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ label }} <span v-if="required" class="text-red-500">*</span>
        </label>

        <!-- Trigger -->
        <button
            ref="triggerRef"
            type="button"
            @click="toggleDropdown"
            @keydown.down.prevent="openDropdown"
            :disabled="disabled"
            :title="title || undefined"
            :class="[
                'w-full rounded-lg border bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 flex items-center justify-between text-left transition disabled:opacity-60 disabled:cursor-not-allowed',
                sizeClass,
                error || invalid ? 'border-red-500' : 'border-gray-300 dark:border-gray-600',
            ]"
        >
            <span :class="hasValue ? '' : 'text-gray-400 dark:text-gray-500'" class="truncate">
                {{ selectedLabel || placeholder }}
            </span>
            <v-icon name="md-keyboardarrowdown" class="w-4 h-4 text-gray-400 flex-shrink-0 transition-transform ml-2" :class="{ 'rotate-180': dropdownOpen }" />
        </button>
        <p v-if="error" class="mt-1 text-sm text-red-500">{{ error }}</p>

        <!-- Dropdown teleported to body -->
        <Teleport to="body">
            <div
                v-if="dropdownOpen"
                ref="dropdownRef"
                :style="dropdownStyle"
                :class="insideModal ? 'z-app-modal-dropdown' : 'z-app-dropdown'"
                class="fixed bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-2xl"
            >
                <div class="p-2 border-b border-gray-200 dark:border-gray-600">
                    <input
                        v-model="searchTerm"
                        ref="searchInputRef"
                        type="text"
                        autocomplete="off"
                        :placeholder="searchPlaceholder"
                        class="w-full px-3 py-1.5 text-sm rounded-md border border-gray-300 dark:border-gray-500 bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        @keydown.down.prevent="moveActiveIndex(1)"
                        @keydown.up.prevent="moveActiveIndex(-1)"
                        @keydown.enter.prevent="confirmActiveItem"
                        @keydown.escape="closeDropdown"
                        @keydown.tab="closeDropdown"
                    />
                </div>
                <ul ref="listRef" class="max-h-60 overflow-y-auto py-1" @click="onListClick">
                    <li
                        v-if="clearable && !searchTerm.trim()"
                        :data-item-id="CLEAR_ID"
                        class="px-3 py-2 text-sm cursor-pointer text-gray-500 dark:text-gray-400 italic flex items-center gap-2 hover:bg-gray-100 dark:hover:bg-gray-600 border-b border-gray-100 dark:border-gray-600"
                    >
                        <v-icon name="io-close" class="w-4 h-4 pointer-events-none" />
                        <span class="pointer-events-none">{{ clearLabel }}</span>
                    </li>
                    <li
                        v-for="(item, idx) in filteredItems"
                        :key="getItemKey(item)"
                        :data-item-id="getItemKey(item)"
                        :data-option-index="idx"
                        @mouseenter="activeIndex = idx"
                        class="px-3 py-2 text-sm cursor-pointer text-gray-800 dark:text-white flex items-center gap-2"
                        :class="[
                            activeIndex === idx ? 'bg-blue-100 dark:bg-blue-900/40' : '',
                            isSelected(item) ? 'font-semibold text-blue-700 dark:text-blue-300' : ''
                        ]"
                    >
                        <v-icon v-if="itemIcon" :name="itemIcon" class="w-4 h-4 text-gray-400 flex-shrink-0 pointer-events-none" />
                        <span class="pointer-events-none min-w-0">
                            <span class="block whitespace-nowrap">{{ getItemLabel(item) }}</span>
                            <span v-if="getItemSublabel(item)" class="block text-xs font-normal text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                {{ getItemSublabel(item) }}
                            </span>
                        </span>
                    </li>
                    <li v-if="filteredItems.length === 0" class="px-3 py-3 text-sm text-gray-400 dark:text-gray-500 text-center italic">
                        {{ searchTerm.trim() ? `Nada coincide con «${searchTerm.trim()}»` : emptyText }}
                    </li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'

/**
 * Desplegable con buscador. Sustituye al <select> nativo donde la lista es
 * larga (técnicos, materiales, custodios, códigos de diagnóstico): se abre, se
 * escribe y se elige, sin bajar a ojo por la lista.
 *
 * Para listas de cientos o miles de elementos con varias líneas de detalle
 * (seriales, catálogo de productos) está `ui/SearchPickerModal.vue`, que abre
 * una ventana. Este componente es el reemplazo directo de un <select>.
 *
 * La búsqueda no distingue mayúsculas ni tildes («tecnico» encuentra
 * «Técnico»), ignora guiones y dos puntos («wifi» encuentra «Wi-Fi») y exige
 * todas las palabras escritas, en cualquier orden. Busca en la etiqueta, en la
 * segunda línea y en `itemSearch` (texto extra: correo, serial, código).
 */
const props = defineProps({
    modelValue: { type: [Number, String, null], default: null },
    items: { type: Array, default: () => [] },
    itemKey: { type: String, default: 'id' },
    itemLabel: { type: [String, Function], default: 'name' },
    /** Segunda línea opcional bajo la etiqueta (clave o función). */
    itemSublabel: { type: [String, Function], default: null },
    /** Texto extra por el que también se encuentra el elemento (función). */
    itemSearch: { type: Function, default: null },
    itemIcon: { type: String, default: 'bi-person' },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '-- Selecciona una opción --' },
    searchPlaceholder: { type: String, default: 'Buscar...' },
    emptyText: { type: String, default: 'No hay opciones.' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    title: { type: String, default: '' },
    error: { type: String, default: '' },
    /** Borde rojo sin texto: para cuando el mensaje lo pinta quien lo usa. */
    invalid: { type: Boolean, default: false },
    clearable: { type: Boolean, default: false },
    clearLabel: { type: String, default: 'Sin selección' },
    /**
     * Lo que se emite al elegir la opción de limpiar. '' por compatibilidad;
     * los formularios que guardan null (técnico «sin asignar») pasan null.
     */
    clearValue: { type: [Number, String, null], default: '' },
    /** sm: filtros y filas compactas · md: por defecto · lg: formularios con inputs py-3. */
    size: { type: String, default: 'md' },
})

const emit = defineEmits(['update:modelValue'])

// Marca de la opción «limpiar» en el DOM: '' chocaría con un item cuya clave
// sea la cadena vacía (la «Bodega (sin sucursal)» de traspasos es `branch:`).
const CLEAR_ID = '__clear__'

const dropdownOpen = ref(false)
const searchTerm = ref('')
const activeIndex = ref(-1)
const dropdownStyle = ref({})

const triggerRef = ref(null)
const dropdownRef = ref(null)
const searchInputRef = ref(null)
const listRef = ref(null)

const sizeClass = computed(() => ({
    sm: 'px-3 py-2 text-sm',
    md: 'px-3 py-2',
    lg: 'px-4 py-3',
}[props.size] ?? 'px-3 py-2'))

const getItemKey = (item) => item?.[props.itemKey]

const getItemLabel = (item) => {
    if (typeof props.itemLabel === 'function') return props.itemLabel(item) ?? ''
    return item?.[props.itemLabel] ?? ''
}

const getItemSublabel = (item) => {
    if (!props.itemSublabel) return ''
    if (typeof props.itemSublabel === 'function') return props.itemSublabel(item) ?? ''
    return item?.[props.itemSublabel] ?? ''
}

const fold = (value) => String(value ?? '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase()

// Sin separadores: «wifi» encuentra «Wi-Fi» y «aabb» encuentra «AA:BB».
const compact = (value) => fold(value).replace(/[\s:.\-_/]/g, '')

const indexed = computed(() => props.items.map(item => {
    const raw = [getItemLabel(item), getItemSublabel(item), props.itemSearch?.(item)].filter(Boolean).join(' ')
    return { item, text: fold(raw), flat: compact(raw) }
}))

const filteredItems = computed(() => {
    const terms = fold(searchTerm.value).split(/\s+/).filter(Boolean)
    if (!terms.length) return props.items
    return indexed.value
        .filter(entry => terms.every(t => entry.text.includes(t) || entry.flat.includes(compact(t))))
        .map(entry => entry.item)
})

const isEmptyValue = (value) => value === null || value === undefined || value === ''

const hasValue = computed(() => !isEmptyValue(props.modelValue) && props.modelValue !== props.clearValue)

const isSelected = (item) => hasValue.value && String(props.modelValue) === String(getItemKey(item))

const selectedLabel = computed(() => {
    if (!hasValue.value) return ''
    const item = props.items.find(x => String(getItemKey(x)) === String(props.modelValue))
    return item ? getItemLabel(item) : ''
})

watch(filteredItems, () => { activeIndex.value = searchTerm.value.trim() ? 0 : -1 })

const closeDropdown = () => {
    dropdownOpen.value = false
    searchTerm.value = ''
    activeIndex.value = -1
}

const selectItem = (item) => {
    emit('update:modelValue', item ? getItemKey(item) : props.clearValue)
    closeDropdown()
    triggerRef.value?.focus({ preventScroll: true })
}

const onListClick = (e) => {
    const li = e.target.closest('li[data-item-id]')
    if (!li) return
    const id = li.dataset.itemId
    if (id === CLEAR_ID) {
        selectItem(null)
        return
    }
    const item = props.items.find(x => String(getItemKey(x)) === String(id))
    if (item) selectItem(item)
}

const moveActiveIndex = async (delta) => {
    const total = filteredItems.value.length
    if (total === 0) return
    const next = activeIndex.value + delta
    if (next < 0) activeIndex.value = total - 1
    else if (next >= total) activeIndex.value = 0
    else activeIndex.value = next
    await nextTick()
    listRef.value?.querySelector(`[data-option-index="${activeIndex.value}"]`)?.scrollIntoView({ block: 'nearest' })
}

const confirmActiveItem = () => {
    if (activeIndex.value >= 0 && activeIndex.value < filteredItems.value.length) {
        selectItem(filteredItems.value[activeIndex.value])
    }
}

const computeDropdownPosition = () => {
    if (!triggerRef.value) return
    const rect = triggerRef.value.getBoundingClientRect()
    const margin = 4
    const dropdownH = dropdownRef.value?.offsetHeight || 300
    const spaceBelow = window.innerHeight - rect.bottom
    const spaceAbove = rect.top
    const openUp = spaceBelow < dropdownH + margin && spaceAbove > spaceBelow
    const top = openUp
        ? Math.max(margin, rect.top - dropdownH - margin)
        : rect.bottom + margin

    // El dropdown puede crecer más ancho que el trigger para mostrar nombres
    // completos (p. ej. planes "Internet Fibra 100 Megas") en vez de cortarlos.
    // Se ancla a la izquierda del trigger y se acota para no salirse de pantalla.
    const dropdownW = dropdownRef.value?.offsetWidth || rect.width
    const maxLeft = Math.max(margin, window.innerWidth - dropdownW - margin)
    const left = Math.min(rect.left, maxLeft)
    dropdownStyle.value = {
        top: `${top}px`,
        left: `${left}px`,
        minWidth: `${rect.width}px`,
        maxWidth: `${window.innerWidth - margin * 2}px`,
    }
}

// La lista va teletransportada a <body>; si el disparador está dentro de un
// modal `z-app-modal`, la capa normal de desplegables quedaría detrás de él.
const insideModal = ref(false)

const openDropdown = async () => {
    if (props.disabled || dropdownOpen.value) return
    insideModal.value = !!triggerRef.value?.closest('.z-app-modal')
    computeDropdownPosition()
    dropdownOpen.value = true
    await nextTick()
    computeDropdownPosition()
    searchInputRef.value?.focus()
}

const toggleDropdown = () => {
    if (dropdownOpen.value) {
        closeDropdown()
        return
    }
    openDropdown()
}

// Deshabilitado con la lista abierta (p. ej. mientras se guarda): se cierra.
watch(() => props.disabled, (value) => { if (value) closeDropdown() })

const handleClickOutside = (e) => {
    if (!dropdownOpen.value) return
    const insideDropdown = dropdownRef.value?.contains(e.target)
    const insideTrigger = triggerRef.value?.contains(e.target)
    if (!insideDropdown && !insideTrigger) closeDropdown()
}

const handleWindowChange = () => {
    if (dropdownOpen.value) computeDropdownPosition()
}

onMounted(() => {
    document.addEventListener('mousedown', handleClickOutside)
    window.addEventListener('resize', handleWindowChange)
    window.addEventListener('scroll', handleWindowChange, true)
})

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', handleClickOutside)
    window.removeEventListener('resize', handleWindowChange)
    window.removeEventListener('scroll', handleWindowChange, true)
})
</script>
