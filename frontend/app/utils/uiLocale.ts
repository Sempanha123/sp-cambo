import { extendLocale } from '@nuxt/ui/composables'
import { en } from '@nuxt/ui/locale'

/**
 * Nuxt UI's English locale declares dashboard-sidebar copy as optional, but the
 * component always requests it for the mobile navigation dialog. Supply the
 * product wording here so a missing library default can never surface as a raw
 * translation key.
 */
export const spCamboLocale = extendLocale(en, {
  messages: {
    dashboardSidebar: {
      title: 'Dashboard navigation',
      description: 'Navigate your SP Cambo account.'
    }
  }
})
