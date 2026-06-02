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
            :class="[
                'w-full px-3 py-2 rounded-lg border bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 flex items-center justify-between text-left transition',
                error ? 'border-red-500' : 'border-gray-300 dark:border-gray-600',
            ]"
        >
            <span :class="modelValue ? '' : 'text-gray-400 dark:text-gray-500'" class="truncate">
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
                class="fixed z-[9999] bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-2xl"
            >
                <div class="p-2 border-b border-gray-200 dark:border-gray-600">
                    <input
                        v-model="searchTerm"
                        ref="searchInputRef"
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="w-full px-3 py-1.5 text-sm rounded-md border border-gray-300 dark:border-gray-500 bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        @keydown.down.prevent="moveActiveIndex(1)"
                        @keydown.up.prevent="moveActiveIndex(-1)"
                        @keydown.enter.prevent="confirmActiveItem"
                        @keydown.escape="dropdownOpen = false"
                    />
                </div>
                <ul class="max-h-52 overflow-y-auto py-1" @click="onListClick">
                    <li
                        v-if="clearable"
                        :data-item-id="''"
                        class="px-3 py-2 text-sm cursor-pointer text-gray-500 dark:text-gray-400 italic flex items-center gap-2 hover:bg-gray-100 dark:hover:bg-gray-600 border-b border-gray-100 dark:border-gray-600"
                    >
                        <v-icon name="io-close" class="w-4 h-4 pointer-events-none" />
                        <span class="pointer-events-none">{{ clearLabel }}</span>
                    </li>
                    <li
                        v-for="(item, idx) in filteredItems"
                        :key="getItemKey(item)"
                        :data-item-id="getItemKey(item)"
                        @mouseenter="activeIndex = idx"
                        class="px-3 py-2 text-sm cursor-pointer text-gray-800 dark:text-white flex items-center gap-2"
                        :class="[
                            activeIndex === idx ? 'bg-blue-100 dark:bg-blue-900/40' : '',
                            String(modelValue) === String(getItemKey(item)) ? 'font-semibold text-blue-700 dark:text-blue-300' : ''
                        ]"
                    >
                        <v-icon :name="itemIcon" class="w-4 h-4 text-gray-400 flex-shrink-0 pointer-events-none" />
                        <span class="pointer-events-none whitespace-nowrap">{{ getItemLabel(item) }}</span>
                    </li>
                    <li v-if="filteredItems.length === 0" class="px-3 py-3 text-sm text-gray-400 dark:text-gray-500 text-center italic">
                        Sin resultados
                    </li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'

const props = defineProps({
    modelValue: { type: [Number, String, null], default: null },
    items: { type: Array, default: () => [] },
    itemKey: { type: String, default: 'id' },
    itemLabel: { type: [String, Function], default: 'name' },
    itemIcon: { type: String, default: 'bi-person' },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '-- Selecciona una opción --' },
    searchPlaceholder: { type: String, default: 'Buscar...' },
    required: { type: Boolean, default: false },
    error: { type: String, default: '' },
    clearable: { type: Boolean, default: false },
    clearLabel: { type: String, default: 'Sin selección' },
})

const emit = defineEmits(['update:modelValue'])

const dropdownOpen = ref(false)
const searchTerm = ref('')
const activeIndex = ref(-1)
const dropdownStyle = ref({})

const triggerRef = ref(null)
const dropdownRef = ref(null)
const searchInputRef = ref(null)

const getItemKey = (item) => item?.[props.itemKey]

const getItemLabel = (item) => {
    if (typeof props.itemLabel === 'function') return props.itemLabel(item)
    return item?.[props.itemLabel] ?? ''
}

const filteredItems = computed(() => {
    const q = searchTerm.value.trim().toLowerCase()
    if (!q) return props.items
    return props.items.filter(item => getItemLabel(item).toLowerCase().includes(q))
})

const selectedLabel = computed(() => {
    if (!props.modelValue && props.modelValue !== 0) return ''
    const item = props.items.find(x => String(getItemKey(x)) === String(props.modelValue))
    return item ? getItemLabel(item) : ''
})

watch(filteredItems, () => { activeIndex.value = -1 })

const selectItem = (item) => {
    emit('update:modelValue', item ? getItemKey(item) : '')
    dropdownOpen.value = false
    searchTerm.value = ''
    activeIndex.value = -1
}

const onListClick = (e) => {
    const li = e.target.closest('li[data-item-id]')
    if (!li) return
    const id = li.dataset.itemId
    if (id === '') {
        selectItem(null)
        return
    }
    const item = props.items.find(x => String(getItemKey(x)) === String(id))
    if (item) selectItem(item)
}

const moveActiveIndex = (delta) => {
    const total = filteredItems.value.length
    if (total === 0) return
    const next = activeIndex.value + delta
    if (next < 0) activeIndex.value = total - 1
    else if (next >= total) activeIndex.value = 0
    else activeIndex.value = next
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

const toggleDropdown = async () => {
    if (dropdownOpen.value) {
        dropdownOpen.value = false
        return
    }
    computeDropdownPosition()
    dropdownOpen.value = true
    await nextTick()
    computeDropdownPosition()
    searchInputRef.value?.focus()
}

const handleClickOutside = (e) => {
    if (!dropdownOpen.value) return
    const insideDropdown = dropdownRef.value?.contains(e.target)
    const insideTrigger = triggerRef.value?.contains(e.target)
    if (!insideDropdown && !insideTrigger) {
        dropdownOpen.value = false
        searchTerm.value = ''
        activeIndex.value = -1
    }
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
