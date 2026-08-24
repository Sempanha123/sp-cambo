// @vitest-environment nuxt
import { afterEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import SpApiKeyRevealModal from '~/components/SpApiKeyRevealModal.vue'

/**
 * One-time reveal is the strictest rule in the product: a full inference key is
 * rendered exactly once, at creation or rotation, and is never re-fetchable. The
 * tests below therefore assert on the *secret* rather than on the markup — that
 * it is visible when the customer needs to copy it, that it can be hidden, that
 * the dialog cannot be dismissed by accident before it is stored, and that it
 * never reaches storage or the address bar.
 *
 * The value below is a test fixture. It is not, and must never be, a real key.
 */
const SECRET = 'sk-spc-test-0000000000000000000000000000'

enableAutoUnmount(afterEach)

/** UModal teleports to the document body, so assertions read the body. */
const bodyText = () => document.body.textContent ?? ''
const bodyHtml = () => document.body.innerHTML

const mount = (props: Partial<InstanceType<typeof SpApiKeyRevealModal>['$props']> = {}) =>
  mountSuspended(SpApiKeyRevealModal, {
    props: {
      open: true,
      secret: SECRET,
      keyLabel: 'Production key',
      context: 'created',
      ...props
    }
  })

/** The reveal controls live in the teleported dialog, not under the wrapper root. */
const findByText = (label: string) =>
  [...document.body.querySelectorAll('button')]
    .find(button => (button.textContent ?? '').trim().includes(label))

const doneButton = () =>
  [...document.body.querySelectorAll('button')]
    .find(button => (button.textContent ?? '').trim() === 'Done')

const acknowledge = async () => {
  const checkbox = document.body.querySelector<HTMLElement>('[role="checkbox"], input[type="checkbox"]')
  checkbox?.click()
  await nextTick()
}

describe('SpApiKeyRevealModal reveal', () => {
  it('shows the full secret so the customer can copy it', async () => {
    await mount()

    expect(bodyText()).toContain(SECRET)
  })

  it('removes the secret from the DOM entirely when hidden', async () => {
    await mount()

    findByText('Hide')?.click()
    await nextTick()

    expect(bodyText()).not.toContain(SECRET)
    expect(bodyHtml()).not.toContain(SECRET)
    expect(bodyText()).toContain('•')
  })

  it('renders nothing at all when there is no secret to reveal', async () => {
    await mount({ open: false })

    expect(bodyText()).not.toContain(SECRET)
  })

  it('states that the secret is shown once and stored only as a hash', async () => {
    await mount()

    expect(bodyText()).toContain('Shown once and never again')
    expect(bodyText()).toContain('hash')
  })

  it('explains a rotation differently from a creation, because the old key just died', async () => {
    await mount({ context: 'rotated' })

    expect(bodyText()).toContain('New secret for this key')
    expect(bodyText()).toContain('stopped working')
  })
})

describe('SpApiKeyRevealModal dismissal', () => {
  it('will not let the dialog be closed before the customer confirms they stored the key', async () => {
    await mount()

    expect(doneButton()?.hasAttribute('disabled')).toBe(true)
  })

  it('enables Done once the acknowledgement is ticked', async () => {
    await mount()
    await acknowledge()

    expect(doneButton()?.hasAttribute('disabled')).toBe(false)
  })

  it('closes through the explicit Done control only', async () => {
    const modal = await mount()
    await acknowledge()

    doneButton()?.click()
    await nextTick()

    expect(modal.emitted('update:open')).toEqual([[false]])
    expect(modal.emitted('close')).toHaveLength(1)
  })

  it('offers no dismiss affordance that could lose the secret', async () => {
    await mount()

    // `:dismissible="false" :close="false"` — no overlay click-away and no X.
    expect(document.body.querySelector('[aria-label="Close"]')).toBeNull()
  })

  it('requires a fresh acknowledgement each time a new secret is revealed', async () => {
    const modal = await mount()
    await acknowledge()

    expect(doneButton()?.hasAttribute('disabled')).toBe(false)

    await modal.setProps({ open: false })
    await modal.setProps({ open: true, secret: `${SECRET}-second`, context: 'rotated' })

    expect(doneButton()?.hasAttribute('disabled')).toBe(true)
  })
})

describe('SpApiKeyRevealModal secret containment', () => {
  it('never writes the secret to web storage', async () => {
    await mount()

    for (const store of [globalThis.localStorage, globalThis.sessionStorage]) {
      if (!store) {
        continue
      }

      for (let index = 0; index < store.length; index += 1) {
        const key = store.key(index)

        expect(key).not.toContain(SECRET)
        expect(store.getItem(key ?? '')).not.toContain(SECRET)
      }
    }
  })

  it('never places the secret in the address bar', async () => {
    await mount()

    expect(window.location.href).not.toContain(SECRET)
  })

  it('never puts the secret in a link, a form action or an image source', async () => {
    await mount()

    for (const element of document.body.querySelectorAll('[href], [action], [src]')) {
      for (const attribute of ['href', 'action', 'src']) {
        expect(element.getAttribute(attribute) ?? '').not.toContain(SECRET)
      }
    }
  })
})
