/**
 * Permission checks for the elevated surfaces (admin, catalogue, reseller).
 *
 * These are *presentation* checks only. The control plane enforces
 * `admin.view`, `catalog.manage`, `access.manage` and `reseller.manage` on every route, so a wrong
 * answer here can hide a link or show one that 403s — it can never grant access.
 *
 * `GET /me` publishes role names and effective permissions for discovery.
 * The pages remain reachable by URL and render the server's `forbidden`
 * response honestly if a stale frontend state ever disagrees with the backend.
 */
export function useSpPermissions() {
  const auth = useAuthStore()

  const permissions = computed(() => auth.user?.permissions ?? [])
  const roles = computed(() => auth.user?.roles ?? [])

  /** True once the authoritative authenticated user has been loaded. */
  const published = computed(() => auth.user !== null)

  const can = (permission: string) => permissions.value.includes(permission)
  const hasRole = (role: string) => roles.value.includes(role)

  /**
   * A role name is accepted as a fallback so the surfaces still light up if the
   * control plane publishes `roles` without a flattened `permissions` list.
   */
  const canViewAdmin = computed(() => can('admin.view') || hasRole('ADMIN') || hasRole('SUPER_ADMIN'))
  const canManageReseller = computed(() => can('reseller.manage') || hasRole('RESELLER'))

  /**
   * Catalogue management is a distinct permission from `admin.view`, and the
   * control plane guards the two sets of routes separately. `ADMIN` is not
   * assumed to imply it — only `SUPER_ADMIN`, which is unconditional by
   * definition — so an analytics-only admin is not shown a link that would 403.
   */
  const canManageCatalog = computed(() => can('catalog.manage') || hasRole('SUPER_ADMIN'))
  const canManageAccess = computed(() => can('access.manage') || hasRole('SUPER_ADMIN'))

  return {
    permissions,
    roles,
    published,
    can,
    hasRole,
    canViewAdmin,
    canManageCatalog,
    canManageAccess,
    canManageReseller
  }
}
