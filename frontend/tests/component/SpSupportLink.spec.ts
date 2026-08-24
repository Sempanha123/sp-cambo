// @vitest-environment nuxt
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { computed, nextTick } from 'vue'
import { resolveSupportChannel } from '~/utils/supportChannel'
import SpSupportLink from '~/components/SpSupportLink.vue'
import SpStateForbidden from '~/components/SpStateForbidden.vue'
import AuthCard from '~/components/AuthCard.vue'

/**
 * The support link, mounted for real.
 *
 * `resolveSupportChannel` is unit-tested on its own; what is asserted here is the
 * rendering decision built on top of it, and the decision that matters most is the
 * negative one. A deployment that has published no channel must render *nothing* —
 * not a disabled button, not a link to `#`, not "contact support" styled as a link.
 * A suspended customer cannot open any other page in the product, so an element that
 * looks like a way out and is not would be the cruellest thing on the screen.
 *
 * The channel is injected by mocking `useSupportChannel` rather than the whole of
 * `useRuntimeConfig`: `NuxtLink` and Nuxt UI read other properties off the runtime
 * config, and replacing all of it would make these tests fail for reasons that have
 * nothing to do with support.
 */

const channel = { value: null as string | null }

mockNuxtImport('useSupportChannel', () => () => computed(() => resolveSupportChannel(channel.value)))

enableAutoUnmount(afterEach)

afterEach(() => {
  channel.value = null
  vi.clearAllMocks()
})

describe('when the deployment publishes no channel', () => {
  it('renders nothing rather than a link with nowhere to go', async () => {
    const link = await mountSuspended(SpSupportLink)

    expect(link.text()).toBe('')
    expect(link.find('a').exists()).toBe(false)
    expect(link.find('button').exists()).toBe(false)
  })

  it('leaves the suspended-account surface exactly as it reads today', async () => {
    const page = await mountSuspended(SpStateForbidden, { props: { code: 'account_suspended' } })

    expect(page.text()).toContain('This account is suspended')
    // The copy still says to contact support; it just cannot say where. That is the
    // honest outcome, and better than naming an address nobody monitors.
    expect(page.text()).toContain('Contact SP Cambo support')
    expect(page.findAll('a').map(a => a.attributes('href'))).toEqual(['/dashboard'])
  })
})

describe('when an inbox is published', () => {
  it('links to it with mailto and shows the address', async () => {
    channel.value = 'help@spcambo.example'

    const link = await mountSuspended(SpSupportLink)
    const anchor = link.get('a')

    expect(anchor.attributes('href')).toBe('mailto:help@spcambo.example')
    expect(link.text()).toContain('help@spcambo.example')
  })

  it('opens in the same tab, so no empty tab is left behind by the mail client', async () => {
    channel.value = 'help@spcambo.example'

    const link = await mountSuspended(SpSupportLink)

    expect(link.get('a').attributes('target')).toBeUndefined()
  })

  it('gives the suspended customer somewhere to go', async () => {
    channel.value = 'help@spcambo.example'

    const page = await mountSuspended(SpStateForbidden, { props: { code: 'account_suspended' } })

    expect(page.findAll('a').map(a => a.attributes('href')))
      .toEqual(['mailto:help@spcambo.example', '/dashboard'])
  })
})

describe('when a page or chat handle is published', () => {
  it('opens in a new tab, so a half-finished form is not thrown away', async () => {
    channel.value = 'https://t.me/spcambo'

    const link = await mountSuspended(SpSupportLink)
    const anchor = link.get('a')

    expect(anchor.attributes('href')).toBe('https://t.me/spcambo')
    expect(anchor.attributes('target')).toBe('_blank')
  })

  it('shows the address rather than the scheme', async () => {
    channel.value = 'https://t.me/spcambo'

    const link = await mountSuspended(SpSupportLink)

    expect(link.text()).toContain('t.me/spcambo')
    expect(link.text()).not.toContain('https://')
  })
})

describe('a value that cannot reach anyone', () => {
  it('renders nothing, so a bad environment file cannot put a scheme in an href', async () => {
    channel.value = 'javascript:alert(document.cookie)'

    const link = await mountSuspended(SpSupportLink)

    expect(link.text()).toBe('')
    expect(link.find('a').exists()).toBe(false)
  })
})

describe('the inline variant', () => {
  it('reads as just the address, with the purpose left to the sentence around it', async () => {
    channel.value = 'help@spcambo.example'

    const link = await mountSuspended(SpSupportLink, { props: { variant: 'inline' } })

    expect(link.text()).toBe('help@spcambo.example')
    // The sentence carries the meaning visually; a screen reader gets it here.
    expect(link.get('a').attributes('aria-label')).toContain('Contact SP Cambo support')
  })
})

/**
 * The sign-in form is where a suspended customer finds out.
 *
 * `POST /auth/login` answers 403 `account_suspended` for a non-active account, so
 * this banner is reached before any page that could explain anything — and unlike
 * every other banner on this form, there is nothing the customer can do about it.
 */
describe('a suspended sign-in', () => {
  /** Mounts the login form with the store already holding the rejected response. */
  const rejectedLogin = async (code: 'account_suspended' | 'validation_failed') => {
    const card = await mountSuspended(AuthCard, { props: { mode: 'login' } })
    const auth = useAuthStore()

    auth.errorMessage = 'This account is not active.'
    auth.errorCode = code

    await nextTick()

    return card
  }

  it('names the failure instead of blaming the request, and offers the channel', async () => {
    channel.value = 'help@spcambo.example'

    const card = await rejectedLogin('account_suspended')

    expect(card.text()).toContain('This account is suspended')
    expect(card.text()).not.toContain('We couldn\'t complete that request')
    expect(card.find('a[href="mailto:help@spcambo.example"]').exists()).toBe(true)
  })

  it('still says what happened when no channel is published', async () => {
    const card = await rejectedLogin('account_suspended')

    expect(card.text()).toContain('This account is suspended')
    expect(card.find('a[href^="mailto:"]').exists()).toBe(false)
  })

  it('leaves every other failure titled as it was, with no support link', async () => {
    channel.value = 'help@spcambo.example'

    const card = await rejectedLogin('validation_failed')

    expect(card.text()).toContain('We couldn\'t complete that request')
    expect(card.find('a[href^="mailto:"]').exists()).toBe(false)
  })
})
