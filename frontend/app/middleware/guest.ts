/** Keeps signed-in customers out of the sign-in and registration flows. */
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()

  await auth.initialize()

  if (!auth.authenticated) {
    return
  }

  const redirect = typeof to.query.redirect === 'string' ? to.query.redirect : null

  // Only same-origin, absolute in-app paths are honoured; anything else could be
  // an open-redirect attempt.
  if (redirect && /^\/(?!\/)/.test(redirect)) {
    return navigateTo(redirect)
  }

  return navigateTo('/dashboard')
})
