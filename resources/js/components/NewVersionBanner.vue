<template>
  <!--
    Aviso de versión nueva (KAN-101 · P-46).

    Abajo a la derecha, y no arriba: arriba viven las notificaciones
    (`NotificationHost`), y este aviso puede quedarse en pantalla mucho rato
    —hasta que la persona decida recargar—, así que no debe ocupar el sitio de
    los mensajes que sí son urgentes.

    No se recarga sola a propósito: recargar por sorpresa a alguien que está a
    medio llenar un formulario de alta le borra el trabajo. Se avisa y decide.
  -->
  <Teleport to="body">
    <Transition name="new-version">
      <div
        v-if="visible"
        class="fixed bottom-4 inset-x-4 sm:left-auto sm:right-4 sm:w-full sm:max-w-sm z-app-toast"
        role="status"
        aria-live="polite"
      >
        <div
          class="rounded-xl shadow-2xl backdrop-blur-sm border p-4
                 bg-blue-50/95 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800"
        >
          <div class="flex items-start gap-3">
            <div class="flex-shrink-0 p-1.5 rounded-lg bg-blue-100 dark:bg-blue-800/30">
              <svg
                class="w-5 h-5 text-blue-600 dark:text-blue-400"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <path d="M21 12a9 9 0 1 1-2.64-6.36" />
                <polyline points="21 3 21 9 15 9" />
              </svg>
            </div>

            <div class="flex-1 min-w-0 pt-0.5">
              <h4 class="font-semibold text-sm mb-0.5 text-blue-900 dark:text-blue-200">
                Hay una versión nueva de ISPWatch
              </h4>
              <p class="text-sm text-blue-800 dark:text-blue-300">
                Estás viendo una versión anterior. Recarga para usar la más reciente<span v-if="deployedVersion"> ({{ deployedVersion }})</span>.
              </p>

              <div class="mt-3 flex items-center gap-2">
                <button
                  type="button"
                  class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors
                         bg-blue-600 hover:bg-blue-700 text-white"
                  @click="reloadNow"
                >
                  Recargar ahora
                </button>
                <button
                  type="button"
                  class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors
                         text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-800/30"
                  @click="dismissed = true"
                >
                  Más tarde
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useVersionWatcher } from '@/composables/useVersionWatcher'

const { updateAvailable, deployedVersion, start, stop, reloadNow } = useVersionWatcher()

// «Más tarde» dura lo que dure esta carga de la página. No se persiste: la
// siguiente carga ya trae el código nuevo, así que no habría nada que recordar.
const dismissed = ref(false)

const visible = computed(() => updateAvailable.value && !dismissed.value)

onMounted(start)
onUnmounted(stop)
</script>

<style scoped>
.new-version-enter-active,
.new-version-leave-active {
  transition: opacity 0.25s ease, transform 0.25s ease;
}

.new-version-enter-from,
.new-version-leave-to {
  opacity: 0;
  transform: translateY(0.5rem);
}
</style>
