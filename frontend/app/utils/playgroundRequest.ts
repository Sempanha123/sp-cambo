import { API_KEY_ENV, API_KEY_PLACEHOLDER, MODEL_ALIAS_PLACEHOLDER, normaliseInferenceRoot, openAiBaseUrl } from '~/utils/cliSnippets'

/**
 * The exact request the SP Cambo gateway accepts, plus runnable snippets for it.
 *
 * Pure building, kept out of the page so the guarantees can be tested directly:
 *
 * - every field emitted is on the gateway's per-protocol allowlist. The gateway
 *   refuses an unrecognised parameter outright with `unsupported_parameter` rather
 *   than ignoring it, so a builder that emits a convenience field produces a
 *   snippet that 400s (`gateway/src/protocol.ts`);
 * - `/v1/messages` always carries `max_tokens`. That protocol alone has no
 *   server-side default for it, so omitting it is a 400 and not a fallback;
 * - the SDK snippets are rendered from the same body object as the raw JSON, so a
 *   copied SDK call and a copied curl call cannot describe different requests;
 * - no snippet contains a credential — only the placeholder the CLI setup page
 *   uses — and the host always comes from public runtime config;
 * - a prompt is embedded as a JSON string literal, which is also a valid Python and
 *   JavaScript string literal, and single-quote-escaped for the shell. A prompt is
 *   customer text and must not be able to break out of the snippet it sits in.
 */

/** The three customer-callable inference protocols, in the order the docs teach them. */
export type PlaygroundProtocol = 'messages' | 'responses' | 'chat_completions'

export interface PlaygroundProtocolInfo {
  value: PlaygroundProtocol
  label: string
  /** Gateway path, appended to the inference root. */
  path: string
  /**
   * The published capability flag the control plane gates this protocol on. An
   * alias that does not state it is refused with `model_unavailable`.
   */
  capability: 'messages_api' | 'responses_api' | 'chat_completions_api'
  /** Which SDK the generated Python and Node snippets use. */
  sdk: 'anthropic' | 'openai'
  /** What the docs call this surface, for the copy next to the selector. */
  summary: string
}

export const PLAYGROUND_PROTOCOLS: readonly PlaygroundProtocolInfo[] = [
  {
    value: 'messages',
    label: 'Claude Messages',
    path: '/v1/messages',
    capability: 'messages_api',
    sdk: 'anthropic',
    summary: 'What Claude Code and the Anthropic SDKs speak.'
  },
  {
    value: 'responses',
    label: 'OpenAI Responses',
    path: '/v1/responses',
    capability: 'responses_api',
    sdk: 'openai',
    summary: 'What Codex CLI speaks.'
  },
  {
    value: 'chat_completions',
    label: 'Chat Completions',
    path: '/v1/chat/completions',
    capability: 'chat_completions_api',
    sdk: 'openai',
    summary: 'The widely supported OpenAI-compatible surface.'
  }
]

export const playgroundProtocol = (value: PlaygroundProtocol): PlaygroundProtocolInfo =>
  PLAYGROUND_PROTOCOLS.find(item => item.value === value) ?? PLAYGROUND_PROTOCOLS[0]!

/** Filled in when the prompt box is empty, so a copied snippet is always runnable. */
export const PLAYGROUND_DEFAULT_PROMPT = 'Say hello in one short sentence.'

export interface PlaygroundHeader {
  name: string
  value: string
}

export interface PlaygroundRequestInput {
  /** `runtimeConfig.public.inferenceRootUrl` — the customer-facing gateway root. */
  inferenceRootUrl: string
  protocol: PlaygroundProtocol
  /** A real alias once one is selected; otherwise a visible placeholder. */
  modelAlias?: string | null
  systemPrompt?: string | null
  userPrompt?: string | null
  maxOutputTokens: number
  /** `null` leaves the field out entirely rather than sending a default. */
  temperature?: number | null
  streaming?: boolean
}

export interface PlaygroundRequest {
  protocol: PlaygroundProtocolInfo
  method: 'POST'
  url: string
  headers: PlaygroundHeader[]
  /** The request body, exactly as the gateway will parse it. */
  body: Record<string, unknown>
  bodyJson: string
  curl: string
  python: string
  node: string
}

/** POSIX single quoting: close the quote, escape the quote, reopen it. */
const shellSingleQuoted = (value: string): string => `'${value.replaceAll('\'', '\'\\\'\'')}'`

/**
 * A JSON literal on one line, with the spacing a reader expects.
 *
 * Indenting and then collapsing the newlines is safe: a string value can never
 * contain a literal newline once serialised, so the only newlines present are
 * structural ones.
 */
const collapsedJson = (value: unknown): string => JSON.stringify(value, null, 1).replace(/\n\s*/g, ' ')

const pythonLiteral = (value: unknown): string =>
  value === true ? 'True' : value === false ? 'False' : collapsedJson(value)

const jsLiteral = (value: unknown): string => collapsedJson(value)

/**
 * SDK keyword arguments rendered from the request body itself.
 *
 * Every key in the body is a field the protocol accepts and the SDKs name
 * identically, so nothing is translated here. `skip` exists only for the streaming
 * helpers, which take the stream flag as a different method rather than a field.
 */
const sdkArguments = (
  body: Record<string, unknown>,
  options: { indent: string, assign: string, literal: (value: unknown) => string, skip?: string[] }
): string =>
  Object.entries(body)
    .filter(([name]) => !(options.skip ?? []).includes(name))
    .map(([name, value]) => `${options.indent}${name}${options.assign}${options.literal(value)},`)
    .join('\n')

/**
 * The request body for one protocol.
 *
 * Field order is the order a reader wants: what model, how much output it may
 * produce, then the prompt, then the optional knobs.
 */
const requestBody = (
  protocol: PlaygroundProtocol,
  input: { alias: string, system: string, user: string, maxOutputTokens: number, temperature: number | null, streaming: boolean }
): Record<string, unknown> => {
  const body: Record<string, unknown> = { model: input.alias }

  if (protocol === 'messages') {
    body.max_tokens = input.maxOutputTokens

    if (input.system) {
      body.system = input.system
    }

    body.messages = [{ role: 'user', content: input.user }]
  } else if (protocol === 'responses') {
    body.max_output_tokens = input.maxOutputTokens

    if (input.system) {
      body.instructions = input.system
    }

    body.input = input.user
  } else {
    // `max_completion_tokens` is the current OpenAI field; the gateway reads it
    // first and falls back to `max_tokens` only when it is absent.
    body.max_completion_tokens = input.maxOutputTokens
    body.messages = [
      ...(input.system ? [{ role: 'system', content: input.system }] : []),
      { role: 'user', content: input.user }
    ]
  }

  if (input.temperature !== null) {
    body.temperature = input.temperature
  }

  if (input.streaming) {
    body.stream = true
  }

  return body
}

const requestHeaders = (protocol: PlaygroundProtocol): PlaygroundHeader[] =>
  protocol === 'messages'
    ? [
        { name: 'x-api-key', value: API_KEY_PLACEHOLDER },
        { name: 'anthropic-version', value: '2023-06-01' },
        { name: 'content-type', value: 'application/json' }
      ]
    : [
        { name: 'authorization', value: `Bearer ${API_KEY_PLACEHOLDER}` },
        { name: 'content-type', value: 'application/json' }
      ]

const curlSnippet = (url: string, headers: PlaygroundHeader[], bodyJson: string, streaming: boolean): string => [
  // `-N` matters: without it curl buffers, and a stream looks like a stall.
  `curl${streaming ? ' -N' : ''} ${url} \\`,
  ...headers.map(header => `  -H ${shellSingleQuoted(`${header.name}: ${header.value}`)} \\`),
  `  -d ${shellSingleQuoted(bodyJson)}`
].join('\n')

const pythonSnippet = (
  protocol: PlaygroundProtocol,
  body: Record<string, unknown>,
  bases: { anthropic: string, openAi: string },
  streaming: boolean
): string => {
  const header = (module: string, klass: string, baseUrl: string) => [
    'import os',
    `from ${module} import ${klass}`,
    '',
    `client = ${klass}(`,
    `    base_url="${baseUrl}",`,
    `    api_key=os.environ["${API_KEY_ENV}"],`,
    ')',
    ''
  ]

  const args = (skip?: string[]) => sdkArguments(body, { indent: '    ', assign: '=', literal: pythonLiteral, skip })

  if (protocol === 'messages') {
    return streaming
      ? [
          ...header('anthropic', 'Anthropic', bases.anthropic),
          '# `.stream()` takes the same fields; the stream flag is the method, not an argument.',
          'with client.messages.stream(',
          args(['stream']),
          ') as stream:',
          '    for text in stream.text_stream:',
          '        print(text, end="", flush=True)'
        ].join('\n')
      : [
          ...header('anthropic', 'Anthropic', bases.anthropic),
          'message = client.messages.create(',
          args(),
          ')',
          '',
          'print(message.content[0].text)'
        ].join('\n')
  }

  if (protocol === 'responses') {
    return streaming
      ? [
          ...header('openai', 'OpenAI', bases.openAi),
          'stream = client.responses.create(',
          args(),
          ')',
          '',
          'for event in stream:',
          '    if event.type == "response.output_text.delta":',
          '        print(event.delta, end="", flush=True)'
        ].join('\n')
      : [
          ...header('openai', 'OpenAI', bases.openAi),
          'response = client.responses.create(',
          args(),
          ')',
          '',
          'print(response.output_text)'
        ].join('\n')
  }

  return streaming
    ? [
        ...header('openai', 'OpenAI', bases.openAi),
        'stream = client.chat.completions.create(',
        args(),
        ')',
        '',
        'for chunk in stream:',
        '    print(chunk.choices[0].delta.content or "", end="", flush=True)'
      ].join('\n')
    : [
        ...header('openai', 'OpenAI', bases.openAi),
        'completion = client.chat.completions.create(',
        args(),
        ')',
        '',
        'print(completion.choices[0].message.content)'
      ].join('\n')
}

const nodeSnippet = (
  protocol: PlaygroundProtocol,
  body: Record<string, unknown>,
  bases: { anthropic: string, openAi: string },
  streaming: boolean
): string => {
  const header = (importLine: string, klass: string, baseUrl: string) => [
    importLine,
    '',
    `const client = new ${klass}({`,
    `  baseURL: '${baseUrl}',`,
    `  apiKey: process.env.${API_KEY_ENV}`,
    '})',
    ''
  ]

  const args = (skip?: string[]) => sdkArguments(body, { indent: '  ', assign: ': ', literal: jsLiteral, skip })

  if (protocol === 'messages') {
    return streaming
      ? [
          ...header('import Anthropic from \'@anthropic-ai/sdk\'', 'Anthropic', bases.anthropic),
          '// `.stream()` takes the same fields; the stream flag is the method, not a field.',
          'const stream = client.messages.stream({',
          args(['stream']),
          '})',
          '',
          'for await (const text of stream.textStream) {',
          '  process.stdout.write(text)',
          '}'
        ].join('\n')
      : [
          ...header('import Anthropic from \'@anthropic-ai/sdk\'', 'Anthropic', bases.anthropic),
          'const message = await client.messages.create({',
          args(),
          '})',
          '',
          'console.log(message.content)'
        ].join('\n')
  }

  if (protocol === 'responses') {
    return streaming
      ? [
          ...header('import OpenAI from \'openai\'', 'OpenAI', bases.openAi),
          'const stream = await client.responses.create({',
          args(),
          '})',
          '',
          'for await (const event of stream) {',
          '  if (event.type === \'response.output_text.delta\') {',
          '    process.stdout.write(event.delta)',
          '  }',
          '}'
        ].join('\n')
      : [
          ...header('import OpenAI from \'openai\'', 'OpenAI', bases.openAi),
          'const response = await client.responses.create({',
          args(),
          '})',
          '',
          'console.log(response.output_text)'
        ].join('\n')
  }

  return streaming
    ? [
        ...header('import OpenAI from \'openai\'', 'OpenAI', bases.openAi),
        'const stream = await client.chat.completions.create({',
        args(),
        '})',
        '',
        'for await (const chunk of stream) {',
        '  process.stdout.write(chunk.choices[0]?.delta.content ?? \'\')',
        '}'
      ].join('\n')
    : [
        ...header('import OpenAI from \'openai\'', 'OpenAI', bases.openAi),
        'const completion = await client.chat.completions.create({',
        args(),
        '})',
        '',
        'console.log(completion.choices[0].message.content)'
      ].join('\n')
}

export function buildPlaygroundRequest(input: PlaygroundRequestInput): PlaygroundRequest {
  const protocol = playgroundProtocol(input.protocol)
  const root = normaliseInferenceRoot(input.inferenceRootUrl)
  const streaming = input.streaming === true

  const body = requestBody(protocol.value, {
    alias: input.modelAlias?.trim() || MODEL_ALIAS_PLACEHOLDER,
    system: (input.systemPrompt ?? '').trim(),
    user: (input.userPrompt ?? '').trim() || PLAYGROUND_DEFAULT_PROMPT,
    maxOutputTokens: input.maxOutputTokens,
    temperature: input.temperature ?? null,
    streaming
  })

  const bodyJson = JSON.stringify(body, null, 2)
  const headers = requestHeaders(protocol.value)
  const url = `${root}${protocol.path}`
  const bases = { anthropic: root, openAi: openAiBaseUrl(input.inferenceRootUrl) }

  return {
    protocol,
    method: 'POST',
    url,
    headers,
    body,
    bodyJson,
    curl: curlSnippet(url, headers, bodyJson, streaming),
    python: pythonSnippet(protocol.value, body, bases, streaming),
    node: nodeSnippet(protocol.value, body, bases, streaming)
  }
}

/**
 * Whether the gateway will silently lower the requested output ceiling.
 *
 * `upstreamBody` clamps the requested maximum to the alias's hard cap before
 * forwarding, so a request asking for more than the catalogue publishes still
 * succeeds — it just returns less than asked for, with nothing in the response
 * saying why. Returns `null` when there is nothing to warn about, including when
 * the catalogue does not publish a cap: an unpublished cap is not an absent one.
 */
export function outputCeilingNote(requested: number, publishedMax: number | null | undefined): string | null {
  if (typeof publishedMax !== 'number' || !Number.isFinite(requested) || requested <= publishedMax) {
    return null
  }

  return `This model publishes a ceiling of ${publishedMax.toLocaleString('en-US')} output tokens. `
    + `A request asking for ${requested.toLocaleString('en-US')} is not refused — the gateway lowers it to the ceiling `
    + 'and the response says nothing about having done so.'
}
