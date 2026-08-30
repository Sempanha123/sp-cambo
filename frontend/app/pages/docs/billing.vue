<script setup lang="ts">
useSeoMeta({
  title: 'Billing and packages',
  description: 'How SP Cambo packages, entitlement lots, FEFO consumption, reservations and KHQR payments work — and why nothing renews.'
})

const ledgerEvents = [
  { event: 'purchase', detail: 'A verified payment created one or more entitlement lots.' },
  { event: 'reservation', detail: 'Budget was held for an in-flight request.' },
  { event: 'settlement', detail: 'Actual metered usage was charged.' },
  { event: 'reservation_release', detail: 'The unused part of a reservation was returned.' },
  { event: 'refund', detail: 'A charge was reversed.' },
  { event: 'promo_grant', detail: 'A promotion granted quota or credit.' },
  { event: 'expiration', detail: 'A lot passed its expiry with quota remaining.' },
  { event: 'admin_adjustment', detail: 'A manual correction, recorded with its reason.' }
]

const lifecycle = [
  {
    title: 'Choose a package',
    detail: 'Every package publishes its quantity, price, exact lifetime, eligible model aliases and limits before you pay.'
  },
  {
    title: 'Pay by KHQR',
    detail: 'An order is created and a Bakong QR is issued with a real expiry. The countdown comes from the server clock, not your device.'
  },
  {
    title: 'Verification',
    detail: 'SP Cambo confirms the payment against the payment network. Nothing you press in the browser can mark an order paid.'
  },
  {
    title: 'Entitlement lots',
    detail: 'A verified payment creates lots with a start, an expiry and a remaining quantity. Fulfilment is idempotent: one payment credits you exactly once.'
  },
  {
    title: 'Spend',
    detail: 'Requests reserve, execute and settle against your lots until they are exhausted or expired.'
  }
]
</script>

<template>
  <SpDocsShell
    title="Billing and packages"
    description="Prepaid packages, exact quantities, no subscriptions and no overage. This page explains the mechanics; the catalogue holds the numbers."
  >
    <h2 id="prepaid">
      Prepaid, not subscribed
    </h2>
    <p>
      You buy a package. It grants a quantity that lasts for a published lifetime. When it is spent or
      expired, requests stop with a <code>402</code> and a clear code — they do not continue and become
      a bill.
    </p>
    <p>
      Nothing renews automatically, nothing is stored for future charges, and there is no monthly
      invoice to cancel. The worst case for a leaked key or a runaway script is the package you have
      already paid for, which is the entire reason the product is shaped this way.
    </p>

    <h2 id="modes">
      Two billing modes
    </h2>
    <p>
      <strong>Token quota</strong> packages grant Tokens. New model-visible input and generated output
      consume Tokens 1:1. When SP Cambo locally recognizes a repeated prompt prefix, those cached input
      Tokens consume 0.25× instead. There is no hidden output weight or provider-controlled multiplier.
    </p>
    <p>
      <strong>Credit balance</strong> is money-style wallet credit and is priced from the same local
      input/output counts. Dollar-denominated quota Credit packages shown in the catalogue use the
      published conversion <code>$1 Credit = 100,000 Tokens</code>.
    </p>
    <p>
      Which mode a package uses is stated on the
      <NuxtLink to="/pricing">
        pricing page
      </NuxtLink>, along with the aliases it covers.
    </p>

    <h2 id="billable-units">
      Local cache-aware metering
    </h2>
    <p>
      SP Cambo measures model-visible input and delivered output at its own public gateway. New input
      and output are 1:1. A repeated prompt prefix detected by SP Cambo's local cache is billed at
      <code>0.25×</code>. For example, 20,000 new input Tokens + 80,000 cached input Tokens + 500 output
      Tokens settles as <code>20,000 + 20,000 + 500 = 40,500 Tokens</code>.
    </p>
    <p>
      The local cache is scoped to the API key, public model and API protocol. It requires at least
      1,024 matching Tokens and uses a five-minute reuse window. Only SHA-256 segment hashes and token
      estimates are kept in the gateway cache; prompt text is not stored there. Failed/rejected
      inference attempts do not seed the cache.
    </p>
    <p>
      OmniRoute/provider usage, provider cache hits, reasoning counters and cost counters are never
      accepted as customer billing input. The local count is tokenizer-like and deterministic, so it
      can differ somewhat from a vendor's private tokenizer while remaining stable for the same public
      request/response.
    </p>

    <h2 id="lifecycle">
      From payment to spendable quota
    </h2>
    <ol>
      <li
        v-for="step in lifecycle"
        :key="step.title"
      >
        <strong>{{ step.title }}.</strong> {{ step.detail }}
      </li>
    </ol>

    <h2 id="lots">
      Entitlement lots and FEFO
    </h2>
    <p>
      Your balance is not one opaque number. Each purchase creates its own <strong>lot</strong> with its
      own quantity, start and expiry. Buying a second package while the first is still live gives you
      two lots, not a merged total.
    </p>
    <p>
      Consumption is <strong>first-expiring-first-out</strong>: the lot that expires soonest is spent
      first, so nothing is wasted while a later-expiring lot sits idle. Your entitlements view lists
      lots in the order they will actually be consumed.
    </p>
    <p>
      An expired lot never becomes spendable again, and neither does a revoked one. Quantity left in a
      lot when it expires is gone; that is what a lifetime means.
    </p>

    <h2 id="expiry">
      How lifetimes are measured
    </h2>
    <p>
      Lifetimes are exact seconds from activation. A one-day package is 24 hours from the moment payment
      is confirmed, not until midnight. Timestamps are stored in UTC and displayed in your timezone.
    </p>
    <p>
      Every package publishes its lifetime before you buy, and your dashboard shows the real expiry
      timestamp of each lot rather than a rounded phrase.
    </p>

    <h2 id="reserve-settle">
      Reserve, execute, settle
    </h2>
    <p>
      SP Cambo does not check your balance and then hope. Each request reserves a safe local maximum
      before inference, taking any SP Cambo local-cache discount into account, then settles the locally
      metered usage and releases the remainder in one atomic step.
    </p>
    <p>
      This is why concurrent requests cannot overspend a nearly-empty package, and why a large
      <code>max_tokens</code> can be refused up front: the reservation has to fit.
    </p>
    <p>
      Between execution and settlement, an activity row is marked <strong>estimated</strong>. An
      estimated figure is never what you are charged — it is the reservation, shown so the page is not
      blank while the request finishes. It is replaced in place when settlement lands.
    </p>
    <p>
      If a request fails in a way that qualifies for a refund, the reservation is released exactly once.
      Rejected requests — bad key, unknown alias, exhausted package, rate limit — never reserve anything
      at all.
    </p>

    <h2 id="exactness">
      Exact numbers, never floats
    </h2>
    <p>
      Money is transported as integer minor units with an explicit currency and exponent. Token and
      credit quantities are integer strings. Nothing in the billing path is a binary float, because
      binary floats cannot represent decimal money exactly and small errors accumulate over millions of
      metered units.
    </p>
    <p>
      If you consume these values programmatically, keep them exact. Parsing them into a
      double-precision number to display them is where rounding errors come from.
    </p>

    <h2 id="ledger">
      The ledger
    </h2>
    <p>
      Every movement of quota or money is an append-only ledger entry with an idempotency key. Nothing
      is edited in place and nothing is deleted, so your history reconciles.
    </p>
    <div class="sp-scroll-x my-6 rounded-lg border border-default">
      <table class="w-full text-left text-sm">
        <thead class="border-b border-default bg-elevated/50 text-xs tracking-wide text-muted uppercase">
          <tr>
            <th class="px-4 py-3 font-medium">
              Entry
            </th>
            <th class="px-4 py-3 font-medium">
              Meaning
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr
            v-for="entry in ledgerEvents"
            :key="entry.event"
          >
            <td class="px-4 py-3 align-top whitespace-nowrap">
              <code class="font-mono text-xs text-toned">{{ entry.event }}</code>
            </td>
            <td class="px-4 py-3 align-top text-muted">
              {{ entry.detail }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p>
      Duplicate payment callbacks and repeated verification checks cannot duplicate money or quota. Ask
      SP Cambo to re-check a payment as often as you like.
    </p>

    <h2 id="payments">
      Payments
    </h2>
    <p>
      Payment is by Bakong KHQR. The QR carries a real expiry; the countdown you see is corrected
      against the server clock so a wrong device clock cannot mislead you.
    </p>
    <p>
      The "I have paid" action asks the backend to re-verify. It is a request for a check, not an
      assertion of success — nothing in the browser can credit an account. If verification has not
      landed yet you will see <code>payment_pending</code>, which is a normal intermediate state.
    </p>
    <p>
      If a QR expires before you pay, start a new order. Do not pay an expired QR.
    </p>

    <h2 id="pricing-changes">
      Pricing changes and your history
    </h2>
    <p>
      Each usage record snapshots the pricing rules that applied at the time. A later catalogue change
      does not retroactively rewrite what you were charged, and your historical usage stays consistent
      with the balance movements beside it.
    </p>

    <h2 id="what-is-recorded">
      What is recorded against your account
    </h2>
    <p>
      Request metadata: alias, key, timestamps, duration, token counts, settlement state and the ledger
      entries above. Prompts, completions, tool payloads and file contents are not stored. See
      <NuxtLink to="/dashboard/usage">
        usage
      </NuxtLink> for exactly what is retained.
    </p>
    <p>
      Quota and balance errors are documented in <NuxtLink to="/docs/errors">
        errors
      </NuxtLink>.
    </p>
  </SpDocsShell>
</template>
