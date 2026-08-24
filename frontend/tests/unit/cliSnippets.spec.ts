import { describe, expect, it } from 'vitest'
import {
  API_KEY_ENV,
  API_KEY_PLACEHOLDER,
  CODEX_PROVIDER,
  MODEL_ALIAS_PLACEHOLDER,
  buildCliSnippets,
  normaliseInferenceRoot,
  openAiBaseUrl
} from '~/utils/cliSnippets'

/** A public gateway root, as `NUXT_PUBLIC_INFERENCE_ROOT_URL` would supply it. */
const ROOT = 'https://gateway.spcambo.example'

const snippets = (overrides: Partial<Parameters<typeof buildCliSnippets>[0]> = {}) =>
  buildCliSnippets({ inferenceRootUrl: ROOT, ...overrides })

const everySnippet = (built = snippets()) => [
  built.claudeCodeShell,
  built.claudeCodePowerShell,
  built.codexConfig,
  built.codexShell,
  built.curlMessages,
  built.pythonAnthropic,
  built.nodeAnthropic,
  built.openAiPython
]

describe('normaliseInferenceRoot', () => {
  it('drops trailing slashes so a joined path never doubles up', () => {
    expect(normaliseInferenceRoot(`${ROOT}/`)).toBe(ROOT)
    expect(normaliseInferenceRoot(`${ROOT}///`)).toBe(ROOT)
  })

  it('tolerates surrounding whitespace in an environment variable', () => {
    expect(normaliseInferenceRoot(`  ${ROOT}  `)).toBe(ROOT)
  })
})

describe('openAiBaseUrl', () => {
  it('ends in exactly one /v1, which the OpenAI clients require', () => {
    expect(openAiBaseUrl(ROOT)).toBe(`${ROOT}/v1`)
    expect(openAiBaseUrl(`${ROOT}/`)).toBe(`${ROOT}/v1`)
    expect(openAiBaseUrl(ROOT)).not.toMatch(/\/v1\/v1$/)
  })
})

describe('base URL contract', () => {
  it('gives Claude Code the gateway root without /v1, because the SDK appends it', () => {
    const built = snippets()

    expect(built.claudeCodeShell).toContain(`export ANTHROPIC_BASE_URL="${ROOT}"`)
    expect(built.claudeCodeShell).not.toContain(`${ROOT}/v1`)
    expect(built.claudeCodePowerShell).toContain(`$env:ANTHROPIC_BASE_URL = "${ROOT}"`)
    expect(built.claudeCodePowerShell).not.toContain(`${ROOT}/v1`)
  })

  it('gives the Anthropic SDKs the same unversioned root', () => {
    expect(snippets().pythonAnthropic).toContain(`base_url="${ROOT}"`)
    expect(snippets().nodeAnthropic).toContain(`baseURL: '${ROOT}'`)
  })

  it('gives Codex CLI and the OpenAI SDK a base that ends in /v1', () => {
    expect(snippets().codexConfig).toContain(`base_url = "${ROOT}/v1"`)
    expect(snippets().openAiPython).toContain(`base_url="${ROOT}/v1"`)
  })

  it('never produces a doubled version segment in any snippet', () => {
    for (const snippet of everySnippet()) {
      expect(snippet).not.toContain('/v1/v1')
    }
  })

  it('addresses the Messages API explicitly in the curl example', () => {
    expect(snippets().curlMessages).toContain(`curl ${ROOT}/v1/messages`)
  })
})

describe('Codex CLI configuration', () => {
  it('declares the Responses wire API, which Codex requires', () => {
    expect(snippets().codexConfig).toContain('wire_api = "responses"')
  })

  it('reads the key from the environment rather than embedding it in the file', () => {
    const built = snippets()

    expect(built.codexConfig).toContain(`env_key = "${API_KEY_ENV}"`)
    expect(built.codexConfig).not.toContain(API_KEY_PLACEHOLDER)
    expect(built.codexShell).toContain(`export ${API_KEY_ENV}="${API_KEY_PLACEHOLDER}"`)
  })

  it('uses one provider name consistently, so --profile resolves', () => {
    const built = snippets()

    expect(built.codexConfig).toContain(`[model_providers.${CODEX_PROVIDER}]`)
    expect(built.codexConfig).toContain(`[profiles.${CODEX_PROVIDER}]`)
    expect(built.codexConfig).toContain(`model_provider = "${CODEX_PROVIDER}"`)
    expect(built.codexShell).toContain(`codex --profile ${CODEX_PROVIDER}`)
  })
})

describe('model alias', () => {
  it('uses the alias the customer selected', () => {
    const built = snippets({ modelAlias: 'spc-sonnet-fast' })

    expect(built.modelAlias).toBe('spc-sonnet-fast')
    expect(built.claudeCodeShell).toContain('export ANTHROPIC_MODEL="spc-sonnet-fast"')
    expect(built.codexConfig).toContain('model = "spc-sonnet-fast"')
  })

  it('shows a visible placeholder rather than inventing a model name', () => {
    for (const alias of [undefined, null, '', '   ']) {
      const built = snippets({ modelAlias: alias })

      expect(built.modelAlias).toBe(MODEL_ALIAS_PLACEHOLDER)
      expect(built.claudeCodeShell).toContain(MODEL_ALIAS_PLACEHOLDER)
    }
  })

  it('places the alias in every snippet that names a model', () => {
    const built = snippets({ modelAlias: 'spc-opus-long' })

    for (const snippet of [
      built.claudeCodeShell,
      built.claudeCodePowerShell,
      built.codexConfig,
      built.curlMessages,
      built.pythonAnthropic,
      built.nodeAnthropic,
      built.openAiPython
    ]) {
      expect(snippet).toContain('spc-opus-long')
    }
  })
})

describe('secret safety', () => {
  it('uses the visible placeholder as the only key-shaped string anywhere', () => {
    for (const snippet of everySnippet()) {
      for (const match of snippet.match(/sk-[\w-]+/g) ?? []) {
        expect(match).toBe(API_KEY_PLACEHOLDER)
      }
    }
  })

  it('is obviously a placeholder rather than something a customer might mistake for a key', () => {
    expect(API_KEY_PLACEHOLDER).toContain('your-key')
  })

  it('never names an internal host, an OmniRoute URL or a local port', () => {
    for (const snippet of everySnippet()) {
      expect(snippet).not.toMatch(/omniroute/i)
      expect(snippet).not.toMatch(/localhost|127\.0\.0\.1|:20128/)
    }
  })

  it('carries nothing but the configured public root when config supplies one', () => {
    for (const snippet of everySnippet()) {
      for (const url of snippet.match(/https?:\/\/[^\s"',\\]+/g) ?? []) {
        expect(url.startsWith(ROOT)).toBe(true)
      }
    }
  })

  it('reads the key from the environment in every SDK snippet, never inline', () => {
    const built = snippets()

    for (const snippet of [built.pythonAnthropic, built.nodeAnthropic, built.openAiPython]) {
      expect(snippet).toContain(API_KEY_ENV)
      expect(snippet).not.toContain(API_KEY_PLACEHOLDER)
    }
  })
})
