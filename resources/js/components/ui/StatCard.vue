<script setup>
import { computed } from 'vue'

/**
 * Tarjeta de cifra para las cabeceras de Finanzas.
 *
 * El orbe difuminado que asoma en la esquina y crece al pasar el cursor ya
 * existía en el panel de Finanzas (`BillingDashboard`); aquí se convierte en el
 * distintivo de toda la sección, para que los totales —que son la razón por la
 * que alguien entra a estas pantallas— se lean como una familia.
 *
 * Los importes van en `tabular-nums`: con cifras proporcionales, una columna de
 * montos queda desalineada y comparar de un vistazo cuesta más.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    hint:  { type: String, default: '' },
    icon:  { type: String, default: '' },
    /** Color del orbe y del chip del ícono. */
    tone:  { type: String, default: 'slate' },
    /** Color de la cifra. Por defecto neutro: sólo se tiñe si significa algo. */
    valueTone: { type: String, default: 'neutral' },
})

const TONES = {
    emerald: { orb: 'bg-emerald-50 dark:bg-emerald-900/20', chip: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400' },
    rose:    { orb: 'bg-rose-50 dark:bg-rose-900/20',       chip: 'bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-400' },
    amber:   { orb: 'bg-amber-50 dark:bg-amber-900/20',     chip: 'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400' },
    indigo:  { orb: 'bg-indigo-50 dark:bg-indigo-900/20',   chip: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-400' },
    slate:   { orb: 'bg-slate-100 dark:bg-gray-700/30',     chip: 'bg-slate-100 text-slate-600 dark:bg-gray-700 dark:text-slate-300' },
}

const VALUE_TONES = {
    neutral: 'text-slate-900 dark:text-white',
    emerald: 'text-emerald-600 dark:text-emerald-400',
    rose:    'text-rose-600 dark:text-rose-400',
}

const tone      = computed(() => TONES[props.tone] ?? TONES.slate)
const valueClass = computed(() => VALUE_TONES[props.valueTone] ?? VALUE_TONES.neutral)
</script>

<template>
    <div class="relative overflow-hidden bg-white dark:bg-gray-800 rounded-3xl p-6 shadow-sm border border-slate-100 dark:border-gray-700 group">
        <div :class="tone.orb"
            class="absolute -right-4 -top-4 w-24 h-24 rounded-full transition-transform duration-500 group-hover:scale-110 motion-reduce:transition-none motion-reduce:group-hover:scale-100"></div>

        <div class="relative">
            <div class="flex items-center gap-3 mb-4">
                <div v-if="icon" :class="tone.chip" class="p-2.5 rounded-2xl shrink-0">
                    <v-icon :name="icon" class="w-5 h-5" />
                </div>
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-[0.14em]">
                    {{ label }}
                </span>
            </div>

            <p :class="valueClass" class="text-3xl font-semibold tabular-nums tracking-tight">{{ value }}</p>

            <p v-if="hint" class="text-xs text-slate-400 dark:text-slate-500 mt-1.5">{{ hint }}</p>
        </div>
    </div>
</template>
