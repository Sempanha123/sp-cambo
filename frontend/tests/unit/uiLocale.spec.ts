import { describe, expect, it } from 'vitest'
import { spCamboLocale } from '~/utils/uiLocale'

describe('SP Cambo UI locale', () => {
  it('supplies human-facing mobile dashboard navigation copy', () => {
    expect(spCamboLocale.messages.dashboardSidebar).toEqual({
      title: 'Dashboard navigation',
      description: 'Navigate your SP Cambo account.'
    })
  })

  it('retains Nuxt UI English defaults outside the product extension', () => {
    expect(spCamboLocale.messages.dashboardSidebarToggle.open).toBe('Open sidebar')
    expect(spCamboLocale.messages.dashboardSidebarCollapse.collapse).toBe('Collapse sidebar')
  })
})
