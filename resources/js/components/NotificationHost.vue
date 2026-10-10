<template>
  <!--
    EL ÚNICO contenedor de notificaciones de la aplicación. Se monta una vez en
    `App.vue`; ninguna pantalla debe montar otro.

    `.z-app-toast` es el escalón más alto de la escala de `resources/css/app.css`
    y eso no es decorativo: un aviso tiene que poder leerse por encima del modal
    que lo provocó. Ver el comentario de `useNotifications.js`.
  -->
  <Teleport to="body">
    <Transition name="notification-container">
      <div
        v-if="notifications.length > 0"
        class="fixed top-4 inset-x-4 sm:left-auto sm:right-4 sm:w-full sm:max-w-sm
               z-app-toast space-y-3 max-h-[calc(100vh-2rem)] overflow-y-auto"
        role="region"
        aria-live="polite"
        aria-label="Notificaciones"
      >
        <TransitionGroup name="notification">
          <div
            v-for="notification in notifications"
            :key="notification.id"
            :class="[
              'rounded-xl shadow-2xl backdrop-blur-sm border p-4',
              'transform transition-all duration-300 ease-out',
              notificationClasses[notification.type]
            ]"
            role="alert"
          >
            <div class="flex items-start gap-3">
              <!-- Icono -->
              <div :class="['flex-shrink-0 p-1.5 rounded-lg', iconBgClasses[notification.type]]">
                <v-icon
                  :name="icons[notification.type]"
                  :class="['w-5 h-5', iconClasses[notification.type]]"
                />
              </div>

              <!-- Contenido -->
              <div class="flex-1 min-w-0 pt-0.5">
                <h4 :class="['font-semibold text-sm mb-0.5', titleClasses[notification.type]]">
                  {{ notification.title }}
                </h4>
                <!--
                  `break-words` y sin truncado: un mensaje de validación del
                  backend puede nombrar un número de factura, y cortarlo con
                  puntos suspensivos deja al operador sin el único dato que
                  necesitaba para arreglarlo.
                -->
                <p :class="['text-sm break-words whitespace-pre-line', messageClasses[notification.type]]">
                  {{ notification.message }}
                </p>
              </div>

              <!-- Cerrar a mano, siempre disponible -->
              <button
                @click="remove(notification.id)"
                :class="['flex-shrink-0 rounded-lg p-1 transition-colors', closeBtnClasses[notification.type]]"
                aria-label="Cerrar notificación"
              >
                <v-icon name="io-close" class="w-5 h-5" />
              </button>
            </div>

            <!-- Acciones opcionales -->
            <div v-if="notification.action" class="mt-3 pt-3 border-t flex justify-end gap-2"
                 :class="borderClasses[notification.type]">
              <button
                @click="remove(notification.id)"
                :class="['px-3 py-1.5 text-sm font-medium rounded-lg transition-colors', cancelBtnClasses[notification.type]]"
              >
                Cancelar
              </button>
              <button
                @click="handleAction(notification)"
                :class="['px-3 py-1.5 text-sm font-medium rounded-lg transition-colors', actionBtnClasses[notification.type]]"
              >
                {{ notification.action.label || 'Confirmar' }}
              </button>
            </div>
          </div>
        </TransitionGroup>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { useNotifications } from '@/composables/useNotifications'

const { notifications, remove } = useNotifications()

const icons = {
  success: 'bi-check-circle-fill',
  error: 'md-error',
  warning: 'md-warning',
  info: 'md-info'
}

const notificationClasses = {
  success: 'bg-emerald-50/95 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800',
  error: 'bg-red-50/95 dark:bg-red-900/20 border-red-200 dark:border-red-800',
  warning: 'bg-amber-50/95 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800',
  info: 'bg-blue-50/95 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800'
}

const iconBgClasses = {
  success: 'bg-emerald-100 dark:bg-emerald-800/30',
  error: 'bg-red-100 dark:bg-red-800/30',
  warning: 'bg-amber-100 dark:bg-amber-800/30',
  info: 'bg-blue-100 dark:bg-blue-800/30'
}

const iconClasses = {
  success: 'text-emerald-600 dark:text-emerald-400',
  error: 'text-red-600 dark:text-red-400',
  warning: 'text-amber-600 dark:text-amber-400',
  info: 'text-blue-600 dark:text-blue-400'
}

const titleClasses = {
  success: 'text-emerald-900 dark:text-emerald-100',
  error: 'text-red-900 dark:text-red-100',
  warning: 'text-amber-900 dark:text-amber-100',
  info: 'text-blue-900 dark:text-blue-100'
}

const messageClasses = {
  success: 'text-emerald-700 dark:text-emerald-200',
  error: 'text-red-700 dark:text-red-200',
  warning: 'text-amber-700 dark:text-amber-200',
  info: 'text-blue-700 dark:text-blue-200'
}

const closeBtnClasses = {
  success: 'text-emerald-600 dark:text-emerald-400 hover:bg-emerald-200 dark:hover:bg-emerald-800/50',
  error: 'text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-800/50',
  warning: 'text-amber-600 dark:text-amber-400 hover:bg-amber-200 dark:hover:bg-amber-800/50',
  info: 'text-blue-600 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-800/50'
}

const borderClasses = {
  success: 'border-emerald-200 dark:border-emerald-700',
  error: 'border-red-200 dark:border-red-700',
  warning: 'border-amber-200 dark:border-amber-700',
  info: 'border-blue-200 dark:border-blue-700'
}

const cancelBtnClasses = {
  success: 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-800/30 hover:bg-emerald-200 dark:hover:bg-emerald-700/50',
  error: 'text-red-700 dark:text-red-300 bg-red-100 dark:bg-red-800/30 hover:bg-red-200 dark:hover:bg-red-700/50',
  warning: 'text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-800/30 hover:bg-amber-200 dark:hover:bg-amber-700/50',
  info: 'text-blue-700 dark:text-blue-300 bg-blue-100 dark:bg-blue-800/30 hover:bg-blue-200 dark:hover:bg-blue-700/50'
}

const actionBtnClasses = {
  success: 'text-white bg-emerald-600 hover:bg-emerald-700',
  error: 'text-white bg-red-600 hover:bg-red-700',
  warning: 'text-white bg-amber-600 hover:bg-amber-700',
  info: 'text-white bg-blue-600 hover:bg-blue-700'
}

const handleAction = async (notification) => {
  if (notification.action?.onClick) {
    await notification.action.onClick()
  }

  remove(notification.id)
}
</script>

<style scoped>
.notification-container-enter-active,
.notification-container-leave-active {
  transition: opacity 0.3s ease;
}

.notification-container-enter-from,
.notification-container-leave-to {
  opacity: 0;
}

.notification-enter-active {
  transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.notification-leave-active {
  transition: all 0.3s ease-out;
}

.notification-enter-from {
  opacity: 0;
  transform: translateX(100%) scale(0.8);
}

.notification-leave-to {
  opacity: 0;
  transform: translateX(100%) scale(0.9);
}

.notification-move {
  transition: transform 0.3s ease;
}
</style>
