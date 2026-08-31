<script setup lang="ts">
const { dashboardLinks } = useSiteNavigation()
const auth = useAuthStore()
const router = useRouter()

const navigationGroups = computed(() => {
  const groups = [
    {
      label: 'Main',
      test: (to: string) => ['/dashboard', '/dashboard/buy', '/dashboard/checkout', '/dashboard/usage', '/dashboard/orders', '/dashboard/entitlements', '/dashboard/referrals'].some(prefix => to === prefix || (prefix !== '/dashboard' && to.startsWith(prefix)))
    },
    {
      label: 'Developer',
      test: (to: string) => ['/dashboard/api-keys', '/dashboard/playground', '/dashboard/cli-setup'].some(prefix => to.startsWith(prefix))
    },
    {
      label: 'Support',
      test: (to: string) => ['/dashboard/support', '/dashboard/telegram', '/dashboard/account'].some(prefix => to.startsWith(prefix))
    },
    {
      label: 'Platform',
      test: (to: string) => to.startsWith('/admin')
    },
    {
      label: 'Reseller',
      test: (to: string) => to.startsWith('/reseller')
    }
  ]

  const items = dashboardLinks.value
  return groups
    .map(group => ({
      label: group.label,
      items: items.filter(item => group.test(String(item.to ?? '')))
    }))
    .filter(group => group.items.length > 0)
})

const signOut = async () => {
  await auth.logout()
  await router.push('/login')
}

const accountItems = computed(() => [
  [
    {
      label: auth.user?.name ?? 'Account',
      type: 'label' as const
    }
  ],
  [
    {
      label: 'Account & security',
      icon: 'i-lucide-shield-check',
      to: '/dashboard/account'
    },
    {
      label: 'Settings',
      icon: 'i-lucide-settings',
      to: '/dashboard/settings'
    },
    {
      label: 'Documentation',
      icon: 'i-lucide-book-open',
      to: '/docs'
    },
    {
      label: 'Public site',
      icon: 'i-lucide-external-link',
      to: '/'
    }
  ],
  [
    {
      label: 'Sign out',
      icon: 'i-lucide-log-out',
      onSelect: signOut
    }
  ]
])
</script>

<template>
  <div class="sp-r4-layout sp-r4-dashboard-layout sp-dashboard-shell">
    <div class="sp-r4-dashboard-grid" aria-hidden="true" />
    <div class="sp-r4-dashboard-orb sp-r4-dashboard-orb--a" aria-hidden="true" />
    <div class="sp-r4-dashboard-orb sp-r4-dashboard-orb--b" aria-hidden="true" />

    <UDashboardGroup storage-key="sp-cambo-dashboard" class="relative z-[1]">
      <UDashboardSidebar
        collapsible
        resizable
        :min-size="14"
        :default-size="17"
        :max-size="24"
        class="sp-dashboard-sidebar sp-r4-dashboard-sidebar"
      >
        <template #header="{ collapsed }">
          <NuxtLink
            to="/dashboard"
            class="flex min-w-0 items-center gap-2.5"
            aria-label="SP Cambo dashboard"
          >
            <SpLogo :mark-only="collapsed" />
            <span
              v-if="!collapsed"
              class="sp-khmer-chip shrink-0"
            >កម្ពុជា</span>
          </NuxtLink>

          <UDashboardSidebarCollapse class="ml-auto" />
        </template>

        <template #default="{ collapsed }">
          <div class="sp-dashboard-nav-groups">
            <section v-for="group in navigationGroups" :key="group.label" class="sp-dashboard-nav-group">
              <p v-if="!collapsed" class="sp-dashboard-nav-label">{{ group.label }}</p>
              <UNavigationMenu
                :items="group.items"
                :collapsed="collapsed"
                orientation="vertical"
                :tooltip="collapsed"
                class="sp-dashboard-nav-menu"
              />
            </section>
          </div>
        </template>

        <template #footer="{ collapsed }">
          <UDropdownMenu
            :items="accountItems"
            :content="{ align: 'start', side: 'top' }"
            class="w-full"
          >
            <UButton
              color="neutral"
              variant="subtle"
              :block="!collapsed"
              :square="collapsed"
              :label="collapsed ? undefined : (auth.user?.name ?? 'Account')"
              :trailing-icon="collapsed ? undefined : 'i-lucide-chevron-down'"
              icon="i-lucide-user"
              :ui="{ label: 'truncate', base: collapsed ? undefined : 'justify-start' }"
            />
          </UDropdownMenu>
        </template>
      </UDashboardSidebar>

      <slot />
    </UDashboardGroup>
  </div>
</template>
