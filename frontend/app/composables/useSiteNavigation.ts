import type { NavigationMenuItem } from '@nuxt/ui'

/**
 * Single source of truth for public and authenticated navigation, shared by the
 * header, the mobile menu and the footer.
 */
export function useSiteNavigation() {
  const route = useRoute()
  const { canViewAdmin, canManageCatalog, canManageAccess, canManageReseller } = useSpPermissions()

  const publicLinks = computed<NavigationMenuItem[]>(() => [
    {
      label: 'Models',
      to: '/models',
      active: route.path === '/models'
    },
    {
      label: 'Pricing',
      to: '/pricing',
      active: route.path.startsWith('/pricing')
    },
    {
      label: 'Developers',
      to: '/developers',
      active: route.path === '/developers'
    },
    {
      label: 'Resellers',
      to: '/resellers',
      active: route.path === '/resellers'
    },
    {
      label: 'Docs',
      to: '/docs',
      active: route.path.startsWith('/docs')
    },
    {
      label: 'Status',
      to: '/status',
      active: route.path === '/status'
    }
  ])

  const dashboardLinks = computed<NavigationMenuItem[]>(() => {
    const links: NavigationMenuItem[] = [
      {
        label: 'Overview',
        icon: 'i-lucide-layout-dashboard',
        to: '/dashboard',
        active: route.path === '/dashboard'
      },
      {
        label: 'Buy tokens & credits',
        icon: 'i-lucide-package',
        to: '/dashboard/buy',
        active: route.path.startsWith('/dashboard/buy') || route.path.startsWith('/dashboard/checkout')
      },
      {
        label: 'API keys',
        icon: 'i-lucide-key-round',
        to: '/dashboard/api-keys',
        active: route.path.startsWith('/dashboard/api-keys')
      },
      {
        label: 'Entitlements & redeem',
        icon: 'i-lucide-gift',
        to: '/dashboard/entitlements',
        active: route.path.startsWith('/dashboard/entitlements')
      },
      {
        label: 'Refer & earn',
        icon: 'i-lucide-users-round',
        to: '/dashboard/referrals',
        active: route.path.startsWith('/dashboard/referrals')
      },
      {
        label: 'Usage & activity',
        icon: 'i-lucide-chart-line',
        to: '/dashboard/usage',
        active: route.path.startsWith('/dashboard/usage')
      },
      {
        label: 'Orders & payments',
        icon: 'i-lucide-receipt',
        to: '/dashboard/orders',
        active: route.path.startsWith('/dashboard/orders')
      },
      {
        label: 'API playground',
        icon: 'i-lucide-flask-conical',
        to: '/dashboard/playground',
        active: route.path.startsWith('/dashboard/playground')
      },
      {
        label: 'CLI setup',
        icon: 'i-lucide-terminal',
        to: '/dashboard/cli-setup',
        active: route.path.startsWith('/dashboard/cli-setup')
      },
      {
        label: 'Support',
        icon: 'i-lucide-circle-help',
        to: '/dashboard/support',
        active: route.path.startsWith('/dashboard/support')
      },
      {
        label: 'Telegram bot',
        icon: 'i-lucide-send',
        to: '/dashboard/telegram',
        active: route.path.startsWith('/dashboard/telegram')
      },
      {
        label: 'Account & security',
        icon: 'i-lucide-shield-check',
        to: '/dashboard/account',
        active: route.path.startsWith('/dashboard/account')
      }
    ]

    /*
     * Elevated surfaces are appended only for accounts the control plane says hold
     * the permission. This is discovery, not access control: the pages remain
     * reachable by URL and are protected by the server's 403. Hiding a link the
     * account may in fact use is the only failure mode, and it is the safe one.
     */
    if (canViewAdmin.value) {
      links.push({
        label: 'Platform overview',
        icon: 'i-lucide-shield',
        to: '/admin',
        // The catalogue pages have their own entries, so this must not claim them.
        active: route.path === '/admin'
      }, {
        label: 'Customers & access',
        icon: 'i-lucide-users-round',
        to: '/admin/access',
        active: route.path.startsWith('/admin/access')
      }, {
        label: 'Operations',
        icon: 'i-lucide-activity',
        to: '/admin/operations',
        active: route.path.startsWith('/admin/operations')
      }, {
        label: 'System health',
        icon: 'i-lucide-heart-pulse',
        to: '/admin/system-health',
        active: route.path.startsWith('/admin/system-health')
      }, {
        label: 'Audit log',
        icon: 'i-lucide-scroll-text',
        to: '/admin/audit-log',
        active: route.path.startsWith('/admin/audit-log')
      }, {
        label: 'Settings',
        icon: 'i-lucide-settings',
        to: '/admin/settings',
        active: route.path.startsWith('/admin/settings')
      })
    }

    if (canManageAccess.value) {
      links.push({
        label: 'Referral program',
        icon: 'i-lucide-badge-dollar-sign',
        to: '/admin/referrals',
        active: route.path.startsWith('/admin/referrals')
      })
    }

    if (canManageCatalog.value) {
      links.push({
        label: 'Packages',
        icon: 'i-lucide-package',
        to: '/admin/packages',
        active: route.path.startsWith('/admin/packages')
      }, {
        label: 'Model pricing',
        icon: 'i-lucide-route',
        to: '/admin/model-aliases',
        active: route.path.startsWith('/admin/model-aliases')
      }, {
        label: 'Providers',
        icon: 'i-lucide-server',
        to: '/admin/providers',
        active: route.path.startsWith('/admin/providers')
      }, {
        label: 'Promotions',
        icon: 'i-lucide-ticket-percent',
        to: '/admin/promotions',
        active: route.path.startsWith('/admin/promotions')
      }, {
        label: 'Playground settings',
        icon: 'i-lucide-flask-conical',
        to: '/admin/playground',
        active: route.path.startsWith('/admin/playground')
      }, {
        label: 'Redeem codes',
        icon: 'i-lucide-gift',
        to: '/admin/redeem-codes',
        active: route.path.startsWith('/admin/redeem-codes')
      }, {
        label: 'Telegram Store',
        icon: 'i-lucide-send',
        to: '/admin/telegram',
        active: route.path.startsWith('/admin/telegram')
      })
    }

    if (canManageReseller.value) {
      links.push({
        label: 'Managed customers',
        icon: 'i-lucide-users',
        to: '/reseller',
        // `/reseller/management-keys` has its own entry, so this one must not claim it.
        active: route.path === '/reseller' || route.path.startsWith('/reseller/customers')
      }, {
        label: 'Management keys',
        icon: 'i-lucide-terminal',
        to: '/reseller/management-keys',
        active: route.path.startsWith('/reseller/management-keys')
      })
    }

    return links
  })

  const footerColumns = computed(() => [
    {
      label: 'Product',
      children: [
        { label: 'Models', to: '/models' },
        { label: 'Pricing', to: '/pricing' },
        { label: 'For developers', to: '/developers' },
        { label: 'API key checker', to: '/public/key-checker' },
        { label: 'Resellers', to: '/resellers' }
      ]
    },
    {
      label: 'Developers',
      children: [
        { label: 'Quick start', to: '/docs/quick-start' },
        { label: 'Claude Code setup', to: '/docs/claude-code' },
        { label: 'Codex CLI setup', to: '/docs/codex-cli' },
        { label: 'API reference', to: '/docs/api-reference' }
      ]
    },
    {
      label: 'Platform',
      children: [
        { label: 'Service status', to: '/status' },
        { label: 'Rate limits', to: '/docs/rate-limits' },
        { label: 'Errors', to: '/docs/errors' },
        { label: 'Billing model', to: '/docs/billing' },
        { label: 'Support', to: '/support' }
      ]
    },
    {
      label: 'Legal',
      children: [
        { label: 'Terms of service', to: '/legal/terms' },
        { label: 'Acceptable use', to: '/legal/acceptable-use' },
        { label: 'Privacy', to: '/legal/privacy' }
      ]
    }
  ])

  return {
    publicLinks,
    dashboardLinks,
    footerColumns
  }
}
