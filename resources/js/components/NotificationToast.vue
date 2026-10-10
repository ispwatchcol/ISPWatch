<template>
  <!--
    Este componente YA NO PINTA NADA. Es un adaptador.

    Hasta ahora cada pantalla montaba el suyo, y cada instancia traía su propio
    contenedor `fixed` con su propio `z-index` y su propia lista. Eso es lo que
    hizo que un error de validación apareciera detrás del modal que lo provocó:
    el contenedor estaba en `z-[100]` y el modal en `z-[9999]`.

    El contenedor real es ahora `NotificationHost`, montado UNA vez en `App.vue`
    por encima de todo. Esto se queda para que las 37 pantallas que hacen
    `<NotificationToast ref="toast" />` y `toast.value?.success(...)` sigan
    funcionando sin tocarlas: expone la misma API y escribe en la cola común.

    EN CÓDIGO NUEVO no lo uses: importa `useNotifications()` directamente.
  -->
</template>

<script setup>
import { addNotification, clearNotifications } from '@/composables/useNotifications'

defineExpose({
  success: (title, message, options) => addNotification('success', title, message, options),
  error: (title, message, options) => addNotification('error', title, message, options),
  warning: (title, message, options) => addNotification('warning', title, message, options),
  info: (title, message, options) => addNotification('info', title, message, options),
  clear: () => clearNotifications(),
})
</script>
