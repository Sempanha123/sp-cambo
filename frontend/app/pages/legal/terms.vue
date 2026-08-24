<script setup lang="ts">
useSeoMeta({
  title: 'Terms of service',
  description: 'The rules SP Cambo operates under: what you are buying, how packages and payments work, what we do not do, and what is still to be published.'
})
</script>

<template>
  <SpLegalShell
    title="Terms of service"
    description="What you are buying, what SP Cambo undertakes to do, and where the limits are."
    reviewed="21 August 2026"
  >
    <h2 id="what-you-buy">
      What you are buying
    </h2>
    <p>
      SP Cambo sells prepaid access to AI models through its own gateway. You buy a package; it grants
      a published quantity, usable with a published set of model aliases, for a published lifetime. You
      are not buying an account with an upstream provider, and you do not need one.
    </p>
    <p>
      There is no subscription. Nothing renews on its own, no payment method is stored for future
      charges, and there is no invoice to cancel. When a package is spent or expired, requests stop
      with an error — they never continue as an overage bill.
    </p>

    <h2 id="account">
      Your account
    </h2>
    <ul>
      <li>You are responsible for the security of your sign-in credentials and your API keys.</li>
      <li>
        Activity performed with your API keys is treated as yours. Revoke a key immediately if it may
        have leaked — revocation is instant and does not affect your other keys.
      </li>
      <li>
        Do not share one account between organisations that should not see each other's usage. Usage
        and activity are visible to whoever can sign in.
      </li>
      <li>
        SP Cambo may suspend an account for non-payment, for abuse, or where required to protect the
        service or other customers. A suspended account returns a clear error rather than failing
        silently.
      </li>
    </ul>

    <h2 id="packages">
      Packages, quantities and lifetimes
    </h2>
    <p>
      Quantities and lifetimes are exact and published before purchase. A lifetime runs in real seconds
      from the moment your payment is confirmed, not to the end of a calendar day.
    </p>
    <p>
      Quantity remaining in a package when its lifetime ends is forfeited. This is the nature of a
      time-limited package, and it is why every package states its lifetime on the
      <NuxtLink to="/pricing">
        pricing page
      </NuxtLink> before you pay.
    </p>
    <p>
      Where you hold more than one package, the one expiring soonest is consumed first, so nothing is
      wasted while a longer-lived package sits idle.
    </p>

    <h2 id="metering">
      Metering and charges
    </h2>
    <p>
      Each request reserves a safe maximum before it runs, then settles on the usage actually reported.
      Unused reservation is released. Until a request settles, the figure shown in your dashboard is
      marked as an estimate, and an estimate is never what you are charged.
    </p>
    <p>
      Requests SP Cambo rejects — invalid key, unknown or out-of-scope alias, exhausted package, rate
      limit, oversized request — are not charged at all. Where an upstream failure qualifies for a
      release or refund, that release happens exactly once, automatically.
    </p>
    <p>
      Every movement of quota or money is recorded as an append-only ledger entry, which is what your
      usage and orders pages read from.
    </p>

    <h2 id="payments">
      Payments
    </h2>
    <p>
      Payment is by Bakong KHQR. Payment confirmation is performed by SP Cambo against the payment
      network; nothing done in a browser can mark an order as paid. Asking SP Cambo to re-check a
      payment is always safe and cannot credit you twice.
    </p>
    <p>
      If a QR code expires before you pay it, create a new order. Do not pay an expired code.
    </p>

    <h2 id="availability">
      Availability
    </h2>
    <p>
      SP Cambo depends on upstream model providers and on payment infrastructure it does not control.
      Availability is reported honestly on the <NuxtLink to="/status">
        status page
      </NuxtLink>, which
      measures the service rather than displaying a decorative badge.
    </p>
    <p>
      No uptime figure is promised on this page. Publishing a service-level commitment is a decision for
      the operator, and inventing one here would be worse than saying plainly that it has not been made.
    </p>

    <h2 id="your-obligations">
      Use of the service
    </h2>
    <p>
      Your use must comply with <NuxtLink to="/legal/acceptable-use">
        acceptable use
      </NuxtLink>, which
      forms part of these terms. Serious or repeated breaches can result in keys being revoked and the
      account being suspended.
    </p>
    <p>
      Do not attempt to circumvent metering, rate limits, key scoping or entitlement expiry, and do not
      resell access unless you are operating under an approved reseller arrangement.
    </p>

    <h2 id="content">
      Your content and your outputs
    </h2>
    <p>
      SP Cambo does not claim ownership of the prompts you send or the completions you receive. Prompt
      and completion content is not retained — see <NuxtLink to="/legal/privacy">
        privacy
      </NuxtLink>.
    </p>
    <p>
      Model output is generated text. It can be wrong, and it is not professional advice. Verify
      anything you intend to rely on, particularly in legal, medical, financial or safety contexts.
    </p>

    <h2 id="changes">
      Changes
    </h2>
    <p>
      Catalogue prices, packages and limits can change. Changes apply to future purchases; they do not
      retroactively alter a package you already hold, and each usage record keeps the pricing rules that
      applied when it was made.
    </p>
    <p>
      Where these operating rules change materially, the reviewed date at the top of this page changes
      with them.
    </p>

    <h2 id="pending">
      Not published yet
    </h2>
    <p>
      The following require a decision by the operator of SP Cambo and are deliberately not stated here
      rather than guessed at:
    </p>
    <ul>
      <li>the contracting legal entity and its registered address;</li>
      <li>governing law, jurisdiction and the dispute-resolution process;</li>
      <li>the refund and cancellation policy beyond the automatic releases described above;</li>
      <li>any service-level commitment;</li>
      <li>the official support and legal contact channels.</li>
    </ul>
    <p>
      Until those are published, treat this page as an accurate description of how the service behaves,
      not as a complete contract.
    </p>
  </SpLegalShell>
</template>
