// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { ApiKeyCreated, ApiKeySummary } from '~/types/commerce'
import { SpApiError } from '~/utils/spApiError'
import ApiKeysPage from '~/pages/dashboard/api-keys.vue'
import SpApiKeyRevealModal from '~/components/SpApiKeyRevealModal.vue'

/**
 * The API keys page, mounted for real.
 *
 * `SpApiKeyRevealModal.spec.ts` covers the dialog itself. This file covers the
 * page that owns the secret, because one-time reveal is a property of the whole
 * flow rather than of one component: a modal that hides the key perfectly is
 * worth nothing if the page keeps it in state, puts it in the list, or hands it
 * back after the dialog closes.
 *
 * The guarantees asserted here are the ones CLAUDE.md states as non-negotiable —
 * a full key is rendered exactly once, and never re-fetchable — plus the one
 * irreversible action on the page: revoke.
 *
 * Both values below are test fixtures and must never be real keys.
 */
const CREATED_SECRET = 'sk-spc-created-000000000000000000000000'
const ROTATED_SECRET = 'sk-spc-rotated-111111111111111111111111'

const NOW = Date.parse('2026-08-21T10:00:00.000Z')

const summary = (overrides: Partial<ApiKeySummary> & { id: string }): ApiKeySummary => ({
  label: `Key ${overrides.id}`,
  prefix: 'sk-spc-',
  last_four: 'ab12',
  status: 'ACTIVE',
  created_at: new Date(NOW - 86_400_000).toISOString(),
  last_used_at: null,
  expires_at: null,
  allowed_model_aliases: [],
  limits: {
    requests_per_minute: null,
    tokens_per_minute: null,
    concurrency: null,
    max_request_bytes: null,
    max_output_tokens: null
  },
  bound_entitlement_id: null,
  ...overrides
})

/** What the mocked control plane will answer with, set per test. */
const plane = {
  keys: [summary({ id: 'key1', label: 'Production key' })] as ApiKeySummary[],
  createError: null as SpApiError | null,
  statusError: null as SpApiError | null
}

const {
  listKeys,
  listModels,
  createKey,
  rotateKey,
  setKeyStatus,
  testKey,
  toastAdd
} = vi.hoisted(() => ({
  listKeys: vi.fn(),
  listModels: vi.fn(),
  createKey: vi.fn(),
  rotateKey: vi.fn(),
  setKeyStatus: vi.fn(),
  testKey: vi.fn(),
  toastAdd: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  account: {
    apiKeys: listKeys,
    createApiKey: createKey,
    rotateApiKey: rotateKey,
    setApiKeyStatus: setKeyStatus,
    testApiKey: testKey
  },
  catalog: { models: listModels }
}))

mockNuxtImport('useToast', () => () => ({ add: toastAdd }))

enableAutoUnmount(afterEach)

beforeEach(() => {
  plane.keys = [summary({ id: 'key1', label: 'Production key' })]
  plane.createError = null
  plane.statusError = null

  listKeys.mockReset().mockImplementation(async () => plane.keys)
  listModels.mockReset().mockImplementation(async () => [])
  toastAdd.mockReset()

  createKey.mockReset().mockImplementation(async (
    input: { label: string }
  ): Promise<ApiKeyCreated> => {
    if (plane.createError) {
      throw plane.createError
    }

    return {
      key: summary({ id: 'key_new', label: input.label }),
      secret: CREATED_SECRET
    }
  })

  rotateKey.mockReset().mockImplementation(async (id: string): Promise<ApiKeyCreated> => ({
    key: summary({ id, label: 'Production key' }),
    secret: ROTATED_SECRET
  }))

  setKeyStatus.mockReset().mockImplementation(async () => {
    if (plane.statusError) {
      throw plane.statusError
    }
  })

  testKey.mockReset().mockResolvedValue({
    valid: true,
    status: 'ACTIVE',
    expires_at: null,
    allowed_model_aliases: [],
    token_quota_remaining: '20000000',
    credit_remaining: null,
    limits: plane.keys[0]!.limits,
    service_status: 'operational'
  })

  clearNuxtData()
  clearNuxtState()
})

/** The page's own setup state. Dialogs teleport, so this is how they are driven. */
interface KeysVm {
  createForm: { label: string, allowed_model_aliases: string[], expiry_date: string }
  createOpen: boolean
  submitCreate: () => Promise<void>
  revealOpen: boolean
  revealSecret: string | null
  revealContext: 'created' | 'rotated'
  clearReveal: () => void
  rotateTarget: ApiKeySummary | null
  confirmRotate: () => Promise<void>
  revokeTarget: ApiKeySummary | null
  revokeConfirmation: string
  revokeReady: boolean
  confirmRevoke: () => Promise<void>
  /** The same row-menu descriptors the template hands to `UDropdownMenu`. */
  menuItems: (key: ApiKeySummary) => { label: string, onSelect: () => unknown }[][]
}

/** Opens a row action the way the row menu does, by running its own handler. */
const selectMenuItem = async (vm: KeysVm, key: ApiKeySummary, label: string) => {
  const item = vm.menuItems(key).flat().find(entry => entry.label === label)

  expect(item, `no "${label}" row action for ${key.id}`).toBeDefined()

  await item!.onSelect()
  await nextTick()
}

const mountKeys = async () => {
  const page = await mountSuspended(ApiKeysPage)

  await nextTick()
  await nextTick()

  return page
}

/** Dialog content teleports to the body, so the whole document is the surface. */
const documentText = () => document.body.textContent ?? ''
const documentHtml = () => document.body.innerHTML

/** Template line breaks are not sentence breaks; copy is asserted on the sentence. */
const squashed = (text: string) => text.replace(/\s+/g, ' ')

describe('api keys one-time reveal', () => {
  it('reveals the secret exactly once, on creation', async () => {
    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    vm.createForm.label = 'CI key'
    await vm.submitCreate()
    await nextTick()

    expect(vm.revealContext).toBe('created')
    expect(documentText()).toContain(CREATED_SECRET)
  })

  /**
   * The rule that makes the reveal *one-time* rather than merely first-time. The
   * dialog is the only place the secret may live; once it closes, a page that
   * still holds it could re-open and show it again, and a secret that can be
   * shown twice is one the customer has no reason to store safely.
   *
   * Driven through the dialog's own `close` event rather than by calling the
   * page's handler, because the wiring is the part that can silently break: a
   * renamed event leaves the page holding the secret with every unit still green.
   */
  it('drops the secret from page state when the dialog closes', async () => {
    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    vm.createForm.label = 'CI key'
    await vm.submitCreate()
    await nextTick()

    expect(vm.revealSecret).toBe(CREATED_SECRET)

    const modal = page.findComponent(SpApiKeyRevealModal)
    expect(modal.exists(), 'reveal dialog not rendered by the page').toBe(true)

    modal.vm.$emit('update:open', false)
    modal.vm.$emit('close')
    await nextTick()

    expect(vm.revealOpen).toBe(false)
    expect(vm.revealSecret).toBeNull()
    expect(documentText()).not.toContain(CREATED_SECRET)
    expect(documentHtml()).not.toContain(CREATED_SECRET)
  })

  /**
   * Re-reading the list is the obvious way a secret could leak back: the refresh
   * runs immediately after the reveal opens. `GET /me/api-keys` must never carry a
   * secret, and the page must never put one there itself.
   */
  it('re-reads the list after creating, and the list carries no secret', async () => {
    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    listKeys.mockClear()

    vm.createForm.label = 'CI key'
    await vm.submitCreate()
    await nextTick()

    expect(listKeys).toHaveBeenCalled()

    vm.clearReveal()
    await nextTick()

    // Nothing about the new key can reproduce its secret.
    expect(documentHtml()).not.toContain(CREATED_SECRET)
  })

  /** A rotation kills the previous secret, so it must not read as a new key. */
  it('distinguishes a rotated secret from a created one', async () => {
    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    vm.rotateTarget = plane.keys[0]!
    await nextTick()
    await vm.confirmRotate()
    await nextTick()

    expect(vm.revealContext).toBe('rotated')
    expect(vm.revealSecret).toBe(ROTATED_SECRET)
    expect(documentText()).toContain(ROTATED_SECRET)
  })

  it('shows only a masked key in the list', async () => {
    plane.keys = [summary({ id: 'key1', label: 'Production key', prefix: 'sk-spc-', last_four: 'ab12' })]

    const page = await mountKeys()

    expect(page.text()).toContain('Production key')
    expect(page.text()).toContain('sk-spc-')
    expect(page.text()).toContain('ab12')
    expect(page.text()).not.toContain(CREATED_SECRET)
    // Stated on the page, so the customer knows the list is not hiding a copy.
    expect(page.text()).toContain('shown once at creation or rotation')
  })

  it('never writes a secret into the page markup outside the dialog', async () => {
    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    vm.createForm.label = 'CI key'
    await vm.submitCreate()
    await nextTick()

    // The dialog is teleported out of the page tree; the page itself must be clean.
    expect(page.html()).not.toContain(CREATED_SECRET)
  })
})

describe('api keys revoke', () => {
  /** Revoke is the one action on this page that cannot be undone. */
  it('will not revoke until the customer types the confirmation', async () => {
    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    vm.revokeTarget = plane.keys[0]!
    await nextTick()

    expect(vm.revokeReady).toBe(false)

    await vm.confirmRevoke()

    expect(setKeyStatus).not.toHaveBeenCalled()
  })

  it('revokes once the confirmation matches', async () => {
    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    vm.revokeTarget = plane.keys[0]!
    vm.revokeConfirmation = 'REVOKE'
    await nextTick()

    expect(vm.revokeReady).toBe(true)

    await vm.confirmRevoke()

    expect(setKeyStatus).toHaveBeenCalledWith('key1', 'REVOKED')
  })

  /** Typed, not guessed: a near-miss is not consent to destroy a key. */
  it('does not accept a partial confirmation', async () => {
    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    vm.revokeTarget = plane.keys[0]!
    vm.revokeConfirmation = 'REVOK'
    await nextTick()

    expect(vm.revokeReady).toBe(false)

    await vm.confirmRevoke()

    expect(setKeyStatus).not.toHaveBeenCalled()
  })

  /**
   * The dangerous carry-over. If the typed confirmation survived from one key to
   * the next, opening the dialog on a second key would arrive already armed and a
   * single click would revoke it — with the customer never having confirmed *that*
   * key. Cancelling deliberately leaves the field alone, so the clearing has to
   * happen as the dialog opens; this drives the row action that opens it.
   */
  it('does not carry a confirmation over to the next key', async () => {
    plane.keys = [
      summary({ id: 'key1', label: 'Production key' }),
      summary({ id: 'key2', label: 'Staging key' })
    ]

    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    // Armed for the first key, then abandoned.
    await selectMenuItem(vm, plane.keys[0]!, 'Revoke permanently')
    vm.revokeConfirmation = 'REVOKE'
    await nextTick()
    vm.revokeTarget = null
    await nextTick()

    // Reopened the way the page does it, through the row menu.
    await selectMenuItem(vm, plane.keys[1]!, 'Revoke permanently')

    expect(vm.revokeTarget?.id).toBe('key2')
    expect(vm.revokeConfirmation).toBe('')
    expect(vm.revokeReady).toBe(false)

    await vm.confirmRevoke()

    expect(setKeyStatus).not.toHaveBeenCalled()
  })

  it('reports a refused revocation rather than reporting success', async () => {
    plane.statusError = new SpApiError({
      code: 'server_error',
      status: 500,
      message: 'SP Cambo could not complete that request. Please try again.'
    })

    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    vm.revokeTarget = plane.keys[0]!
    vm.revokeConfirmation = 'REVOKE'
    await nextTick()
    await vm.confirmRevoke()
    await nextTick()

    expect(toastAdd).toHaveBeenCalledWith(expect.objectContaining({
      title: 'That did not work',
      color: 'error'
    }))
    expect(toastAdd).not.toHaveBeenCalledWith(expect.objectContaining({ title: 'Key revoked' }))
  })
})

describe('api keys activity links', () => {
  it('links an active key to its safe activity deep link', async () => {
    const page = await mountKeys()
    const activityLink = page.get('a[href="/dashboard/usage?key=key1"]')

    expect(activityLink.text()).toContain('View activity')
  })

  it('keeps historical activity reachable for a revoked key', async () => {
    plane.keys = [summary({ id: 'revoked-key', label: 'Retired worker', status: 'REVOKED' })]

    const page = await mountKeys()
    const activityLink = page.get('a[href="/dashboard/usage?key=revoked-key"]')

    expect(activityLink.text()).toContain('View activity')
  })
})

describe('api keys ceilings', () => {
  /**
   * A 429 with no visible limit is indistinguishable from a fault. The gateway refuses
   * a request against exactly these five numbers, so a customer sizing their
   * concurrency has to be able to read them.
   */
  it('shows the per-key ceilings a request can be refused for', async () => {
    plane.keys = [summary({
      id: 'key1',
      label: 'Production key',
      limits: {
        requests_per_minute: 60,
        tokens_per_minute: 120_000,
        concurrency: 4,
        max_request_bytes: 262_144,
        max_output_tokens: 8192
      }
    })]

    const page = await mountKeys()
    const text = page.text()

    expect(text).toContain('Requests / minute')
    expect(text).toContain('60')
    expect(text).toContain('120,000')
    expect(text).toContain('Concurrent requests')
    expect(text).toContain('8,192')
  })

  /**
   * A null ceiling is neither zero nor unlimited, and the difference is the customer's
   * to know. Reading "unlimited" off a blank field would have them size a workload
   * around a promise SP Cambo never made.
   */
  it('does not read an unrecorded ceiling as unlimited', async () => {
    plane.keys = [summary({ id: 'key1', label: 'Production key' })]

    const page = await mountKeys()
    const text = page.text()

    expect(text).toContain('None recorded on this key')
    expect(text).not.toContain('Unlimited')
    expect(text).not.toContain('No limit')
    // A ceiling of zero would mean no request may be made at all.
    expect(text).not.toMatch(/Requests \/ minute\s*0\b/)
  })

  /** Only the limits that exist. A partial set must not imply the others are zero. */
  it('lists only the ceilings that are recorded', async () => {
    plane.keys = [summary({
      id: 'key1',
      limits: {
        requests_per_minute: 30,
        tokens_per_minute: null,
        concurrency: null,
        max_request_bytes: null,
        max_output_tokens: null
      }
    })]

    const page = await mountKeys()
    const text = page.text()

    expect(text).toContain('Requests / minute')
    expect(text).not.toContain('Tokens / minute')
    expect(text).not.toContain('Max output tokens')
  })

  /**
   * The key check reports no balance at all today. A blank next to "Tokens remaining"
   * reads as "you have none", which would send a customer to buy quota they already
   * hold — so the blank has to say what it is.
   */
  it('does not let an unreported balance read as an empty one', async () => {
    testKey.mockResolvedValue({
      valid: true,
      status: 'ACTIVE',
      expires_at: null,
      allowed_model_aliases: [],
      token_quota_remaining: null,
      credit_remaining: null,
      limits: {
        requests_per_minute: null,
        tokens_per_minute: null,
        concurrency: null,
        max_request_bytes: null,
        max_output_tokens: null
      },
      service_status: 'operational'
    })

    const page = await mountKeys()
    const vm = page.vm as unknown as KeysVm

    await selectMenuItem(vm, plane.keys[0]!, 'Test key')
    await nextTick()

    expect(documentText()).toContain('Not reported by this check')
    expect(squashed(documentText())).toContain('not that there is nothing left')
  })
})

describe('api keys honesty about missing data', () => {
  it('says the endpoint is not published rather than showing an empty key list', async () => {
    listKeys.mockRejectedValue(new SpApiError({
      code: 'endpoint_unavailable',
      status: 501,
      message: 'This part of the SP Cambo API is not available yet.'
    }))

    const page = await mountKeys()

    expect(page.text()).toContain('not available yet')
  })

  it('distinguishes being offline from an unpublished endpoint', async () => {
    listKeys.mockRejectedValue(new SpApiError({
      code: 'network_unreachable',
      status: 0,
      message: 'SP Cambo could not be reached. Check your connection and try again.'
    }))

    const page = await mountKeys()

    expect(page.text()).toContain('could not be reached')
  })
})
