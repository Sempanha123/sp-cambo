// @vitest-environment nuxt
import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import SpStatusBadge from '~/components/SpStatusBadge.vue'

/**
 * `SpStatusBadge` is the only place a backend status becomes words and colour, and
 * it renders states from four different domains, so two things have to hold.
 *
 * The first is that the request lifecycle states are told apart. A customer
 * scanning the request log is reading for one thing: what did this cost. `settled`
 * is the only state whose figures are final and charged, and `released` means the
 * reservation was returned and nothing was billed — rendering those two
 * identically turns "you were not charged" into a line that looks like a charge.
 *
 * The second is that an unrecognised status is humanised, never bent onto the
 * nearest known one. A new backend state must read as itself, because the
 * alternative is a badge that confidently states the wrong outcome.
 */

const mount = (status: string | null) => mountSuspended(SpStatusBadge, { props: { status } })

/** The rendered tone, as the class string Nuxt UI derives from the colour. */
const tone = async (status: string) => (await mount(status)).classes().join(' ')

describe('request lifecycle states', () => {
  it('labels every state the activity feed can report', async () => {
    const labels: Record<string, string> = {
      received: 'Received',
      reserved: 'Reserved',
      connecting: 'Connecting',
      streaming: 'Streaming',
      reconciling: 'Billing pending',
      settled: 'Settled',
      failed: 'Failed',
      released: 'Released'
    }

    for (const [status, label] of Object.entries(labels)) {
      const badge = await mount(status)

      expect(badge.text(), status).toBe(label)
    }
  })

  it('does not render a charged request and an uncharged one in the same tone', async () => {
    const settled = await tone('settled')
    const released = await tone('released')
    const failed = await tone('failed')

    expect(settled).not.toBe(released)
    expect(settled).not.toBe(failed)
    expect(released).not.toBe(failed)
  })

  it('marks an in-flight request as informational rather than as a settled one', async () => {
    for (const status of ['received', 'reserved', 'connecting', 'streaming']) {
      expect(await tone(status), status).not.toBe(await tone('settled'))
    }
  })

  it('marks reconciliation as non-final rather than as a settled charge', async () => {
    expect(await tone('reconciling')).not.toBe(await tone('settled'))
    expect(await tone('reconciling')).not.toBe(await tone('released'))
  })
})

describe('unknown statuses', () => {
  it('humanises a status it does not recognise instead of guessing at a known one', async () => {
    const badge = await mount('pending_review')

    expect(badge.text()).toBe('Pending review')
  })

  it('says Unknown rather than nothing when no status is reported', async () => {
    const badge = await mount(null)

    expect(badge.text()).toBe('Unknown')
  })
})
