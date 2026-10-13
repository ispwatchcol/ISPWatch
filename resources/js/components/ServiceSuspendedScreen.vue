<template>
  <!--
    Pantalla de «servicio suspendido» (KAN-126).

    Reemplaza el panel ENTERO (DefaultLayout no monta ni el sidebar ni la página)
    cuando Converza suspendió al ISP por una factura vencida. Lo único que deja
    hacer es lo que tiene sentido en ese momento: ver cómo pagar, comprobar si ya
    se levantó el corte y cerrar sesión.

    Los textos de Converza se pintan con {{ }} (escapados) y NUNCA con v-html:
    vienen de otro sistema.
  -->
  <div class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-4 py-10">
    <div class="w-full max-w-lg">
      <div class="flex items-center justify-center gap-2 mb-6">
        <img :src="'/brand/icon.svg'" alt="" class="w-9 h-9" />
        <span class="font-extrabold text-lg text-gray-900 dark:text-white">ISP<span class="text-blue-600">Watch</span></span>
      </div>

      <div
        class="bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-800 p-6 sm:p-8"
        role="alert"
      >
        <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center mb-4">
          <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="11" width="18" height="11" rx="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
          </svg>
        </div>

        <h1 class="text-xl font-bold text-slate-900 dark:text-white">Servicio suspendido</h1>

        <p class="mt-2 text-sm text-slate-700 dark:text-slate-300 whitespace-pre-line">{{ suspension.message }}</p>

        <p v-if="sinceLabel" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Desde el {{ sinceLabel }}.</p>

        <div
          v-if="suspension.paymentInstructions"
          class="mt-5 rounded-xl border border-blue-200 dark:border-blue-900/50 bg-blue-50 dark:bg-blue-900/10 p-4"
        >
          <h2 class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Cómo pagar</h2>
          <p class="mt-1 text-sm text-slate-800 dark:text-slate-200 whitespace-pre-line break-words">{{ suspension.paymentInstructions }}</p>
        </div>

        <p class="mt-5 text-sm text-slate-600 dark:text-slate-400">
          El internet de tus clientes <strong class="font-semibold text-slate-800 dark:text-slate-200">no se interrumpe</strong>:
          lo que está suspendido es el acceso a este panel.
        </p>

        <p
          v-if="feedback"
          class="mt-4 text-sm rounded-lg px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300"
          aria-live="polite"
        >{{ feedback }}</p>

        <div class="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
          <button
            type="button"
            class="px-4 py-2.5 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
            @click="logout"
          >
            Cerrar sesión
          </button>
          <button
            type="button"
            class="px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60 disabled:cursor-wait transition-colors"
            :disabled="checking"
            @click="checkAgain"
          >
            {{ checking ? 'Comprobando…' : 'Ya pagué, comprobar de nuevo' }}
          </button>
        </div>
      </div>

      <p v-if="authStore.isAuthenticated" class="mt-4 text-center text-xs text-slate-500 dark:text-slate-400">
        Sesión de {{ authStore.userName }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { apiClient } from '@/services/api.js'
import { reportServiceStatus, useServiceSuspension } from '@/composables/useServiceSuspension.js'

const authStore = useAuthStore()
const { suspension } = useServiceSuspension()

const checking = ref(false)
const feedback = ref('')

const sinceLabel = computed(() => {
  if (!suspension.value?.since) return ''
  const date = new Date(suspension.value.since)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat('es-CO', { dateStyle: 'long' }).format(date)
})

/**
 * El backend recuerda la suspensión un minuto (ConverzaServiceStatus): pulsar
 * varias veces no llama a Converza cada vez, y un pago se ve como mucho un
 * minuto después de registrado.
 */
async function checkAgain() {
  checking.value = true
  feedback.value = ''

  try {
    const { data } = await apiClient.get('/saas/status')

    if (data?.data?.suspended !== true) {
      // Recarga completa: las páginas que quedaron a medias con un 423 vuelven
      // a pedir sus datos desde cero.
      window.location.reload()
      return
    }

    reportServiceStatus(data.data)
    feedback.value = 'Todavía figura suspendido. Si acabas de pagar, el pago puede tardar unos minutos en reflejarse.'
  } catch {
    feedback.value = 'No se pudo comprobar en este momento. Intenta de nuevo en un minuto.'
  } finally {
    checking.value = false
  }
}

function logout() {
  authStore.logout()
  window.location.replace('/')
}
</script>
