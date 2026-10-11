<template>
    <Teleport to="body">
        <div
            v-if="abierto"
            class="fixed inset-0 z-app-modal flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
            @click="cerrar"
        >
            <div
                class="flex max-h-[90vh] w-full max-w-xl flex-col overflow-hidden rounded-xl border border-gray-100 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800"
                @click.stop
            >
                <div class="border-b border-gray-100 p-6 dark:border-gray-700">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">Asuntos frecuentes</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        La lista que se ofrece al radicar y al cerrar un ticket, y con la que se filtra el listado.
                        Un asunto que ya usaron tickets no se borra: se desactiva y deja de ofrecerse.
                    </p>
                </div>

                <!-- Agregar -->
                <form class="flex gap-2 border-b border-gray-100 p-4 dark:border-gray-700" @submit.prevent="agregar">
                    <input
                        v-model="nuevo"
                        type="text"
                        maxlength="120"
                        placeholder="Ej: Sin internet, Internet lento, Traslado…"
                        class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    />
                    <button
                        type="submit"
                        :disabled="!nuevo.trim() || guardando"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Agregar
                    </button>
                </form>
                <p v-if="errorNuevo" class="px-4 pt-2 text-xs text-red-500">{{ errorNuevo }}</p>

                <!-- Lista -->
                <div class="flex-1 overflow-y-auto p-4">
                    <div v-if="cargando" class="flex items-center gap-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                        <div class="h-5 w-5 animate-spin rounded-full border-2 border-blue-500 border-t-transparent"></div>
                        Cargando asuntos…
                    </div>

                    <p v-else-if="!lista.length" class="py-4 text-sm text-gray-500 dark:text-gray-400">
                        Todavía no hay asuntos. Agrega los más comunes: con ellos el listado de tickets se puede filtrar por asunto.
                    </p>

                    <ul v-else class="space-y-2">
                        <li
                            v-for="(s, i) in lista"
                            :key="s.id"
                            class="flex items-center gap-2 rounded-lg border p-2"
                            :class="s.is_active
                                ? 'border-gray-200 dark:border-gray-700'
                                : 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40'"
                        >
                            <div class="flex flex-col">
                                <button type="button" :disabled="i === 0 || guardando" @click="mover(i, -1)"
                                    class="px-1 text-xs text-gray-500 hover:text-gray-800 disabled:opacity-30 dark:text-gray-400 dark:hover:text-white"
                                    title="Subir">▲</button>
                                <button type="button" :disabled="i === lista.length - 1 || guardando" @click="mover(i, 1)"
                                    class="px-1 text-xs text-gray-500 hover:text-gray-800 disabled:opacity-30 dark:text-gray-400 dark:hover:text-white"
                                    title="Bajar">▼</button>
                            </div>

                            <input
                                v-model="s.name"
                                @change="renombrar(s)"
                                maxlength="120"
                                class="min-w-0 flex-1 rounded border border-transparent bg-transparent px-2 py-1 text-sm text-gray-800 hover:border-gray-300 focus:border-blue-500 focus:outline-none dark:text-gray-100 dark:hover:border-gray-600"
                                :class="{ 'line-through opacity-60': !s.is_active }"
                            />

                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" :title="`${s.usage} ticket(s) con este asunto`">
                                {{ s.usage }}
                            </span>

                            <button type="button" :disabled="guardando" @click="alternar(s)"
                                class="shrink-0 rounded px-2 py-1 text-xs font-medium transition"
                                :class="s.is_active
                                    ? 'text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20'
                                    : 'text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20'">
                                {{ s.is_active ? 'Desactivar' : 'Activar' }}
                            </button>

                            <button type="button" :disabled="s.usage > 0 || guardando" @click="borrar(s)"
                                :title="s.usage > 0 ? 'Lo usan tickets: desactívalo en vez de borrarlo' : 'Eliminar'"
                                class="shrink-0 rounded px-2 py-1 text-xs text-rose-600 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-30 dark:text-rose-400 dark:hover:bg-rose-900/20">
                                ✕
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="flex justify-end border-t border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                    <button @click="cerrar"
                        class="rounded-lg px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-700">
                        Listo
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue'
import api from '../services/api'

/**
 * Administración del catálogo de asuntos del ticket (`ticket_manage_catalogs`).
 *
 * Cada cambio se guarda al momento —no hay «guardar todo»—: son pocas filas y
 * un formulario a medio guardar con varias filas tocadas es más difícil de
 * razonar que un cambio por acción. Al cerrar avisa con `changed` para que la
 * pantalla refresque el catálogo de los desplegables.
 */
const props = defineProps({
    abierto: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'changed', 'error'])

const lista = ref([])
const cargando = ref(false)
const guardando = ref(false)
const nuevo = ref('')
const errorNuevo = ref('')
let huboCambios = false

const mensajeDe = (err, porDefecto) => {
    const errores = err.response?.data?.errors
    return (errores ? Object.values(errores)[0]?.[0] : err.response?.data?.message) || porDefecto
}

const cargar = async () => {
    try {
        cargando.value = true
        const { data } = await api.support.getSubjects()
        lista.value = data
    } catch (err) {
        emit('error', mensajeDe(err, 'No se pudieron cargar los asuntos.'))
    } finally {
        cargando.value = false
    }
}

watch(() => props.abierto, (abierto) => {
    if (abierto) {
        huboCambios = false
        nuevo.value = ''
        errorNuevo.value = ''
        cargar()
    }
})

const agregar = async () => {
    const nombre = nuevo.value.trim()
    if (!nombre) return

    try {
        guardando.value = true
        errorNuevo.value = ''
        const { data } = await api.support.createSubject({ name: nombre })
        lista.value.push(data)
        nuevo.value = ''
        huboCambios = true
    } catch (err) {
        errorNuevo.value = mensajeDe(err, 'No se pudo agregar el asunto.')
    } finally {
        guardando.value = false
    }
}

const guardar = async (s, cambios, porDefecto) => {
    try {
        guardando.value = true
        const { data } = await api.support.updateSubject(s.id, cambios)
        Object.assign(s, data)
        huboCambios = true
    } catch (err) {
        emit('error', mensajeDe(err, porDefecto))
        await cargar()
    } finally {
        guardando.value = false
    }
}

const renombrar = (s) => guardar(s, { name: s.name }, 'No se pudo renombrar el asunto.')
const alternar = (s) => guardar(s, { is_active: !s.is_active }, 'No se pudo cambiar el asunto.')

/** Intercambia el orden con el vecino. Se guardan los dos: la lista se lee por `sort_order`. */
const mover = async (i, paso) => {
    const a = lista.value[i]
    const b = lista.value[i + paso]
    if (!a || !b) return

    // Si dos filas comparten orden (las creadas sin orden explícito), se
    // renumera la lista entera antes de intercambiar.
    if (new Set(lista.value.map((s) => s.sort_order)).size !== lista.value.length) {
        lista.value.forEach((s, idx) => { s.sort_order = (idx + 1) * 10 })
        for (const s of lista.value) {
            await guardar(s, { sort_order: s.sort_order }, 'No se pudo reordenar.')
        }
    }

    const ordenA = a.sort_order
    a.sort_order = b.sort_order
    b.sort_order = ordenA
    lista.value.splice(i, 1)
    lista.value.splice(i + paso, 0, a)

    await guardar(a, { sort_order: a.sort_order }, 'No se pudo reordenar.')
    await guardar(b, { sort_order: b.sort_order }, 'No se pudo reordenar.')
}

const borrar = async (s) => {
    if (s.usage > 0) return

    try {
        guardando.value = true
        await api.support.deleteSubject(s.id)
        lista.value = lista.value.filter((x) => x.id !== s.id)
        huboCambios = true
    } catch (err) {
        emit('error', mensajeDe(err, 'No se pudo eliminar el asunto.'))
        await cargar()
    } finally {
        guardando.value = false
    }
}

const cerrar = () => {
    if (huboCambios) emit('changed')
    emit('close')
}
</script>
