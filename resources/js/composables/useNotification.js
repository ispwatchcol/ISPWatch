import { ref } from 'vue'

/**
 * Composable for toast notifications.
 * Usage:
 *   const { toast, success, error, warning, info } = useNotification()
 *   // In template: <NotificationToast ref="toast" />
 *   success('Title', 'Message')
 */
export function useNotification() {
    const toast = ref(null)

    function success(title, message) {
        toast.value?.success(title, message)
    }

    function error(title, message) {
        toast.value?.error(title, message)
    }

    function warning(title, message) {
        toast.value?.warning(title, message)
    }

    function info(title, message) {
        toast.value?.info(title, message)
    }

    return {
        toast,
        success,
        error,
        warning,
        info,
    }
}
