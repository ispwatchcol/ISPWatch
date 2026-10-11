<template>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            Asunto <span v-if="requerido" class="text-red-500">*</span>
        </label>

        <!-- Con catálogo: desplegable + «Otro…». Sin catálogo todavía, el texto
             libre de siempre: una lista vacía no puede bloquear el radicado. -->
        <select
            v-if="opciones.length"
            :value="valorDelSelect"
            :disabled="deshabilitado"
            @change="elegir($event.target.value)"
            class="w-full px-3 py-2 rounded-lg border bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60 disabled:cursor-not-allowed"
            :class="error ? 'border-red-500' : 'border-gray-300 dark:border-gray-600'"
        >
            <option value="" disabled>— Selecciona un asunto —</option>
            <option v-for="s in opciones" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
            <option :value="OTRO">Otro…</option>
        </select>

        <input
            v-if="!opciones.length || esOtro"
            :value="modelValue.subject || ''"
            :disabled="deshabilitado"
            @input="escribir($event.target.value)"
            type="text"
            maxlength="255"
            :placeholder="opciones.length ? 'Escribe el asunto' : 'Ej: Problema con conexión a internet'"
            class="w-full px-3 py-2 rounded-lg border bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60 disabled:cursor-not-allowed"
            :class="[error ? 'border-red-500' : 'border-gray-300 dark:border-gray-600', opciones.length ? 'mt-2' : '']"
        />

        <p v-if="error" class="mt-1 text-sm text-red-500">{{ Array.isArray(error) ? error[0] : error }}</p>
        <p v-else-if="!opciones.length && puedeAdministrar" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Define los asuntos frecuentes desde <strong>Soporte → Asuntos</strong> para poder filtrar los tickets por asunto.
        </p>
        <p v-else-if="esOtro && opciones.length" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Los tickets con asunto escrito a mano se agrupan como «Otro» al filtrar.
        </p>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'

/**
 * Asunto del ticket: del catálogo del ISP o escrito a mano («Otro…»).
 *
 * El contrato es `{ subject_id, subject }`, el mismo que espera la API:
 *   · asunto del catálogo → `subject_id` con su id; el servidor copia el nombre.
 *   · «Otro…»              → `subject_id: null` y el texto en `subject`.
 *
 * Vive en un componente porque lo usan el alta, la edición y el modal de
 * cierre del detalle: tres copias del mismo desplegable se desincronizan a la
 * primera corrección.
 */
const props = defineProps({
    /** { subject_id: number|null, subject: string } */
    modelValue: { type: Object, required: true },
    error: { type: [String, Array], default: null },
    deshabilitado: { type: Boolean, default: false },
    requerido: { type: Boolean, default: true },
    /** Muestra la pista de «configura los asuntos» a quien puede hacerlo. */
    puedeAdministrar: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const OTRO = '__otro__'

const { subjects } = useTicketCatalogs()

/**
 * Los vigentes, más el que ya tiene el ticket si se desactivó después: editar
 * un ticket viejo no puede hacer desaparecer su asunto del desplegable.
 */
const opciones = computed(() => {
    const vigentes = subjects.value ?? []
    const id = props.modelValue.subject_id

    if (id && !vigentes.some((s) => s.id === Number(id))) {
        return [...vigentes, { id: Number(id), name: `${props.modelValue.subject || 'Asunto'} (desactivado)` }]
    }

    return vigentes
})

// «Otro…» elegido a propósito, aunque el texto todavía esté vacío.
const otroElegido = ref(false)

const esOtro = computed(() => !props.modelValue.subject_id && (otroElegido.value || !!props.modelValue.subject))

const valorDelSelect = computed(() => {
    if (props.modelValue.subject_id) return String(props.modelValue.subject_id)
    return esOtro.value ? OTRO : ''
})

// Si el formulario se reinicia desde fuera (cargar otro ticket), se olvida la
// elección manual de «Otro…».
watch(() => props.modelValue.subject_id, (id) => {
    if (id) otroElegido.value = false
})

function elegir(valor) {
    if (valor === OTRO) {
        otroElegido.value = true
        // El texto anterior era el nombre del catálogo: no es lo que se quiere
        // escribir ahora.
        emit('update:modelValue', { subject_id: null, subject: props.modelValue.subject_id ? '' : (props.modelValue.subject || '') })
        return
    }

    otroElegido.value = false
    const elegido = opciones.value.find((s) => String(s.id) === valor)
    emit('update:modelValue', { subject_id: elegido ? elegido.id : null, subject: elegido ? elegido.name.replace(/ \(desactivado\)$/, '') : '' })
}

function escribir(texto) {
    emit('update:modelValue', { subject_id: null, subject: texto })
}
</script>
