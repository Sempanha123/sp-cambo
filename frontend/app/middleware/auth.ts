/**
 * Guards authenticated surfaces. `/dashboard/**` is client-rendered, so this
 * runs in the browser and can safely await the authoritative `/me` lookup.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()

  await auth.initialize()

  if (!auth.authenticated) {
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
  }
})
