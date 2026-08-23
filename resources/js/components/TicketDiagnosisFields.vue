<template>
    <fieldset class="border border-gray-200 dark:border-gray-700 rounded-lg p-5">
        <legend class="px-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
            Diagnóstico técnico
        </legend>

        <p class="text-xs text-gray-500 dark:text-gray-400 -mt-1 mb-5">
            Opcional. Se puede completar más adelante, cuando se cierre la visita.
        </p>

        <!-- Cargando: los desplegables vacíos sin explicación parecen un error -->
        <div v-if="cargando" class="flex items-center gap-3 py-4 text-gray-500 dark:text-gray-400">
            <div class="animate-spin rounded-full h-5 w-5 border-2 border-blue-500 border-t-transparent"></div>
            <span class="text-sm">Cargando catálogos de diagnóstico…</span>
        </div>

        <!-- Error: se dice qué pasó y se ofrece reintentar, en vez de dejar
             cinco selects vacíos que parecen un catálogo sin datos -->
        <div
            v-else-if="error"
            class="rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4"
        >
            <p class="text-sm text-amber-800 dark:text-amber-200">
                No se pudieron cargar los catálogos de diagnóstico. El resto del ticket
                se puede guardar igual; el diagnóstico queda para más tarde.
            </p>
            <button
                type="button"
                @click="$emit('reintentar')"
                class="mt-3 text-sm font-medium text-amber-900 dark:text-amber-100 underline"
            >
                Reintentar
            </button>
        </div>

        <!-- Vacío: el catálogo respondió pero no trae vocabulario -->
        <div
            v-else-if="sinVocabulario"
            class="rounded-lg border border-gray-200 dark:border-gray-700 p-4"
        >
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Todavía no hay vocabulario de diagnóstico configurado para este operador.
            </p>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div v-for="campo in campos" :key="campo.clave" :class="campo.ancho">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    {{ campo.etiqueta }}
                </label>

                <select
                    :value="modelValue[campo.clave] ?? ''"
                    @change="actualizar(campo.clave, $event.target.value)"
                    class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    :class="{ 'border-red-500': errores[campo.clave] }"
                >
                    <option value="">— Sin definir —</option>
                    <option v-for="fila in campo.opciones" :key="fila.code" :value="fila.code">
                        {{ fila.code }} · {{ fila.label }}
                    </option>
                </select>

                <p v-if="errores[campo.clave]" class="mt-1 text-sm text-red-500">
                    {{ Array.isArray(errores[campo.clave]) ? errores[campo.clave][0] : errores[campo.clave] }}
                </p>

                <!-- Las familias de causa arrastran las subcausas del Anexo A como
                     texto de referencia. NO son opciones: el documento del cliente
                     no les asigna código (decisión D-06). -->
                <p
                    v-else-if="campo.esCausa && referenciaDe(modelValue[campo.clave])"
                    class="mt-2 text-xs text-gray-500 dark:text-gray-400 leading-relaxed"
                >
                    <span class="font-medium">Subcausas de referencia:</span>
                    {{ referenciaDe(modelValue[campo.clave]) }}
                </p>
            </div>
        </div>
    </fieldset>
</template>

<script setup>
import { computed } from 'vue'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'

/**
 * Los cinco campos de diagnóstico del Anexo A, en un solo sitio.
 *
 * Vive en un componente y no repetido en SupportCreate y SupportEdit por la
 * misma razón que el composable de catálogos: este módulo ya tuvo los mapas de
 * etiquetas duplicados en cinco pantallas, y añadir un valor obligaba a
 * acordarse de las cinco.
 *
 * El contrato hacia fuera son CÓDIGOS (`S01`, `RF`, `AC07`, `R02`), igual que
 * `status`/`priority`/`category`. El componente no conoce ids: los ids son de la
 * base de datos y no viajan al navegador.
 */

const props = defineProps({
    /** { symptom, suspected_cause, confirmed_cause, solution, result } */
    modelValue: { type: Object, required: true },
    /** Errores de validación del backend, por clave. */
    errores: { type: Object, default: () => ({}) },
    cargando: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'reintentar'])

const { symptoms, causes, actions, results, error } = useTicketCatalogs()

const campos = computed(() => [
    { clave: 'symptom', etiqueta: 'Síntoma reportado', opciones: symptoms.value, ancho: '' },
    { clave: 'suspected_cause', etiqueta: 'Causa sospechada', opciones: causes.value, ancho: '', esCausa: true },
    { clave: 'confirmed_cause', etiqueta: 'Causa confirmada', opciones: causes.value, ancho: '', esCausa: true },
    { clave: 'solution', etiqueta: 'Acción realizada', opciones: actions.value, ancho: '' },
    { clave: 'result', etiqueta: 'Resultado del cierre', opciones: results.value, ancho: 'md:col-span-2' },
])

const sinVocabulario = computed(
    () => symptoms.value.length === 0 && causes.value.length === 0
        && actions.value.length === 0 && results.value.length === 0,
)

/** Texto de subcausas de la familia elegida, si lo trae el catálogo. */
function referenciaDe(code) {
    if (!code) return ''
    return causes.value.find((fila) => fila.code === code)?.description ?? ''
}

/**
 * La cadena vacía del `<select>` se manda como null, no como ''.
 *
 * El backend distingue «no lo mandó» de «mándalo a null»: '' no es un código
 * válido y sería un 422, mientras que null es la orden explícita de borrar.
 */
function actualizar(clave, valor) {
    emit('update:modelValue', {
        ...props.modelValue,
        [clave]: valor === '' ? null : valor,
    })
}
</script>
