<script setup lang="ts">
useSeoMeta({
  title: 'Quick start',
  description: 'Create an SP Cambo account, buy a package, issue an API key and make your first request.'
})

const { claudeCodeShell, curlMessages } = useCliSnippets()
</script>

<template>
  <SpDocsShell
    title="Quick start"
    description="From a new account to a working request. Nothing here requires a provider account of your own."
  >
    <h2 id="create-an-account">
      1. Create an account
    </h2>
    <p>
      Sign up with an email address and a password of at least 12 characters. No payment method is
      stored, and nothing renews automatically.
    </p>
    <p>
      <NuxtLink to="/register">
        Create your account
      </NuxtLink>, then sign in. Your dashboard is the authoritative view of your balance, keys and
      usage.
    </p>

    <h2 id="buy-a-package">
      2. Buy a package
    </h2>
    <p>
      Open <NuxtLink to="/dashboard/buy">
        Buy tokens &amp; credits
      </NuxtLink> and choose a package.
      Each package publishes its price, its included units, its exact lifetime in seconds and the
      model aliases it covers.
    </p>
    <p>
      Checkout produces a Bakong KHQR code. Scan it with your banking app. The countdown you see is
      driven by the server, not your local clock. When you have paid, you can ask SP Cambo to
      re-check immediately — but only our backend can mark an order paid, and access activates only
      after that verification succeeds.
    </p>

    <h2 id="issue-an-api-key">
      3. Issue an API key
    </h2>
    <p>
      Go to <NuxtLink to="/dashboard/api-keys">
        API keys
      </NuxtLink> and create a key. Scope it to the
      models you intend to call, and give it a label that identifies where it will live, for example
      <code>laptop-cli</code> or <code>ci-runner</code>.
    </p>
    <p>
      <strong>The full secret is shown exactly once, at creation.</strong> Copy it into your password
      manager or your environment before closing the dialog. Afterwards, only the prefix and last
      four characters are ever displayed. If you lose it, rotate the key — you cannot recover it.
    </p>

    <h2 id="pick-a-model-alias">
      4. Pick a model alias
    </h2>
    <p>
      Browse the <NuxtLink to="/models">
        model catalogue
      </NuxtLink> and copy a public alias. Aliases
      are stable: SP Cambo can change upstream routing for reliability without breaking your
      configuration, so always configure the alias rather than a provider-specific model id.
    </p>

    <h2 id="make-your-first-request">
      5. Make your first request
    </h2>
    <p>
      For Claude Code, export two variables and run it. Note that the base URL is the gateway root
      with no <code>/v1</code> suffix — Claude Code appends its own path.
    </p>
    <SpCodeBlock
      filename="bash"
      :code="claudeCodeShell"
    />

    <p>Or call the Messages API directly:</p>
    <SpCodeBlock
      filename="bash"
      :code="curlMessages"
    />

    <h2 id="verify-it-worked">
      6. Verify it worked
    </h2>
    <p>
      Your dashboard overview shows the request within seconds. While a request is in flight its
      figures are marked <strong>Estimated</strong>: SP Cambo reserves an estimate up front and then
      settles against the usage the provider actually reported. Only settled rows are final.
    </p>

    <h2 id="what-next">
      Where to go next
    </h2>
    <ul>
      <li>
        <NuxtLink to="/docs/authentication">
          Authentication
        </NuxtLink> — key scoping, rotation, and what to do if a key leaks.
      </li>
      <li>
        <NuxtLink to="/docs/errors">
          Errors
        </NuxtLink> — the stable codes to branch on instead of parsing messages.
      </li>
      <li>
        <NuxtLink to="/docs/billing">
          Billing model
        </NuxtLink> — how reservations, settlement and expiry actually work.
      </li>
    </ul>
  </SpDocsShell>
</template>
