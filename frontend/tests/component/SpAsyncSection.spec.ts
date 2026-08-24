// @vitest-environment nuxt
import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import SpAsyncSection from '~/components/SpAsyncSection.vue'

/**
 * `SpAsyncSection` is the single async surface every page in SP Cambo renders
 * through, so its state precedence is a product guarantee rather than a styling
 * detail: while data is loading, missing or broken, the customer must not be
 * shown the content slot, because on the commercial pages that slot holds
 * prices, balances, quotas and order state.
 */

/** What the default slot would hold on a real page. */
const LIVE = 'LIVE-COMMERCIAL-DATA'

const mount = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) =>
  mountSuspended(SpAsyncSection, {
    props,
    slots: { default: LIVE, ...slots }
  })

describe('SpAsyncSection state precedence', () => {
  it('renders the content slot only when nothing is loading, missing or broken', async () => {
    const section = await mount()

    expect(section.text()).toContain(LIVE)
  })

  it('shows the loading skeleton ahead of every other state', async () => {
    const section = await mount({
      loading: true,
      unavailable: true,
      failed: true,
      empty: true
    })

    expect(section.find('[role="status"]').exists()).toBe(true)
    expect(section.text()).toContain('Loading')
    expect(section.text()).not.toContain(LIVE)
  })

  it('prefers the honest unavailable state over a failure or an empty list', async () => {
    const section = await mount({
      unavailable: true,
      failed: true,
      empty: true,
      errorTitle: 'ERROR-TITLE'
    })

    expect(section.text()).toContain('Not available yet')
    expect(section.text()).not.toContain('ERROR-TITLE')
    expect(section.text()).not.toContain(LIVE)
  })

  it('describes a connectivity problem differently from a missing endpoint', async () => {
    const missing = await mount({ unavailable: true })
    const offline = await mount({ unavailable: true, offline: true })

    expect(missing.text()).toContain('has not published this endpoint yet')
    expect(offline.text()).toContain('could not be reached')
  })

  /**
   * Regression: an offline customer used to be told the endpoint had not shipped.
   *
   * Every commercial page passes its own unavailable copy, written about a
   * *missing route* — "the control plane has not shipped the orders endpoint".
   * That sentence used to win over the connectivity copy, so a customer whose
   * connection had dropped was told SP Cambo had not built the page yet: false,
   * and it points them away from the one thing they can actually fix.
   */
  it('does not let a page blame a missing endpoint for a dropped connection', async () => {
    const section = await mount({
      unavailable: true,
      offline: true,
      unavailableTitle: 'Order history is not published yet',
      unavailableDescription: 'The control plane has not shipped the orders endpoint.'
    })

    expect(section.text()).toContain('could not be reached')
    expect(section.text()).toContain('Check your connection')
    expect(section.text()).not.toContain('Order history is not published yet')
    expect(section.text()).not.toContain('has not shipped the orders endpoint')
  })

  /** The same copy must still be honoured when the endpoint really is the problem. */
  it('uses the page\'s own wording when the endpoint is genuinely missing', async () => {
    const section = await mount({
      unavailable: true,
      unavailableTitle: 'Order history is not published yet',
      unavailableDescription: 'The control plane has not shipped the orders endpoint.'
    })

    expect(section.text()).toContain('Order history is not published yet')
    expect(section.text()).toContain('has not shipped the orders endpoint')
    expect(section.text()).not.toContain('could not be reached')
  })

  /**
   * A 403 is an answer, not a fault: the endpoint exists and said no. Ranking it
   * ahead of `failed` matters because `useSpResource` raises both flags for the
   * same response, and the error surface would invite a retry that can only ever
   * be refused again.
   */
  it('prefers the forbidden state over a failure, because a 403 is an answer', async () => {
    const section = await mount({
      forbidden: true,
      failed: true,
      empty: true,
      errorTitle: 'ERROR-TITLE'
    })

    expect(section.text()).toContain('do not have access')
    expect(section.text()).not.toContain('ERROR-TITLE')
    expect(section.text()).not.toContain(LIVE)
  })

  it('yields to loading and to a missing endpoint, which say more', async () => {
    const loading = await mount({ loading: true, forbidden: true })
    const missing = await mount({ unavailable: true, forbidden: true })

    expect(loading.text()).not.toContain('do not have access')
    expect(missing.text()).toContain('has not published this endpoint yet')
    expect(missing.text()).not.toContain('do not have access')
  })

  /**
   * The control plane returns two 403 codes that mean opposite things about the
   * account. Telling an operator to grant a permission when the account is in
   * fact suspended would send them to fix the wrong thing.
   */
  it('distinguishes a missing permission from a suspended account', async () => {
    const unpermitted = await mount({ forbidden: true, forbiddenPermission: 'admin.view' })
    const suspended = await mount({
      forbidden: true,
      forbiddenPermission: 'admin.view',
      forbiddenCode: 'account_suspended'
    })

    expect(unpermitted.text()).toContain('does not hold the permission')
    expect(unpermitted.text()).toContain('admin.view')

    expect(suspended.text()).toContain('suspended')
    // Naming a permission here would suggest granting it would help. It would not.
    expect(suspended.text()).not.toContain('admin.view')
  })

  it('lets a page write its own forbidden copy', async () => {
    const section = await mount({
      forbidden: true,
      forbiddenTitle: 'Reseller tools are not enabled on this account',
      forbiddenDescription: 'CUSTOM-FORBIDDEN-DESCRIPTION'
    })

    expect(section.text()).toContain('Reseller tools are not enabled on this account')
    expect(section.text()).toContain('CUSTOM-FORBIDDEN-DESCRIPTION')
  })

  it('prefers a failure over an empty list', async () => {
    const section = await mount({
      failed: true,
      empty: true,
      errorTitle: 'Balance could not be loaded',
      errorMessage: 'The control plane rejected the request.'
    })

    expect(section.text()).toContain('Balance could not be loaded')
    expect(section.text()).toContain('The control plane rejected the request.')
    expect(section.text()).not.toContain(LIVE)
  })

  it('renders the empty state, and lets a page override it', async () => {
    const fallback = await mount({ empty: true, emptyTitle: 'No orders yet' })
    const overridden = await mount(
      { empty: true, emptyTitle: 'No orders yet' },
      { empty: 'CUSTOM-EMPTY' }
    )

    expect(fallback.text()).toContain('No orders yet')
    expect(fallback.text()).not.toContain(LIVE)
    expect(overridden.text()).toContain('CUSTOM-EMPTY')
    expect(overridden.text()).not.toContain('No orders yet')
  })

  it('never leaks the content slot in any non-content state', async () => {
    for (const flag of ['loading', 'unavailable', 'forbidden', 'failed', 'empty']) {
      const section = await mount({ [flag]: true })

      expect(section.text(), `${flag} state leaked the content slot`).not.toContain(LIVE)
    }
  })
})

/**
 * Every one of these states replaces a loading skeleton that announced itself as
 * "Loading" in a live region. When that skeleton is removed and a silent element
 * takes its place, a screen reader user's last announcement is still "Loading":
 * they are never told the read finished, never told it failed, and never told a
 * "Try again" button now exists. So each settled state has to announce itself.
 *
 * Which role is not arbitrary. A fault interrupts (`alert`); an answer — no
 * access, nothing here yet, endpoint not published — does not (`status`). That
 * mirrors the retry rule: `SpStateForbidden` offers no retry for the same reason
 * it does not interrupt.
 */
describe('SpAsyncSection announces settled states to assistive technology', () => {
  it('announces a failure assertively, since it is a fault with a recovery', async () => {
    const section = await mount({ failed: true, errorTitle: 'Balance could not be loaded' })

    const alert = section.find('[role="alert"]')

    expect(alert.exists()).toBe(true)
    expect(alert.text()).toContain('Balance could not be loaded')
    // Read together with the fault, so the user hears that recovery is possible.
    expect(alert.text()).toContain('Try again')
  })

  it('announces a missing endpoint politely rather than interrupting', async () => {
    const section = await mount({ unavailable: true })

    expect(section.find('[role="status"]').exists()).toBe(true)
    expect(section.find('[role="alert"]').exists()).toBe(false)
  })

  it('announces a dropped connection, and says it is the connection', async () => {
    const section = await mount({ unavailable: true, offline: true })

    expect(section.find('[role="status"]').text()).toContain('could not be reached')
  })

  it('announces a 403 without treating it as a fault', async () => {
    const section = await mount({ forbidden: true })

    expect(section.find('[role="status"]').text()).toContain('do not have access')
    expect(section.find('[role="alert"]').exists()).toBe(false)
  })

  it('announces an empty result, which is an answer and not a stall', async () => {
    const section = await mount({ empty: true, emptyTitle: 'No orders yet' })

    expect(section.find('[role="status"]').text()).toContain('No orders yet')
  })

  /** The state a user waits in, so it must be the one that is announced first. */
  it('announces that it is loading', async () => {
    const section = await mount({ loading: true })

    expect(section.find('[role="status"]').text()).toContain('Loading')
  })

  /** Content needs no announcement; a live region around it would narrate every update. */
  it('adds no live region once real content is on screen', async () => {
    const section = await mount()

    expect(section.find('[role="alert"]').exists()).toBe(false)
    expect(section.find('[role="status"]').exists()).toBe(false)
  })
})

describe('SpAsyncSection retry', () => {
  it('lets the customer ask for a retry when the endpoint is missing', async () => {
    const section = await mount({ unavailable: true })

    await section.find('button').trigger('click')

    expect(section.emitted('retry')).toHaveLength(1)
  })

  it('lets the customer ask for a retry after a failure', async () => {
    const section = await mount({ failed: true })

    await section.find('button').trigger('click')

    expect(section.emitted('retry')).toHaveLength(1)
  })

  /**
   * Nothing went wrong, so there is nothing to retry. Offering one would put the
   * customer in a loop against a decision only an operator can change.
   */
  it('offers no retry on a 403', async () => {
    const section = await mount({ forbidden: true })

    expect(section.find('button').exists()).toBe(false)
    expect(section.emitted('retry')).toBeUndefined()
  })

  it('offers no retry once real content is on screen', async () => {
    const section = await mount()

    expect(section.find('button').exists()).toBe(false)
  })
})
