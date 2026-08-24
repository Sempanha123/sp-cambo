<script setup lang="ts">
useSeoMeta({
  title: 'Claude Code setup',
  description: 'Configure Claude Code to use SP Cambo: two environment variables, a model alias, and no provider account of your own.'
})

const { claudeCodeShell, claudeCodePowerShell, inferenceRoot } = useCliSnippets()

/** Render a usable shell command on first load instead of an empty tab panel. */
const selectedTab = ref('bash')

const tabs = computed(() => [
  { label: 'macOS / Linux', value: 'bash', code: claudeCodeShell.value, filename: 'bash' },
  { label: 'Windows PowerShell', value: 'pwsh', code: claudeCodePowerShell.value, filename: 'PowerShell' }
])
</script>

<template>
  <SpDocsShell
    title="Claude Code setup"
    description="Claude Code talks to SP Cambo through its standard Anthropic environment variables. No plugin and no patching required."
  >
    <h2 id="before-you-start">
      Before you start
    </h2>
    <ul>
      <li>Claude Code installed and working.</li>
      <li>
        An active package on your account — see <NuxtLink to="/pricing">
          pricing
        </NuxtLink>.
      </li>
      <li>
        An API key from <NuxtLink to="/dashboard/api-keys">
          your dashboard
        </NuxtLink>, and a model
        alias from <NuxtLink to="/models">
          the catalogue
        </NuxtLink>.
      </li>
    </ul>

    <h2 id="configure">
      Configure the environment
    </h2>
    <p>
      Set the base URL to the SP Cambo gateway root, the auth token to your key, and the model to an
      SP Cambo alias.
    </p>
    <UTabs
      v-model="selectedTab"
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

    <p>
      <strong>Do not append <code>/v1</code></strong> to <code>ANTHROPIC_BASE_URL</code>. Claude Code
      adds its own path, so the correct value is exactly <code>{{ inferenceRoot }}</code>. Adding
      <code>/v1</code> produces requests to <code>/v1/v1/messages</code>, which fail with a not-found
      error.
    </p>

    <h2 id="make-it-persistent">
      Making it persistent
    </h2>
    <p>
      Exporting in a shell only lasts for that shell. For everyday use, put the variables in your
      shell profile (<code>~/.zshrc</code>, <code>~/.bashrc</code>) or, better, in a per-project
      <code>.envrc</code> or secret manager so the key is not sitting in a file that gets backed up
      and shared.
    </p>
    <p>
      If you keep the key in a dotfile, make sure that dotfile is not in a repository. A key committed
      to git must be treated as compromised and rotated.
    </p>

    <h2 id="choosing-a-model">
      Choosing a model
    </h2>
    <p>
      Use the public alias exactly as shown in the catalogue. Aliases are stable across upstream
      routing changes, so a working configuration keeps working. A provider-specific model id is not a
      valid value here and will be rejected.
    </p>
    <p>
      Your key may be scoped to a subset of aliases. If Claude Code reports that the model is not
      permitted, check the key's scope in the dashboard rather than changing the base URL.
    </p>

    <h2 id="troubleshooting">
      Troubleshooting
    </h2>
    <h3>Every request 404s</h3>
    <p>
      Almost always a <code>/v1</code> on the end of <code>ANTHROPIC_BASE_URL</code>. Remove it and
      restart the shell.
    </p>

    <h3>401 or 403 immediately</h3>
    <p>
      The key is wrong, disabled, revoked, or scoped to different models. Validate it from the
      dashboard — validation does not spend any quota.
    </p>

    <h3>402 or "insufficient" errors</h3>
    <p>
      Your package is spent or expired. Requests stop rather than becoming an overage bill. Buy another
      package and access resumes immediately.
    </p>

    <h3>429s during a long session</h3>
    <p>
      You are hitting the per-key or per-package rate limit. See
      <NuxtLink to="/docs/rate-limits">
        rate limits
      </NuxtLink> for the correct backoff behaviour.
    </p>

    <h3>Variables set but ignored</h3>
    <p>
      Claude Code reads the environment at launch. Restart it after changing variables, and confirm
      they are exported in the same shell that launches it — not only in a parent process.
    </p>

    <h2 id="what-sp-cambo-sees">
      What SP Cambo records
    </h2>
    <p>
      Request metadata only: which alias, which key, when, how long, how many tokens, and whether it
      settled. Prompts, completions, tool payloads and file contents are not stored. Your
      <NuxtLink to="/dashboard/usage">
        activity view
      </NuxtLink> shows exactly what is retained.
    </p>
  </SpDocsShell>
</template>
