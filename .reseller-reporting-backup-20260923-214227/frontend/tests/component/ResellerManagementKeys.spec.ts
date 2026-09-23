// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { ResellerManagementKey, ResellerManagementScope } from '~/types/reseller'
import { RESELLER_MANAGEMENT_SCOPES } from '~/types/reseller'
import ManagementKeysPage from '~/pages/reseller/management-keys.vue'

/**
 * The management-key scope picker, mounted for real.
 *
 * A management key's scopes are fixed at creation and there is no rotate route, so
 * the choice made in this dialog is the only one the reseller gets. That makes the
 * *accuracy of what each scope claims to authorise* the guarantee worth protecting.
 *
 * Two of the seven scopes the control plane grants — `allocations:read` and
 * `usage:read` — are read by no route in `routes/api.php`. A key holding only those
 * is issued happily and then refused by every endpoint with `insufficient_scope`,
 * which reads as a broken key rather than as a scope that does nothing. The page
 * must say so before the key is created, not after.
 *
 * These tests fail if the backend gains the missing endpoints, which is the point:
 * the day `usage:read` starts authorising something, this page must stop saying it
 * does not.
 */

/** What the mocked control plane will answer with, set per test. */
const plane = {
  keys: [] as ResellerManagementKey[]
}

const { listKeys, createKey, revokeKey } = vi.hoisted(() => ({
  listKeys: vi.fn(),
  createKey: vi.fn(),
  revokeKey: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  reseller: {
    managementKeys: listKeys,
    createManagementKey: createKey,
    revokeManagementKey: revokeKey
  }
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  plane.keys = []

  listKeys.mockReset().mockImplementation(async () => plane.keys)
  createKey.mockReset()
  revokeKey.mockReset()

  /*
   * `useSpResource` keys into Nuxt's payload, which is shared for the whole test
   * file, so without clearing it a later test renders an earlier test's list.
   */
  clearNuxtData()
  clearNuxtState()
})

/**
 * The slice of the page's own state these tests drive.
 *
 * The create form lives inside a teleported `UModal`, so its checkboxes are not in
 * the component's subtree and cannot be clicked through the wrapper. The scope
 * choice is therefore made the way the widget makes it — through the page's own
 * `toggleScope` — and what is asserted is the copy the page then renders.
 */
interface ManagementKeysVm {
  createOpen: boolean
  createForm: { label: string, scopes: ResellerManagementScope[], expiry_date: string }
  toggleScope: (scope: ResellerManagementScope, enabled: boolean) => void
}

/**
 * Mounts the page and opens the create dialog, which is where the scope copy lives.
 * The dialog is teleported, so its text is read from the document rather than from
 * the component's own subtree.
 */
const openCreateDialog = async () => {
  const page = await mountSuspended(ManagementKeysPage)

  await nextTick()
  await nextTick()

  const vm = page.vm as unknown as ManagementKeysVm

  vm.createOpen = true

  await nextTick()
  await nextTick()

  return { page, vm, body: document.body.textContent ?? '' }
}

/** Ticks scopes and returns the dialog text that results. */
const withScopes = async (vm: ManagementKeysVm, scopes: ResellerManagementScope[]) => {
  for (const scope of scopes) {
    vm.toggleScope(scope, true)
  }

  await nextTick()
  await nextTick()

  return document.body.textContent ?? ''
}

describe('scope disclosure', () => {
  it('offers every scope the control plane publishes, so none is silently unavailable', async () => {
    const { body } = await openCreateDialog()

    for (const scope of RESELLER_MANAGEMENT_SCOPES) {
      expect(body, scope).toContain(scope)
    }
  })

  it('names the endpoint each usable scope authorises', async () => {
    const { body } = await openCreateDialog()

    expect(body).toContain('GET /reseller-management/customers')
    expect(body).toContain('POST /reseller-management/customers')
    expect(body).toContain('POST /reseller-management/customers/{id}/allocations')
    expect(body).toContain('GET /reseller-management/customers/{id}/api-keys')
    expect(body).toContain('POST /reseller-management/customers/{id}/api-keys/{key}/revoke')
  })

  it('says plainly that the two unenforced scopes authorise nothing today', async () => {
    const { body } = await openCreateDialog()

    // Once per inert scope, and on neither of the five that do authorise something.
    expect(body.match(/No endpoint yet/g)).toHaveLength(2)
    expect(body.match(/No endpoint reads this scope yet/g)).toHaveLength(2)
  })
})

describe('a key that would be refused everywhere', () => {
  it('warns when every chosen scope is one no endpoint reads', async () => {
    const { vm } = await openCreateDialog()
    const body = await withScopes(vm, ['allocations:read', 'usage:read'])

    expect(body).toContain('This key would be refused everywhere')
    expect(body).toContain('insufficient_scope')
  })

  it('does not warn once a scope that authorises something is added', async () => {
    const { vm } = await openCreateDialog()

    expect(await withScopes(vm, ['usage:read'])).toContain('This key would be refused everywhere')
    expect(await withScopes(vm, ['customers:read'])).not.toContain('This key would be refused everywhere')
  })

  it('says nothing at all until a scope is chosen', async () => {
    const { body } = await openCreateDialog()

    expect(body).not.toContain('This key would be refused everywhere')
  })

  it('warns about a write scope separately, because that is a different risk', async () => {
    const { vm } = await openCreateDialog()
    const body = await withScopes(vm, ['allocations:write'])

    expect(body).toContain('This key will be able to change things')
    expect(body).not.toContain('This key would be refused everywhere')
  })
})
