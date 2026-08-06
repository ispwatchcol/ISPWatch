<script setup>
import { computed } from 'vue'

/**
 * Encabezado de página: chip de ícono + título + subtítulo, con las acciones a
 * la derecha.
 *
 * Existe para que la consistencia entre módulos sea estructural y no copiada:
 * antes cada vista de Finanzas tenía su propio encabezado (tres tamaños de
 * título y tres tratamientos de ícono distintos dentro del mismo submenú).
 *
 * `accent` es el color de la sección, no del módulo: todo Finanzas va en
 * esmeralda.
 */
const props = defineProps({
    title:    { type: String, required: true },
    subtitle: { type: String, default: '' },
    icon:     { type: String, default: '' },
    accent:   { type: String, default: 'emerald' },
})

const ACCENTS = {
    emerald: 'from-emerald-500 to-teal-600 shadow-emerald-500/30',
    indigo:  'from-indigo-500 to-violet-600 shadow-indigo-500/30',
    blue:    'from-blue-500 to-cyan-600 shadow-blue-500/30',
    rose:    'from-rose-500 to-pink-600 shadow-rose-500/30',
}

const chipClasses = computed(() => ACCENTS[props.accent] ?? ACCENTS.emerald)
</script>

<template>
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div class="flex items-center gap-4 min-w-0">
            <div v-if="icon" :class="chipClasses"
                class="w-12 h-12 shrink-0 rounded-2xl bg-gradient-to-br flex items-center justify-center shadow-lg">
                <v-icon :name="icon" class="w-6 h-6 text-white" />
            </div>
            <div class="min-w-0">
                <h1 class="text-2xl md:text-3xl font-semibold text-slate-900 dark:text-white tracking-tight">
                    {{ title }}
                </h1>
                <p v-if="subtitle" class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    {{ subtitle }}
                </p>
            </div>
        </div>

        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-3">
            <slot name="actions" />
        </div>
    </div>
</template>
