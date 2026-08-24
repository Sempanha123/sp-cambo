<script setup lang="ts">
useSeoMeta({
  title: 'Authentication',
  description: 'How SP Cambo API keys work: scoping, the one-time secret reveal, rotation, revocation and safe storage.'
})

const { keyPlaceholder, inferenceRoot } = useCliSnippets()

const headerExamples = computed(() => [
  `# Anthropic-compatible clients\ncurl ${inferenceRoot.value}/v1/messages \\\n  -H "x-api-key: ${keyPlaceholder}"`,
  `# OpenAI-compatible clients\ncurl ${inferenceRoot.value}/v1/responses \\\n  -H "Authorization: Bearer ${keyPlaceholder}"`
].join('\n\n'))
</script>

<template>
  <SpDocsShell
    title="Authentication"
    description="One credential type for inference, scoped per key, revocable at any time, and never recoverable after creation."
  >
    <h2 id="two-credential-types">
      Two credential types, kept apart
    </h2>
    <p>
      Signing in to this website creates a <strong>browser session</strong>. That session can manage
      your account, but it cannot call a model.
    </p>
    <p>
      Calling a model requires an <strong>SP Cambo API key</strong>. A key cannot manage your account,
      cannot buy packages and cannot read your other keys. Losing one is bad, but it is not the same
      as losing your login.
    </p>

    <h2 id="sending-the-key">
      Sending the key
    </h2>
    <p>
      Use the header your client already uses. Both are accepted on the inference gateway.
    </p>
    <SpCodeBlock
      filename="bash"
      :code="headerExamples"
    />
    <p>
      Never put a key in a query string. Query strings end up in proxy logs, browser history and
      error reports.
    </p>

    <h2 id="one-time-reveal">
      The secret is shown once
    </h2>
    <p>
      When you create or rotate a key, the full secret is returned exactly once and displayed in a
      dialog. After that it is unrecoverable: SP Cambo stores only a hash, plus a display prefix and
      the last four characters so you can tell your keys apart.
    </p>
    <p>
      This is deliberate. A key list that could reveal secrets would mean one compromised browser
      session leaks every credential you have ever created.
    </p>
    <p>
      If you did not capture the secret, rotate the key. Rotation issues a new secret and invalidates
      the old one immediately.
    </p>

    <h2 id="scoping">
      Scope every key
    </h2>
    <p>
      A key can be restricted to specific model aliases. Give each environment its own key, scoped to
      what that environment actually needs:
    </p>
    <ul>
      <li>a laptop key for interactive CLI work;</li>
      <li>a CI key, scoped narrowly, stored as a masked secret in your pipeline;</li>
      <li>a production key that nobody copies onto a developer machine.</li>
    </ul>
    <p>
      When you revoke one, the others keep working. That is the whole point.
    </p>

    <h2 id="status">
      Key states
    </h2>
    <ul>
      <li><strong>Active</strong> — usable.</li>
      <li><strong>Disabled</strong> — temporarily refused; can be re-enabled.</li>
      <li><strong>Revoked</strong> — permanently dead. This cannot be undone.</li>
      <li><strong>Expired</strong> — past its expiry date, if you set one.</li>
    </ul>
    <p>
      A revoked or expired key fails immediately and does not consume any balance.
    </p>

    <h2 id="testing-a-key">
      Testing a key without spending
    </h2>
    <p>
      The dashboard can validate a key and report its status, scope and remaining balance without
      running an inference request. Use that instead of firing a throwaway prompt: validation does not
      reserve or spend any of your quota.
    </p>

    <h2 id="if-a-key-leaks">
      If a key leaks
    </h2>
    <ol>
      <li>Revoke it. Do this first, before investigating — revocation is instant.</li>
      <li>Create a replacement with the narrowest scope that still works.</li>
      <li>
        Check <NuxtLink to="/dashboard/usage">
          usage and activity
        </NuxtLink> for requests you do not
        recognise. Activity is recorded per key.
      </li>
      <li>
        Purge the key from wherever it leaked: shell history, committed files, CI logs, screenshots.
        Rotating is not enough if the old value is still in your git history.
      </li>
    </ol>

    <h2 id="storage">
      Storing keys safely
    </h2>
    <ul>
      <li>Read keys from environment variables or a secret manager, never from source.</li>
      <li>
        Keep them out of client-side code entirely. A key shipped to a browser or a mobile app is a
        public key, whatever your intentions.
      </li>
      <li>Do not paste keys into chat tools, issue trackers or AI assistants.</li>
      <li>
        Treat any key that has ever been committed as compromised, even in a private repository, and
        rotate it.
      </li>
    </ul>
  </SpDocsShell>
</template>
