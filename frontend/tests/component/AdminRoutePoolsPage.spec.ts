// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import RoutePoolsPage from '~/pages/admin/route-pools.vue'

const { request } = vi.hoisted(() => ({
  request: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({ request }))

enableAutoUnmount(afterEach)

const aliases = [{
  id: '7',
  public_alias: 'gemini-3.6-pro',
  display_name: 'Gemini 3.6 Pro',
  primary_provider: 'Provider A',
  route_pool: {
    configured: false,
    enabled: false,
    route_count: 0,
    max_concurrency: null
  }
}]

const detail = {
  model: {
    id: '7',
    public_alias: 'gemini-3.6-pro',
    display_name: 'Gemini 3.6 Pro'
  },
  pool: {
    configured: false,
    enabled: false,
    strategy: 'WEIGHTED_LEAST_CONNECTIONS',
    max_concurrency: null,
    max_failover_attempts: 2,
    circuit_failure_threshold: 3,
    circuit_cooldown_seconds: 30,
    entries: []
  },
  candidates: [],
  active_model_connections: 0
}

const settle = async () => {
  await nextTick()
  await flushPromises()
  await nextTick()
}

beforeEach(() => {
  request.mockReset().mockImplementation(async (path: string) => {
    if (path === '/admin/model-route-pools') return aliases
    if (path === '/admin/model-route-pools/7') return detail
    throw new Error(`Unexpected API path: ${path}`)
  })

  clearNuxtData()
  clearNuxtState()
})

describe('admin model routing states', () => {
  it('shows an actionable inline error instead of a contradictory empty state', async () => {
    request.mockImplementation(async (path: string) => {
      if (path === '/admin/model-route-pools') return aliases
      if (path === '/admin/model-route-pools/7') {
        throw new Error('Route-health storage is not ready.')
      }
      throw new Error(`Unexpected API path: ${path}`)
    })

    const page = await mountSuspended(RoutePoolsPage)
    await settle()

    ;(page.vm.$.setupState as { selectedAliasId: string | undefined }).selectedAliasId = '7'
    await settle()

    expect(request).toHaveBeenCalledWith('/admin/model-route-pools/7')
    const text = page.text()
    expect(text).toContain('Model routing could not be loaded')
    expect(text).toContain('Route-health storage is not ready.')
    expect(text).toContain('Retry model routing')
    expect(text).not.toContain('Choose a public model alias to configure scalable routing.')
  })
})
