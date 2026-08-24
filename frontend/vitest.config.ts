import { fileURLToPath } from 'node:url'
import { defineVitestConfig } from '@nuxt/test-utils/config'

/**
 * Two kinds of test share one config.
 *
 * `tests/unit/**` is pure logic — money and quota arithmetic, error
 * normalisation, payment/entitlement/order state — and runs in a plain Node
 * environment so the suite stays fast enough for every change.
 *
 * `tests/component/**` mounts real components, which need Nuxt's auto-imports
 * and component resolution. Those files opt in with a `// @vitest-environment
 * nuxt` pragma on the first line; booting Nuxt costs seconds, so it is paid only
 * by the files that need it.
 */
export default defineVitestConfig({
  resolve: {
    alias: {
      '~': fileURLToPath(new URL('./app', import.meta.url))
    }
  },
  test: {
    environment: 'node',
    include: ['tests/**/*.spec.ts'],
    environmentOptions: {
      nuxt: {
        domEnvironment: 'happy-dom'
      }
    }
  }
})
