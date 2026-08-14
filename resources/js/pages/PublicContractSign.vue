<template>
  <div class="min-h-screen bg-slate-100 py-6 px-4 sm:py-10">
    <div class="mx-auto w-full max-w-2xl">

      <!-- Cargando -->
      <div v-if="loading" class="bg-white rounded-2xl shadow-sm p-10 text-center">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-indigo-500 border-t-transparent"></div>
        <p class="mt-4 text-slate-500 text-sm">Cargando tu contrato…</p>
      </div>

      <!-- Enlace inservible (vencido, anulado, bloqueado) o no encontrado -->
      <div v-else-if="blocked" class="bg-white rounded-2xl shadow-sm p-8 text-center">
        <div class="mx-auto w-14 h-14 rounded-full bg-amber-100 flex items-center justify-center mb-4">
          <svg class="w-7 h-7 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        </div>
        <h1 class="text-lg font-bold text-slate-800 mb-2">{{ blocked.title }}</h1>
        <p class="text-sm text-slate-500 leading-relaxed">{{ blocked.message }}</p>
      </div>

      <!-- Firmado (recién ahora o de antes) -->
      <div v-else-if="state.status === 'signed'" class="bg-white rounded-2xl shadow-sm p-8 text-center">
        <div class="mx-auto w-14 h-14 rounded-full bg-emerald-100 flex items-center justify-center mb-4">
          <svg class="w-7 h-7 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h1 class="text-xl font-bold text-slate-800 mb-2">¡Contrato firmado!</h1>
        <p class="text-sm text-slate-500 leading-relaxed mb-1">
          Tu contrato con <strong>{{ state.company_name }}</strong> quedó firmado y guardado.
        </p>
        <p v-if="state.contract_number" class="text-sm text-slate-500 mb-6">
          Número de contrato: <strong class="font-mono text-slate-700">{{ state.contract_number }}</strong>
        </p>
        <a v-if="state.document_url" :href="state.document_url" target="_blank" rel="noopener"
          class="inline-block px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition">
          Descargar mi copia en PDF
        </a>
        <p v-if="state.document_url" class="mt-4 text-xs text-slate-400">
          Guarda el archivo: este enlace de descarga caduca en unos minutos.
        </p>
      </div>

      <!-- Paso 1: verificación de identidad -->
      <div v-else-if="!contract" class="bg-white rounded-2xl shadow-sm overflow-hidden">
        <div class="bg-indigo-600 px-6 py-7 text-center">
          <p class="text-indigo-200 text-xs uppercase tracking-wide font-semibold">{{ state.company_name }}</p>
          <h1 class="text-white text-xl font-bold mt-1">Firma de contrato</h1>
        </div>
        <div class="p-6 sm:p-8">
          <p class="text-slate-600 text-sm leading-relaxed mb-6">
            Hola <strong>{{ state.customer_first_name }}</strong>. Para mostrarte tu contrato necesitamos confirmar que
            eres tú.
          </p>

          <template v-if="state.requires_verification">
            <label class="block text-xs font-semibold text-slate-500 uppercase mb-2">
              Últimos 4 dígitos de tu cédula
            </label>
            <input
              v-model="documentLast4"
              inputmode="numeric"
              maxlength="4"
              placeholder="1234"
              autocomplete="off"
              @keyup.enter="verify"
              class="w-full text-center tracking-[0.5em] text-2xl font-mono border-2 border-slate-200 rounded-xl px-4 py-3.5 text-slate-800 focus:outline-none focus:border-indigo-500"
            />
            <p v-if="error" class="mt-3 text-sm text-rose-600">{{ error }}</p>
          </template>
          <p v-else class="text-sm text-slate-500 mb-4">
            Toca continuar para leer tu contrato.
          </p>

          <button
            @click="verify"
            :disabled="verifying || (state.requires_verification && documentLast4.length < 4)"
            class="mt-5 w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-base font-semibold rounded-xl transition"
          >
            {{ verifying ? 'Verificando…' : 'Continuar' }}
          </button>
        </div>
      </div>

      <!-- Paso 2: leer y firmar -->
      <div v-else class="space-y-4">
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
          <div class="bg-indigo-600 px-6 py-5">
            <p class="text-indigo-200 text-xs uppercase tracking-wide font-semibold">{{ state.company_name }}</p>
            <h1 class="text-white text-lg font-bold mt-0.5">Tu contrato de servicio</h1>
          </div>

          <div class="p-4 sm:p-6">
            <p class="text-sm text-slate-500 mb-3">Lee el documento completo antes de firmar.</p>

            <!-- El contrato va en un iframe con srcdoc y no con v-html: el
                 documento trae su propia hoja de estilos con selectores
                 globales (`* { … }`) que, inyectada en la página, repintaría
                 también los botones y el recuadro de firma. El iframe lo aísla
                 por completo y además le da su propio scroll. -->
            <iframe
              v-if="contract.contract_html"
              :srcdoc="contract.contract_html"
              class="w-full h-[55vh] min-h-[320px] border border-slate-200 rounded-xl bg-white"
              title="Contrato de servicio"
            ></iframe>

            <div v-else class="border border-slate-200 rounded-xl p-4 text-sm text-slate-600 space-y-1">
              <p class="text-amber-600 mb-2">No se pudo mostrar el documento completo. Estos son los datos principales:</p>
              <p><span class="text-slate-400">Cliente:</span> <strong>{{ contract.customer.name }} {{ contract.customer.last_name }}</strong></p>
              <p><span class="text-slate-400">Cédula:</span> <strong>{{ contract.customer.cedula || '—' }}</strong></p>
              <p><span class="text-slate-400">Plan:</span> <strong>{{ contract.plan?.name || 'Sin plan' }}</strong></p>
              <p><span class="text-slate-400">Valor mensual:</span> <strong>${{ Number(contract.plan?.cost_product || 0).toLocaleString('es-CO') }}</strong></p>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm p-4 sm:p-6">
          <h2 class="text-base font-bold text-slate-800 mb-1">Tu firma</h2>
          <p class="text-sm text-slate-500 mb-4">Traza tu firma con el dedo dentro del recuadro.</p>

          <SignaturePad
            ref="pad"
            :height="180"
            hint="Puedes borrar y volver a intentarlo las veces que quieras."
            @change="hasSignature = $event"
          />

          <label class="flex items-start gap-3 mt-5 cursor-pointer">
            <input type="checkbox" v-model="accepted"
              class="mt-0.5 w-5 h-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 shrink-0" />
            <span class="text-sm text-slate-600 leading-relaxed">
              He leído el contrato y acepto sus condiciones. Reconozco que esta firma electrónica tiene la misma
              validez que mi firma manuscrita.
            </span>
          </label>

          <p v-if="error" class="mt-4 text-sm text-rose-600">{{ error }}</p>

          <div class="flex flex-col sm:flex-row gap-3 mt-5">
            <button @click="clearSignature" type="button"
              class="sm:w-auto px-5 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl transition">
              Borrar firma
            </button>
            <button @click="submit" type="button" :disabled="signing || !hasSignature || !accepted"
              class="flex-1 py-3.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-base font-semibold rounded-xl transition">
              {{ signing ? 'Firmando…' : 'Firmar contrato' }}
            </button>
          </div>

          <p class="mt-4 text-xs text-slate-400 leading-relaxed">
            Al firmar se registran la fecha, la hora y la dirección IP desde la que firmaste, como constancia del acto.
          </p>
        </div>
      </div>

      <p class="mt-6 text-center text-xs text-slate-400">Firma segura — ISPWatch</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import SignaturePad from '@/components/SignaturePad.vue'
import publicContractApi from '@/services/api/public-contract'

const route = useRoute()
const token = route.params.token

const loading = ref(true)
const state = ref({})
const contract = ref(null)
const documentLast4 = ref('')
const verifying = ref(false)
const signing = ref(false)
const error = ref('')
const accepted = ref(false)
const hasSignature = ref(false)
const pad = ref(null)

/**
 * Estados en los que no hay nada que firmar. `signed` NO entra aquí: ese tiene
 * su propia pantalla porque no es un error, es el cliente volviendo a buscar su
 * copia del contrato.
 */
const BLOCKED_COPY = {
  expired: {
    title: 'El enlace venció',
    message: 'Por seguridad los enlaces de firma caducan. Comunícate con tu proveedor para que te envíe uno nuevo.',
  },
  revoked: {
    title: 'El enlace fue anulado',
    message: 'Tu proveedor anuló este enlace. Pídele uno nuevo para firmar tu contrato.',
  },
  locked: {
    title: 'Demasiados intentos',
    message: 'Se superó el número de intentos de verificación. Pídele a tu proveedor un enlace nuevo.',
  },
  invalid: {
    title: 'Enlace no válido',
    message: 'Revisa que hayas copiado la dirección completa, o pídele a tu proveedor que te la envíe de nuevo.',
  },
}

const blocked = computed(() => BLOCKED_COPY[state.value.status] || null)

const load = async () => {
  loading.value = true
  try {
    const res = await publicContractApi.show(token)
    state.value = res.data
  } catch (e) {
    state.value = { status: 'invalid' }
  } finally {
    loading.value = false
  }
}

const verify = async () => {
  error.value = ''
  verifying.value = true
  try {
    const res = await publicContractApi.verify(token, { document_last4: documentLast4.value })
    contract.value = res.data
  } catch (e) {
    const data = e.response?.data
    // 409 = el link dejó de servir mientras el cliente lo tenía abierto (lo
    // anularon, venció, o el ISP acabó firmando presencialmente).
    if (e.response?.status === 409 && data?.status) {
      state.value = { ...state.value, ...data }
    } else {
      error.value = data?.message || 'No se pudo verificar. Inténtalo de nuevo.'
      if (data?.status === 'locked') state.value = { ...state.value, status: 'locked' }
    }
  } finally {
    verifying.value = false
  }
}

const clearSignature = () => {
  pad.value?.clear()
  hasSignature.value = false
}

const submit = async () => {
  error.value = ''

  if (!pad.value?.hasInk()) {
    hasSignature.value = false
    error.value = 'Traza tu firma dentro del recuadro antes de continuar.'
    return
  }

  signing.value = true
  try {
    const res = await publicContractApi.sign(token, {
      signature: pad.value.toDataURL(),
      document_last4: documentLast4.value,
      accepted: true,
    })
    state.value = {
      ...state.value,
      status: 'signed',
      contract_number: res.data.contract_number,
      document_url: res.data.document_url,
    }
    contract.value = null
  } catch (e) {
    const data = e.response?.data
    if (e.response?.status === 409 && data?.status) {
      state.value = { ...state.value, ...data }
      contract.value = null
    } else {
      error.value = data?.message || 'No se pudo firmar el contrato. Inténtalo de nuevo.'
    }
  } finally {
    signing.value = false
  }
}

onMounted(load)
</script>
