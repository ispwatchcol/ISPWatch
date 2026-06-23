<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="visible"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @click.self="cancel"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6 m-4">
          <!-- Header -->
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
              <slot name="icon">
                <v-icon
                  :name="iconName"
                  class="w-6 h-6"
                  :class="iconColorClass"
                />
              </slot>
              {{ title }}
            </h2>
            <button
              @click="cancel"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
              <v-icon name="md-close" class="w-5 h-5" />
            </button>
          </div>

          <!-- Body -->
          <div class="mb-6">
            <slot>
              <div
                class="rounded-lg p-4 border"
                :class="alertClasses"
              >
                <p class="text-sm" :class="textClass">{{ message }}</p>
              </div>
            </slot>

            <!-- Type-to-confirm (optional) -->
            <div v-if="requireText" class="mt-4">
              <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">
                Escribe <span class="font-semibold">{{ requireText }}</span> para confirmar
              </label>
              <input
                v-model="typed"
                type="text"
                :placeholder="requireText"
                @keyup.enter="canConfirm && confirm()"
                class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 dark:text-white"
              />
            </div>
          </div>

          <!-- Footer -->
          <div class="flex justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="cancel"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
            >
              {{ cancelText }}
            </button>
            <button
              @click="confirm"
              :disabled="loading || !canConfirm"
              class="px-4 py-2 text-sm font-medium text-white rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
              :class="confirmBtnClass"
            >
              <v-icon v-if="loading" name="ri-loader-4-line" class="w-4 h-4 animate-spin" />
              {{ loading ? loadingText : confirmText }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: 'Confirmar' },
  message: { type: String, default: '¿Estás seguro de que deseas continuar?' },
  confirmText: { type: String, default: 'Confirmar' },
  cancelText: { type: String, default: 'Cancelar' },
  loadingText: { type: String, default: 'Procesando...' },
  loading: { type: Boolean, default: false },
  variant: { type: String, default: 'danger', validator: v => ['danger', 'warning', 'info'].includes(v) },
  // When set, the user must type this exact text before confirming (e.g. "ELIMINAR").
  requireText: { type: String, default: '' },
})

const emit = defineEmits(['confirm', 'cancel'])

const typed = ref('')
const canConfirm = computed(() => !props.requireText || typed.value.trim() === props.requireText)

// Reset the typed text whenever the modal is shown/hidden.
watch(() => props.visible, () => { typed.value = '' })

const iconName = computed(() => ({
  danger: 'md-delete',
  warning: 'md-warning',
  info: 'md-info',
}[props.variant]))

const iconColorClass = computed(() => ({
  danger: 'text-red-600',
  warning: 'text-yellow-600',
  info: 'text-blue-600',
}[props.variant]))

const alertClasses = computed(() => ({
  danger: 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800',
  warning: 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800',
  info: 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800',
}[props.variant]))

const textClass = computed(() => ({
  danger: 'text-red-600 dark:text-red-400',
  warning: 'text-yellow-600 dark:text-yellow-400',
  info: 'text-blue-600 dark:text-blue-400',
}[props.variant]))

const confirmBtnClass = computed(() => ({
  danger: 'bg-red-600 hover:bg-red-700',
  warning: 'bg-yellow-600 hover:bg-yellow-700',
  info: 'bg-blue-600 hover:bg-blue-700',
}[props.variant]))

function confirm() {
  if (props.loading || !canConfirm.value) return
  emit('confirm')
}

function cancel() {
  if (!props.loading) emit('cancel')
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>
