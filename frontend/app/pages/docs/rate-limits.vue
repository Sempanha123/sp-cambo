<script setup lang="ts">
useSeoMeta({
  title: 'Rate limits',
  description: 'How SP Cambo rate limits are scoped, how to read a 429, and the retry behaviour that keeps a long session healthy.'
})

const backoff = `const MAX_ATTEMPTS = 5

async function send(request) {
  for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
    const res = await fetch(request)
    if (res.status !== 429 && res.status < 500) return res

    // Honour the server first; fall back to exponential backoff with jitter.
    const retryAfter = Number(res.headers.get('retry-after'))
    const wait = Number.isFinite(retryAfter) && retryAfter > 0
      ? retryAfter * 1000
      : Math.min(2 ** attempt * 500, 20_000) + Math.random() * 250

    await new Promise(resolve => setTimeout(resolve, wait))
  }

  throw new Error('Rate limited after retries')
}`

const dimensions = [
  {
    field: 'requests_per_minute',
    label: 'Requests per minute',
    detail: 'How many calls you may start in a rolling minute. The first limit most interactive sessions meet.'
  },
  {
    field: 'tokens_per_minute',
    label: 'Tokens per minute',
    detail: 'Metered throughput. A few very large requests can hit this while your request count is low.'
  },
  {
    field: 'concurrency',
    label: 'Concurrency',
    detail: 'How many requests may be in flight at once. Long streams hold a slot for their whole duration.'
  },
  {
    field: 'max_request_bytes',
    label: 'Maximum request size',
    detail: 'Rejected before any upstream call, so an oversized request costs nothing.'
  },
  {
    field: 'max_output_tokens',
    label: 'Maximum output tokens',
    detail: 'Caps a single completion. A request asking for more than this is refused up front.'
  }
]
</script>

<template>
  <SpDocsShell
    title="Rate limits"
    description="Limits are per key and per package, set by SP Cambo rather than by the upstream provider, and published before you buy — plus two service-wide ceilings that apply to every key."
  >
    <h2 id="where-limits-come-from">
      Where your limits come from
    </h2>
    <p>
      Limits are attached to the package you bought and to the key you are calling with. They are
      published on the <NuxtLink to="/pricing">
        pricing page
      </NuxtLink> for every package and in the
      <NuxtLink to="/models">
        model catalogue
      </NuxtLink> for every alias, and repeated in your
      dashboard for the entitlements you actually hold.
    </p>
    <p>
      This page does not restate the numbers. Quoting them here would go stale the moment a package is
      re-configured, and the catalogue is the authoritative source.
    </p>

    <h2 id="dimensions">
      What is limited
    </h2>
    <div class="sp-scroll-x my-6 rounded-lg border border-default">
      <table class="w-full text-left text-sm">
        <thead class="border-b border-default bg-elevated/50 text-xs tracking-wide text-muted uppercase">
          <tr>
            <th class="px-4 py-3 font-medium">
              Limit
            </th>
            <th class="px-4 py-3 font-medium">
              Field
            </th>
            <th class="px-4 py-3 font-medium">
              Notes
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr
            v-for="dimension in dimensions"
            :key="dimension.field"
          >
            <td class="px-4 py-3 align-top text-default whitespace-nowrap">
              {{ dimension.label }}
            </td>
            <td class="px-4 py-3 align-top whitespace-nowrap">
              <code class="font-mono text-xs text-toned">{{ dimension.field }}</code>
            </td>
            <td class="px-4 py-3 align-top text-muted">
              {{ dimension.detail }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p>
      A <code>null</code> value means that dimension is not limited for that package or key. It does
      not mean zero, and it does not mean unlimited either — the service-wide ceilings below still
      apply.
    </p>

    <h2 id="service-ceilings">
      Ceilings that apply to every key
    </h2>
    <p>
      Two protections sit in front of your own limits and are enforced whatever your package says,
      including when a dimension on your key is <code>null</code>:
    </p>
    <ul>
      <li>
        an admission ceiling of <strong>120 requests per minute</strong> per API key on the inference
        endpoints, and <strong>60 per minute</strong> on the two key and model read endpoints. It is
        checked before your key is even looked up, so it applies to invalid credentials too.
      </li>
      <li>
        a maximum request size for the service as a whole, applied in addition to your key's
        <code>max_request_bytes</code>. The smaller of the two wins.
      </li>
    </ul>
    <p>
      These exist to keep one client from degrading the service for everyone, not as a product tier.
      If you are hitting them, you are almost certainly better served by spreading load across
      several keys — which is also how you find out which workload is producing it.
    </p>
    <p>
      When the limiter itself cannot be reached, requests are refused with
      <code>rate_limiter_unavailable</code> rather than admitted unchecked. That is deliberate: an
      unmetered request is worse than a refused one, for you as well as for us.
    </p>

    <h2 id="reading-a-429">
      Reading a 429
    </h2>
    <p>
      A rate-limited request returns HTTP <code>429</code>. There are two codes, and they are worth
      telling apart: <code>rate_limit_exceeded</code> means a per-minute ceiling on requests or tokens,
      and <code>concurrency_limit_exceeded</code> means too many requests are in flight at once. The
      first clears when the minute rolls over; the second clears as soon as one of your own requests
      finishes, which is why it is sent with a much shorter <code>Retry-After</code>.
    </p>
    <p>
      Where the server can say how long to wait, it sends a <code>Retry-After</code> header. Prefer
      that value over your own timer — it reflects the actual window, and ignoring it makes the queue
      worse for you as well as everyone else.
    </p>
    <p>
      Neither is a billing event. The request never reached a model, so nothing was metered and your
      balance is untouched.
    </p>

    <h2 id="backoff">
      Correct retry behaviour
    </h2>
    <p>
      Retry with exponential backoff and jitter, and cap the number of attempts. Jitter matters: a
      fleet of clients retrying on identical timers re-collides on every cycle.
    </p>
    <SpCodeBlock
      filename="retry.ts"
      :code="backoff"
    />
    <p>
      Retry <code>429</code> and <code>5xx</code>. Do <strong>not</strong> retry <code>402</code>,
      <code>401</code>, <code>403</code> or <code>422</code> — a quota, credential or validation failure
      returns the same answer every time, and retrying it just consumes your request budget.
    </p>

    <h2 id="cli-sessions">
      Long CLI sessions
    </h2>
    <p>
      Claude Code and Codex CLI already back off on 429s, so an occasional one during heavy use is
      normal and self-correcting. Sustained rate limiting usually means one of three things:
    </p>
    <ul>
      <li>several tools or machines are sharing a single key — give each its own;</li>
      <li>a script is looping without backoff alongside your interactive session;</li>
      <li>the package's limits are lower than the way you are working needs.</li>
    </ul>
    <p>
      Per-key activity in <NuxtLink to="/dashboard/usage">
        your dashboard
      </NuxtLink> tells you which
      key is producing the load, which is the fastest way to tell these apart.
    </p>

    <h2 id="concurrency">
      Concurrency and streaming
    </h2>
    <p>
      A streaming request holds a concurrency slot from the reservation until the stream ends. Parallel
      agents that each open a long stream can exhaust concurrency while the requests-per-minute figure
      still looks comfortable, which reads as unexplained 429s. Bound your own parallelism rather than
      letting a worker pool grow to whatever the machine allows.
    </p>

    <h2 id="upstream-limits">
      Limits that are not ours
    </h2>
    <p>
      Upstream capacity can also apply back-pressure. When that happens you get a retryable error
      rather than a silent failure or a partial charge, and the reservation is released. Your own SP
      Cambo limits are unaffected by it.
    </p>
    <p>
      See <NuxtLink to="/docs/errors">
        errors
      </NuxtLink> for the full code list and which of them are
      worth retrying.
    </p>
  </SpDocsShell>
</template>
