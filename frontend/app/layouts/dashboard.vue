<script setup lang="ts">
const { dashboardLinks } = useSiteNavigation()
const auth = useAuthStore()
const router = useRouter()

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
  <div class="sp-dashboard-shell">
    <UDashboardGroup storage-key="sp-cambo-dashboard">
      <UDashboardSidebar
        collapsible
        resizable
        :min-size="14"
        :default-size="17"
        :max-size="24"
        class="sp-dashboard-sidebar"
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
          <div
            v-if="!collapsed"
            class="mx-1 mb-3 rounded-lg border border-white/10 bg-white/5 backdrop-blur-md px-3 py-2.5 transition-all duration-300 hover:bg-white/10"
          >
            <p class="text-[10px] font-semibold tracking-[0.16em] text-dimmed uppercase">
              Customer workspace
            </p>
            <p class="mt-1 truncate text-xs text-muted">
              {{ auth.user?.email ?? 'Secure AI access' }}
            </p>
          </div>

          <UNavigationMenu
            :items="dashboardLinks"
            :collapsed="collapsed"
            orientation="vertical"
            :tooltip="collapsed"
            class="-mx-1.5"
          />
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
