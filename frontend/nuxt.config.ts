import { publicConfigProblems, resolvePublicEndpoints } from './app/utils/publicConfig'
import { SUPPORT_URL_ENV, supportChannelProblem } from './app/utils/supportChannel'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: ['@nuxt/eslint', '@nuxt/ui', '@pinia/nuxt'],

  devtools: {
    enabled: true
  },

  css: ['~/assets/css/main.css'],

  colorMode: {
    preference: 'dark',
    fallback: 'dark'
  },

  runtimeConfig: {
    // Server-only control-plane origin used during SSR, e.g. http://nginx/api/v1.
    // Set by NUXT_INTERNAL_API_BASE_URL. Public pages are server-rendered from
    // inside the deployment network, where the public hostname may require DNS,
    // TLS and NAT hairpin that the container cannot perform. Left empty in local
    // development, where the public base is reachable from both sides.
    // This is not a NUXT_PUBLIC_* value: it is never sent to the browser.
    internalApiBaseUrl: '',

    public: {
      // SP Cambo control-plane (Laravel) base URL used by the browser.
      // Overridden in every environment by NUXT_PUBLIC_API_BASE_URL.
      // The browser must never call OmniRoute directly.
      apiBaseUrl: 'http://localhost:8000/api/v1',

      // Public inference root advertised to customers in CLI/docs snippets.
      // Claude Code appends /v1/messages itself, so this must stay a root URL.
      // Overridden by NUXT_PUBLIC_INFERENCE_ROOT_URL.
      inferenceRootUrl: 'http://127.0.0.1:3010',

      // Marketing/canonical site origin, used for SEO metadata.
      siteUrl: 'http://localhost:3000',

      // Where a customer reaches a human. Optional and empty by default: several
      // surfaces tell a suspended or blocked account to contact SP Cambo, and this
      // is what turns that into a link. Nothing is invented when it is unset — see
      // app/utils/supportChannel.ts. An email address or an http(s) URL; Nitro
      // reads NUXT_PUBLIC_SUPPORT_URL at boot, so it can change without a rebuild.
      supportUrl: '',

      // Browser session transport.
      // 'bearer' matches the control plane as implemented today.
      // 'cookie' switches to first-party Sanctum SPA session auth and must only
      // be enabled once the backend exposes /sanctum/csrf-cookie and lists this
      // origin as a stateful domain. See docs/ai/CLAUDE_TO_CODEX.md.
      sessionMode: (process.env.NUXT_PUBLIC_SESSION_MODE || 'bearer') as 'bearer' | 'cookie'
    }
  },

  routeRules: {
    // Authenticated surfaces are client-rendered: the session credential lives in
    // the browser and there is no SEO value in server-rendering private data.
    // Crawler exclusion is handled by `public/robots.txt` plus per-page `robots`
    // meta tags, so no extra module is required.
    '/dashboard/**': { ssr: false },
    '/admin/**': { ssr: false },
    '/reseller/**': { ssr: false }
  },

  compatibilityDate: '2026-06-30',

  hooks: {
    /**
     * Fails a release build whose customer-facing endpoints are unfit to ship.
     *
     * `runtimeConfig.public.*` reaches the browser, so a deployment template that
     * misnames a variable — `NUXT_PUBLIC_INFERENCE_ROOT` instead of
     * `NUXT_PUBLIC_INFERENCE_ROOT_URL` — leaves the loopback development default in
     * the Claude Code and Codex snippets customers are told to copy. Nothing about
     * the built output reveals that, so it is asserted here.
     *
     * Opt-in via SP_CAMBO_STRICT_PUBLIC_CONFIG so an ordinary local `nuxt build`
     * still works against development defaults. The production image sets it.
     *
     * The values come from `process.env` via `resolvePublicEndpoints`, not from
     * `nuxt.options.runtimeConfig.public`: that object still holds the literals
     * below when `ready` runs, because Nuxt applies `NUXT_PUBLIC_*` when Nitro
     * boots. Reading it here reported the loopback defaults even for a correctly
     * configured image, so the check failed every build rather than none.
     */
    ready: (nuxt) => {
      if (process.env.SP_CAMBO_STRICT_PUBLIC_CONFIG !== '1') {
        return
      }

      const problems = publicConfigProblems(
        resolvePublicEndpoints(process.env, nuxt.options.runtimeConfig.public)
      )

      /*
       * The support channel is optional, so an unset one is not a problem. One that
       * is set and unusable is reported here rather than discovered by a customer:
       * `SpSupportLink` renders nothing for a value it cannot use, which looks
       * identical to not having configured it at all.
       */
      const support = supportChannelProblem(
        process.env[SUPPORT_URL_ENV] ?? String(nuxt.options.runtimeConfig.public.supportUrl ?? '')
      )

      if (support !== null) {
        problems.push(support)
      }

      if (problems.length > 0) {
        throw new Error(
          'SP Cambo public endpoint configuration is not fit for release:\n'
          + problems.map(problem => `  - ${problem}`).join('\n')
        )
      }
    }
  },


  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  },

  icon: {
    clientBundle: {
      icons: [
        'lucide:activity',
        'lucide:arrow-left',
        'lucide:arrow-right',
        'lucide:arrow-up-right',
        'lucide:badge-check',
        'lucide:ban',
        'lucide:book-open',
        'lucide:building-2',
        'lucide:chart-line',
        'lucide:check',
        'lucide:chevron-down',
        'lucide:chevron-right',
        'lucide:circle-alert',
        'lucide:circle-check-big',
        'lucide:circle-help',
        'lucide:circle-slash',
        'lucide:clock',
        'lucide:code',
        'lucide:copy',
        'lucide:credit-card',
        'lucide:database-zap',
        'lucide:external-link',
        'lucide:eye',
        'lucide:eye-off',
        'lucide:file-text',
        'lucide:gauge',
        'lucide:hourglass',
        'lucide:key',
        'lucide:key-round',
        'lucide:layout-dashboard',
        'lucide:loader-circle',
        'lucide:lock-keyhole',
        'lucide:log-out',
        'lucide:mail',
        'lucide:menu',
        'lucide:package',
        'lucide:plug-zap',
        'lucide:qr-code',
        'lucide:receipt',
        'lucide:refresh-cw',
        'lucide:rocket',
        'lucide:route',
        'lucide:server',
        'lucide:settings',
        'lucide:shield-check',
        'lucide:sparkles',
        'lucide:terminal',
        'lucide:ticket-percent',
        'lucide:timer',
        'lucide:triangle-alert',
        'lucide:user',
        'lucide:users',
        'lucide:wallet',
        'lucide:wifi-off',
        'lucide:zap'
      ]
    }
  }
})
