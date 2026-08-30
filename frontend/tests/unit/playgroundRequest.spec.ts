import { describe, expect, it } from 'vitest'
import { API_KEY_PLACEHOLDER, MODEL_ALIAS_PLACEHOLDER } from '~/utils/cliSnippets'
import {
  PLAYGROUND_DEFAULT_PROMPT,
  PLAYGROUND_PROTOCOLS,
  type PlaygroundProtocol,
  buildPlaygroundRequest,
  outputCeilingNote
} from '~/utils/playgroundRequest'

/**
 * A built request is only worth showing if it is the request the gateway accepts.
 *
 * The gateway refuses an unrecognised parameter outright rather than ignoring it,
 * so a body carrying one convenience field 400s with `unsupported_parameter` — and
 * a customer who copied it from SP Cambo's own playground has no reason to suspect
 * the snippet rather than their account. The allowlists below are copied from
 * `gateway/src/protocol.ts`; every field this builder emits has to be in them.
 */

/** A public gateway root, as `NUXT_PUBLIC_INFERENCE_ROOT_URL` would supply it. */
const ROOT = 'https://gateway.spcambo.example'

/** `FIELDS` from `gateway/src/protocol.ts`, verbatim. */
const ACCEPTED: Record<string, string[]> = {
  '/v1/messages': ['model', 'messages', 'system', 'max_tokens', 'metadata', 'stop_sequences', 'stream', 'temperature', 'thinking', 'tool_choice', 'tools', 'top_k', 'top_p', 'service_tier'],
  '/v1/responses': ['model', 'input', 'instructions', 'max_output_tokens', 'metadata', 'parallel_tool_calls', 'reasoning', 'service_tier', 'store', 'stream', 'temperature', 'text', 'tool_choice', 'tools', 'top_logprobs', 'top_p', 'truncation', 'user', 'include', 'previous_response_id', 'prompt_cache_key', 'stream_options', 'background', 'prompt', 'conversation', 'max_tool_calls'],
  '/v1/chat/completions': ['model', 'messages', 'max_completion_tokens', 'max_tokens', 'metadata', 'n', 'parallel_tool_calls', 'presence_penalty', 'frequency_penalty', 'reasoning_effort', 'response_format', 'seed', 'service_tier', 'stop', 'store', 'stream', 'stream_options', 'temperature', 'tool_choice', 'tools', 'top_logprobs', 'top_p', 'user']
}

const build = (overrides: Partial<Parameters<typeof buildPlaygroundRequest>[0]> = {}) =>
  buildPlaygroundRequest({
    inferenceRootUrl: ROOT,
    protocol: 'messages',
    modelAlias: 'sp-sonnet',
    userPrompt: 'Hello there',
    maxOutputTokens: 256,
    ...overrides
  })

const protocols = PLAYGROUND_PROTOCOLS.map(item => item.value)

const everySnippet = (request = build()) => [request.curl, request.python, request.node]

describe('request bodies', () => {
  it('emits only fields the gateway accepts, on every protocol and both stream modes', () => {
    for (const protocol of protocols) {
      for (const streaming of [false, true]) {
        const request = build({
          protocol,
          streaming,
          systemPrompt: 'Be brief.',
          temperature: 0.4
        })

        for (const field of Object.keys(request.body)) {
          expect(ACCEPTED[request.protocol.path], `${protocol}/${field}`).toContain(field)
        }
      }
    }
  })

  it('always carries max_tokens on Claude Messages, which has no server-side default', () => {
    expect(build({ protocol: 'messages' }).body.max_tokens).toBe(256)
  })

  it('names the output ceiling with the field each protocol reads', () => {
    expect(build({ protocol: 'responses', maxOutputTokens: 512 }).body).toMatchObject({ max_output_tokens: 512 })
    expect(build({ protocol: 'chat_completions', maxOutputTokens: 512 }).body).toMatchObject({ max_completion_tokens: 512 })
  })

  it('carries the system prompt where each protocol expects it', () => {
    expect(build({ protocol: 'messages', systemPrompt: 'Be brief.' }).body.system).toBe('Be brief.')
    expect(build({ protocol: 'responses', systemPrompt: 'Be brief.' }).body.instructions).toBe('Be brief.')
    expect(build({ protocol: 'chat_completions', systemPrompt: 'Be brief.' }).body.messages).toEqual([
      { role: 'system', content: 'Be brief.' },
      { role: 'user', content: 'Hello there' }
    ])
  })

  it('leaves an unset field out rather than sending a default in its place', () => {
    for (const protocol of protocols) {
      const body = build({ protocol, systemPrompt: '   ', temperature: null }).body

      expect(Object.keys(body), protocol).not.toContain('temperature')
      expect(Object.keys(body), protocol).not.toContain('system')
      expect(Object.keys(body), protocol).not.toContain('instructions')
      expect(Object.keys(body), protocol).not.toContain('stream')
    }
  })

  it('sends the stream flag only when streaming is asked for', () => {
    expect(build({ streaming: true }).body.stream).toBe(true)
    expect(build({ streaming: false }).body).not.toHaveProperty('stream')
  })

  it('sends temperature zero, which is a setting and not an absence', () => {
    expect(build({ temperature: 0 }).body.temperature).toBe(0)
  })

  it('falls back to a runnable prompt rather than an empty one', () => {
    expect(build({ userPrompt: '   ' }).body.messages).toEqual([
      { role: 'user', content: PLAYGROUND_DEFAULT_PROMPT }
    ])
  })

  it('shows a visible placeholder when no alias is selected yet', () => {
    expect(build({ modelAlias: null }).body.model).toBe(MODEL_ALIAS_PLACEHOLDER)
  })
})

describe('snippets', () => {
  it('never contains a credential', () => {
    for (const snippet of everySnippet(build({ streaming: true }))) {
      expect(snippet).not.toMatch(/sk-(?!your-key)/)
    }
  })

  it('reads the key from the environment in the SDK snippets and inlines only a placeholder in curl', () => {
    const request = build({ streaming: true })

    expect(request.curl).toContain(API_KEY_PLACEHOLDER)

    // An SDK snippet is committed to a file far more often than a curl line is, so
    // neither of them names a key at all — they read the environment variable.
    for (const snippet of [request.python, request.node]) {
      expect(snippet).toContain('SPCAMBO_API_KEY')
      expect(snippet).not.toContain(API_KEY_PLACEHOLDER)
    }
  })

  it('derives every host from runtime config rather than a literal', () => {
    for (const protocol of protocols) {
      for (const snippet of everySnippet(build({ protocol }))) {
        expect(snippet, protocol).toContain(ROOT)
        expect(snippet, protocol).not.toContain('spcambo.com')
      }
    }
  })

  it('gives the Anthropic SDK a root without /v1 and the OpenAI SDK a base with it', () => {
    expect(build({ protocol: 'messages' }).node).toContain(`baseURL: '${ROOT}'`)
    expect(build({ protocol: 'responses' }).node).toContain(`baseURL: '${ROOT}/v1'`)
    expect(build({ protocol: 'chat_completions' }).python).toContain(`base_url="${ROOT}/v1"`)
  })

  it('posts to the path the selected protocol is served on', () => {
    const paths: Record<PlaygroundProtocol, string> = {
      messages: '/v1/messages',
      responses: '/v1/responses',
      chat_completions: '/v1/chat/completions'
    }

    for (const [protocol, path] of Object.entries(paths) as Array<[PlaygroundProtocol, string]>) {
      const request = build({ protocol })

      expect(request.url).toBe(`${ROOT}${path}`)
      expect(request.curl).toContain(`${ROOT}${path}`)
    }
  })

  it('authenticates each protocol the way its own clients do', () => {
    expect(build({ protocol: 'messages' }).headers.map(header => header.name)).toEqual([
      'x-api-key',
      'anthropic-version',
      'content-type'
    ])
    expect(build({ protocol: 'responses' }).headers[0]).toEqual({
      name: 'authorization',
      value: `Bearer ${API_KEY_PLACEHOLDER}`
    })
  })

  it('renders the SDK call from the same body as the JSON, so the two cannot disagree', () => {
    const request = build({ protocol: 'chat_completions', maxOutputTokens: 999, temperature: 0.25 })

    expect(request.bodyJson).toContain('"max_completion_tokens": 999')
    expect(request.python).toContain('max_completion_tokens=999')
    expect(request.node).toContain('max_completion_tokens: 999')
    expect(request.python).toContain('temperature=0.25')
    expect(request.node).toContain('temperature: 0.25')
  })

  it('spells booleans the way each language does', () => {
    const request = build({ protocol: 'responses', streaming: true })

    expect(request.python).toContain('stream=True')
    expect(request.node).toContain('stream: true')
  })

  it('uses the streaming helper rather than a stream field on the Anthropic SDKs', () => {
    const request = build({ protocol: 'messages', streaming: true })

    expect(request.python).toContain('client.messages.stream(')
    expect(request.python).not.toContain('stream=True')
    expect(request.node).toContain('client.messages.stream({')
    expect(request.node).not.toContain('stream: true')
  })

  it('tells curl not to buffer a stream, so a stream does not look like a stall', () => {
    expect(build({ streaming: true }).curl.startsWith('curl -N ')).toBe(true)
    expect(build({ streaming: false }).curl.startsWith('curl https')).toBe(true)
  })

  it('keeps a quote in the prompt inside the shell argument it belongs to', () => {
    const request = build({ userPrompt: 'What\'s a monad?' })

    // The prompt is one -d argument. Closing it early would make the rest of the
    // JSON a separate shell word, and curl would post a truncated body.
    expect(request.curl).toContain('\'\\\'\'')
    expect(request.curl.split('  -d ')[1]?.startsWith('\'{')).toBe(true)
    expect(request.curl.trimEnd().endsWith('\'')).toBe(true)
  })

  it('embeds a prompt as a string literal both Python and JavaScript accept', () => {
    const request = build({ userPrompt: 'Line one\nLine "two"\\' })

    for (const snippet of [request.python, request.node]) {
      expect(snippet).toContain('Line one\\nLine \\"two\\"\\\\')
      expect(snippet).not.toContain('Line one\nLine')
    }
  })
})

describe('output ceiling', () => {
  it('warns that the gateway lowers a request above the published ceiling', () => {
    const note = outputCeilingNote(64000, 8192)

    expect(note).toContain('8,192')
    expect(note).toContain('64,000')
    expect(note).toContain('lowers it')
  })

  it('says nothing when the request is within the ceiling', () => {
    expect(outputCeilingNote(4096, 8192)).toBeNull()
    expect(outputCeilingNote(8192, 8192)).toBeNull()
  })

  it('does not invent a ceiling the catalogue has not published', () => {
    expect(outputCeilingNote(1_000_000, null)).toBeNull()
    expect(outputCeilingNote(1_000_000, undefined)).toBeNull()
  })
})
