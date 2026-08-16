<template>
  <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
    <div class="p-4 md:p-6 border-b border-gray-200 dark:border-gray-700">
      <div class="flex items-center gap-3">
        <div class="p-2 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg">
          <v-icon name="md-vpnkey" class="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
        </div>
        <div>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Llaves de API</h3>
          <p class="text-sm text-gray-600 dark:text-gray-400">
            Acceso de solo lectura para tus integraciones. Cada llave ve
            únicamente los datos de tu empresa, nunca los de otra.
          </p>
        </div>
      </div>
    </div>

    <div class="p-4 md:p-6 space-y-6">
      <!-- ══ LLAVE RECIÉN EMITIDA ══ -->
      <!-- Sólo se muestra una vez: el servidor guarda un hash, no el texto. -->
      <div
        v-if="freshKey"
        class="border-2 border-amber-400 dark:border-amber-500 bg-amber-50 dark:bg-amber-900/20 rounded-xl p-4"
      >
        <div class="flex items-start gap-3">
          <v-icon name="md-warning" class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
          <div class="min-w-0 flex-1">
            <h4 class="text-sm font-semibold text-amber-900 dark:text-amber-200">
              Copia esta llave ahora: no se vuelve a mostrar
            </h4>
            <p class="text-xs text-amber-800 dark:text-amber-300 mt-1">
              En la base de datos sólo queda un hash. Si se pierde, hay que
              revocarla y emitir otra.
            </p>
            <div class="mt-3 flex items-center gap-2">
              <code
                class="flex-1 min-w-0 text-xs bg-white dark:bg-gray-900 border border-amber-300 dark:border-amber-700 rounded-lg px-3 py-2 font-mono break-all"
              >{{ freshKey.plain_text_token }}</code>
              <button
                type="button"
                class="shrink-0 text-sm bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded-lg transition-all"
                @click="copyFreshKey"
              >
                {{ copied ? 'Copiado' : 'Copiar' }}
              </button>
            </div>
            <button
              type="button"
              class="mt-3 text-xs text-amber-800 dark:text-amber-300 underline"
              @click="freshKey = null"
            >
              Ya la guardé, ocultar
            </button>
          </div>
        </div>
      </div>

      <!-- ══ LÍMITES ══ -->
      <!-- Van arriba y no en un pie de página: son la respuesta a "por qué me
           rechazó el formulario", y llegar a leerlos después de que pase ya es
           tarde. -->
      <div
        v-if="limits"
        class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center"
      >
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
          <p class="text-xs text-gray-500 dark:text-gray-400">Llaves vigentes</p>
          <p class="text-lg font-semibold" :class="atKeyLimit ? 'text-amber-600' : 'text-gray-900 dark:text-white'">
            {{ limits.active_keys }} / {{ limits.max_active_keys }}
          </p>
        </div>
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
          <p class="text-xs text-gray-500 dark:text-gray-400">Integraciones</p>
          <p class="text-lg font-semibold" :class="atClientLimit ? 'text-amber-600' : 'text-gray-900 dark:text-white'">
            {{ limits.clients }} / {{ limits.max_clients }}
          </p>
        </div>
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
          <p class="text-xs text-gray-500 dark:text-gray-400">Vigencia máxima</p>
          <p class="text-lg font-semibold text-gray-900 dark:text-white">{{ limits.max_expiration_days }} días</p>
        </div>
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
          <p class="text-xs text-gray-500 dark:text-gray-400">Rango IP más amplio</p>
          <p class="text-lg font-semibold text-gray-900 dark:text-white">/{{ limits.min_ipv4_prefix }}</p>
        </div>
      </div>

      <!-- ══ ALTA DE INTEGRACIÓN ══ -->
      <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-4 md:p-5">
        <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
          <v-icon name="md-personadd" class="w-4 h-4" /> Nueva integración
        </h4>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="label">Nombre</label>
            <input v-model="newClient.name" type="text" class="input" placeholder="Bot de WhatsApp" />
          </div>
          <div>
            <label class="label">Correo de contacto</label>
            <input v-model="newClient.contact_email" type="email" class="input" placeholder="it@miempresa.com" />
          </div>
          <div class="flex items-end">
            <button
              type="button"
              :disabled="!canCreateClient || saving || atClientLimit"
              class="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white px-4 py-2.5 rounded-lg transition-all text-sm font-medium"
              @click="createClient"
            >
              {{ atClientLimit ? 'Límite alcanzado' : 'Crear integración' }}
            </button>
          </div>
        </div>
      </div>

      <!-- ══ INTEGRACIONES EXISTENTES ══ -->
      <div v-if="loading" class="text-sm text-gray-500 dark:text-gray-400 py-8 text-center">
        Cargando…
      </div>

      <div
        v-else-if="!clients.length"
        class="text-sm text-gray-500 dark:text-gray-400 py-8 text-center border border-dashed border-gray-300 dark:border-gray-600 rounded-xl"
      >
        Todavía no tienes integraciones registradas.
      </div>

      <div
        v-for="client in clients"
        v-else
        :key="client.id"
        class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden"
      >
        <div class="flex flex-wrap items-center justify-between gap-3 p-4 bg-gray-50 dark:bg-gray-900/40">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <h4 class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                {{ client.name }}
              </h4>
              <span
                :class="[
                  'text-[11px] px-2 py-0.5 rounded-full font-medium',
                  client.is_active
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                    : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                ]"
              >
                {{ client.is_active ? 'Activa' : 'Desactivada' }}
              </span>
            </div>
            <p v-if="client.contact_email" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
              {{ client.contact_email }}
            </p>
          </div>

          <div class="flex items-center gap-2">
            <button
              type="button"
              class="text-xs px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 transition-all"
              @click="toggleLogs(client)"
            >
              {{ logsFor === client.id ? 'Ocultar peticiones' : 'Ver peticiones' }}
            </button>
            <button
              type="button"
              :disabled="atKeyLimit"
              class="text-xs px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white transition-all"
              @click="openKeyForm(client)"
            >
              Emitir llave
            </button>
          </div>
        </div>

        <!-- Formulario de emisión -->
        <div v-if="keyFormFor === client.id" class="p-4 border-t border-gray-200 dark:border-gray-700 space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="label">Nombre de la llave</label>
              <input v-model="newKey.name" type="text" class="input" placeholder="produccion-bot" />
            </div>
            <div>
              <label class="label">Vence el (obligatorio)</label>
              <input
                v-model="newKey.expires_at"
                type="date"
                class="input"
                :min="minExpiry"
                :max="maxExpiry"
              />
              <p class="text-xs text-gray-400 mt-1">
                Máximo {{ limits?.max_expiration_days }} días. Cuando venza,
                emites otra: una llave que nadie rota sigue viva cuando ya
                terminó el contrato con el proveedor.
              </p>
            </div>
          </div>

          <div>
            <label class="label">Permisos de lectura</label>
            <div class="space-y-2 mt-1">
              <label
                v-for="(label, key) in abilities"
                :key="key"
                class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"
              >
                <input v-model="newKey.abilities" type="checkbox" :value="key" class="mt-0.5" />
                <span><code class="text-xs text-indigo-600 dark:text-indigo-400">{{ key }}</code> — {{ label }}</span>
              </label>
            </div>
            <p class="text-xs text-gray-400 mt-2">
              Concede sólo lo que la integración necesite. El acceso a
              facturación no se emite desde aquí: pídeselo al operador.
            </p>
          </div>

          <div>
            <label class="label">IPs autorizadas (obligatorio)</label>
            <textarea
              v-model="newKey.allowed_ips_raw"
              rows="2"
              class="input font-mono text-xs"
              placeholder="190.24.7.10, 190.24.8.0/24"
            ></textarea>
            <p class="text-xs text-gray-400 mt-1">
              La IP pública del servidor donde corre la integración, una por
              línea o separadas por coma. Rangos hasta /{{ limits?.min_ipv4_prefix }}.
              Esta lista es lo que hace que una llave filtrada no sirva desde
              fuera: no la abras «para que funcione».
            </p>
          </div>

          <div v-if="keyError" class="text-xs text-red-600 dark:text-red-400">{{ keyError }}</div>

          <div class="flex items-center gap-2">
            <button
              type="button"
              :disabled="!canCreateKey || saving"
              class="text-sm bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white px-4 py-2 rounded-lg transition-all"
              @click="createKey(client)"
            >
              Emitir llave
            </button>
            <button
              type="button"
              class="text-sm px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 transition-all"
              @click="keyFormFor = null"
            >
              Cancelar
            </button>
          </div>
        </div>

        <!-- Llaves de la integración -->
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs text-gray-500 dark:text-gray-400">
              <tr>
                <th class="text-left px-4 py-2 font-medium">Llave</th>
                <th class="text-left px-4 py-2 font-medium">Permisos</th>
                <th class="text-left px-4 py-2 font-medium">IPs</th>
                <th class="text-left px-4 py-2 font-medium">Último uso</th>
                <th class="text-left px-4 py-2 font-medium">Vence</th>
                <th class="text-right px-4 py-2 font-medium">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
              <tr v-if="!client.keys.length">
                <td colspan="6" class="px-4 py-4 text-center text-xs text-gray-400">
                  Sin llaves emitidas.
                </td>
              </tr>
              <tr v-for="key in client.keys" :key="key.id">
                <td class="px-4 py-2">
                  <span :class="key.revoked_at ? 'line-through text-gray-400' : 'text-gray-900 dark:text-white'">
                    {{ key.name }}
                  </span>
                </td>
                <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                  {{ (key.abilities || []).join(', ') || '—' }}
                </td>
                <td class="px-4 py-2 text-xs font-mono text-gray-500 dark:text-gray-400">
                  {{ (key.allowed_ips || []).join(', ') || '—' }}
                </td>
                <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                  {{ formatDate(key.last_used_at) }}
                  <span v-if="key.last_used_ip" class="font-mono"> · {{ key.last_used_ip }}</span>
                </td>
                <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                  {{ formatDate(key.expires_at) }}
                </td>
                <td class="px-4 py-2 text-right">
                  <span v-if="key.revoked_at" class="text-xs text-gray-400">Revocada</span>
                  <button
                    v-else
                    type="button"
                    class="text-xs text-red-600 dark:text-red-400 hover:underline"
                    @click="revokeKey(client, key)"
                  >
                    Revocar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Bitácora de peticiones: casi todo problema de integración se ve aquí -->
        <div v-if="logsFor === client.id" class="border-t border-gray-200 dark:border-gray-700 p-4">
          <p v-if="!logs.length" class="text-xs text-gray-400 text-center py-2">
            Todavía no hay peticiones registradas.
          </p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-xs">
              <thead class="text-gray-500 dark:text-gray-400">
                <tr>
                  <th class="text-left px-2 py-1 font-medium">Fecha</th>
                  <th class="text-left px-2 py-1 font-medium">Ruta</th>
                  <th class="text-left px-2 py-1 font-medium">IP</th>
                  <th class="text-left px-2 py-1 font-medium">Estado</th>
                  <th class="text-left px-2 py-1 font-medium">Motivo</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100 dark:divide-gray-700 font-mono">
                <tr v-for="row in logs" :key="row.id">
                  <td class="px-2 py-1 text-gray-500 dark:text-gray-400">{{ formatDate(row.created_at) }}</td>
                  <td class="px-2 py-1 text-gray-700 dark:text-gray-300">{{ row.method }} /{{ row.path }}</td>
                  <td class="px-2 py-1 text-gray-500 dark:text-gray-400">{{ row.ip }}</td>
                  <td
                    class="px-2 py-1"
                    :class="row.status_code >= 400 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'"
                  >
                    {{ row.status_code }}
                  </td>
                  <td class="px-2 py-1 text-gray-500 dark:text-gray-400">{{ row.denied_reason || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { myApiKeysService } from '@/services/apiKeys'

const emit = defineEmits(['notify'])

const loading = ref(false)
const saving = ref(false)
const clients = ref([])
const abilities = ref({})
const limits = ref(null)

const freshKey = ref(null)
const copied = ref(false)
const keyFormFor = ref(null)
const keyError = ref('')
const logsFor = ref(null)
const logs = ref([])

const newClient = ref({ name: '', contact_email: '' })
const newKey = ref({ name: '', abilities: [], allowed_ips_raw: '', expires_at: '' })

const atKeyLimit = computed(
  () => !!limits.value && limits.value.active_keys >= limits.value.max_active_keys
)

const atClientLimit = computed(
  () => !!limits.value && limits.value.clients >= limits.value.max_clients
)

const canCreateClient = computed(() => newClient.value.name.trim().length > 0)

const canCreateKey = computed(
  () =>
    newKey.value.name.trim().length > 0 &&
    newKey.value.abilities.length > 0 &&
    parsedIps.value.length > 0 &&
    !!newKey.value.expires_at
)

/** Acepta comas y saltos de línea: se pega tal cual desde un correo. */
const parsedIps = computed(() =>
  newKey.value.allowed_ips_raw
    .split(/[\n,]/)
    .map(ip => ip.trim())
    .filter(Boolean)
)

function isoDaysFromNow(days) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

// Mañana: hoy a medianoche ya es pasado para el `after:now` del backend, y un
// rechazo por eso sería incomprensible desde el formulario.
const minExpiry = computed(() => isoDaysFromNow(1))
const maxExpiry = computed(() => isoDaysFromNow(limits.value?.max_expiration_days ?? 90))

function notify(message, type = 'success') {
  emit('notify', { message, type })
}

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString('es-CO', {
    year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit',
  })
}

async function load() {
  loading.value = true
  try {
    const payload = await myApiKeysService.list()
    clients.value = payload.data
    abilities.value = payload.abilities
    limits.value = payload.limits
  } catch (e) {
    notify(e?.response?.data?.message || 'No se pudieron cargar las llaves de API.', 'error')
  } finally {
    loading.value = false
  }
}

async function createClient() {
  saving.value = true
  try {
    await myApiKeysService.createClient({
      name: newClient.value.name.trim(),
      contact_email: newClient.value.contact_email.trim() || null,
    })
    newClient.value = { name: '', contact_email: '' }
    notify('Integración creada.')
    await load()
  } catch (e) {
    const errors = e?.response?.data?.errors
    notify(
      errors
        ? Object.values(errors).flat().join(' ')
        : e?.response?.data?.message || 'No se pudo crear la integración.',
      'error'
    )
  } finally {
    saving.value = false
  }
}

function openKeyForm(client) {
  keyFormFor.value = client.id
  keyError.value = ''
  newKey.value = {
    name: '',
    abilities: [],
    allowed_ips_raw: '',
    // Se propone la vigencia máxima: es lo que casi siempre se quiere, y deja
    // el campo relleno con un valor que el backend acepta.
    expires_at: maxExpiry.value,
  }
}

async function createKey(client) {
  saving.value = true
  keyError.value = ''
  try {
    const created = await myApiKeysService.createKey(client.id, {
      name: newKey.value.name.trim(),
      abilities: newKey.value.abilities,
      allowed_ips: parsedIps.value,
      expires_at: newKey.value.expires_at,
    })
    freshKey.value = created
    copied.value = false
    keyFormFor.value = null
    notify('Llave emitida. Cópiala ahora: no se vuelve a mostrar.')
    await load()
  } catch (e) {
    const errors = e?.response?.data?.errors
    keyError.value = errors
      ? Object.values(errors).flat().join(' ')
      : e?.response?.data?.message || 'No se pudo emitir la llave.'
  } finally {
    saving.value = false
  }
}

async function revokeKey(client, key) {
  if (!confirm(`¿Revocar la llave «${key.name}»? La integración dejará de funcionar de inmediato.`)) {
    return
  }
  try {
    await myApiKeysService.revokeKey(client.id, key.id)
    notify('Llave revocada.')
    await load()
  } catch (e) {
    notify(e?.response?.data?.message || 'No se pudo revocar la llave.', 'error')
  }
}

async function toggleLogs(client) {
  if (logsFor.value === client.id) {
    logsFor.value = null
    logs.value = []
    return
  }
  try {
    logs.value = await myApiKeysService.logs(client.id)
    logsFor.value = client.id
  } catch (e) {
    notify(e?.response?.data?.message || 'No se pudieron cargar las peticiones.', 'error')
  }
}

async function copyFreshKey() {
  try {
    await navigator.clipboard.writeText(freshKey.value.plain_text_token)
    copied.value = true
  } catch {
    notify('No se pudo copiar automáticamente: selecciona el texto y cópialo.', 'error')
  }
}

onMounted(load)
</script>
