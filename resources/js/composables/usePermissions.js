import { useAuthStore } from '@/stores/auth'

export function usePermissions() {
  const auth = useAuthStore()

  const can    = (permission)  => auth.hasPermission(permission)
  const canAny = (permissions) => permissions.some(p  => auth.hasPermission(p))
  const canAll = (permissions) => permissions.every(p => auth.hasPermission(p))

  return { can, canAny, canAll }
}
