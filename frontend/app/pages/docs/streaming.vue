<script setup lang="ts">
useSeoMeta({
  title: 'Streaming',
  description: 'How streaming works through the SP Cambo gateway, how streamed requests are metered and settled, and how to handle interruptions.'
})

const { inferenceRoot, keyPlaceholder } = useCliSnippets()

const curlStream = computed(() => [
  `curl -N ${inferenceRoot.value}/v1/messages \\`,
  `  -H "x-api-key: ${keyPlaceholder}" \\`,
  '  -H "anthropic-version: 2023-06-01" \\',
  '  -H "content-type: application/json" \\',
  '  -d \'{',
  '    "model": "<your-model-alias>",',
  '    "max_tokens": 512,',
  '    "stream": true,',
  '    "messages": [{ "role": "user", "content": "Write a haiku about latency." }]',
  '  }\''
].join('\n'))

const nodeStream = computed(() => [
  'import Anthropic from \'@anthropic-ai/sdk\'',
  '',
  'const client = new Anthropic({',
  `  baseURL: '${inferenceRoot.value}',`,
  '  apiKey: process.env.SPCAMBO_API_KEY',
  '})',
  '',
  'const stream = await client.messages.stream({',
  '  model: \'<your-model-alias>\',',
  '  max_tokens: 512,',
  '  messages: [{ role: \'user\', content: \'Write a haiku about latency.\' }]',
  '})',
  '',
  'for await (const event of stream) {',
  '  if (event.type === \'content_block_delta\' && event.delta.type === \'text_delta\') {',
  '    process.stdout.write(event.delta.text)',
  '  }',
  '}',
  '',
  '// Usage totals are on the final message, not the deltas.',
  'const final = await stream.finalMessage()',
  'console.log(final.usage)'
].join('\n'))

const pythonStream = computed(() => [
  'import os',
  'from anthropic import Anthropic',
  '',
  'client = Anthropic(',
  `    base_url="${inferenceRoot.value}",`,
  '    api_key=os.environ["SPCAMBO_API_KEY"],',
  ')',
  '',
  'with client.messages.stream(',
  '    model="<your-model-alias>",',
  '    max_tokens=512,',
  '    messages=[{"role": "user", "content": "Write a haiku about latency."}],',
  ') as stream:',
  '    for text in stream.text_stream:',
  '        print(text, end="", flush=True)',
  '',
  '    print(stream.get_final_message().usage)'
].join('\n'))

const tabs = computed(() => [
  { label: 'cURL', value: 'curl', code: curlStream.value, filename: 'bash' },
  { label: 'Node.js', value: 'node', code: nodeStream.value, filename: 'stream.mjs' },
  { label: 'Python', value: 'python', code: pythonStream.value, filename: 'stream.py' }
])
</script>

<template>
  <SpDocsShell
    title="Streaming"
    description="Streaming is passed through unchanged. What differs is when the request is metered and when your balance settles."
  >
    <h2 id="enabling">
      Enabling it
    </h2>
    <p>
      Set <code>stream: true</code> on the request, exactly as you would against the upstream API. No
      SP Cambo-specific parameter or header is required, and no client patch is needed. Claude Code and
      Codex CLI stream by default.
    </p>
    <UTabs
      :items="tabs"
      class="my-6"
    >
      <template #content="{ item }">
        <SpCodeBlock
          :code="item.code"
          :filename="item.filename"
        />
      </template>
    </UTabs>

    <h2 id="transport">
      Transport
    </h2>
    <p>
      Streams are server-sent events. The gateway forwards upstream events as they arrive and does not
      buffer the response to completion, so first-token latency is the upstream latency plus routing
      overhead.
    </p>
    <p>
      If you are placing your own proxy in front of SP Cambo, disable response buffering on it. A
      buffering proxy turns a working stream into a request that appears to hang and then delivers
      everything at once — this is the single most common cause of "streaming does not work" reports,
      and it is not something the gateway can detect for you.
    </p>

    <h2 id="metering">
      When a streamed request is metered
    </h2>
    <p>
      Budget is reserved <strong>before the first upstream byte</strong>. A stream is not started
      unless the reservation safely covers the completion you asked for, which is why an oversized
      <code>max_tokens</code> against a nearly-spent package can be refused up front rather than
      failing halfway through.
    </p>
    <p>
      When the stream ends, the actual usage reported upstream is settled and the unused part of the
      reservation is released. Between those two moments your activity row shows an
      <strong>estimated</strong> figure.
    </p>
    <p>
      Estimated rows are never what you are charged. Read the value again after settlement — the
      dashboard marks the row and updates it in place.
    </p>

    <h2 id="usage-totals">
      Reading usage from a stream
    </h2>
    <p>
      Token counts are not on the individual deltas. They arrive with the terminal event of the
      stream, which the official SDKs expose as the final message. If you are parsing SSE by hand,
      accumulate the deltas for text and read usage from the last event rather than trying to count
      tokens yourself.
    </p>

    <h2 id="interruptions">
      Interruptions and cancellation
    </h2>
    <p>
      If you abort a stream — closing the connection, cancelling the SDK request, Ctrl-C in a CLI — the
      request still settles on what was actually produced before the abort. Cancelling early is not a
      way to get a completion for nothing, but nor are you charged for the tokens that were never
      generated.
    </p>
    <p>
      If the upstream call fails in a way that qualifies for a refund, the reservation is released
      exactly once. You do not need to ask for that, and re-checking does not double-release.
    </p>
    <p>
      A stream that dies mid-response is a normal network event, not a lost request. Retry it — see
      <NuxtLink to="/docs/rate-limits">
        rate limits
      </NuxtLink> for the backoff behaviour to use.
    </p>

    <h2 id="errors">
      Errors during a stream
    </h2>
    <p>
      An error raised before the stream opens looks like any other failure: an HTTP status and a JSON
      body carrying a machine <code>code</code>. An error raised after the first event has already been
      sent arrives as an error event <em>inside</em> the stream, because the status line has already
      gone out as <code>200</code>.
    </p>
    <p>
      Handle both. Treating any <code>200</code> as success is the classic streaming bug: your client
      reports a successful call while the user sees a truncated answer. The SDKs surface in-stream
      errors as exceptions, so this mostly matters if you are reading SSE directly.
    </p>

    <h2 id="privacy">
      What is recorded
    </h2>
    <p>
      The same metadata as any other request: alias, key, timing, token counts and settlement state.
      Streamed content is forwarded, not stored — no prompt text and no completion text is retained,
      including for streams that fail partway through.
    </p>
  </SpDocsShell>
</template>
