<script setup lang="ts">
useSeoMeta({
  title: 'Privacy',
  description: 'What SP Cambo stores, what it deliberately does not store, who sees your requests, and what is still to be published.'
})

interface DataRow {
  category: string
  examples: string
  why: string
}

const stored: DataRow[] = [
  {
    category: 'Account',
    examples: 'Name, email address, a hash of your password, account status.',
    why: 'To let you sign in and to attribute purchases and usage to you.'
  },
  {
    category: 'Orders and payments',
    examples: 'Order records, amounts, currency, payment state, verification results.',
    why: 'To fulfil purchases, keep an auditable ledger, and answer billing questions.'
  },
  {
    category: 'API keys',
    examples: 'A hash of the secret, a display prefix, the last four characters, scope, status.',
    why: 'To authenticate requests without ever being able to show you the secret again.'
  },
  {
    category: 'Request metadata',
    examples: 'Model alias, key used, timestamps, duration, token counts, settlement state, error code.',
    why: 'To meter accurately, show you your own activity, and diagnose failures.'
  },
  {
    category: 'Operational logs',
    examples: 'Server logs with request identifiers, IP address and user agent.',
    why: 'Security, abuse prevention and debugging. Secrets are redacted before anything is written.'
  }
]

const notStored: string[] = [
  'Prompt text you send to a model.',
  'Completion text a model returns.',
  'Tool call arguments and results, and file contents you attach.',
  'System prompts and conversation history.',
  'Full API key secrets — only a hash, a prefix and the last four characters.',
  'Card or bank credentials. Payment happens on the payment network, not here.'
]
</script>

<template>
  <SpLegalShell
    title="Privacy"
    description="Metadata is what makes metering and your dashboard work. Content is not needed for either, so it is not kept."
    reviewed="21 August 2026"
  >
    <h2 id="principle">
      The short version
    </h2>
    <p>
      SP Cambo needs to know <em>that</em> you made a request, which alias and key it used, when, and how
      many tokens it consumed. It does not need to know <em>what</em> you asked. Content logging is off
      by default and usage logging is metadata-only.
    </p>

    <h2 id="stored">
      What is stored
    </h2>
    <div class="sp-scroll-x my-6 rounded-lg border border-default">
      <table class="w-full text-left text-sm">
        <thead class="border-b border-default bg-elevated/50 text-xs tracking-wide text-muted uppercase">
          <tr>
            <th class="px-4 py-3 font-medium">
              Category
            </th>
            <th class="px-4 py-3 font-medium">
              Examples
            </th>
            <th class="px-4 py-3 font-medium">
              Why
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr
            v-for="row in stored"
            :key="row.category"
          >
            <td class="px-4 py-3 align-top text-default whitespace-nowrap">
              {{ row.category }}
            </td>
            <td class="px-4 py-3 align-top text-muted">
              {{ row.examples }}
            </td>
            <td class="px-4 py-3 align-top text-muted">
              {{ row.why }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p>
      Your <NuxtLink to="/dashboard/usage">
        activity view
      </NuxtLink> shows the request metadata that is
      retained about you. If a field is not visible there, it is not being kept.
    </p>

    <h2 id="not-stored">
      What is not stored
    </h2>
    <ul>
      <li
        v-for="item in notStored"
        :key="item"
      >
        {{ item }}
      </li>
    </ul>
    <p>
      This is a design decision, not a promise about intentions. The metering path reads token counts,
      not text, so there is nowhere for prompt content to be persisted on the way through.
    </p>

    <h2 id="upstream">
      Who else sees your requests
    </h2>
    <p>
      Your request is forwarded to an upstream model provider in order to be answered, so that provider
      necessarily receives its content and handles it under its own policies and retention rules. SP
      Cambo cannot make an upstream provider forget a request on your behalf.
    </p>
    <p>
      SP Cambo does not publish which upstream provider serves a given alias, and does not expose
      internal routing. If you require a specific provider's data-handling terms for a compliance
      review, ask the operator before you build on it — do not infer it from a model name.
    </p>
    <p>
      Payments are processed over the Bakong payment network, which receives what it needs to settle the
      transaction.
    </p>

    <h2 id="security">
      How it is protected
    </h2>
    <ul>
      <li>Passwords are hashed, never stored or transmitted in a recoverable form.</li>
      <li>API key secrets are hashed. The full secret is shown exactly once, at creation or rotation.</li>
      <li>Secrets are redacted from logs and error reports.</li>
      <li>Privileged administrative actions are recorded in an audit trail.</li>
      <li>Traffic is served over TLS, and browser sessions are separate from inference credentials.</li>
    </ul>
    <p>
      Practical consequence for you: a compromised browser session cannot reveal your existing API key
      secrets, and a compromised API key cannot touch your account.
    </p>

    <h2 id="your-controls">
      Your controls
    </h2>
    <ul>
      <li>Revoke any API key at any time. Revocation is immediate and permanent.</li>
      <li>Scope keys to specific model aliases so each environment sees only what it needs.</li>
      <li>Review your own activity and orders in the dashboard.</li>
      <li>Sign out to end a browser session.</li>
    </ul>
    <p>
      Retention and purge windows are configurable by the operator and have not been published as fixed
      periods. Account deletion, data export and the process for a formal data-subject request are also
      not published yet. Those are operator decisions, and this page will not invent a timescale it
      cannot guarantee.
    </p>

    <h2 id="cookies">
      Cookies and local storage
    </h2>
    <p>
      SP Cambo uses storage for things the site cannot work without: your session credential, your
      light/dark preference, and dashboard layout state such as sidebar width. There is no advertising or
      cross-site tracking, and no third-party analytics tag is loaded by this site.
    </p>

    <h2 id="children">
      Children
    </h2>
    <p>
      SP Cambo is a developer tool sold to adults and is not intended for children.
    </p>

    <h2 id="pending">
      Not published yet
    </h2>
    <ul>
      <li>The data controller's legal identity and address.</li>
      <li>Concrete retention periods per data category.</li>
      <li>The self-serve route for account deletion and data export.</li>
      <li>A privacy contact channel.</li>
    </ul>
    <p>
      Related: <NuxtLink to="/legal/terms">
        terms of service
      </NuxtLink> and
      <NuxtLink to="/legal/acceptable-use">
        acceptable use
      </NuxtLink>.
    </p>
  </SpLegalShell>
</template>
