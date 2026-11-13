<template>
  <!--
    Cartel GRANDE de «no tienes permiso». Se monta una sola vez en `App.vue`
    (como NotificationHost) y lo alimenta el interceptor de axios vía
    `usePermissionDenied`. Ninguna pantalla debe montar otro ni pintar su propio
    aviso para un 403 de permisos.

    Es un diálogo centrado con fondo oscurecido, no un toast: el objetivo es que
    quien lo ve no quede perdido. `.z-app-toast` lo deja por encima de cualquier
    modal que haya provocado la llamada.

    Todo texto del backend se pinta con {{ }} (escapado), nunca con v-html.
  -->
  <Teleport to="body">
    <Transition name="permission-denied">
      <div
        v-if="denials.length > 0"
        class="fixed inset-0 z-app-toast flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="permission-denied-title"
        @keydown.esc="dismiss"
      >
        <div
          ref="panel"
          tabindex="-1"
          class="w-full max-w-xl bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border-2 border-red-500 overflow-hidden outline-none"
        >
          <div class="bg-red-600 px-6 py-5 flex items-center gap-4">
            <div class="flex-shrink-0 w-14 h-14 rounded-full bg-white/20 flex items-center justify-center">
              <svg class="w-8 h-8 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="11" width="18" height="11" rx="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
              </svg>
            </div>
            <h2 id="permission-denied-title" class="text-2xl font-extrabold text-white leading-tight">
              {{ noRole ? 'Tu usuario no tiene un rol asignado' : 'No tienes permiso para hacer esto' }}
            </h2>
          </div>

          <div class="px-6 py-5 space-y-4">
            <p class="text-base text-gray-800 dark:text-gray-200">
              <template v-if="noRole">
                Tu cuenta no tiene ningún rol, así que el sistema no te deja ver ni hacer nada.
              </template>
              <template v-else>
                Lo que intentaste no falló por un error del sistema: tu rol
                <strong>no tiene el permiso necesario</strong>.
              </template>
              Pídele a un administrador de tu empresa que te lo asigne en
              <strong>Roles</strong>; después cierra sesión y vuelve a entrar.
            </p>

            <ul class="space-y-3">
              <li
                v-for="d in denials"
                :key="d.key"
                class="rounded-xl border border-red-200 dark:border-red-900/60 bg-red-50 dark:bg-red-900/10 p-4"
              >
                <div v-if="d.required.length" class="text-sm text-gray-700 dark:text-gray-300">
                  <span class="font-semibold">Permiso que falta:</span>
                  <span v-for="p in d.required" :key="p"
                        class="ml-2 inline-block px-2 py-0.5 rounded bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 font-mono text-sm font-bold">{{ p }}</span>
                  <span v-if="d.required.length > 1" class="block mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Basta con tener cualquiera de ellos.
                  </span>
                </div>
                <div v-if="d.request" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  <span class="font-semibold">Acción bloqueada:</span>
                  <span class="font-mono">{{ d.request }}</span>
                </div>
              </li>
            </ul>

            <p class="text-xs text-gray-500 dark:text-gray-400">
              Si se lo comentas a soporte, dile el permiso y la acción de arriba.
            </p>
          </div>

          <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/60 flex justify-end">
            <button
              type="button"
              class="px-6 py-3 rounded-lg text-base font-semibold text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-300 transition-colors"
              @click="dismiss"
            >
              Entendido
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { usePermissionDenied } from '../composables/usePermissionDenied.js'

const { denials, dismiss } = usePermissionDenied()
const panel = ref(null)

const noRole = computed(() => denials.value.some(d => d.noRole))

// Foco al cartel al aparecer: así Esc lo cierra y un lector de pantalla lo anuncia.
watch(() => denials.value.length > 0, async (open) => {
  if (open) {
    await nextTick()
    panel.value?.focus()
  }
})
</script>

<style scoped>
.permission-denied-enter-active,
.permission-denied-leave-active {
  transition: opacity 0.2s ease;
}
.permission-denied-enter-from,
.permission-denied-leave-to {
  opacity: 0;
}
</style>
