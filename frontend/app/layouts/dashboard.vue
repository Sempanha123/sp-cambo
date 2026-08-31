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
  <div class="sp-r4-layout sp-r4-dashboard-layout sp-dashboard-shell sp-r6-dashboard-shell">
    <div class="sp-r4-dashboard-grid" aria-hidden="true" />
    <div class="sp-r4-dashboard-orb sp-r4-dashboard-orb--a" aria-hidden="true" />
    <div class="sp-r4-dashboard-orb sp-r4-dashboard-orb--b" aria-hidden="true" />

    <!--
      R6 keeps the dashboard itself at viewport height.
      Individual panels own their vertical scroll instead of making the entire
      document grow. This keeps the account/profile control visible.
    -->
    <UDashboardGroup
      storage-key="sp-cambo-dashboard"
      class="sp-r6-dashboard-group relative z-[1]"
    >
      <UDashboardSidebar
        collapsible
        resizable
        :min-size="14"
        :default-size="17"
        :max-size="24"
        class="sp-dashboard-sidebar sp-r4-dashboard-sidebar sp-r6-dashboard-sidebar"
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
          <!-- Only navigation scrolls when the sidebar has many links. -->
          <div class="sp-dashboard-nav-groups sp-r6-sidebar-nav">
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
                class="sp-dashboard-nav-menu"
              />
            </section>
          </div>
        </template>

        <template #footer="{ collapsed }">
          <!--
            This footer is always part of the viewport-height sidebar.
            It no longer sits after the full dashboard page content.
          -->
          <div class="sp-r6-sidebar-account">
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
          </div>
        </template>
      </UDashboardSidebar>

      <slot />
    </UDashboardGroup>
  </div>
</template>

<style>
/* ========================================================================== */
/* SP Cambo Dashboard Responsive R6                                           */
/* ========================================================================== */

/*
 * Critical layout rule:
 * The dashboard group stays inside the current viewport. UDashboardPanel body
 * can scroll, but the sidebar/profile control does not move with page content.
 */
.sp-r6-dashboard-shell {
  width: 100%;
  height: 100dvh;
  min-height: 0 !important;
  max-height: 100dvh;
  overflow: hidden;
}

.sp-r6-dashboard-group {
  width: 100%;
  height: 100dvh;
  min-height: 0;
  max-height: 100dvh;
  overflow: hidden;
}

.sp-r6-dashboard-group > * {
  min-width: 0;
}

/* Desktop sidebar always occupies the viewport, not the document height. */
.sp-r6-dashboard-sidebar {
  position: sticky !important;
  top: 0;
  align-self: flex-start;
  height: 100dvh !important;
  min-height: 0 !important;
  max-height: 100dvh !important;
  overflow: hidden;
}

/* Navigation may scroll independently if it becomes taller than the sidebar. */
.sp-r6-sidebar-nav {
  min-width: 0;
  min-height: 0;
  max-height: calc(100dvh - 9.25rem);
  overflow-x: hidden;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color:
    color-mix(in oklab, var(--ui-primary) 22%, transparent)
    transparent;
}

.sp-r6-sidebar-nav::-webkit-scrollbar {
  width: 4px;
}

.sp-r6-sidebar-nav::-webkit-scrollbar-thumb {
  border-radius: 9999px;
  background: color-mix(in oklab, var(--ui-primary) 22%, transparent);
}

/* Profile/account stays visible at the bottom of the sidebar. */
.sp-r6-sidebar-account {
  position: relative;
  z-index: 8;
  flex: 0 0 auto;
  width: 100%;
  padding-bottom: max(.25rem, env(safe-area-inset-bottom));
  background:
    linear-gradient(
      to top,
      color-mix(in oklab, var(--ui-bg) 92%, transparent),
      color-mix(in oklab, var(--ui-bg) 74%, transparent) 72%,
      transparent
    );
  backdrop-filter: blur(14px);
}

/* -------------------------------------------------------------------------- */
/* PANEL WIDTH / PHONE OVERFLOW FIX                                           */
/* -------------------------------------------------------------------------- */

/*
 * Flex/grid children default to min-width:auto. A wide package card/filter can
 * therefore make the dashboard panel wider than a phone. Force every important
 * dashboard layer to be shrinkable.
 */
.sp-r6-dashboard-group .sp-dashboard-page,
.sp-r6-dashboard-group .sp-dashboard-content,
.sp-r6-dashboard-group .sp-dashboard-content > *,
.sp-r6-dashboard-group .sp-dashboard-content section,
.sp-r6-dashboard-group .sp-dashboard-content aside,
.sp-r6-dashboard-group .sp-dashboard-content ul,
.sp-r6-dashboard-group .sp-dashboard-content li {
  min-width: 0 !important;
  max-width: 100%;
}

.sp-r6-dashboard-group .sp-dashboard-page {
  width: 100%;
  max-width: 100%;
  overflow: hidden;
}

.sp-r6-dashboard-group .sp-dashboard-content {
  width: 100%;
  overflow-x: clip;
}

/*
 * The Buy page package cards already have this class. Make all text/value rows
 * shrink correctly instead of pushing the card beyond the viewport.
 */
.sp-r6-dashboard-group .sp-catalog-option {
  min-width: 0;
  max-width: 100%;
  overflow: hidden;
}

.sp-r6-dashboard-group .sp-catalog-option > * {
  min-width: 0;
  max-width: 100%;
}

.sp-r6-dashboard-group .sp-catalog-option dl > div {
  min-width: 0;
}

.sp-r6-dashboard-group .sp-catalog-option dd {
  min-width: 0;
  max-width: 68%;
  text-align: right;
  overflow-wrap: anywhere;
}

/*
 * Any dashboard grid must be allowed to shrink. This specifically fixes the
 * Buy page's package grid and its desktop order-summary grid.
 */
.sp-r6-dashboard-group .sp-dashboard-content .grid {
  min-width: 0;
}

.sp-r6-dashboard-group .sp-dashboard-content button,
.sp-r6-dashboard-group .sp-dashboard-content a,
.sp-r6-dashboard-group .sp-dashboard-content input,
.sp-r6-dashboard-group .sp-dashboard-content textarea,
.sp-r6-dashboard-group .sp-dashboard-content select {
  max-width: 100%;
}

/* -------------------------------------------------------------------------- */
/* PHONE                                                                      */
/* -------------------------------------------------------------------------- */

@media (max-width: 639px) {
  .sp-r6-dashboard-shell,
  .sp-r6-dashboard-group {
    width: 100dvw;
    max-width: 100dvw;
  }

  /*
   * UDashboardPanel becomes the whole phone width after the sidebar is hidden
   * behind the hamburger/drawer.
   */
  .sp-r6-dashboard-group .sp-dashboard-page {
    width: 100dvw;
    min-width: 0 !important;
    max-width: 100dvw;
  }

  .sp-r6-dashboard-group .sp-dashboard-content {
    box-sizing: border-box;
    width: 100%;
    max-width: 100%;
    padding-inline: .75rem;
    padding-bottom: max(1.25rem, env(safe-area-inset-bottom));
    overflow-x: hidden;
  }

  .sp-r6-dashboard-group .sp-dashboard-intro {
    padding: .875rem;
    border-radius: 1rem;
  }

  .sp-r6-dashboard-group .sp-page-lead-icon {
    width: 2.25rem;
    height: 2.25rem;
  }

  /*
   * Package cards become compact without hiding their values.
   * Descriptions can wrap rather than determining a wider minimum width.
   */
  .sp-r6-dashboard-group .sp-catalog-option {
    padding: .875rem !important;
    border-radius: 1rem !important;
  }

  .sp-r6-dashboard-group .sp-catalog-option p.truncate {
    white-space: normal;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  .sp-r6-dashboard-group .sp-catalog-option dl > div {
    align-items: flex-start;
    gap: .75rem;
  }

  .sp-r6-dashboard-group .sp-catalog-option dd {
    max-width: 62%;
  }

  /*
   * Family/package filters use wrapping rows on phones. The old horizontal
   * crop happened because an ancestor kept a desktop minimum width.
   */
  .sp-r6-dashboard-group .sp-dashboard-content .flex.flex-wrap {
    min-width: 0;
    max-width: 100%;
  }

  .sp-r6-dashboard-group .sp-dashboard-content .flex.flex-wrap > * {
    min-width: 0;
  }

  /* Dropdown needs room above the browser safe area. */
  .sp-r6-sidebar-account {
    padding-bottom: max(.5rem, env(safe-area-inset-bottom));
  }
}

/* Tablet: keep content shrinkable before the two-column package breakpoint. */
@media (min-width: 640px) and (max-width: 1023px) {
  .sp-r6-dashboard-group .sp-dashboard-content {
    padding-inline: 1rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .sp-r6-dashboard-shell *,
  .sp-r6-dashboard-shell *::before,
  .sp-r6-dashboard-shell *::after {
    scroll-behavior: auto !important;
  }
}
</style>
