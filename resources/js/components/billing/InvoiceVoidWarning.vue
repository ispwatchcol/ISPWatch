<script setup>
import { computed } from 'vue'

/**
 * Cuerpo del modal de "Anular factura".
 *
 * Vive aparte por lo mismo que `InvoiceDeleteWarning`: la anulación se dispara
 * desde el listado y desde el detalle, y el aviso tiene que decir exactamente
 * lo mismo en los dos sitios.
 *
 * Lo que este aviso tiene que dejar claro, porque es lo que diferencia anular
 * de borrar: la factura NO desaparece. Se queda con su número, su importe y su
 * histórico; lo que cambia es que deja de cobrarse.
 */
const props = defineProps({
    invoice: { type: Object, default: null },
    modelValue: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const motivo = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
})

// Lo ya aplicado a la factura, venga de un pago o de saldo a favor: es justo lo
// que la anulación va a soltar. Mismo cálculo que el aviso de borrado, porque
// el dinero se mueve igual — lo que no se mueve es la factura.
const aplicado = computed(() => {
    if (!props.invoice) return 0
    const total = Number(props.invoice.total ?? 0)
    const saldo = Number(props.invoice.balance_due ?? 0)
    return Math.max(0, total - saldo)
})

const fmt = (n) => Number(n || 0).toLocaleString('es-CO')
</script>

<template>
    <div v-if="invoice" class="space-y-3">
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
            <p class="text-sm text-amber-800 dark:text-amber-300">
                Vas a anular la factura <span class="font-semibold">#{{ invoice.number }}</span>.
                Deja de cobrarse y sale de los totales, pero <strong>no se borra</strong>: se conservan
                el número, el importe, el titular, las fechas y el histórico.
            </p>
        </div>

        <!-- El dato que decide si esto es inocuo o mueve plata. -->
        <div v-if="aplicado > 0"
            class="rounded-lg border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <p class="flex items-center gap-1.5 text-sm font-semibold text-red-700 dark:text-red-300">
                <v-icon name="md-warning" class="h-4 w-4 shrink-0" />
                Esta factura tiene ${{ fmt(aplicado) }} ya aplicados
            </p>
            <p class="mt-1 text-xs text-red-700/90 dark:text-red-300/90">
                Al anularla, ese dinero vuelve como <strong>saldo a favor</strong> del cliente.
                <strong>El pago no se borra</strong> —el recaudo ocurrió— pero queda suelto hasta que
                alguien lo aplique a otra factura.
            </p>
        </div>

        <div v-if="invoice.ticket_id"
            class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs text-slate-600 dark:text-slate-300">
                Es el cargo del <strong>ticket #{{ invoice.ticket_id }}</strong>. El vínculo se conserva,
                y una vez anulada ese ticket ya podrá archivarse.
            </p>
        </div>

        <div>
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                Motivo de la anulación <span class="text-red-500">*</span>
            </label>
            <textarea
                v-model="motivo"
                rows="3"
                maxlength="500"
                placeholder="Explica por qué esta factura deja de cobrarse…"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
            ></textarea>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ motivo.length }}/500 · mínimo 10 caracteres. Queda en la bitácora con tu nombre.
            </p>
        </div>
    </div>
</template>
