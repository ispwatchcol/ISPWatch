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
              Acceso de solo lectura para tus integraciones. Cada llave ve
              únicamente los datos de tu empresa, nunca los de otra.
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

            <!-- Primera llamada, lista para pegar.
                 Quien acaba de emitir una llave se queda mirando una cadena
                 larga sin saber qué hacer con ella: la documentación estaba en
                 el manual, o sea a tres clics y una búsqueda de distancia. Este
                 bloque convierte "ya tengo la llave" en "ya la probé", que es
                 donde de verdad se descubre si la IP quedó bien. -->
            <div class="mt-4 pt-3 border-t border-amber-200 dark:border-amber-500/30">
              <p class="text-xs font-semibold text-amber-900 dark:text-amber-200 mb-1.5">
                Pruébala ahora — pega esto en una terminal
              </p>
              <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <code
                  class="flex-1 min-w-0 text-[11px] rounded-lg px-3 py-2.5 font-mono break-all
                         bg-white dark:bg-gray-900/70 text-gray-700 dark:text-amber-100/90
                         border border-amber-200 dark:border-amber-500/30"
                >{{ curlExample }}</code>
                <button
                  type="button"
                  class="shrink-0 inline-flex items-center justify-center gap-1.5 text-sm font-medium
                         bg-white dark:bg-gray-800 text-amber-800 dark:text-amber-200 border border-amber-300
                         dark:border-amber-500/40 hover:bg-amber-50 dark:hover:bg-gray-700 px-3.5 py-2.5 rounded-lg transition-colors"
                  @click="copyCurl"
                >
                  <v-icon :name="curlCopied ? 'md-check' : 'md-contentcopy'" class="w-4 h-4" />
                  {{ curlCopied ? 'Copiado' : 'Copiar' }}
                </button>
              </div>
              <p class="text-[11px] text-amber-800/80 dark:text-amber-200/70 mt-2">
                Responde con los permisos de la llave y con <strong>la IP desde la que te ve
                el servidor</strong>. Si devuelve <code>ip_not_allowed</code>, esa IP no es
                la que autorizaste — mírala en <em>Ver peticiones</em> y emite una llave
                nueva con ella (la lista de IPs de una llave ya emitida no se puede editar).
              </p>
              <p class="text-[11px] text-amber-800/80 dark:text-amber-200/70 mt-1.5">
                Para armar la integración completa, el contrato de la API se descarga con la
                misma llave desde <code>{{ baseUrl }}/openapi.yaml</code> y se importa
                directo en Postman o Insomnia. El paso a paso está en
                <strong>Manual → Integraciones y API</strong>.
              </p>
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

      <!-- ══ LÍMITES ══ -->
      <!-- Van arriba y no en un pie de página: son la respuesta a "por qué me
           rechazó el formulario", y llegar a leerlos después de que pase ya es
           tarde. -->
      <div v-if="limits" class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="tile">
          <p class="tile-label">Llaves vigentes</p>
          <p class="tile-value" :class="atKeyLimit ? 'text-amber-600 dark:text-amber-400' : ''">
            {{ limits.active_keys }}<span class="tile-total"> / {{ limits.max_active_keys }}</span>
          </p>
          <div class="meter">
            <div
              class="meter-fill"
              :class="atKeyLimit ? 'bg-amber-500' : 'bg-indigo-500'"
              :style="{ width: pct(limits.active_keys, limits.max_active_keys) }"
            ></div>
          </div>
        </div>

        <div class="tile">
          <p class="tile-label">Integraciones</p>
          <p class="tile-value" :class="atClientLimit ? 'text-amber-600 dark:text-amber-400' : ''">
            {{ limits.clients }}<span class="tile-total"> / {{ limits.max_clients }}</span>
          </p>
          <div class="meter">
            <div
              class="meter-fill"
              :class="atClientLimit ? 'bg-amber-500' : 'bg-indigo-500'"
              :style="{ width: pct(limits.clients, limits.max_clients) }"
            ></div>
          </div>
        </div>

        <div class="tile">
          <p class="tile-label">Vigencia máxima</p>
          <p class="tile-value">{{ limits.max_expiration_days }}<span class="tile-total"> días</span></p>
          <p class="tile-hint">Por llave emitida</p>
        </div>

        <div class="tile">
          <p class="tile-label">Rango IP más amplio</p>
          <p class="tile-value">/{{ limits.min_ipv4_prefix }}</p>
          <p class="tile-hint">Prefijo mínimo aceptado</p>
        </div>
      </div>

      <!-- ══ ALTA DE INTEGRACIÓN ══ -->
      <div class="panel">
        <h4 class="panel-title">
          <v-icon name="md-personadd" class="w-4 h-4 text-indigo-500 dark:text-indigo-400" />
          Nueva integración
        </h4>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="label">Nombre</label>
            <input
              v-model="newClient.name"
              type="text"
              class="input"
              placeholder="Bot de WhatsApp"
              @keyup.enter="canCreateClient && !atClientLimit && createClient()"
            />
          </div>
          <div>
            <label class="label">Correo de contacto <span class="label-opt">(opcional)</span></label>
            <input v-model="newClient.contact_email" type="email" class="input" placeholder="it@miempresa.com" />
          </div>
          <div class="flex items-end">
            <button
              type="button"
              :disabled="!canCreateClient || saving || atClientLimit"
              class="btn-primary w-full"
              @click="createClient"
            >
              <v-icon name="md-add" class="w-4 h-4" />
              {{ atClientLimit ? 'Límite alcanzado' : 'Crear integración' }}
            </button>
          </div>
        </div>
      </div>

      <!-- ══ INTEGRACIONES EXISTENTES ══ -->
      <div v-if="loading" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        Cargando…
      </div>

      <div
        v-else-if="!clients.length"
        class="py-12 text-center rounded-xl border border-dashed border-gray-300 dark:border-gray-700"
      >
        <v-icon name="md-vpnkey" class="w-9 h-9 mx-auto text-gray-300 dark:text-gray-600" />
        <p class="mt-2 text-sm font-medium text-gray-600 dark:text-gray-300">
          Todavía no tienes integraciones registradas.
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
          Crea una arriba y después emítele una llave.
        </p>
      </div>

      <div v-else class="space-y-4">
        <div
          v-for="client in clients"
          :key="client.id"
          class="rounded-xl border border-gray-200 dark:border-gray-700/70 overflow-hidden
                 bg-white dark:bg-gray-800/40"
        >
          <!-- Cabecera de la integración -->
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
                    {{ client.is_active ? 'Activa' : 'Desactivada' }}
                  </span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">
                  <span v-if="client.contact_email">{{ client.contact_email }} · </span>
                  {{ activeKeyCount(client) }} de {{ client.keys.length }} llaves vigentes
                </p>
              </div>
            </div>

            <div class="flex items-center gap-2">
              <button type="button" class="btn-ghost" @click="toggleLogs(client)">
                <v-icon name="md-list" class="w-4 h-4" />
                {{ logsFor === client.id ? 'Ocultar peticiones' : 'Ver peticiones' }}
              </button>
              <button
                type="button"
                :disabled="atKeyLimit || keyFormFor === client.id"
                class="btn-primary"
                :title="atKeyLimit ? 'Alcanzaste el máximo de llaves vigentes' : ''"
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
                <input v-model="newKey.name" type="text" class="input" placeholder="produccion-bot" />
                <p class="hint">Para reconocerla cuando haya varias.</p>
              </div>
              <div>
                <label class="label">Vence el</label>
                <input
                  v-model="newKey.expires_at"
                  type="date"
                  class="input"
                  :min="minExpiry"
                  :max="maxExpiry"
                />
                <div class="mt-2 flex flex-wrap gap-1.5">
                  <button
                    v-for="d in expiryPresets"
                    :key="d"
                    type="button"
                    class="preset"
                    :class="newKey.expires_at === isoDaysFromNow(d) ? 'preset-on' : ''"
                    @click="newKey.expires_at = isoDaysFromNow(d)"
                  >
                    {{ d }} días<span v-if="d === maxDays"> (máx.)</span>
                  </button>
                </div>
                <p class="hint">
                  Obligatorio. Cuando venza, emites otra: una llave que nadie rota
                  sigue viva cuando ya terminó el contrato con el proveedor.
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
              <p class="hint">
                Concede sólo lo que la integración necesite. El acceso a
                facturación no se emite desde aquí: pídeselo al operador.
              </p>
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
                Obligatorio. La IP pública del servidor donde corre la integración,
                una por línea o separadas por coma. Rangos hasta /{{ limits?.min_ipv4_prefix }}.
                Esta lista es lo que hace que una llave filtrada no sirva desde
                fuera: no la abras «para que funcione».
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

          <!-- ── Llaves de la integración ── -->
          <div class="border-t border-gray-200 dark:border-gray-700/70 overflow-x-auto">
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
                <tr v-if="!client.keys.length">
                  <td colspan="6" class="px-4 py-6 text-center text-xs text-gray-500 dark:text-gray-500">
                    Sin llaves emitidas.
                  </td>
                </tr>
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
                      {{ formatDate(key.expires_at) }}
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

          <!-- ── Bitácora de peticiones: casi todo problema de integración se ve aquí ── -->
          <div
            v-if="logsFor === client.id"
            class="border-t border-gray-200 dark:border-gray-700/70 p-4 bg-gray-50/60 dark:bg-gray-900/30"
          >
            <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
              Últimas peticiones
            </h5>
            <p v-if="!logs.length" class="text-xs text-gray-500 dark:text-gray-500 text-center py-3">
              Todavía no hay peticiones registradas.
            </p>
            <div
              v-else
              class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700/70 bg-white dark:bg-gray-800/60"
            >
              <table class="w-full text-xs">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                  <tr class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="th-sm">Fecha</th>
                    <th class="th-sm">Ruta</th>
                    <th class="th-sm">IP</th>
                    <th class="th-sm">Estado</th>
                    <th class="th-sm">Motivo</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                  <tr v-for="row in logs" :key="row.id" class="hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                    <td class="td-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                      {{ formatDate(row.created_at) }}
                    </td>
                    <td class="td-sm font-mono text-gray-700 dark:text-gray-300">{{ row.method }} /{{ row.path }}</td>
                    <td class="td-sm font-mono text-gray-500 dark:text-gray-400">{{ row.ip }}</td>
                    <td class="td-sm">
                      <span class="chip" :class="row.status_code >= 400 ? 'chip-red' : 'chip-emerald'">
                        {{ row.status_code }}
                      </span>
                    </td>
                    <td class="td-sm text-gray-500 dark:text-gray-400">{{ row.denied_reason || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
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
const maxDays = computed(() => limits.value?.max_expiration_days ?? 90)
const maxExpiry = computed(() => isoDaysFromNow(maxDays.value))

/** Atajos de vigencia: el máximo siempre aparece; los cortos, sólo si caben. */
const expiryPresets = computed(() =>
  [30, 60, maxDays.value].filter((d, i, all) => d <= maxDays.value && all.indexOf(d) === i)
)

function pct(used, total) {
  if (!total) return '0%'
  return `${Math.min(100, Math.round((used / total) * 100))}%`
}

function isExpired(key) {
  return !!key.expires_at && new Date(key.expires_at).getTime() <= Date.now()
}

function activeKeyCount(client) {
  return (client.keys || []).filter(k => !k.revoked_at && !isExpired(k)).length
}

/**
 * Estado de la llave de un vistazo: una fecha suelta obliga a hacer la resta
 * mental, y «vence en 3 días» es justo el aviso que hay que ver a tiempo.
 */
function keyState(key) {
  if (key.revoked_at) return { label: 'Revocada', tone: 'gray' }
  if (isExpired(key)) return { label: 'Vencida', tone: 'red' }
  if (key.expires_at) {
    const days = Math.ceil((new Date(key.expires_at).getTime() - Date.now()) / 86400000)
    if (days <= 7) return { label: `Vence en ${days} d`, tone: 'amber' }
    return { label: `Vigente · ${days} d`, tone: 'emerald' }
  }
  return { label: 'Vigente', tone: 'emerald' }
}

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

/**
 * URL base de la API, tomada del propio origen del navegador.
 *
 * No se escribe a mano ni se lee de una variable de entorno del frontend: el
 * panel se sirve desde el mismo despliegue que atiende la API, así que el
 * origen actual ES la respuesta correcta — y no puede quedarse desactualizado
 * si mañana cambia el dominio.
 */
const baseUrl = computed(() => `${window.location.origin}/api/v1/partner`)

/**
 * `Accept: application/json` va en el ejemplo a propósito, no por costumbre.
 * Sin esa cabecera, una llamada con llave inválida no devuelve 401: cae en el
 * redirect de invitados y responde 302 hacia `/`. El cliente HTTP lo sigue y
 * termina mostrando el HTML del panel, con un error que no menciona las
 * credenciales por ningún lado.
 */
// Se arma por líneas y no con un template literal multilínea: ahí, una barra
// invertida al final de la línea es continuación de JS y desaparece, con lo que
// el comando copiado salía en una sola línea y sin las barras que curl espera.
const curlExample = computed(() => [
  `curl -H "Authorization: Bearer ${freshKey.value?.plain_text_token ?? ''}" \\`,
  `     -H "Accept: application/json" \\`,
  `     ${baseUrl.value}/ping`,
].join('\n'))

const curlCopied = ref(false)

async function copyCurl() {
  try {
    await navigator.clipboard.writeText(curlExample.value)
    curlCopied.value = true
    setTimeout(() => (curlCopied.value = false), 2000)
  } catch (e) {
    // Sin portapapeles (contexto no seguro, permisos): el texto sigue visible
    // y seleccionable, que es lo que importa.
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
.tile {
  @apply rounded-xl p-3 bg-gray-50 dark:bg-white/[0.04];
}
.tile-label {
  @apply text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400;
}
.tile-value {
  @apply mt-1 text-xl font-semibold text-gray-900 dark:text-white leading-none;
}
.tile-total {
  @apply text-sm font-normal text-gray-400 dark:text-gray-500;
}
.tile-hint {
  @apply mt-2 text-[11px] text-gray-400 dark:text-gray-500;
}
.meter {
  @apply mt-2 h-1.5 rounded-full overflow-hidden bg-gray-200 dark:bg-gray-700;
}
.meter-fill {
  @apply h-full rounded-full transition-all;
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
.preset {
  @apply text-xs px-2.5 py-1 rounded-md transition-colors
         border border-gray-300 dark:border-gray-600
         text-gray-600 dark:text-gray-300
         hover:bg-gray-100 dark:hover:bg-gray-700/60;
}
.preset-on {
  @apply border-indigo-500 bg-indigo-50 text-indigo-700
         dark:border-indigo-500/70 dark:bg-indigo-500/15 dark:text-indigo-300;
}

/* Tablas */
.th {
  @apply text-left font-medium px-4 py-2.5 whitespace-nowrap;
}
.td {
  @apply px-4 py-3 align-top;
}
.th-sm {
  @apply text-left font-medium px-3 py-2 whitespace-nowrap;
}
.td-sm {
  @apply px-3 py-2 align-top;
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
