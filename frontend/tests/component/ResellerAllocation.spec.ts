// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { EntitlementLot } from '~/types/commerce'
import type { ResellerAllocation, ResellerCustomer, ResellerCustomerKey } from '~/types/reseller'
import { SpApiError } from '~/utils/spApiError'
import CustomerPage from '~/pages/reseller/customers/[id].vue'

/**
 * Reseller allocation, mounted for real.
 *
 * An allocation moves quota out of the reseller's own inventory and into a
 * customer's account. It is the one customer-facing write in SP Cambo that the
 * control plane deduplicates on a client-minted key, which makes the *lifecycle
 * of that key* the guarantee worth protecting:
 *
 * - unchanged form, resubmitted → the same key, so a dropped response replays
 *   the original transfer instead of making a second one;
 * - any edit to the form → a new key, so a deliberate second transfer is a
 *   second transfer rather than being swallowed as a replay;
 * - a 409 conflict → a new key, which is safe precisely because a conflict
 *   transferred nothing;
 * - an ambiguous 500 → the *same* key, because a new one would turn a retry into
 *   a second transfer of real quota.
 *
 * That last rule is the dangerous one to get wrong and the reason this file
 * exists. The pure inventory maths is covered in
 * `tests/unit/resellerInventory.spec.ts`.
 *
 * The form lives inside a teleported `UModal` with select menus, so it is driven
 * through the page's own state and submit handler rather than through synthetic
 * clicks on widgets. What is asserted is what reaches the API.
 */

const CUSTOMER_ID = 'cus_test'
const NOW = Date.parse('2026-08-21T10:00:00.000Z')
const DAY = 24 * 3600_000

const iso = (ms: number) => new Date(ms).toISOString()

const customer = (overrides: Partial<ResellerCustomer> = {}): ResellerCustomer => ({
  id: CUSTOMER_ID,
  name: 'Managed Customer',
  email: 'customer@example.test',
  label: 'Reseller label',
  status: 'ACTIVE',
  created_at: iso(NOW - 30 * DAY),
  ...overrides
})

const lot = (overrides: Partial<EntitlementLot> & { id: string }): EntitlementLot => ({
  billing_mode: 'TOKEN_QUOTA',
  package_name: `Package ${overrides.id}`,
  family_label: 'Standard',
  original_units: '20000000',
  remaining_units: '20000000',
  reserved_units: '0',
  unit_label: 'tokens',
  remaining_amount: null,
  activated_at: iso(NOW - DAY),
  expires_at: iso(NOW + 30 * DAY),
  allowed_model_aliases: ['sp-fast'],
  status: 'ACTIVE',
  source: 'ORDER',
  access_scope: 'ACCOUNT',
  fulfillment_claim_id: null,
  bound_api_key: null,
  ...overrides
})

const customerKey = (overrides: Partial<ResellerCustomerKey> = {}): ResellerCustomerKey => ({
  id: 'key_test',
  label: 'Managed customer production key',
  prefix: 'sk-',
  last_four: 'abcd',
  status: 'ACTIVE',
  created_at: iso(NOW - DAY),
  last_used_at: null,
  expires_at: null,
  allowed_model_aliases: [],
  ...overrides
})

/** What the mocked control plane will answer with, set per test. */
const plane = {
  customers: [customer()],
  lots: [lot({ id: 'inv1' })],
  keys: [] as ResellerCustomerKey[],
  allocateError: null as SpApiError | null,
  lifecycleError: null as SpApiError | null
}

const {
  listCustomers,
  listEntitlements,
  listModels,
  allocate,
  listCustomerKeys,
  updateCustomerStatus,
  revokeCustomerKey,
  toastAdd
} = vi.hoisted(() => ({
  listCustomers: vi.fn(),
  listEntitlements: vi.fn(),
  listModels: vi.fn(),
  allocate: vi.fn(),
  listCustomerKeys: vi.fn(),
  updateCustomerStatus: vi.fn(),
  revokeCustomerKey: vi.fn(),
  toastAdd: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  account: { entitlements: listEntitlements },
  catalog: { models: listModels },
  reseller: {
    customers: listCustomers,
    allocate,
    customerKeys: listCustomerKeys,
    updateCustomerStatus,
    revokeCustomerKey
  }
}))

mockNuxtImport('useRoute', () => () => ({ params: { id: CUSTOMER_ID } }))
mockNuxtImport('useToast', () => () => ({ add: toastAdd }))

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)

  plane.customers = [customer()]
  plane.lots = [lot({ id: 'inv1' })]
  plane.keys = []
  plane.allocateError = null
  plane.lifecycleError = null

  listCustomers.mockReset().mockImplementation(async () => plane.customers)
  listEntitlements.mockReset().mockImplementation(async () => plane.lots)
  listModels.mockReset().mockImplementation(async () => [])
  listCustomerKeys.mockReset().mockImplementation(async () => plane.keys)
  toastAdd.mockReset()

  allocate.mockReset().mockImplementation(async (
    customerId: string,
    input: { public_model_alias: string, units: number }
  ): Promise<ResellerAllocation> => {
    if (plane.allocateError) {
      throw plane.allocateError
    }

    return {
      id: 'alloc_1',
      customer_id: customerId,
      billing_mode: 'TOKEN_QUOTA',
      public_model_alias: input.public_model_alias,
      units: String(input.units),
      created_at: iso(NOW)
    }
  })

  updateCustomerStatus.mockReset().mockImplementation(async (
    customerId: string,
    input: { status: ResellerCustomer['status'], reason: string }
  ): Promise<ResellerCustomer> => {
    if (plane.lifecycleError) {
      throw plane.lifecycleError
    }

    const current = plane.customers.find(entry => entry.id === customerId)

    if (!current) {
      throw new SpApiError({ code: 'not_found', status: 404, message: 'That resource could not be found.' })
    }

    const updated = { ...current, status: input.status }
    plane.customers = plane.customers.map(entry => entry.id === customerId ? updated : entry)

    return updated
  })

  revokeCustomerKey.mockReset().mockImplementation(async (customerId: string, keyId: string) => {
    expect(customerId).toBe(CUSTOMER_ID)
    plane.keys = plane.keys.map(key => key.id === keyId ? { ...key, status: 'REVOKED' } : key)
  })

  clearNuxtData()
  clearNuxtState()
})

afterEach(() => {
  vi.useRealTimers()
})

/** The page's own setup state, which is what the modal form is bound to. */
interface AllocationVm {
  allocation: {
    billing_mode: string
    public_model_alias: string
    units: string
    reason: string
  }
  allocationKey: string | null
  allocationError: string | null
  allocationUnconfirmed: boolean
  openAllocate: () => void
  submitAllocation: () => Promise<void>
}

interface LifecycleVm {
  lifecycle: { reason: string }
  lifecycleActions: Array<{ action: string, status: ResellerCustomer['status'] }>
  lifecycleOpen: boolean
  lifecycleTarget: { action: string, status: ResellerCustomer['status'] } | null
  lifecycleError: string | null
  openLifecycle: (action: LifecycleVm['lifecycleActions'][number]) => void
  validateLifecycle: (state: { reason: string }) => Array<{ name?: string, message?: string }>
  submitLifecycle: () => Promise<void>
  confirmRevoke: () => Promise<void>
  revokeTarget: ResellerCustomerKey | null
}

const mountCustomer = async () => {
  const page = await mountSuspended(CustomerPage)

  await vi.advanceTimersByTimeAsync(0)
  await nextTick()

  return page
}

/** Opens the form and fills it with a valid, fundable transfer. */
const openValidForm = async (page: Awaited<ReturnType<typeof mountCustomer>>) => {
  const vm = page.vm as unknown as AllocationVm

  vm.openAllocate()
  await nextTick()

  vm.allocation.billing_mode = 'TOKEN_QUOTA'
  vm.allocation.public_model_alias = 'sp-fast'
  vm.allocation.units = '5000000'
  vm.allocation.reason = 'Funding the launch pilot for this customer.'
  await nextTick()

  return vm
}

/** The `idempotency_key` of the nth call to `allocate`. */
const keyOfCall = (index: number) =>
  (allocate.mock.calls[index]?.[1] as { idempotency_key: string } | undefined)?.idempotency_key

/**
 * Text of everything currently announced to a screen reader.
 *
 * Queried against the document rather than the wrapper on purpose: the allocation
 * form is inside a `UModal`, which teleports its content to `document.body`. A
 * wrapper-scoped query finds nothing there and would pass an assertion of the
 * form "no alert exists" for entirely the wrong reason.
 */
const announced = () =>
  Array.from(document.querySelectorAll('[role="alert"]'))
    .map(node => node.textContent ?? '')
    .join(' ')

describe('reseller allocation idempotency key', () => {
  it('sends a key with the transfer, bound to the customer in the route', async () => {
    const page = await mountCustomer()
    const vm = await openValidForm(page)

    await vm.submitAllocation()

    expect(allocate).toHaveBeenCalledTimes(1)
    expect(allocate).toHaveBeenCalledWith(CUSTOMER_ID, expect.objectContaining({
      billing_mode: 'TOKEN_QUOTA',
      public_model_alias: 'sp-fast',
      units: 5000000,
      reason: 'Funding the launch pilot for this customer.'
    }))
    expect(keyOfCall(0)).toBeTruthy()
  })

  /**
   * The replay guarantee. A dropped response leaves the reseller with no idea
   * whether the transfer landed; resubmitting the untouched form must reach the
   * server with the same key so the control plane returns the original transfer
   * rather than moving the quota a second time.
   */
  it('reuses the same key when an untouched form is resubmitted', async () => {
    plane.allocateError = new SpApiError({
      code: 'server_error',
      status: 500,
      message: 'SP Cambo could not complete that request. Please try again.'
    })

    const page = await mountCustomer()
    const vm = await openValidForm(page)

    await vm.submitAllocation()

    plane.allocateError = null
    await vm.submitAllocation()

    expect(allocate).toHaveBeenCalledTimes(2)
    expect(keyOfCall(1)).toBe(keyOfCall(0))
  })

  /**
   * The most dangerous rule on this page. A 500 leaves the outcome unknown — the
   * transfer may well have been written before the fault. Minting a fresh key
   * here would make the retry a *second* transfer of real quota, so the key must
   * survive the failure untouched.
   */
  it('keeps the key after an ambiguous 500, so a retry cannot transfer twice', async () => {
    plane.allocateError = new SpApiError({
      code: 'server_error',
      status: 500,
      message: 'SP Cambo could not complete that request. Please try again.'
    })

    const page = await mountCustomer()
    const vm = await openValidForm(page)

    const before = vm.allocationKey
    await vm.submitAllocation()
    await nextTick()

    expect(vm.allocationKey).toBe(before)
    expect(vm.allocationUnconfirmed).toBe(true)
  })

  /**
   * A conflict is a definite answer: the service throws inside its transaction
   * before writing anything, so nothing moved. The spent key would only clash
   * again, and a fresh one is safe.
   */
  it('mints a new key after a 409, because a conflict transferred nothing', async () => {
    plane.allocateError = new SpApiError({
      code: 'idempotency_conflict',
      status: 409,
      message: 'This safety key has already been used for a different request.'
    })

    const page = await mountCustomer()
    const vm = await openValidForm(page)

    const before = vm.allocationKey
    await vm.submitAllocation()
    await nextTick()

    expect(vm.allocationKey).not.toBe(before)
    expect(vm.allocationKey).toBeTruthy()
    expect(vm.allocationUnconfirmed).toBe(false)
  })

  /**
   * Editing the amount means the reseller wants something different, not a
   * replay of what they asked for before. Holding the old key would let the
   * server return the first transfer and report success for an amount that was
   * never moved.
   */
  it('mints a new key when the amount changes, so a second transfer is not swallowed', async () => {
    const page = await mountCustomer()
    const vm = await openValidForm(page)

    const before = vm.allocationKey

    vm.allocation.units = '6000000'
    await nextTick()

    expect(vm.allocationKey).not.toBe(before)
  })

  /**
   * The reason is not part of the server's comparison, which is exactly why it
   * has to mint here: otherwise a reseller who corrects the audit reason and
   * resubmits gets the earlier transfer back and believes a reason was recorded
   * that never was.
   */
  it('mints a new key when only the audit reason changes', async () => {
    const page = await mountCustomer()
    const vm = await openValidForm(page)

    const before = vm.allocationKey

    vm.allocation.reason = 'Corrected: funding the launch pilot, approved by finance.'
    await nextTick()

    expect(vm.allocationKey).not.toBe(before)
  })

  it('mints a fresh key for each newly opened form', async () => {
    const page = await mountCustomer()
    const first = await openValidForm(page)
    const firstKey = first.allocationKey

    await first.submitAllocation()

    const second = await openValidForm(page)

    expect(second.allocationKey).toBeTruthy()
    expect(second.allocationKey).not.toBe(firstKey)
  })
})

describe('reseller allocation outcomes', () => {
  it('confirms the transfer with the quantity the server reported', async () => {
    const page = await mountCustomer()
    const vm = await openValidForm(page)

    await vm.submitAllocation()
    await nextTick()

    expect(toastAdd).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Quota allocated',
      color: 'success'
    }))
    expect(vm.allocationError).toBeNull()
  })

  /** A refusal the server named is definite, so it must not read as unconfirmed. */
  it('reports a named refusal as decided, not as unknown', async () => {
    plane.allocateError = new SpApiError({
      code: 'insufficient_units',
      status: 422,
      message: 'You do not hold enough quota for this model.'
    })

    const page = await mountCustomer()
    const vm = await openValidForm(page)

    await vm.submitAllocation()
    await nextTick()

    expect(vm.allocationUnconfirmed).toBe(false)
  })

  it('re-reads inventory after an ambiguous failure, since the transfer may have landed', async () => {
    plane.allocateError = new SpApiError({
      code: 'server_error',
      status: 500,
      message: 'SP Cambo could not complete that request. Please try again.'
    })

    const page = await mountCustomer()
    const vm = await openValidForm(page)

    listEntitlements.mockClear()
    await vm.submitAllocation()
    await vi.advanceTimersByTimeAsync(0)

    expect(listEntitlements).toHaveBeenCalled()
  })

  /**
   * The unconfirmed warning is the highest-stakes message in the product: it says
   * real quota may or may not have moved. It appears in a dialog the reseller is
   * already looking at, with no navigation and no toast, so without a live region
   * a screen reader user is told nothing at all — and would retry a transfer whose
   * outcome nobody knows.
   */
  it('announces an unconfirmed transfer instead of only drawing it', async () => {
    plane.allocateError = new SpApiError({
      code: 'server_error',
      status: 500,
      message: 'SP Cambo could not complete that request. Please try again.'
    })

    const page = await mountCustomer()
    const vm = await openValidForm(page)

    await vm.submitAllocation()
    await nextTick()

    expect(announced()).toContain('could not confirm this transfer')
    // The recovery rule has to be part of the same announcement, not left on screen alone.
    expect(announced()).toContain('cannot transfer twice')
  })

  /** A named refusal is likewise silent otherwise: no toast fires on this path. */
  it('announces a refusal the server named', async () => {
    plane.allocateError = new SpApiError({
      code: 'insufficient_units',
      status: 422,
      message: 'You do not hold enough quota for this model.'
    })

    const page = await mountCustomer()
    const vm = await openValidForm(page)

    await vm.submitAllocation()
    await nextTick()

    expect(announced()).toContain('You do not hold enough quota for this model.')
  })
})

describe('reseller customer page honesty', () => {
  it('says the customer is not in the roster rather than rendering an empty shell', async () => {
    plane.customers = [customer({ id: 'someone_else' })]

    const page = await mountCustomer()

    expect(page.text()).not.toContain('Managed Customer')
  })

  /**
   * Every write on this page is scoped server-side to an ACTIVE customer, so a
   * suspended one would 404 on each route. The page refuses the write itself
   * rather than letting the reseller discover that from a failed request — and it
   * says which state the customer is in instead of quietly hiding the control,
   * which would leave them hunting for a button that used to be there.
   */
  it('refuses allocation for a suspended customer and names the reason', async () => {
    plane.customers = [customer({ status: 'SUSPENDED' })]

    const page = await mountCustomer()

    const allocate = page.findAll('button').find(button => button.text().trim() === 'Allocate')

    expect(allocate, 'no Allocate button on the page at all').toBeDefined()
    expect(allocate!.attributes('disabled')).toBeDefined()
    expect(page.text()).toContain('This customer is suspended')
  })

  it('keeps allocation disabled and lists customer keys after suspension', async () => {
    plane.customers = [customer({ status: 'SUSPENDED' })]
    plane.keys = [customerKey()]

    const page = await mountCustomer()

    const allocate = page.findAll('button').find(button => button.text().trim() === 'Allocate')

    expect(allocate, 'no Allocate button on the page at all').toBeDefined()
    expect(allocate!.attributes('disabled')).toBeDefined()
    expect(listCustomerKeys).toHaveBeenCalledWith(CUSTOMER_ID)
    expect(page.text()).toContain('Managed customer production key')
  })

  it('lists keys for a closed customer but exposes no further lifecycle mutation', async () => {
    plane.customers = [customer({ status: 'CLOSED' })]
    plane.keys = [customerKey()]

    const page = await mountCustomer()
    const vm = page.vm as unknown as LifecycleVm

    expect(listCustomerKeys).toHaveBeenCalledWith(CUSTOMER_ID)
    expect(page.text()).toContain('Managed customer production key')
    expect(vm.lifecycleActions).toEqual([])
    expect(page.findAll('button').some(button => button.text().trim() === 'Issue key' && button.attributes('disabled') !== undefined)).toBe(true)
  })

  it('allows an active key to be revoked after suspension without revealing a secret', async () => {
    plane.customers = [customer({ status: 'SUSPENDED' })]
    plane.keys = [customerKey()]

    const page = await mountCustomer()
    const vm = page.vm as unknown as LifecycleVm

    vm.revokeTarget = plane.keys[0]
    await vm.confirmRevoke()

    expect(revokeCustomerKey).toHaveBeenCalledWith(CUSTOMER_ID, 'key_test')
    expect(page.text()).toContain('sk-••••••••abcd')
    expect(page.text()).not.toContain('sk-abcdefghijklmnop')
  })
})

describe('reseller customer lifecycle', () => {
  it('derives only valid actions from the REST-backed status', async () => {
    const activePage = await mountCustomer()
    const activeVm = activePage.vm as unknown as LifecycleVm

    expect(activeVm.lifecycleActions.map(action => action.action)).toEqual(['suspend', 'close'])

    activePage.unmount()
    plane.customers = [customer({ status: 'SUSPENDED' })]
    clearNuxtData()
    clearNuxtState()

    const suspendedPage = await mountCustomer()
    const suspendedVm = suspendedPage.vm as unknown as LifecycleVm

    expect(suspendedVm.lifecycleActions.map(action => action.action)).toEqual(['reactivate', 'close'])
  })

  it('sends a trimmed audited reason and refreshes authoritative lifecycle resources', async () => {
    const page = await mountCustomer()
    const vm = page.vm as unknown as LifecycleVm
    const suspend = vm.lifecycleActions.find(action => action.action === 'suspend')

    expect(suspend).toBeDefined()
    vm.openLifecycle(suspend!)
    vm.lifecycle.reason = '  Pausing service during the customer security review.  '
    await nextTick()

    listCustomers.mockClear()
    listCustomerKeys.mockClear()
    listEntitlements.mockClear()
    await vm.submitLifecycle()

    expect(updateCustomerStatus).toHaveBeenCalledWith(CUSTOMER_ID, {
      status: 'SUSPENDED',
      reason: 'Pausing service during the customer security review.'
    })
    expect(listCustomers).toHaveBeenCalled()
    expect(listCustomerKeys).toHaveBeenCalledWith(CUSTOMER_ID)
    expect(listEntitlements).toHaveBeenCalled()
    expect(vm.lifecycleOpen).toBe(false)
    expect(vm.lifecycleTarget).toBeNull()
    expect(toastAdd).toHaveBeenCalledWith(expect.objectContaining({ title: 'Customer suspended' }))
  })

  it('rejects a lifecycle reason shorter than the audited minimum before writing', async () => {
    const page = await mountCustomer()
    const vm = page.vm as unknown as LifecycleVm

    expect(vm.validateLifecycle({ reason: 'Too short' })).toEqual([expect.objectContaining({
      name: 'reason',
      message: expect.stringContaining('at least 10 characters')
    })])
    expect(updateCustomerStatus).not.toHaveBeenCalled()
  })

  it('resynchronises REST state after a concurrent invalid lifecycle transition', async () => {
    plane.lifecycleError = new SpApiError({
      code: 'invalid_status_transition',
      status: 409,
      message: 'That customer status has already changed and this transition is no longer available.'
    })

    const page = await mountCustomer()
    const vm = page.vm as unknown as LifecycleVm
    const suspend = vm.lifecycleActions.find(action => action.action === 'suspend')

    vm.openLifecycle(suspend!)
    vm.lifecycle.reason = 'Pausing service during the customer security review.'
    await nextTick()

    listCustomers.mockClear()
    listCustomerKeys.mockClear()
    listEntitlements.mockClear()
    await vm.submitLifecycle()

    expect(vm.lifecycleError).toContain('status has already changed')
    expect(vm.lifecycleOpen).toBe(true)
    expect(listCustomers).toHaveBeenCalled()
    expect(listCustomerKeys).toHaveBeenCalledWith(CUSTOMER_ID)
    expect(listEntitlements).toHaveBeenCalled()
  })
})
