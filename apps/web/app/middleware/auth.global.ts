// Guests can only open the pages below; signed-in users skip login and register.
const guestPages = ['/login', '/register']
const publicPages = ['/status']

export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()

  if (!auth.checked) {
    await auth.fetchMe()
  }

  if (publicPages.includes(to.path)) {
    return
  }

  if (!auth.user && !guestPages.includes(to.path)) {
    return navigateTo('/login')
  }

  if (auth.user && guestPages.includes(to.path)) {
    return navigateTo('/')
  }

  // The sidebar and task forms need the workspace's modules.
  const modules = useModulesStore()
  if (auth.user && !modules.loaded) {
    await modules.load()
  }
})
