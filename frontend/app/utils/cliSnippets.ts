/**
 * CLI, SDK and curl snippet text.
 *
 * Pure string building, kept out of the composable so the guarantees can be
 * tested directly:
 *
 * - a snippet is derived from public runtime config, never from a hard-coded host;
 * - a snippet never contains a real credential, only a visible placeholder;
 * - Claude Code gets the gateway root *without* `/v1`, because the Anthropic
 *   clients append the version segment themselves;
 * - Codex CLI and the OpenAI clients get a base that *ends* in `/v1` and speak
 *   the Responses wire API.
 *
 * The two base URLs differ by contract. Getting either wrong produces a 404 that
 * looks like an outage, so both are asserted in `tests/unit/cliSnippets.spec.ts`.
 */

/** Stand-in for a customer key. A real secret is shown only at creation, never here. */
export const API_KEY_PLACEHOLDER = 'sk-spc-your-key'

/** Stand-in until the catalogue publishes real aliases via `GET /catalog/models`. */
export const MODEL_ALIAS_PLACEHOLDER = '<your-model-alias>'

/** Environment variable name used for the key in every snippet. */
export const API_KEY_ENV = 'SPCAMBO_API_KEY'

/** Codex provider/profile name used in `~/.codex/config.toml`. */
export const CODEX_PROVIDER = 'spcambo'

/** Gateway root without a trailing slash. */
export function normaliseInferenceRoot(url: string): string {
  return (url ?? '').trim().replace(/\/+$/, '')
}

/** OpenAI-compatible base, which must end in `/v1`. */
export function openAiBaseUrl(inferenceRootUrl: string): string {
  return `${normaliseInferenceRoot(inferenceRootUrl)}/v1`
}

export interface CliSnippetInput {
  /** `runtimeConfig.public.inferenceRootUrl` — the customer-facing gateway root. */
  inferenceRootUrl: string
  /** A real alias once one is selected; otherwise a visible placeholder. */
  modelAlias?: string | null
  /** Optional one-time revealed key. General docs omit it and keep the placeholder. */
  apiKey?: string | null
}

export interface CliSnippets {
  inferenceRoot: string
  openAiBase: string
  modelAlias: string
  claudeCodeShell: string
  claudeCodePowerShell: string
  claudeCodeSettingsJson: string
  codexConfig: string
  codexShell: string
  curlMessages: string
  pythonAnthropic: string
  nodeAnthropic: string
  openAiPython: string
}

export function buildCliSnippets({ inferenceRootUrl, modelAlias, apiKey }: CliSnippetInput): CliSnippets {
  const inferenceRoot = normaliseInferenceRoot(inferenceRootUrl)
  const openAiBase = openAiBaseUrl(inferenceRootUrl)
  const alias = modelAlias?.trim() || MODEL_ALIAS_PLACEHOLDER
  const key = apiKey?.trim() || API_KEY_PLACEHOLDER

  return {
    inferenceRoot,
    openAiBase,
    modelAlias: alias,

    claudeCodeShell: [
      `export ANTHROPIC_BASE_URL="${inferenceRoot}"`,
      `export ANTHROPIC_AUTH_TOKEN="${key}"`,
      `export ANTHROPIC_MODEL="${alias}"`,
      '',
      'claude'
    ].join('\n'),

    claudeCodePowerShell: [
      `$env:ANTHROPIC_BASE_URL = "${inferenceRoot}"`,
      `$env:ANTHROPIC_AUTH_TOKEN = "${key}"`,
      `$env:ANTHROPIC_MODEL = "${alias}"`,
      '',
      'claude'
    ].join('\n'),

    claudeCodeSettingsJson: JSON.stringify({
      env: {
        ANTHROPIC_AUTH_TOKEN: key,
        ANTHROPIC_BASE_URL: inferenceRoot,
        ANTHROPIC_MODEL: alias
      }
    }, null, 2),

    codexConfig: [
      `[model_providers.${CODEX_PROVIDER}]`,
      'name = "SP Cambo"',
      `base_url = "${openAiBase}"`,
      `env_key = "${API_KEY_ENV}"`,
      'wire_api = "responses"',
      '',
      `[profiles.${CODEX_PROVIDER}]`,
      `model = "${alias}"`,
      `model_provider = "${CODEX_PROVIDER}"`
    ].join('\n'),

    codexShell: [
      `export ${API_KEY_ENV}="${key}"`,
      '',
      `codex --profile ${CODEX_PROVIDER}`
    ].join('\n'),

    curlMessages: [
      `curl ${inferenceRoot}/v1/messages \\`,
      `  -H "x-api-key: ${key}" \\`,
      '  -H "anthropic-version: 2023-06-01" \\',
      '  -H "content-type: application/json" \\',
      '  -d \'{',
      `    "model": "${alias}",`,
      '    "max_tokens": 256,',
      '    "messages": [{ "role": "user", "content": "Hello" }]',
      '  }\''
    ].join('\n'),

    pythonAnthropic: [
      'import os',
      'from anthropic import Anthropic',
      '',
      'client = Anthropic(',
      `    base_url="${inferenceRoot}",`,
      `    api_key=os.environ["${API_KEY_ENV}"],`,
      ')',
      '',
      'message = client.messages.create(',
      `    model="${alias}",`,
      '    max_tokens=256,',
      '    messages=[{"role": "user", "content": "Hello"}],',
      ')',
      '',
      'print(message.content[0].text)'
    ].join('\n'),

    nodeAnthropic: [
      'import Anthropic from \'@anthropic-ai/sdk\'',
      '',
      'const client = new Anthropic({',
      `  baseURL: '${inferenceRoot}',`,
      `  apiKey: process.env.${API_KEY_ENV}`,
      '})',
      '',
      'const message = await client.messages.create({',
      `  model: '${alias}',`,
      '  max_tokens: 256,',
      '  messages: [{ role: \'user\', content: \'Hello\' }]',
      '})',
      '',
      'console.log(message.content)'
    ].join('\n'),

    openAiPython: [
      'import os',
      'from openai import OpenAI',
      '',
      'client = OpenAI(',
      `    base_url="${openAiBase}",`,
      `    api_key=os.environ["${API_KEY_ENV}"],`,
      ')',
      '',
      'response = client.responses.create(',
      `    model="${alias}",`,
      '    input="Hello",',
      ')',
      '',
      'print(response.output_text)'
    ].join('\n')
  }
}
