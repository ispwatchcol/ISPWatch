<script setup>
import { computed } from 'vue'

/**
 * Cuerpo del modal de "Eliminar factura".
 *
 * Vive aparte porque el borrado se dispara desde dos pantallas (el listado y el
 * detalle) y el aviso tiene que decir exactamente lo mismo en las dos: si cada
 * una escribe su propio texto, una acaba avisando de algo que la otra calla.
 *
 * Por qué hace falta este aviso: borrar una factura pagada desasigna el dinero
 * y lo devuelve como saldo a favor. El modal anterior decía "si tiene pagos, el
 * monto se devolverá como saldo a favor" — en condicional y sin cifra, de modo
 * que quien borraba no sabía si estaba moviendo $0 o $52.500. Ocurrió: se borró
 * una mensualidad YA PAGADA creyendo que era la del mes en curso.
 */
const props = defineProps({
    invoice: { type: Object, default: null },
})

// Lo aplicado a la factura, venga de un pago o de saldo a favor: es justo lo que
// el borrado va a soltar.
const applied = computed(() => {
    if (!props.invoice) return 0
    const total   = Number(props.invoice.total ?? 0)
    const balance = Number(props.invoice.balance_due ?? 0)
    return Math.max(0, total - balance)
})

// Sólo las mensuales dejan lápida: instalación, cargos y tickets no los genera
// nadie automáticamente, así que no hay nada que bloquear.
const isMonthly = computed(() => {
    const type = props.invoice?.invoice_type
    return !type || type === 'monthly'
})

const periodLabel = computed(() => {
    const raw = props.invoice?.period_start
    if (!raw) return ''
    const d = new Date(String(raw).split('T')[0] + 'T00:00:00')
    if (isNaN(d.getTime())) return ''
    return d.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' })
})

const fmt = (n) => Number(n || 0).toLocaleString('es-CO')
</script>

<template>
    <div v-if="invoice" class="space-y-3">
        <div class="rounded-lg p-4 border bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800">
            <p class="text-sm text-red-600 dark:text-red-400">
                Vas a eliminar la factura <span class="font-semibold">#{{ invoice.number }}</span>
                de forma permanente. Esta acción no se puede deshacer.
            </p>
        </div>

        <!-- El dato que faltaba: cuánto dinero queda sin factura detrás. -->
        <div v-if="applied > 0"
            class="rounded-lg p-4 border bg-amber-50 dark:bg-amber-900/20 border-amber-300 dark:border-amber-800">
            <p class="text-sm font-semibold text-amber-700 dark:text-amber-300 flex items-center gap-1.5">
                <v-icon name="md-warning" class="w-4 h-4 shrink-0" />
                Esta factura tiene ${{ fmt(applied) }} ya aplicados
            </p>
            <p class="mt-1 text-xs text-amber-700/90 dark:text-amber-300/90">
                Al eliminarla, ese dinero vuelve como <strong>saldo a favor</strong> del cliente y deja de
                respaldar ninguna factura. El recaudo no se borra, pero queda suelto hasta que alguien lo
                aplique a otra factura.
            </p>
        </div>

        <div v-if="isMonthly"
            class="rounded-lg p-4 border bg-slate-50 dark:bg-gray-900 border-slate-200 dark:border-gray-700">
            <p class="text-xs text-slate-600 dark:text-slate-300">
                <strong>{{ periodLabel ? `El periodo ${periodLabel} queda bloqueado` : 'Ese periodo queda bloqueado' }}:</strong>
                la facturación automática no volverá a generar esta factura. Los meses siguientes siguen igual.
            </p>
        </div>

        <div class="rounded-lg p-3 border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20">
            <p class="text-xs text-emerald-800 dark:text-emerald-300">
                <strong>¿Buscabas otra cosa?</strong>
                Para un <strong>descuento</strong>, agrega un ítem con monto negativo en el detalle de la factura.
                Para dejarla sin efecto conservando el número, edítala y ponla en <strong>Cancelada</strong>.
            </p>
        </div>
    </div>
</template>
