<template>
  <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
    <!-- ══ CABECERA ══ -->
    <div class="p-4 md:p-6 border-b border-gray-200 dark:border-gray-700/70">
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3 min-w-0">
          <div class="p-2 bg-indigo-100 dark:bg-indigo-500/15 rounded-lg shrink-0">
            <v-icon name="md-vpnkey" class="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
          </div>
          <div class="min-w-0">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Llaves de API</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">
              Acceso de solo lectura para integraciones externas. Cada llave ve
              únicamente los datos del tenant al que se emitió.
            </p>
          </div>
        </div>

        <button
          type="button"
          class="btn-ghost shrink-0"
          :disabled="loading"
          title="Actualizar"
          @click="load"
        >
          <v-icon name="md-refresh" class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
          <span class="hidden sm:inline">Actualizar</span>
        </button>
      </div>
    </div>

    <div class="p-4 md:p-6 space-y-6">
      <!-- ══ LLAVE RECIÉN EMITIDA ══ -->
      <!-- Sólo se muestra una vez: el servidor guarda un hash, no el texto. -->
      <div
        v-if="freshKey"
        class="rounded-xl border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 p-4"
      >
        <div class="flex items-start gap-3">
          <div class="p-1.5 rounded-lg bg-amber-100 dark:bg-amber-500/20 shrink-0">
            <v-icon name="md-warning" class="w-4 h-4 text-amber-600 dark:text-amber-300" />
          </div>
          <div class="min-w-0 flex-1">
            <h4 class="text-sm font-semibold text-amber-900 dark:text-amber-200">
              Copia esta llave ahora: no se vuelve a mostrar
            </h4>
            <p class="text-xs text-amber-800/90 dark:text-amber-200/80 mt-1">
              En la base de datos sólo queda un hash. Si se pierde, hay que
              revocarla y emitir otra.
            </p>

            <div class="mt-3 flex flex-col sm:flex-row sm:items-center gap-2">
              <code
                class="flex-1 min-w-0 text-xs rounded-lg px-3 py-2.5 font-mono break-all
                       bg-white dark:bg-gray-900/70 text-gray-800 dark:text-amber-100
                       border border-amber-200 dark:border-amber-500/30"
              >{{ freshKey.plain_text_token }}</code>
              <button
                type="button"
                class="shrink-0 inline-flex items-center justify-center gap-1.5 text-sm font-medium
                       bg-amber-600 hover:bg-amber-700 text-white px-3.5 py-2.5 rounded-lg transition-colors"
                @click="copyFreshKey"
              >
                <v-icon :name="copied ? 'md-check' : 'md-contentcopy'" class="w-4 h-4" />
                {{ copied ? 'Copiado' : 'Copiar' }}
              </button>
            </div>

            <button
              type="button"
              class="mt-3 text-xs font-medium text-amber-800 dark:text-amber-300 hover:underline"
              @click="freshKey = null"
            >
              Ya la guardé, ocultar
            </button>
          </div>
        </div>
      </div>

      <!-- ══ ALTA DE CLIENTE ══ -->
      <div class="panel">
        <h4 class="panel-title">
          <v-icon name="md-personadd" class="w-4 h-4 text-indigo-500 dark:text-indigo-400" />
          Nuevo cliente de API
        </h4>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label class="label">Tenant</label>
            <select v-model="newClient.tenant_id" class="input">
              <option :value="null" disabled>Selecciona…</option>
              <option v-for="t in tenants" :key="t.id" :value="t.id">
                {{ t.name }} (#{{ t.id }})
              </option>
            </select>
          </div>
          <div>
            <label class="label">Nombre</label>
            <input v-model="newClient.name" type="text" class="input" placeholder="CRM del ISP" />
          </div>
          <div>
            <label class="label">Correo de contacto <span class="label-opt">(opcional)</span></label>
            <input v-model="newClient.contact_email" type="email" class="input" placeholder="it@isp.com" />
          </div>
          <div class="flex items-end">
            <button
              type="button"
              :disabled="!canCreateClient || saving"
              class="btn-primary w-full"
              @click="createClient"
            >
              <v-icon name="md-add" class="w-4 h-4" />
              Crear cliente
            </button>
          </div>
        </div>
      </div>

      <!-- ══ CLIENTES EXISTENTES ══ -->
      <div v-if="loading" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        Cargando…
      </div>

      <div
        v-else-if="!clients.length"
        class="py-12 text-center rounded-xl border border-dashed border-gray-300 dark:border-gray-700"
      >
        <v-icon name="md-vpnkey" class="w-9 h-9 mx-auto text-gray-300 dark:text-gray-600" />
        <p class="mt-2 text-sm font-medium text-gray-600 dark:text-gray-300">
          Todavía no hay clientes de API.
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
          Crea uno arriba y después emítele una llave.
        </p>
      </div>

      <div v-else class="space-y-4">
        <div
          v-for="client in clients"
          :key="client.id"
          class="rounded-xl border border-gray-200 dark:border-gray-700/70 overflow-hidden
                 bg-white dark:bg-gray-800/40"
        >
          <!-- Cabecera del cliente -->
          <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 bg-gray-50 dark:bg-gray-900/40">
            <div class="flex items-center gap-3 min-w-0">
              <div class="w-9 h-9 rounded-lg grid place-items-center shrink-0 bg-indigo-100 dark:bg-indigo-500/15">
                <v-icon name="md-vpnkey" class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
              </div>
              <div class="min-w-0">
                <div class="flex items-center gap-2">
                  <h4 class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                    {{ client.name }}
                  </h4>
                  <span class="chip" :class="client.is_active ? 'chip-emerald' : 'chip-gray'">
                    {{ client.is_active ? 'Activo' : 'Desactivado' }}
                  </span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">
                  Tenant: {{ client.tenant_name || '—' }} (#{{ client.tenant_id }})
                  <span v-if="client.contact_email"> · {{ client.contact_email }}</span>
                </p>
              </div>
            </div>

            <div class="flex items-center gap-2">
              <button type="button" class="btn-ghost" @click="toggleActive(client)">
                <v-icon :name="client.is_active ? 'md-pausecircle' : 'md-playcircle'" class="w-4 h-4" />
                {{ client.is_active ? 'Desactivar' : 'Activar' }}
              </button>
              <button
                type="button"
                class="btn-primary"
                :disabled="keyFormFor === client.id"
                @click="openKeyForm(client)"
              >
                <v-icon name="md-add" class="w-4 h-4" />
                Emitir llave
              </button>
            </div>
          </div>

          <!-- ── Formulario de emisión ── -->
          <div
            v-if="keyFormFor === client.id"
            class="px-4 py-4 border-t border-gray-200 dark:border-gray-700/70
                   bg-indigo-50/40 dark:bg-indigo-500/[0.06] space-y-5"
          >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="label">Nombre de la llave</label>
                <input v-model="newKey.name" type="text" class="input" placeholder="produccion-crm" />
                <p class="hint">Para reconocerla cuando haya varias.</p>
              </div>
              <div>
                <label class="label">Vence el <span class="label-opt">(opcional)</span></label>
                <input v-model="newKey.expires_at" type="date" class="input" />
                <p class="hint">
                  Vacío = sin vencimiento. Una llave que nadie rota es una llave
                  que sigue viva cuando ya terminó el contrato.
                </p>
              </div>
            </div>

            <div>
              <label class="label">Permisos de lectura</label>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <label
                  v-for="(label, key) in abilities"
                  :key="key"
                  class="ability"
                  :class="newKey.abilities.includes(key) ? 'ability-on' : ''"
                >
                  <input v-model="newKey.abilities" type="checkbox" :value="key" class="checkbox" />
                  <span class="min-w-0">
                    <span class="block font-mono text-xs text-indigo-700 dark:text-indigo-300">{{ key }}</span>
                    <span class="block text-xs text-gray-600 dark:text-gray-400 mt-0.5">{{ label }}</span>
                  </span>
                </label>
              </div>
            </div>

            <div>
              <label class="label">IPs autorizadas</label>
              <textarea
                v-model="newKey.allowed_ips_raw"
                rows="2"
                class="input input-mono"
                placeholder="190.24.7.10, 190.24.8.0/24"
              ></textarea>
              <div v-if="parsedIps.length" class="mt-2 flex flex-wrap gap-1.5">
                <span v-for="ip in parsedIps" :key="ip" class="mono-chip">{{ ip }}</span>
              </div>
              <p class="hint">
                Obligatorio. Una IP o rango CIDR por línea o separados por coma.
                La llave sólo funciona desde estos orígenes: si se filtra, no
                sirve desde fuera.
              </p>
            </div>

            <div
              v-if="keyError"
              class="flex items-start gap-2 rounded-lg px-3 py-2 text-xs
                     bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-300
                     border border-red-200 dark:border-red-500/30"
            >
              <v-icon name="md-error" class="w-4 h-4 shrink-0 mt-px" />
              <span>{{ keyError }}</span>
            </div>

            <div class="flex items-center gap-2">
              <button type="button" :disabled="!canCreateKey || saving" class="btn-primary" @click="createKey(client)">
                <v-icon name="md-vpnkey" class="w-4 h-4" />
                {{ saving ? 'Emitiendo…' : 'Emitir llave' }}
              </button>
              <button type="button" class="btn-ghost" @click="keyFormFor = null">Cancelar</button>
            </div>
          </div>

          <!-- ── Llaves del cliente ── -->
          <div v-if="client.keys.length" class="border-t border-gray-200 dark:border-gray-700/70 overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-gray-50/70 dark:bg-gray-900/30">
                <tr class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  <th class="th">Llave</th>
                  <th class="th">Permisos</th>
                  <th class="th">IPs</th>
                  <th class="th">Último uso</th>
                  <th class="th">Estado</th>
                  <th class="th text-right">Acción</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                <tr
                  v-for="key in client.keys"
                  :key="key.id"
                  class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                >
                  <td class="td">
                    <span
                      class="font-medium"
                      :class="key.revoked_at
                        ? 'line-through text-gray-400 dark:text-gray-500'
                        : 'text-gray-900 dark:text-white'"
                    >{{ key.name }}</span>
                  </td>
                  <td class="td">
                    <div class="flex flex-wrap gap-1">
                      <span v-for="a in key.abilities || []" :key="a" class="mono-chip">{{ a }}</span>
                      <span v-if="!(key.abilities || []).length" class="text-xs text-gray-400">—</span>
                    </div>
                  </td>
                  <td class="td">
                    <div class="flex flex-wrap gap-1">
                      <span v-for="ip in key.allowed_ips || []" :key="ip" class="mono-chip">{{ ip }}</span>
                      <span v-if="!(key.allowed_ips || []).length" class="text-xs text-gray-400">—</span>
                    </div>
                  </td>
                  <td class="td text-xs text-gray-500 dark:text-gray-400">
                    <template v-if="key.last_used_at">
                      {{ formatDate(key.last_used_at) }}
                      <span v-if="key.last_used_ip" class="block font-mono text-[11px] text-gray-400 dark:text-gray-500">
                        {{ key.last_used_ip }}
                      </span>
                    </template>
                    <span v-else class="text-gray-400 dark:text-gray-500">Nunca</span>
                  </td>
                  <td class="td">
                    <span class="chip" :class="`chip-${keyState(key).tone}`">{{ keyState(key).label }}</span>
                    <span class="block text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">
                      {{ key.expires_at ? formatDate(key.expires_at) : 'Sin vencimiento' }}
                    </span>
                  </td>
                  <td class="td text-right">
                    <span v-if="key.revoked_at" class="text-xs text-gray-400 dark:text-gray-500">Revocada</span>
                    <button v-else type="button" class="btn-danger" @click="revokeKey(client, key)">
                      <v-icon name="md-delete" class="w-3.5 h-3.5" />
                      Revocar
                    </button>
                  </td>
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
import { apiKeysService } from '@/services/apiKeys'

const emit = defineEmits(['notify'])

const loading = ref(false)
const saving = ref(false)
const clients = ref([])
const tenants = ref([])
const abilities = ref({})

const freshKey = ref(null)
const copied = ref(false)
const keyFormFor = ref(null)
const keyError = ref('')

const newClient = ref({ tenant_id: null, name: '', contact_email: '' })
const newKey = ref({ name: '', abilities: [], allowed_ips_raw: '', expires_at: '' })

const canCreateClient = computed(
  () => !!newClient.value.tenant_id && newClient.value.name.trim().length > 0
)

const canCreateKey = computed(
  () =>
    newKey.value.name.trim().length > 0 &&
    newKey.value.abilities.length > 0 &&
    parsedIps.value.length > 0
)

/** Acepta comas y saltos de línea: se pega tal cual desde un correo. */
const parsedIps = computed(() =>
  newKey.value.allowed_ips_raw
    .split(/[\n,]/)
    .map(ip => ip.trim())
    .filter(Boolean)
)

function notify(message, type = 'success') {
  emit('notify', { message, type })
}

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString('es-CO', {
    year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit',
  })
}

/**
 * Estado de la llave de un vistazo: una fecha suelta obliga a hacer la resta
 * mental, y «vence en 3 días» es justo el aviso que hay que ver a tiempo.
 */
function keyState(key) {
  if (key.revoked_at) return { label: 'Revocada', tone: 'gray' }
  if (!key.expires_at) return { label: 'Sin vencimiento', tone: 'amber' }
  const days = Math.ceil((new Date(key.expires_at).getTime() - Date.now()) / 86400000)
  if (days <= 0) return { label: 'Vencida', tone: 'red' }
  if (days <= 7) return { label: `Vence en ${days} d`, tone: 'amber' }
  return { label: `Vigente · ${days} d`, tone: 'emerald' }
}

async function load() {
  loading.value = true
  try {
    const [payload, tenantList] = await Promise.all([
      apiKeysService.list(),
      apiKeysService.tenants(),
    ])
    clients.value = payload.data
    abilities.value = payload.abilities
    tenants.value = tenantList
  } catch (e) {
    notify(e?.response?.data?.message || 'No se pudieron cargar las llaves de API.', 'error')
  } finally {
    loading.value = false
  }
}

async function createClient() {
  saving.value = true
  try {
    await apiKeysService.createClient({
      tenant_id: newClient.value.tenant_id,
      name: newClient.value.name.trim(),
      contact_email: newClient.value.contact_email.trim() || null,
    })
    newClient.value = { tenant_id: null, name: '', contact_email: '' }
    notify('Cliente de API creado.')
    await load()
  } catch (e) {
    notify(e?.response?.data?.message || 'No se pudo crear el cliente.', 'error')
  } finally {
    saving.value = false
  }
}

function openKeyForm(client) {
  keyFormFor.value = client.id
  keyError.value = ''
  newKey.value = { name: '', abilities: [], allowed_ips_raw: '', expires_at: '' }
}

async function createKey(client) {
  saving.value = true
  keyError.value = ''
  try {
    const created = await apiKeysService.createKey(client.id, {
      name: newKey.value.name.trim(),
      abilities: newKey.value.abilities,
      allowed_ips: parsedIps.value,
      expires_at: newKey.value.expires_at || null,
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
    await apiKeysService.revokeKey(client.id, key.id)
    notify('Llave revocada.')
    await load()
  } catch (e) {
    notify(e?.response?.data?.message || 'No se pudo revocar la llave.', 'error')
  }
}

async function toggleActive(client) {
  try {
    await apiKeysService.updateClient(client.id, { is_active: !client.is_active })
    await load()
  } catch (e) {
    notify(e?.response?.data?.message || 'No se pudo actualizar el cliente.', 'error')
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

<!--
  Los estilos de formulario del panel (`.input`, `.label`) viven `scoped` en
  Settings.vue, así que no llegaban hasta aquí: sin esta hoja los campos salían
  con el estilo nativo del navegador — cajas blancas ilegibles sobre la tarjeta
  oscura. Se redeclaran localmente, no en `app.css`, para no alterar de rebote
  los demás formularios de la aplicación.
-->
<style scoped>
.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5;
}
.label-opt {
  @apply font-normal text-gray-400 dark:text-gray-500;
}
.input {
  @apply w-full px-3.5 py-2.5 rounded-lg text-sm
         border border-gray-300 dark:border-gray-600
         bg-white dark:bg-gray-900/60 text-gray-900 dark:text-gray-100
         focus:outline-none focus:ring-2 focus:ring-indigo-500/60 focus:border-indigo-500
         disabled:opacity-50 disabled:cursor-not-allowed transition-colors
         placeholder:text-gray-400 dark:placeholder:text-gray-500;
}
/* Va después de `.input` a propósito: misma especificidad, gana la última. */
.input-mono {
  @apply font-mono text-xs;
}
.hint {
  @apply text-xs text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed;
}

/* Botones */
.btn-primary {
  @apply inline-flex items-center justify-center gap-1.5 text-sm font-medium
         bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2.5 rounded-lg
         transition-colors disabled:opacity-50 disabled:cursor-not-allowed;
}
.btn-ghost {
  @apply inline-flex items-center justify-center gap-1.5 text-sm font-medium
         px-3.5 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600
         text-gray-700 dark:text-gray-300
         hover:bg-gray-100 dark:hover:bg-gray-700/60
         transition-colors disabled:opacity-50 disabled:cursor-not-allowed;
}
.btn-danger {
  @apply inline-flex items-center gap-1 text-xs font-medium
         px-2.5 py-1.5 rounded-md text-red-600 dark:text-red-400
         hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors;
}

/* Superficies: fondo suave en lugar de más marcos, que era lo que hacía ver la
   pantalla como una cuadrícula de cajas. */
.panel {
  @apply rounded-xl p-4 md:p-5
         bg-gray-50 dark:bg-gray-900/30
         border border-gray-200 dark:border-gray-700/70;
}
.panel-title {
  @apply text-sm font-semibold text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2;
}

/* Permisos como tarjetas seleccionables: la casilla suelta era diminuta y no
   dejaba ver de un vistazo qué quedó marcado. */
.ability {
  @apply flex items-start gap-2.5 rounded-lg p-3 cursor-pointer transition-colors
         border border-gray-200 dark:border-gray-700
         bg-white dark:bg-gray-900/40
         hover:border-gray-300 dark:hover:border-gray-600;
}
.ability-on {
  @apply border-indigo-500 dark:border-indigo-500/70 bg-indigo-50 dark:bg-indigo-500/10;
}
.checkbox {
  @apply mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded accent-indigo-600;
}

/* Tablas */
.th {
  @apply text-left font-medium px-4 py-2.5 whitespace-nowrap;
}
.td {
  @apply px-4 py-3 align-top;
}

/* Etiquetas de estado */
.chip {
  @apply inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap;
}
.chip-emerald {
  @apply bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300;
}
.chip-amber {
  @apply bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300;
}
.chip-red {
  @apply bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300;
}
.chip-gray {
  @apply bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300;
}
.mono-chip {
  @apply inline-block rounded-md px-1.5 py-0.5 font-mono text-[11px]
         bg-gray-100 text-gray-600 dark:bg-gray-900/60 dark:text-gray-300;
}
</style>
