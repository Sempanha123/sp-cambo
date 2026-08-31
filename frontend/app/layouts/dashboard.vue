<script setup lang="ts">
import '~/assets/css/sp-dashboard-r9.css'

const { dashboardLinks } = useSiteNavigation()
const auth = useAuthStore()
const router = useRouter()

/**
 * R11 sidebar behavior
 *
 * - `sidebarCollapsed` is the real persisted/pinned sidebar state.
 * - Hovering the left edge DOES NOT mutate the real collapsed state.
 * - A separate overlay preview opens smoothly while collapsed.
 * - Clicking the edge handle pins the real sidebar open.
 * - A dedicated collapse button is always visible while expanded.
 */
const sidebarCollapsed = ref(false)
const hoverPreview = ref(false)
let previewCloseTimer: ReturnType<typeof setTimeout> | undefined

const clearPreviewTimer = () => {
  if (previewCloseTimer) {
    clearTimeout(previewCloseTimer)
    previewCloseTimer = undefined
  }
}

const openPreview = () => {
  if (!sidebarCollapsed.value) return

  clearPreviewTimer()
  hoverPreview.value = true
}

const keepPreviewOpen = () => {
  clearPreviewTimer()
}

const closePreview = () => {
  clearPreviewTimer()
  hoverPreview.value = false
}

const closePreviewSoon = () => {
  if (!hoverPreview.value) return

  clearPreviewTimer()
  previewCloseTimer = setTimeout(() => {
    hoverPreview.value = false
  }, 180)
}

const expandSidebar = () => {
  clearPreviewTimer()
  hoverPreview.value = false
  sidebarCollapsed.value = false
}

const collapseSidebar = () => {
  clearPreviewTimer()
  hoverPreview.value = false
  sidebarCollapsed.value = true
}

const toggleSidebar = () => {
  if (sidebarCollapsed.value) {
    expandSidebar()
  } else {
    collapseSidebar()
  }
}

onBeforeUnmount(clearPreviewTimer)

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

const closePreviewAfterNavigation = () => {
  hoverPreview.value = false
}
</script>

<template>
  <div
    class="sp-r9-dashboard-shell sp-r11-dashboard-shell sp-dashboard-shell"
    :class="{
      'sp-r11-dashboard-shell--collapsed': sidebarCollapsed,
      'sp-r11-dashboard-shell--previewing': hoverPreview
    }"
  >
    <div class="sp-r9-dashboard-atmosphere" aria-hidden="true">
      <div class="sp-r9-dashboard-atmosphere__grid" />
      <div class="sp-r9-dashboard-atmosphere__glow sp-r9-dashboard-atmosphere__glow--a" />
      <div class="sp-r9-dashboard-atmosphere__glow sp-r9-dashboard-atmosphere__glow--b" />
      <div class="sp-r9-dashboard-atmosphere__line sp-r9-dashboard-atmosphere__line--a" />
      <div class="sp-r9-dashboard-atmosphere__line sp-r9-dashboard-atmosphere__line--b" />
    </div>

    <!-- Invisible desktop hover rail. -->
    <div
      v-if="sidebarCollapsed"
      class="sp-r11-sidebar-edge-zone hidden lg:block"
      aria-hidden="true"
      @mouseenter="openPreview"
    />

    <!-- Persistent discoverability handle while collapsed. -->
    <button
      v-if="sidebarCollapsed"
      type="button"
      class="sp-r11-sidebar-edge-trigger hidden lg:flex"
      aria-label="Open navigation sidebar"
      title="Open navigation"
      @mouseenter="openPreview"
      @focus="openPreview"
      @click="expandSidebar"
    >
      <span class="sp-r11-sidebar-edge-trigger__pulse" />
      <UIcon
        name="i-lucide-panel-left-open"
        class="size-4"
      />
      <span class="sp-r11-sidebar-edge-trigger__label">
        Menu
      </span>
    </button>

    <!--
      Smooth hover-preview overlay.
      Important: this does NOT alter `sidebarCollapsed`, so the main content
      never resizes/jumps when the user only hovers.
    -->
    <Transition name="sp-r11-sidebar-preview">
      <aside
        v-if="sidebarCollapsed && hoverPreview"
        class="sp-r11-sidebar-preview hidden lg:flex"
        @mouseenter="keepPreviewOpen"
        @mouseleave="closePreviewSoon"
      >
        <div class="sp-r11-sidebar-preview__header">
          <NuxtLink
            to="/dashboard"
            class="flex min-w-0 items-center gap-2.5"
            aria-label="SP Cambo dashboard"
            @click="closePreviewAfterNavigation"
          >
            <SpLogo />
            <span class="sp-khmer-chip shrink-0">កម្ពុជា</span>
          </NuxtLink>

          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            square
            icon="i-lucide-panel-left-open"
            aria-label="Pin sidebar open"
            title="Pin sidebar open"
            class="sp-r11-sidebar-control"
            @click="expandSidebar"
          />
        </div>

        <div class="sp-r11-sidebar-preview__nav">
          <section
            v-for="group in navigationGroups"
            :key="group.label"
            class="sp-dashboard-nav-group"
          >
            <p class="sp-dashboard-nav-label">
              {{ group.label }}
            </p>

            <UNavigationMenu
              :items="group.items"
              orientation="vertical"
              class="sp-dashboard-nav-menu sp-r9-dashboard-nav"
              @click="closePreviewAfterNavigation"
            />
          </section>
        </div>

        <div class="sp-r11-sidebar-preview__footer">
          <UDropdownMenu
            :items="accountItems"
            :content="{ align: 'start', side: 'top' }"
            class="w-full"
          >
            <UButton
              color="neutral"
              variant="subtle"
              block
              :label="auth.user?.name ?? 'Account'"
              trailing-icon="i-lucide-chevron-down"
              icon="i-lucide-user-round"
              :ui="{ label: 'truncate', base: 'justify-start' }"
              class="sp-r9-account-button"
            />
          </UDropdownMenu>
        </div>
      </aside>
    </Transition>

    <UDashboardGroup
      storage-key="sp-cambo-dashboard"
      class="sp-r9-dashboard-group sp-r11-dashboard-group relative z-[1]"
    >
      <UDashboardSidebar
        v-model:collapsed="sidebarCollapsed"
        collapsible
        resizable
        :min-size="14"
        :default-size="17"
        :max-size="24"
        :collapsed-size="0"
        class="sp-dashboard-sidebar sp-r9-dashboard-sidebar sp-r11-dashboard-sidebar"
      >
        <template #header="{ collapsed }">
          <NuxtLink
            to="/dashboard"
            class="sp-r9-sidebar-brand flex min-w-0 items-center gap-2.5"
            aria-label="SP Cambo dashboard"
          >
            <SpLogo :mark-only="collapsed" />
            <span
              v-if="!collapsed"
              class="sp-khmer-chip shrink-0"
            >
              កម្ពុជា
            </span>
          </NuxtLink>

          <!--
            Dedicated R11 collapse/expand control.
            This replaces dependency on the built-in collapse action so there
            is always an obvious way to collapse the expanded sidebar again.
          -->
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            square
            :icon="collapsed ? 'i-lucide-panel-left-open' : 'i-lucide-panel-left-close'"
            :aria-label="collapsed ? 'Expand navigation sidebar' : 'Collapse navigation sidebar'"
            :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            class="sp-r11-sidebar-control ml-auto"
            @click="toggleSidebar"
          />
        </template>

        <template #default="{ collapsed }">
          <div class="sp-dashboard-nav-groups sp-r9-sidebar-nav">
            <section
              v-for="group in navigationGroups"
              :key="group.label"
              class="sp-dashboard-nav-group"
            >
              <p
                v-if="!collapsed"
                class="sp-dashboard-nav-label"
              >
                {{ group.label }}
              </p>

              <UNavigationMenu
                :items="group.items"
                :collapsed="collapsed"
                orientation="vertical"
                :tooltip="collapsed"
                class="sp-dashboard-nav-menu sp-r9-dashboard-nav"
              />
            </section>
          </div>
        </template>

        <template #footer="{ collapsed }">
          <div class="sp-r9-sidebar-account">
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
                icon="i-lucide-user-round"
                :ui="{ label: 'truncate', base: collapsed ? undefined : 'justify-start' }"
                class="sp-r9-account-button"
              />
            </UDropdownMenu>
          </div>
        </template>
      </UDashboardSidebar>

      <slot />
    </UDashboardGroup>
  </div>
</template>
